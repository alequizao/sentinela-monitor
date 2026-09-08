<?php
declare(strict_types=1);

/**
 * Monitor do Traccar em https://monitoramento.top/
 * -------------------------------------------------
 * Roda por cron 1x/min, mas cada execução faz um MINI-LOOP interno
 * (CICLOS x INTERVALO_SEG) — assim a checagem acontece a cada ~5s sem precisar
 * de daemon/supervisor. Um flock em monitor.run.lock impede sobreposição.
 *
 * O que é checado, em ESCADA (para no primeiro nível conclusivo, economizando
 * requisições):
 *   1) A página de login está no ar (HTTP 200 + shell do app React servido).
 *   2) O backend Traccar responde (GET /api/server = 200).
 *   3) O login REAL funciona: POST /api/session com o usuário/senha abaixo
 *      retorna 200 + JSON com "id". Só é testado 1x a cada LOGIN_CADA_MIN
 *      minutos (é o check mais caro e o que sofre rate limit no Traccar).
 *
 * CONFIRMAÇÃO DUPLA RÁPIDA: falha isolada não avisa. Ao falhar, o script repete
 * a checagem depois de RETRY_SEG segundos; só se a repetição também falhar é
 * que declara "down". Blip vira linha SUSPEITA no log e nada mais.
 *
 * Só avisa no Direct quando MUDA de estado (caiu / voltou) e re-lembra a cada
 * REAVISO_MIN enquanto estiver fora.
 *
 * SAÚDE DO ALERTA (checar a cada 5s sem virar spam):
 *   - "caiu" só vira Direct após CARENCIA_SEG (5 min) fora ININTERRUPTOS; queda
 *     curta/reinício de serviço fica somente no log.
 *   - "voltou" só depois de ESTAVEL_SEG de OK contínuo, para não gerar
 *     ping-pong quando o site oscila.
 *   - cooldown de COOLDOWN_MIN entre dois Direct quaisquer.
 *   - RELATÓRIO DIÁRIO às RELATORIO_HORA:RELATORIO_MIN: disponibilidade, tempo
 *     fora, incidentes e volume de checagens do dia (1 Direct, sempre enviado).
 *   - se houver FLAP_MAX transições numa hora, manda UM aviso "instável" e
 *     silencia por FLAP_SILENCIO_MIN (o log continua completo).
 *   - teto de MAX_AVISOS_DIA Direct por dia; estourando, só grava no log.
 * Limite da Meta é 100 chamadas/s por conta, então o gargalo aqui é reputação
 * da conta e a sua atenção — não a API.
 *
 * Aviso via Instagram Direct: manda a partir do cliente_id da própria conversa
 * (dm_conversas) usando dm_enviar() do sistema de agendamentos. A Meta só
 * entrega DM dentro de 24h desde a última msg da pessoa — se estourar, só log.
 */

date_default_timezone_set('America/Maceio');

/* ===== INSTÂNCIA =====
 * O mesmo código monitora mais de um Traccar: um wrapper fino (ex.:
 * nova/monitor.php) define as constantes abaixo e dá require neste arquivo.
 * Sem wrapper, valem os padrões — a instância original (monitoramento.top). */
if (!defined('ALVO_URL'))   { define('ALVO_URL', 'https://monitoramento.top/'); }
if (!defined('ALVO_NOME'))  { define('ALVO_NOME', 'monitoramento.top'); }   // rótulo nos alertas
if (!defined('INST_DIR'))   { define('INST_DIR', __DIR__); }                // estado/histórico/log
if (!defined('PAINEL_URL')) { define('PAINEL_URL', is_dir('/www/wwwroot/alequizao.com/agendamentos') ? 'https://alequizao.com/monitoramentotop/' : 'https://publishdev.com.br/monitoramentotop/'); }
/* Credenciais do Traccar ficam FORA do docroot, em arquivo 600 do root.
   Formato (ini):  usuario = xxx / senha = yyy  */
if (!defined('CRED_ARQ'))   { define('CRED_ARQ', '/etc/monitor-traccar.conf'); }
const TIMEOUT      = 6;   // segundos por requisição (site saudável responde <1s)
const CICLOS       = 11;  // checagens por execução do cron
const INTERVALO_SEG = 5;  // espera entre ciclos (11 x 5s ≈ 1 min de cobertura)
const RETRY_SEG    = 4;   // espera antes da confirmação da falha
const REAVISO_MIN  = 180; // enquanto fora, re-avisa a cada X min (3h)
const LOGIN_CADA_MIN = 5; // testa login de verdade a cada X min
/* --- saúde do alerta (evita rajada de Direct e conta marcada como spam) --- */
const CARENCIA_SEG   = 300; // só avisa queda após X s FORA ininterruptos (5 min)
const ESTAVEL_SEG    = 120; // só avisa "voltou" após X s seguidos de OK
/* --- relatório diário (um Direct por dia, com o resumo do período) --- */
const RELATORIO_HORA = 23;
const RELATORIO_MIN  = 50;
const HISTORICO_DIAS = 400; // dias de histórico guardados em historico/*.json
const COOLDOWN_MIN   = 10;  // silêncio mínimo entre dois Direct quaisquer
const FLAP_MAX       = 4;   // transições numa hora que caracterizam instabilidade
const FLAP_SILENCIO_MIN = 120; // silêncio após declarar "instável"
const MAX_AVISOS_DIA = 8;   // teto de segurança de Direct por dia
// Sistema de agendamentos (Direct + Web Push): VPS nova ou servidor publishdev.
define('AGENDA_DIR', is_dir('/www/wwwroot/alequizao.com/agendamentos') ? '/www/wwwroot/alequizao.com/agendamentos' : '/www/wwwroot/publishdev.com.br/agendamentos');
// Destinos do aviso no Direct (nome em dm_conversas).
const DM_DESTINOS = ['alequizao', 'djalma_rapha'];

/* ===== CREDENCIAIS (fora do docroot) ===== */
$cred = is_readable(CRED_ARQ) ? (parse_ini_file(CRED_ARQ) ?: []) : [];
define('LOGIN_USER', (string) ($cred['usuario'] ?? ''));
define('LOGIN_PASS', (string) ($cred['senha'] ?? ''));
/* IP real do servidor de origem (opcional). Com ele o monitor consegue provar
   DE QUE LADO a falha aconteceu: bate direto na origem, furando a Cloudflare.
   Sem ele, a sonda é pulada e o veredito fica "indeterminado". */
define('ORIGEM_IP', (string) ($cred['origem_ip'] ?? ''));

require_once __DIR__ . '/lib_dossie.php';

$DIR    = INST_DIR;
$ESTADO = $DIR . '/estado.json';

/* ===== NOTA NO DOSSIÊ: php monitor.php --nota INC-XXXX "texto" ===== */
if (isset($argv[1]) && $argv[1] === '--nota') {
    $ok = dossie_nota((string) ($argv[2] ?? ''), trim((string) ($argv[3] ?? '')), 'operador');
    echo $ok ? "nota gravada em {$argv[2]}\n" : "dossiê não encontrado\n";
    exit($ok ? 0 : 1);
}
$LOG    = $DIR . '/monitor.log';
// Lock próprio: o cron já usa "flock -n monitor.lock", então NÃO reutilize esse
// arquivo aqui — o lock do cron bloquearia o do próprio script.
$LOCK   = $DIR . '/monitor.run.lock';

/* ===== PRÉ-VISUALIZAÇÃO: php monitor.php --exemplos =====
 * Mostra os 4 alertas como sairão no Direct, sem checar nada e sem enviar. */
if (in_array('--exemplos', $argv ?? [], true)) {
    $t = time();
    echo msg_alerta('critico', [
        'Incidente' => inc_id($t), 'Detectado' => date('d/m H:i:s', $t),
        'Falha'     => 'Cloudflare não alcançou o servidor de origem (HTTP 530)',
        'Onde'      => camada_rotulo('borda')
                     . ' · origem respondeu 200 em 796ms — a falha está entre a Cloudflare e o servidor',
        'CF-Ray'    => 'a2eb5499b9e94f2e-EWR',
        'Impacto'   => 'clientes sem acesso ao rastreamento',
    ], acao_sugerida(['camada' => 'borda', 'origem_ok' => true])), "\n\n";
    echo msg_alerta('ativo', [
        'Incidente' => inc_id($t),
        'Fora desde'=> date('d/m H:i:s', $t) . ' (' . dur(3900) . ')',
        'Falha'     => 'Backend Traccar fora (api/server HTTP 502)',
    ], 'Ainda sem resposta · intervenção manual necessária'), "\n\n";
    echo msg_alerta('resolvido', [
        'Incidente'  => inc_id($t), 'Normalizado' => date('d/m H:i:s', $t + 740),
        'Duração'    => dur(740),
        'Verificado' => 'página, API e login OK por ' . dur(ESTAVEL_SEG),
    ]), "\n\n";
    echo msg_alerta('atencao', [
        'Detectado' => date('d/m H:i:s', $t),
        'Padrão'    => 'caiu e voltou ' . FLAP_MAX . 'x na última hora',
        'Estado'    => 'no ar agora',
        'Último motivo' => "Login FALHOU para '" . LOGIN_USER . "' (HTTP 400)",
    ], 'Alertas silenciados por ' . FLAP_SILENCIO_MIN . ' min · verifique logs do Traccar'), "\n\n";
    echo msg_relatorio([
        'seg_fora'   => 754,
        'incidentes' => [
            ['inicio' => $t - 7200, 'fim' => $t - 6800, 'seg' => 400,
             'motivo' => 'Internal Server Error (Apache/Traccar caiu, HTTP 500)'],
            ['inicio' => $t - 1800, 'fim' => $t - 1446, 'seg' => 354,
             'motivo' => 'Backend Traccar fora (api/server HTTP 502)'],
        ],
        'checagens'  => 15840, 'falhas' => 142, 'blips' => 3,
    ], 'ok', 4, $t), "\n";
    exit(0);
}

/* ===== ENVIO MANUAL DO RELATÓRIO =====
 * php monitor.php --relatorio-agora [destino]
 * Usa as estatísticas REAIS acumuladas hoje e NÃO marca relatorio_data, então o
 * relatório automático das 23:50 continua saindo normalmente. Sem "destino",
 * manda para todos os DM_DESTINOS. */
if (in_array('--relatorio-agora', $argv ?? [], true)) {
    $e = ler_estado($ESTADO);
    $ts = time();
    $dest = null;
    foreach (array_slice($argv, 1) as $a) {
        if ($a !== '--relatorio-agora' && $a[0] !== '-') { $dest = [$a]; }
    }
    $rel = msg_relatorio([
        'seg_fora'   => (int) $e['dia_seg_fora'],
        'incidentes' => (array) $e['dia_incidentes'],
        'checagens'  => (int) $e['dia_checagens'],
        'falhas'     => (int) $e['dia_falhas'],
        'blips'      => (int) $e['dia_blips'],
    ], (string) $e['status'], (int) $e['avisos_dia'], $ts);
    echo $rel, "\n\n";
    $r = enviar_direct($rel, $dest);
    gravar_log($LOG, '[' . date('Y-m-d H:i:s') . "] RELATORIO :: envio manual :: ({$r})\n");
    exit(0);
}

/* ===== LOCK: uma execução por vez (cada run dura ~1 min) ===== */
$fh = fopen($LOCK, 'c');
if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
    exit(0); // execução anterior ainda rodando: nada a fazer
}

/* ===== HTTP: um único handle reaproveitado (keep-alive + gzip) ===== */
$EVIDENCIA = [];   // evidência da falha do ciclo atual (ver anotar_evidencia)
$CH = curl_init();
curl_setopt_array($CH, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_ACCEPT_ENCODING => '',      // aceita gzip: menos bytes na rede
    CURLOPT_USERAGENT      => 'MonitorTraccar/2.0',
]);

/* Cabeçalhos da última resposta (minúsculos). Preenchido por http(). */
$CABECALHOS = [];

function http(string $url, ?array $post = null): array {
    global $CH, $CABECALHOS;
    $CABECALHOS = [];
    // Guardar server/cf-ray é o que separa "a origem devolveu erro" de
    // "a Cloudflare não alcançou a origem" — sem isso o motivo é chute.
    curl_setopt($CH, CURLOPT_HEADERFUNCTION, function ($ch, $linha) {
        global $CABECALHOS;
        $par = explode(':', $linha, 2);
        if (count($par) === 2) { $CABECALHOS[strtolower(trim($par[0]))] = trim($par[1]); }
        return strlen($linha);
    });
    curl_setopt($CH, CURLOPT_URL, $url);
    if ($post !== null) {
        curl_setopt($CH, CURLOPT_POST, true);
        curl_setopt($CH, CURLOPT_POSTFIELDS, http_build_query($post));
    } else {
        curl_setopt($CH, CURLOPT_HTTPGET, true);
    }
    $body = curl_exec($CH);
    global $CABECALHOS;
    return [
        'code'   => (int) curl_getinfo($CH, CURLINFO_HTTP_CODE),
        'body'   => (string) $body,
        'err'    => curl_error($CH),
        'ms'     => (int) round(curl_getinfo($CH, CURLINFO_TOTAL_TIME) * 1000),
        'server' => (string) ($CABECALHOS['server'] ?? ''),
        'cf_ray' => (string) ($CABECALHOS['cf-ray'] ?? ''),
    ];
}

/* ===== DE QUE LADO QUEBROU =====
 * A Cloudflare usa a faixa 520-530 para dizer "eu não consegui falar com a
 * origem" (521 recusou, 522 timeout, 523 inalcançável, 530 = 1016 etc.). Um
 * 500/502 comum, ao contrário, é erro que a PRÓPRIA origem produziu e a CF só
 * repassou. Distinguir os dois é a diferença entre "reiniciar o Traccar" e
 * "olhar a rede/o servidor". */
function camada_da_falha(int $code, string $server, string $err): array
{
    $cf = stripos($server, 'cloudflare') !== false;
    if ($code >= 520 && $code <= 530) {
        return ['borda', 'Cloudflare não alcançou o servidor de origem (HTTP ' . $code . ')'];
    }
    if ($code >= 500 && $code < 520) {
        return [$cf ? 'origem' : 'origem',
            'A origem respondeu com erro ' . $code . ($cf ? ' (repassado pela Cloudflare)' : '')];
    }
    if ($code === 0) {
        return ['rede', 'Nenhuma resposta: ' . ($err !== '' ? $err : 'conexão não completou')];
    }
    return ['indeterminado', 'HTTP ' . $code];
}

/* ===== SONDA DIRETA NA ORIGEM =====
 * Repete a requisição resolvendo o domínio NO IP DE ORIGEM, furando a
 * Cloudflare — mesmo Host e mesmo SNI, então o certificado continua válido.
 * Só roda quando uma falha já foi confirmada: é uma requisição extra por
 * incidente, não por ciclo. Devolve o veredito da causa raiz. */
function sondar_origem(): array
{
    if (ORIGEM_IP === '') {
        return ['ok' => null, 'veredito' => 'indeterminado',
                'texto' => 'sonda de origem não configurada (origem_ip)'];
    }
    $host = (string) parse_url(ALVO_URL, PHP_URL_HOST);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => ALVO_URL,
        CURLOPT_RESOLVE        => [$host . ':443:' . ORIGEM_IP, $host . ':80:' . ORIGEM_IP],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,   // a origem costuma ter cert da CF (origin cert)
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'MonitorTraccar/2.0 (sonda-origem)',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms   = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $err  = curl_error($ch);
    curl_close($ch);

    $viva = ($code > 0 && $code < 500);
    if ($viva) {
        return ['ok' => true, 'veredito' => 'borda',
                'texto' => 'origem respondeu ' . $code . ' em ' . $ms . 'ms — a falha está entre a Cloudflare e o servidor'];
    }
    return ['ok' => false, 'veredito' => 'origem',
            'texto' => 'origem também falhou (' . ($code ?: ($err ?: 'sem resposta')) . ') — o problema é no servidor'];
}

/* ===== CHECAGEM (uma passada) =====
 * Em escada: se a home já está fora, não faz sentido bater em api/server nem
 * gastar um POST de login. Retorna a lista de falhas (vazia = tudo ok). */
/* Rótulo humano da camada e a ação que ela realmente pede. */
function camada_rotulo(string $camada): string
{
    if ($camada === 'borda')  { return 'entre a Cloudflare e o servidor'; }
    if ($camada === 'origem') { return 'no servidor de origem'; }
    if ($camada === 'rede')   { return 'na rede / sem resposta'; }
    if ($camada === 'tunel')  { return 'no Cloudflare Tunnel (cloudflared desconectado)'; }
    return 'indeterminado';
}

function acao_sugerida(array $ev): string
{
    $camada = (string) ($ev['camada'] ?? '');
    // "Origem viva" só pode ser afirmado se a SONDA confirmou. Sem ela, o 52x
    // apenas diz que a Cloudflare não chegou lá — e o servidor segue suspeito.
    $sondado = array_key_exists('origem_ok', $ev) && $ev['origem_ok'] === true;
    if ($camada === 'tunel')  { return 'Túnel desligado: systemctl restart cloudflared na VPS e conferir o ingress no Zero Trust'; }
    if ($camada === 'origem') { return 'Checar Traccar + Apache no servidor de origem'; }
    if ($camada === 'borda') {
        return $sondado
            ? 'Origem viva: checar Cloudflare, firewall e rota até a origem'
            : 'Cloudflare não alcançou a origem: checar o servidor, o firewall e a rota';
    }
    if ($camada === 'rede')   { return 'Sem resposta: checar conectividade e DNS'; }
    return 'Checar Traccar + Apache no servidor';
}

/* Evidência técnica da última falha (código, cabeçalhos, camada). Fica global
 * porque atravessa a checagem, o incidente, o alerta e o painel. */
$EVIDENCIA = [];

function anotar_evidencia(array $r, string $etapa): void
{
    global $EVIDENCIA;
    list($camada, $explica) = camada_da_falha((int) $r['code'], (string) $r['server'], (string) $r['err']);
    $EVIDENCIA = [
        'etapa'   => $etapa,
        'code'    => (int) $r['code'],
        'ms'      => (int) $r['ms'],
        'server'  => (string) $r['server'],
        'cf_ray'  => (string) $r['cf_ray'],
        'camada'  => $camada,
        'explica' => $explica,
        // Código de erro da Cloudflare (1033 = túnel sem cloudflared, 1016 =
        // origem DNS, 1000...). Vem no HTML da página de erro da borda.
        'cf_erro' => preg_match('/error code:?\s*(\d{4})/i', (string) ($r['body'] ?? ''), $m) ? (int) $m[1] : 0,
    ];
    if ($EVIDENCIA['cf_erro'] === 1033) {
        $EVIDENCIA['camada']  = 'tunel';
        $EVIDENCIA['explica'] = 'Cloudflare Tunnel sem cloudflared conectado (HTTP ' . $r['code'] . ', erro 1033)';
    }
}

function checar(bool $com_login): array {
    global $EVIDENCIA;
    $EVIDENCIA = [];

    // 1) Página de login no ar (detecta o "Internal Server Error" do Apache)
    $home = http(ALVO_URL);
    $ise  = stripos($home['body'], 'Internal Server Error') !== false
         || stripos($home['body'], 'internal error or misconfiguration') !== false;
    if ($home['code'] >= 500 || $ise) {
        anotar_evidencia($home, 'home');
        // O motivo agora sai da EVIDÊNCIA, não de um rótulo fixo: 520-530 é a
        // Cloudflare sem alcançar a origem, e chamar isso de "Apache caiu" foi
        // exatamente o diagnóstico errado que o painel exibiu por meses.
        return [$EVIDENCIA['explica']];
    }
    if ($home['code'] !== 200) {
        anotar_evidencia($home, 'home');
        return ["Página não abre (HTTP {$home['code']}" . ($home['err'] ? " / {$home['err']}" : '') . ')'];
    }
    if (stripos($home['body'], '<div id="root">') === false
     && stripos($home['body'], 'assets/index-') === false) {
        return ['Página abre mas o app (tela de login) não carregou'];
    }

    // 2) Backend Traccar vivo
    $srv = http(ALVO_URL . 'api/server');
    if ($srv['code'] !== 200) {
        anotar_evidencia($srv, 'api/server');
        return ["Backend Traccar fora (api/server HTTP {$srv['code']})"];
    }

    // 3) Login real (usuário + senha) — só quando pedido e com credencial lida.
    // Sem credencial, NÃO inventa falha de login: o problema é de configuração,
    // sinalizado à parte para não gerar alerta de queda indevido.
    if ($com_login && LOGIN_USER !== '' && LOGIN_PASS !== '') {
        $sess = http(ALVO_URL . 'api/session', ['email' => LOGIN_USER, 'password' => LOGIN_PASS]);
        if ($sess['code'] !== 200) {
            anotar_evidencia($sess, 'api/session');
            return ["Login FALHOU para '" . LOGIN_USER . "' (HTTP {$sess['code']})"];
        }
        if (strpos($sess['body'], '"id"') === false) {
            return ['Login retornou 200 mas sem sessão válida'];
        }
    }

    return [];
}

/* ===== MENSAGEM (estilo app de notificação de segurança) =====
 * Cabeçalho fixo + severidade + campos rotulados + ID de incidente, para o
 * alerta ser lido de relance no Direct e dar para referenciar depois. */
function msg_alerta(string $nivel, array $campos, string $acao = ''): string
{
    $cab = [
        'critico'  => "🛡️ SENTINELA · " . ALVO_NOME . "\n🔴 ALERTA CRÍTICO",
        'ativo'    => "🛡️ SENTINELA · " . ALVO_NOME . "\n🔴 INCIDENTE EM ANDAMENTO",
        'resolvido'=> "🛡️ SENTINELA · " . ALVO_NOME . "\n🟢 INCIDENTE RESOLVIDO",
        'atencao'  => "🛡️ SENTINELA · " . ALVO_NOME . "\n🟡 ATENÇÃO · SERVIÇO INSTÁVEL",
        'relatorio'=> "🛡️ SENTINELA · " . ALVO_NOME . "\n📊 RELATÓRIO DO DIA",
    ][$nivel];

    $linha = str_repeat('━', 18);
    $out   = "{$cab}\n{$linha}\n";
    foreach ($campos as $rotulo => $valor) {
        $out .= "{$rotulo}: {$valor}\n";
    }
    $out .= $linha . "\n";
    if ($acao !== '') { $out .= "▸ {$acao}\n"; }
    return $out . ALVO_URL;
}

/* Relatório de fim de dia: disponibilidade, incidentes e volume de checagens. */
function msg_relatorio(array $d, string $status, int $avisos, int $ts): string
{
    $seg_dia  = $ts - strtotime(date('Y-m-d 00:00:00', $ts));
    $fora     = (int) $d['seg_fora'];
    $disp     = $seg_dia > 0 ? max(0, 100 - ($fora / $seg_dia * 100)) : 100;
    $incid    = (array) $d['incidentes'];

    $campos = [
        'Período'         => date('d/m', $ts) . ' · 00:00–' . date('H:i', $ts),
        'Disponibilidade' => number_format($disp, 2, ',', '.') . '%',
        'Tempo fora'      => $fora > 0 ? dur($fora) : 'nenhum',
        'Incidentes'      => count($incid) > 0 ? (string) count($incid) : 'nenhum',
        'Checagens'       => (string) $d['checagens'] . ' (falhas: ' . $d['falhas']
                           . ' · isoladas descartadas: ' . $d['blips'] . ')',
        'Alertas enviados'=> (string) $avisos,
        'Estado agora'    => ($status === 'ok' ? '🟢 no ar' : '🔴 fora do ar'),
    ];

    $out = msg_alerta('relatorio', $campos);
    if ($incid) {
        // Detalha os 5 incidentes mais recentes, do mais novo para o mais velho.
        $linhas = [];
        foreach (array_reverse(array_slice($incid, -5)) as $i) {
            $dura = (int) $i['fim'] > 0 ? dur((int) $i['seg']) : 'em andamento';
            $linhas[] = '• ' . date('H:i', (int) $i['inicio']) . " ({$dura}) — "
                      . mb_strimwidth((string) $i['motivo'], 0, 70, '…');
        }
        $out = str_replace(ALVO_URL, "Incidentes:\n" . implode("\n", $linhas) . "\n" . ALVO_URL, $out);
    }
    return $out;
}

/* ID curto e estável por incidente (derivado do instante em que caiu). */
function inc_id(int $ts): string
{
    return 'INC-' . strtoupper(base_convert((string) $ts, 10, 36));
}

/* Duração legível: 45s, 12min, 2h03. */
function dur(int $seg): string
{
    if ($seg < 60)   { return $seg . 's'; }
    if ($seg < 3600) { return intdiv($seg, 60) . 'min'; }
    return intdiv($seg, 3600) . 'h' . str_pad((string) intdiv($seg % 3600, 60), 2, '0', STR_PAD_LEFT);
}

/* ===== ESTADO ===== */
function ler_estado(string $arq): array {
    $prev = [
        'status'       => 'ok',              // o que os checks dizem AGORA
        'avisado'      => 'ok',              // o último estado que virou Direct
        'desde'        => date('Y-m-d H:i:s'),
        'mudou_em'     => 0,                 // timestamp da última troca de status
        'inc_em'       => 0,                 // início do incidente atual (ID/duração)
        'ultimo_aviso' => 0,
        'ultimo_login' => 0,
        'transicoes'   => [],                // timestamps das trocas (última hora)
        'flap_ate'     => 0,                 // silêncio por instabilidade até
        'avisos_dia'   => 0,
        'avisos_data'  => date('Y-m-d'),
        /* --- estatísticas do dia, base do relatório das 23:50 --- */
        'dia_data'      => date('Y-m-d'),
        'dia_checagens' => 0,   // ciclos executados hoje
        'dia_falhas'    => 0,   // ciclos que terminaram em falha confirmada
        'dia_blips'     => 0,   // falhas isoladas descartadas pelo retry
        'dia_seg_fora'  => 0,   // tempo total fora do ar (segundos)
        'dia_incidentes'=> [],  // [{inicio, fim, seg, motivo}]
        'ultimo_check'  => 0,   // ts do ciclo anterior (para medir tempo fora)
        'relatorio_data'=> '',  // último dia em que o relatório foi enviado
    ];
    if (is_file($arq)) {
        $j = json_decode((string) file_get_contents($arq), true);
        if (is_array($j)) { $prev = array_merge($prev, $j); }
    }
    return $prev;
}

/* ===== HISTÓRICO POR DIA =====
 * Antes de zerar as estatísticas na virada do dia, congela o resumo do dia que
 * terminou em historico/AAAA-MM-DD.json. É o que alimenta o filtro por data do
 * painel — sem isso o dia anterior some para sempre. Idempotente: se o arquivo
 * do dia já existe, sobrescreve com os números finais (o cron pode virar o dia
 * mais de uma vez em cenários de relógio/reprocessamento).
 * Também poda arquivos mais velhos que HISTORICO_DIAS. */
function arquivar_dia(array $prev): string
{
    $data = (string) ($prev['dia_data'] ?? '');
    if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) { return 'sem-data'; }
    // Dia sem nenhuma checagem não vira arquivo (evita lixo se o cron ficou parado).
    if ((int) ($prev['dia_checagens'] ?? 0) <= 0) { return 'dia-vazio'; }

    $dir = INST_DIR . '/historico';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { return 'sem-dir'; }

    // Incidente que atravessou a meia-noite fica fechado às 23:59:59 do dia dele.
    $fim_do_dia = (int) strtotime($data . ' 23:59:59');
    $incidentes = [];
    foreach ((array) ($prev['dia_incidentes'] ?? []) as $i) {
        $ini = (int) ($i['inicio'] ?? 0);
        $fim = (int) ($i['fim'] ?? 0);
        if ($fim === 0) { $fim = $fim_do_dia; }
        $incidentes[] = [
            'inicio' => $ini,
            'fim'    => $fim,
            'seg'    => (int) ($i['seg'] ?? 0) ?: max(0, $fim - $ini),
            'motivo' => (string) ($i['motivo'] ?? ''),
            'aberto_na_virada' => ((int) ($i['fim'] ?? 0) === 0),
            // A evidência (camada, CF-Ray, sonda) vai junto: é o que responde
            // "qual foi a causa raiz?" meses depois, quando o log já rotacionou.
            'evidencia' => (array) ($i['evidencia'] ?? []),
        ];
    }

    $seg_dia  = max(1, min(86400, $fim_do_dia + 1 - (int) strtotime($data . ' 00:00:00')));
    $seg_fora = (int) ($prev['dia_seg_fora'] ?? 0);
    $resumo = [
        'data'       => $data,
        'checagens'  => (int) ($prev['dia_checagens'] ?? 0),
        'falhas'     => (int) ($prev['dia_falhas'] ?? 0),
        'blips'      => (int) ($prev['dia_blips'] ?? 0),
        'seg_fora'   => $seg_fora,
        'seg_dia'    => $seg_dia,
        'disp'       => round(max(0, 100 - ($seg_fora / $seg_dia * 100)), 4),
        'avisos'     => (int) ($prev['avisos_dia'] ?? 0),
        'incidentes' => $incidentes,
        'status_fim' => (string) ($prev['status'] ?? 'ok'),
        'gerado_em'  => date('Y-m-d H:i:s'),
        'versao'     => 1,
    ];

    $arq = $dir . '/' . $data . '.json';
    $tmp = $arq . '.tmp';
    file_put_contents($tmp, json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @rename($tmp, $arq);

    // Poda: mantém no máximo HISTORICO_DIAS arquivos.
    $todos = glob($dir . '/*.json') ?: [];
    sort($todos);
    if (count($todos) > HISTORICO_DIAS) {
        foreach (array_slice($todos, 0, count($todos) - HISTORICO_DIAS) as $velho) { @unlink($velho); }
    }
    return 'ok(' . $data . ')';
}

function gravar_log(string $arq, string $linha): void {
    file_put_contents($arq, $linha, FILE_APPEND);
    echo $linha;
    // Rotação simples: mantém só as últimas 2000 linhas.
    clearstatcache(true, $arq);
    if (filesize($arq) > 400000) {
        $linhas = file($arq, FILE_IGNORE_NEW_LINES);
        file_put_contents($arq, implode("\n", array_slice($linhas, -2000)) . "\n");
    }
}

/* ===== LOOP PRINCIPAL ===== */
const JANELA_SEG = 55; // encerra antes do próximo cron, para não pular execução
$inicio = time();

// Sem credencial o teste de login é pulado (não vira falso alarme), mas isso
// precisa ficar visível: o monitor estaria cego para "login quebrado".
if (LOGIN_USER === '' || LOGIN_PASS === '') {
    gravar_log($LOG, '[' . date('Y-m-d H:i:s') . '] CONFIG :: sem credencial em '
        . CRED_ARQ . ' — teste de login DESATIVADO' . "\n");
}

for ($ciclo = 0; $ciclo < CICLOS; $ciclo++) {
    if ($ciclo > 0) { sleep(INTERVALO_SEG); }
    // Com retries, a execução pode se alongar; sair no tempo evita que o
    // flock -n do cron pule o minuto seguinte e abra uma lacuna na cobertura.
    $ultimo_ciclo = ($ciclo === CICLOS - 1) || (time() - $inicio) >= JANELA_SEG;

    $prev = ler_estado($ESTADO);
    // Login entra na conta quando venceu a janela (ou quando o site está fora,
    // para o aviso já dizer se o problema é o login).
    $com_login = (time() - (int) $prev['ultimo_login']) >= LOGIN_CADA_MIN * 60;

    $falhas = checar($com_login);

    // Confirmação dupla, na mesma execução: repete depois de RETRY_SEG.
    // Na repetição o login sempre entra, para o detalhe do aviso ser completo.
    $blip = false;
    if ($falhas) {
        $primeira = implode(' | ', $falhas);
        sleep(RETRY_SEG);
        $falhas = checar(true);
        $com_login = true;
        if (!$falhas) { $blip = true; }
    }

    /* Falha confirmada: bate DIRETO na origem, furando a Cloudflare, para saber
     * de que lado quebrou. Uma requisição extra por incidente — não por ciclo. */
    if ($falhas && $EVIDENCIA) {
        $sonda = sondar_origem();
        $EVIDENCIA['origem_ok']      = $sonda['ok'];
        $EVIDENCIA['origem_texto']   = $sonda['texto'];
        // A sonda tem a palavra final: ela testou os dois caminhos.
        if ($sonda['veredito'] !== 'indeterminado' && ($EVIDENCIA['camada'] ?? '') !== 'tunel') {
            $EVIDENCIA['camada'] = $sonda['veredito'];
        }
    }

    $agora   = date('Y-m-d H:i:s');
    $status  = $falhas ? 'down' : 'ok';
    $detalhe = $falhas ? implode(' | ', $falhas) : 'tudo ok';

    $ts      = time();
    $mudou   = ($status !== $prev['status']);
    $avisado = (string) $prev['avisado'];

    /* Transições da última hora (base do detector de instabilidade).
     * Conta só eventos DIGNOS DE ALERTA (queda que passou da carência e sua
     * recuperação) — do contrário uma sequência de quedas curtas, que por regra
     * nem gera mensagem, silenciaria os alertas reais por horas. */
    $transicoes = array_values(array_filter(
        array_map('intval', (array) $prev['transicoes']),
        static fn (int $t): bool => $t > $ts - 3600
    ));
    $mudou_em = $mudou ? $ts : (int) $prev['mudou_em'];

    // Cap diário (zera na virada do dia).
    $avisos_dia = ((string) $prev['avisos_data'] === date('Y-m-d')) ? (int) $prev['avisos_dia'] : 0;

    /* ===== ESTATÍSTICAS DO DIA =====
     * Zeram na virada do dia (o relatório sai antes, às RELATORIO_HORA:MIN). */
    $hoje = date('Y-m-d');
    $novo_dia = ((string) $prev['dia_data'] !== $hoje);
    // Virou o dia: congela o dia que terminou ANTES de zerar os contadores.
    if ($novo_dia) {
        $arq_res = arquivar_dia($prev);
        gravar_log($LOG, "[{$agora}] HISTORICO :: dia arquivado :: ({$arq_res})\n");
    }
    $d_checagens = $novo_dia ? 0 : (int) $prev['dia_checagens'];
    $d_falhas    = $novo_dia ? 0 : (int) $prev['dia_falhas'];
    $d_blips     = $novo_dia ? 0 : (int) $prev['dia_blips'];
    $d_seg_fora  = $novo_dia ? 0 : (int) $prev['dia_seg_fora'];
    $d_incidentes = $novo_dia ? [] : (array) $prev['dia_incidentes'];

    $d_checagens++;
    if ($falhas) { $d_falhas++; }
    if ($blip)   { $d_blips++; }
    // Tempo fora = intervalo desde o ciclo anterior, quando ele já estava fora.
    $ultimo_check = (int) $prev['ultimo_check'];
    if ($prev['status'] === 'down' && $ultimo_check > 0 && !$novo_dia) {
        $d_seg_fora += max(0, min($ts - $ultimo_check, 300));
    }
    // Abre incidente na queda; fecha na volta (guarda os 20 mais recentes).
    if ($mudou && $status === 'down') {
        $d_incidentes[] = ['inicio' => $ts, 'fim' => 0, 'seg' => 0, 'motivo' => $detalhe,
                           'evidencia' => $EVIDENCIA];
        $d_incidentes = array_slice($d_incidentes, -20);
        // Dossiê: prontuário do incidente (lib_dossie.php), lido por dossie.php.
        dossie_abrir($ts, $detalhe, $EVIDENCIA);
    } elseif ($status === 'down' && (int) $prev['inc_em'] > 0) {
        dossie_atualizar((int) $prev['inc_em'], $detalhe, $EVIDENCIA, $ts);
    } elseif ($mudou && $status === 'ok' && $d_incidentes) {
        $i = count($d_incidentes) - 1;
        if ((int) $d_incidentes[$i]['fim'] === 0) {
            $d_incidentes[$i]['fim'] = $ts;
            $d_incidentes[$i]['seg'] = max(0, $ts - (int) $d_incidentes[$i]['inicio']);
        }
        if ((int) $prev['inc_em'] > 0) { dossie_fechar((int) $prev['inc_em'], $ts, max(0, $ts - (int) $prev['inc_em'])); }
    }

    /* ===== DECIDIR AVISO =====
     * Queda só vira Direct após CARENCIA_SEG (5 min) FORA ininterruptos — quedas
     * curtas e reinícios de serviço não incomodam ninguém, ficam só no log.
     * "Voltou" só depois de ESTAVEL_SEG de OK contínuo (evita ping-pong). */
    $aviso = null;
    $nivel = '';          // nível do aviso, usado também na notificação push
    $flap_ate = (int) $prev['flap_ate'];
    $flap_pretendido = 0;
    $transicoes_base = $transicoes; // para desfazer se o aviso for barrado
    $estavel  = ($ts - $mudou_em) >= ESTAVEL_SEG;

    // Instante em que o incidente atual começou (para ID e duração).
    $inc_em = ($mudou && $status === 'down') ? $ts : (int) $prev['inc_em'];
    $hora   = date('d/m H:i:s', $ts);
    $fora_ha  = ($status === 'down' && $inc_em > 0) ? $ts - $inc_em : 0;
    $carencia = ($status === 'down' && $fora_ha < CARENCIA_SEG);

    if (count($transicoes) >= FLAP_MAX && $ts >= $flap_ate) {
        // Instabilidade: um único aviso e silêncio por FLAP_SILENCIO_MIN.
        $aviso = msg_alerta('atencao', [
            'Detectado' => $hora,
            'Padrão'    => 'caiu e voltou ' . count($transicoes) . 'x na última hora',
            'Estado'    => ($status === 'down' ? 'fora do ar agora' : 'no ar agora'),
            'Último motivo' => $detalhe,
        ], 'Alertas silenciados por ' . FLAP_SILENCIO_MIN . ' min · verifique logs do Traccar');
        // O silêncio só é imposto se este aviso REALMENTE for entregue (ver
        // portão abaixo): silenciar sem explicar deixaria você no escuro.
        $flap_pretendido = $ts + FLAP_SILENCIO_MIN * 60;
        $nivel = 'atencao';
        $avisado  = $status;
    } elseif ($ts < $flap_ate) {
        $aviso = null; // em janela de silêncio por instabilidade
    } elseif ($carencia) {
        $aviso = null; // fora do ar, mas ainda dentro da carência de 5 min
    } elseif ($status === 'down' && $avisado === 'ok') {
        $campos_critico = [
            'Incidente' => inc_id($inc_em),
            'Fora desde'=> date('d/m H:i:s', $inc_em) . ' (' . dur($fora_ha) . ')',
            'Falha'     => $detalhe,
        ];
        // Onde quebrou: sai da sonda, não de suposição. Muda a ação recomendada.
        if (!empty($EVIDENCIA['camada'])) {
            $campos_critico['Onde'] = camada_rotulo((string) $EVIDENCIA['camada'])
                . (!empty($EVIDENCIA['origem_texto']) ? ' · ' . $EVIDENCIA['origem_texto'] : '');
        }
        if (!empty($EVIDENCIA['cf_ray'])) { $campos_critico['CF-Ray'] = $EVIDENCIA['cf_ray']; }
        $campos_critico['Impacto'] = 'clientes sem acesso ao rastreamento';
        $aviso = msg_alerta('critico', $campos_critico, acao_sugerida($EVIDENCIA));
        $avisado = 'down';
        $transicoes[] = $ts;
        $nivel = 'critico';
    } elseif ($status === 'ok' && $avisado === 'down' && $estavel) {
        $aviso = msg_alerta('resolvido', [
            'Incidente'  => inc_id($inc_em),
            'Normalizado'=> $hora,
            'Duração'    => dur(max(0, $mudou_em - $inc_em)),
            'Verificado' => 'página, API e login OK por ' . dur(ESTAVEL_SEG),
        ]);
        $avisado = 'ok';
        $transicoes[] = $ts;
        $nivel = 'resolvido';
    } elseif ($status === 'down' && $avisado === 'down'
           && ($ts - (int) $prev['ultimo_aviso']) >= REAVISO_MIN * 60) {
        $aviso = msg_alerta('ativo', [
            'Incidente' => inc_id($inc_em),
            'Fora desde'=> date('d/m H:i:s', $inc_em) . ' (' . dur($ts - $inc_em) . ')',
            'Falha'     => $detalhe,
        ], 'Ainda sem resposta · intervenção manual necessária');
        $nivel = 'ativo';
    }

    /* ===== PORTÃO ANTI-SPAM =====
     * Dois freios sobre QUALQUER aviso: cooldown mínimo entre mensagens e teto
     * diário. Se o aviso for barrado, o estado "avisado" NÃO avança — assim o
     * alerta sai quando o cooldown vencer, em vez de ser perdido de vez. */
    $aviso_resultado = '';
    $aviso_enviado   = false;
    $espera = COOLDOWN_MIN * 60 - ($ts - (int) $prev['ultimo_aviso']);
    if ($aviso !== null && $espera > 0) {
        $aviso_resultado = 'cooldown(faltam ' . dur($espera) . ')';
        $avisado         = (string) $prev['avisado'];
        $transicoes      = $transicoes_base;
    } elseif ($aviso !== null && $avisos_dia >= MAX_AVISOS_DIA) {
        $aviso_resultado = 'cap-diario(' . $avisos_dia . '/' . MAX_AVISOS_DIA . ')';
        $avisado         = (string) $prev['avisado'];
        $transicoes      = $transicoes_base;
    } elseif ($aviso !== null) {
        $aviso_resultado = enviar_direct($aviso);
        // Push segue exatamente o mesmo portão do Direct (carência, cooldown, cap),
        // para o celular não receber nada que o Direct também não receberia.
        list($p_tit, $p_corpo, $p_tag, $p_crit) = push_do_alerta($nivel, $detalhe,
            $nivel === 'resolvido' ? 'Site e login normalizados às ' . date('H:i') : '');
        $aviso_resultado .= ' ' . enviar_push($p_tit, $p_corpo, $p_tag, $p_crit);
        $aviso_enviado   = true;
        $avisos_dia++;
        if ($inc_em > 0) { dossie_aviso($inc_em, $nivel, $aviso_resultado, $ts); }
        // Silêncio por instabilidade só passa a valer com o aviso 🟡 entregue.
        if ($flap_pretendido > 0) { $flap_ate = $flap_pretendido; }
    }

    /* ===== RELATÓRIO DE FIM DE DIA =====
     * Uma única mensagem por dia, às RELATORIO_HORA:RELATORIO_MIN (antes da
     * virada, quando as estatísticas ainda são de hoje). Não passa pelo cooldown
     * nem pelo cap: é 1 Direct/dia e é o resumo que fecha o período. */
    $relatorio_data = (string) $prev['relatorio_data'];
    $min_do_dia = (int) date('H', $ts) * 60 + (int) date('i', $ts);
    if ($relatorio_data !== $hoje && $min_do_dia >= RELATORIO_HORA * 60 + RELATORIO_MIN) {
        $rel = msg_relatorio([
            'seg_fora'   => $d_seg_fora,
            'incidentes' => $d_incidentes,
            'checagens'  => $d_checagens,
            'falhas'     => $d_falhas,
            'blips'      => $d_blips,
        ], $status, $avisos_dia, $ts);
        $r_res = enviar_direct($rel);
        $r_res .= ' ' . enviar_push('📊 Sentinela · relatório do dia',
            'Disponibilidade e incidentes de ' . date('d/m') . ' — toque para abrir o painel.',
            'sentinela-relatorio');
        $relatorio_data = $hoje;
        gravar_log($LOG, "[{$agora}] RELATORIO :: disponibilidade do dia enviada :: ({$r_res})\n");
    }

    /* ===== SALVAR ESTADO ===== */
    file_put_contents($ESTADO, json_encode([
        'status'       => $status,
        'avisado'      => $avisado,
        'desde'        => $mudou ? $agora : $prev['desde'],
        'mudou_em'     => $mudou_em,
        'ultimo_aviso' => $aviso_enviado ? $ts : (int) $prev['ultimo_aviso'],
        'ultimo_login' => $com_login ? $ts : (int) $prev['ultimo_login'],
        'inc_em'       => $inc_em,
        'transicoes'   => $transicoes,
        'flap_ate'     => $flap_ate,
        'avisos_dia'   => $avisos_dia,
        'avisos_data'  => date('Y-m-d'),
        'dia_data'      => $hoje,
        'dia_checagens' => $d_checagens,
        'dia_falhas'    => $d_falhas,
        'dia_blips'     => $d_blips,
        'dia_seg_fora'  => $d_seg_fora,
        'dia_incidentes'=> $d_incidentes,
        'ultimo_check'  => $ts,
        'relatorio_data'=> $relatorio_data,
        'checado_em'   => $agora,
        'detalhe'      => $detalhe,
        'evidencia'    => $EVIDENCIA,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    /* ===== LOG =====
     * Escreve sempre que mudar de estado, quando houver blip ou aviso, e uma
     * linha de resumo no último ciclo. Ciclos OK no meio não poluem o log. */
    if ($blip) {
        gravar_log($LOG, "[{$agora}] SUSPEITA :: falha isolada, não confirmada: {$primeira}\n");
    }
    if ($mudou || $aviso !== null || $status === 'down' || $ultimo_ciclo) {
        gravar_log($LOG, "[{$agora}] " . strtoupper($status) . " :: {$detalhe}"
            . (!empty($EVIDENCIA['camada']) ? " :: CAMADA(" . $EVIDENCIA['camada']
                . (!empty($EVIDENCIA['cf_ray']) ? ' ray=' . $EVIDENCIA['cf_ray'] : '') . ")" : '')
            . ($aviso !== null ? " :: AVISO({$aviso_resultado})" : '') . "\n");
    }

    if ($ultimo_ciclo) { break; }
}

curl_close($CH);
flock($fh, LOCK_UN);
fclose($fh);

/* ===== WEB PUSH (navegador/celular) =====
 * Usa a lib de Web Push nativo do sistema de agendamentos (VAPID + aes128gcm) e as
 * inscrições gravadas por index.php em push_subs.json. Best-effort: nunca deixa uma
 * falha de push atrapalhar o alerta por Direct. Inscrição morta (404/410) é removida.
 * $tag agrupa por tipo: um alerta novo substitui o anterior na bandeja. */
function enviar_push(string $titulo, string $corpo, string $tag, bool $critico = false): string
{
    $arq = INST_DIR . '/push_subs.json';
    if (!is_readable($arq)) { return 'sem-inscritos'; }
    $subs = json_decode((string) file_get_contents($arq), true);
    if (!is_array($subs) || !$subs) { return 'sem-inscritos'; }

    try {
        require_once AGENDA_DIR . '/config.php';
        require_once AGENDA_DIR . '/init.php';
        require_once AGENDA_DIR . '/lib_push.php';
    } catch (\Throwable $e) {
        return 'push-exc:' . $e->getMessage();
    }

    $payload = json_encode([
        'titulo'  => $titulo,
        'corpo'   => $corpo,
        'tag'     => $tag,
        'critico' => $critico,
        'url'     => PAINEL_URL,
    ], JSON_UNESCAPED_UNICODE);

    $ok = 0; $err = 0; $mudou = false;
    foreach ($subs as $k => $s) {
        try {
            $r = push_enviar((string) $s['endpoint'], (string) $s['p256dh'], (string) $s['auth'], $payload);
            if (!empty($r['ok'])) {
                $ok++;
                continue;
            }
            $err++;
            // 404/410 = inscrição expirada ou revogada: limpa do arquivo.
            if (in_array((int) ($r['status'] ?? 0), [404, 410], true)) {
                unset($subs[$k]);
                $mudou = true;
            }
        } catch (\Throwable $e) {
            $err++;
        }
    }
    if ($mudou) {
        file_put_contents($arq, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return "push:{$ok}ok" . ($err ? "/{$err}erro" : '');
}

/* Título e corpo curtos para a bandeja, derivados do alerta longo do Direct. */
function push_do_alerta(string $nivel, string $detalhe, string $extra = ''): array
{
    $mapa = [
        'critico'  => ['🔴 ' . ALVO_NOME . ' FORA DO AR', 'queda', true],
        'ativo'    => ['🔴 Ainda fora do ar', 'queda', true],
        'resolvido'=> ['🟢 ' . ALVO_NOME . ' voltou', 'queda', false],
        'atencao'  => ['🟡 Serviço instável', 'instavel', false],
        'relatorio'=> ['📊 Relatório do dia', 'relatorio', false],
    ];
    [$titulo, $tag, $critico] = $mapa[$nivel];
    $corpo = $extra !== '' ? $extra : $detalhe;
    return [$titulo, mb_strimwidth($corpo, 0, 160, '…'), 'sentinela-' . $tag, $critico];
}

/* ===== DIRECT via sistema de agendamentos ===== */
function enviar_direct(string $texto, ?array $destinos = null): string {
    static $db = null;
    $destinos = $destinos ?? DM_DESTINOS;
    try {
        if ($db === null) {
            require_once AGENDA_DIR . '/config.php';
            require_once AGENDA_DIR . '/init.php';
            require_once AGENDA_DIR . '/lib_direct.php';
            $db = db();
        }

        $convStmt = $db->prepare(
            "SELECT cliente_id, remetente_id, TIMESTAMPDIFF(HOUR, ultima_recebida_em, NOW()) AS horas
             FROM dm_conversas WHERE nome = ? ORDER BY ultima_em DESC LIMIT 1");
        $cliStmt  = $db->prepare('SELECT id, nome, ig_user_id, access_token FROM clientes WHERE id = ?');

        $res = [];
        foreach ($destinos as $nome) {
            $convStmt->execute([$nome]);
            $conv = $convStmt->fetch();
            if (!$conv) { $res[] = "{$nome}:sem-conversa"; continue; }
            if ((int) $conv['horas'] >= 24) { $res[] = "{$nome}:fora-24h"; continue; }

            $cliStmt->execute([(int) $conv['cliente_id']]);
            $cliente = $cliStmt->fetch();
            if (!$cliente) { $res[] = "{$nome}:sem-remetente"; continue; }

            $r = dm_enviar($db, $cliente, $conv['remetente_id'], $texto, 'humano');
            $res[] = "{$nome}:" . (!empty($r['ok']) ? 'ok' : ('erro-' . ($r['erro'] ?? '?')));
        }
        return implode(',', $res);
    } catch (\Throwable $e) {
        return 'exc:' . $e->getMessage();
    }
}
