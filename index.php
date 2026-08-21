<?php
declare(strict_types=1);

/**
 * Painel de status do monitor (Sentinela) — https://publishdev.com.br/monitoramentotop/
 * -----------------------------------------------------------------------------------
 * Lê estado.json (dia de hoje, ao vivo) e historico/AAAA-MM-DD.json (dias fechados
 * pelo monitor.php) pelo FILESYSTEM. Tudo isso continua bloqueado na web pelo
 * .htaccess — só este index.php é servido, e ele nunca expõe credenciais,
 * caminhos internos ou o log cru.
 *
 * v1.3: filtro por data, linha do tempo de 24h, comparativo de período,
 * exportação CSV/JSON e busca nos incidentes.
 * v1.5: navegação do filtro por AJAX, com barra de progresso, dimming do
 * conteúdo e transição — sem recarregar a página.
 * v1.4: causa raiz por incidente — camada (origem x Cloudflare), CF-Ray e o
 * resultado da sonda direta na origem, gravados pelo monitor.php.
 *
 * Padrão Designi Alequizao: flat, cards brancos arredondados, fundo cinza claro,
 * destaque azul, Inter. O dia de HOJE atualiza sozinho via fetch+JSON (polling 5s,
 * pausando com a aba oculta); dias passados são estáticos, não fazem polling.
 *
 * ATENÇÃO: o vhost desta pasta roda PHP 7.4 — nada de match(), ?->, enums ou
 * str_contains() aqui.
 */

date_default_timezone_set('America/Maceio');

const VERSAO     = '1.5.0';
const ESTADO_ARQ = __DIR__ . '/estado.json';
const HIST_DIR   = __DIR__ . '/historico';
const SUBS_ARQ   = __DIR__ . '/push_subs.json';   // inscrições Web Push (bloqueado na web)
const AGENDA_DIR = '/www/wwwroot/publishdev.com.br/agendamentos';
const META_DISP  = 99.9;                          // meta de disponibilidade (SLA interno)

/* ===== WEB PUSH =====
 * Reaproveita a lib de Web Push nativo do sistema de agendamentos (VAPID +
 * aes128gcm, sem dependência externa) e as chaves VAPID já existentes lá.
 * As inscrições ficam num JSON local, que o .htaccess não serve. */
function push_lib(): void
{
    static $ok = false;
    if ($ok) { return; }
    require_once AGENDA_DIR . '/config.php';
    require_once AGENDA_DIR . '/init.php';
    require_once AGENDA_DIR . '/lib_push.php';
    $ok = true;
}

function subs_ler(): array
{
    if (!is_readable(SUBS_ARQ)) { return []; }
    $j = json_decode((string) file_get_contents(SUBS_ARQ), true);
    return is_array($j) ? $j : [];
}

function subs_gravar(array $subs): void
{
    file_put_contents(SUBS_ARQ, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/* Endpoints de push: ?push=vapid | sub | teste | remover */
if (isset($_GET['push'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $acao = (string) $_GET['push'];
    try {
        push_lib();

        if ($acao === 'vapid') {
            echo json_encode(['ok' => true, 'chave' => push_vapid_public()]);
            exit;
        }

        $corpo = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $end   = (string) ($corpo['endpoint'] ?? '');
        if ($end === '') {
            echo json_encode(['ok' => false, 'msg' => 'Inscrição inválida.']);
            exit;
        }

        $subs = subs_ler();
        if ($acao === 'remover') {
            unset($subs[md5($end)]);
            subs_gravar($subs);
            echo json_encode(['ok' => true, 'msg' => 'Notificações desativadas.']);
            exit;
        }

        if ($acao === 'sub') {
            $subs[md5($end)] = [
                'endpoint' => $end,
                'p256dh'   => (string) ($corpo['keys']['p256dh'] ?? ''),
                'auth'     => (string) ($corpo['keys']['auth'] ?? ''),
                'criado'   => date('Y-m-d H:i:s'),
                'agente'   => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            ];
            subs_gravar($subs);
            echo json_encode(['ok' => true, 'msg' => 'Notificações ativadas neste aparelho.']);
            exit;
        }

        if ($acao === 'teste') {
            $s = $subs[md5($end)] ?? null;
            if (!$s) {
                echo json_encode(['ok' => false, 'msg' => 'Este aparelho não está inscrito.']);
                exit;
            }
            $r = push_enviar($s['endpoint'], $s['p256dh'], $s['auth'], json_encode([
                'titulo' => '🛡️ Sentinela · teste',
                'corpo'  => 'Notificações funcionando. Alertas de queda chegam por aqui.',
                'tag'    => 'sentinela-teste',
                'url'    => '/monitoramentotop/',
            ], JSON_UNESCAPED_UNICODE));
            echo json_encode(['ok' => !empty($r['ok']),
                'msg' => !empty($r['ok']) ? 'Notificação de teste enviada.'
                                          : ('Falhou: ' . ($r['erro'] ?? 'erro'))]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

/* ===== UTILIDADES ===== */

/* Nunca vazar usuário do Traccar em mensagem de falha (página é pública). */
function mascarar(string $txt): string
{
    return (string) preg_replace("/para '[^']*'/", "para '•••'", $txt);
}

function dur(int $seg): string
{
    if ($seg < 60)   { return $seg . 's'; }
    if ($seg < 3600) { return intdiv($seg, 60) . 'min'; }
    if ($seg < 86400) {
        return intdiv($seg, 3600) . 'h' . str_pad((string) intdiv($seg % 3600, 60), 2, '0', STR_PAD_LEFT);
    }
    return intdiv($seg, 86400) . 'd ' . intdiv($seg % 86400, 3600) . 'h';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function num(float $v, int $casas = 0): string
{
    return number_format($v, $casas, ',', '.');
}

/* Mesmo ID de incidente usado nos alertas do Direct (INC-<ts em base36>). */
function inc_id(int $ts): string
{
    return 'INC-' . strtoupper(base_convert((string) $ts, 10, 36));
}

function data_valida(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function data_br(string $d): string
{
    $ts = strtotime($d);
    if (!$ts) { return $d; }
    $dias = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
    $hoje = date('Y-m-d');
    $rot  = date('d/m/Y', $ts) . ' · ' . $dias[(int) date('w', $ts)];
    if ($d === $hoje) { return 'Hoje, ' . $rot; }
    if ($d === date('Y-m-d', strtotime('-1 day'))) { return 'Ontem, ' . $rot; }
    return ucfirst($rot);
}

/* ===== FONTES DE DADOS ===== */
function estado(): array
{
    $j = is_readable(ESTADO_ARQ)
        ? json_decode((string) file_get_contents(ESTADO_ARQ), true)
        : null;
    return is_array($j) ? $j : [];
}

/* Datas com arquivo em historico/ (mais antiga -> mais recente). */
function dias_arquivados(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = [];
    foreach (glob(HIST_DIR . '/*.json') ?: [] as $f) {
        $d = basename($f, '.json');
        if (data_valida($d)) { $out[] = $d; }
    }
    sort($out);
    $cache = $out;
    return $out;
}

/* Resumo normalizado de UM dia. Hoje vem do estado.json (ao vivo); dias
 * anteriores, do arquivo congelado. Formato único para todo o render. */
function dia(string $data): array
{
    $hoje  = date('Y-m-d');
    $vazio = [
        'data' => $data, 'hoje' => ($data === $hoje), 'existe' => false, 'estimado' => false,
        'checagens' => 0, 'falhas' => 0, 'blips' => 0, 'avisos' => 0,
        'seg_fora' => 0, 'seg_dia' => 86400, 'disp' => 100.0,
        'incidentes' => [], 'status' => 'desconhecido', 'detalhe' => '—', 'evidencia' => [],
        'desde' => 0, 'ha' => 0, 'atraso' => 0, 'checado' => 0, 'parado' => false,
    ];

    if ($data === $hoje) {
        $e  = estado();
        if (!$e) { return $vazio; }
        $ts      = time();
        $status  = (($e['status'] ?? 'ok') === 'down') ? 'down' : 'ok';
        $checado = strtotime((string) ($e['checado_em'] ?? '')) ?: 0;
        // Se o cron parou, o painel não pode mentir "no ar": marca desconhecido.
        $parado  = $checado > 0 && ($ts - $checado) > 180;
        $seg_dia = max(1, $ts - (int) strtotime(date('Y-m-d 00:00:00', $ts)));
        $fora    = (int) ($e['dia_seg_fora'] ?? 0);
        $desde   = strtotime((string) ($e['desde'] ?? '')) ?: 0;

        return array_merge($vazio, [
            'existe'     => true,
            'checagens'  => (int) ($e['dia_checagens'] ?? 0),
            'falhas'     => (int) ($e['dia_falhas'] ?? 0),
            'blips'      => (int) ($e['dia_blips'] ?? 0),
            'avisos'     => (int) ($e['avisos_dia'] ?? 0),
            'seg_fora'   => $fora,
            'seg_dia'    => $seg_dia,
            'disp'       => max(0, 100 - ($fora / $seg_dia * 100)),
            'incidentes' => (array) ($e['dia_incidentes'] ?? []),
            'status'     => $parado ? 'desconhecido' : $status,
            'parado'     => $parado,
            'checado'    => $checado,
            'atraso'     => $checado > 0 ? $ts - $checado : 0,
            'desde'      => $desde,
            'ha'         => $desde > 0 ? $ts - $desde : 0,
            'detalhe'    => mascarar((string) ($e['detalhe'] ?? '—')),
            'evidencia'  => (array) ($e['evidencia'] ?? []),
        ]);
    }

    $arq = HIST_DIR . '/' . $data . '.json';
    if (!is_readable($arq)) { return $vazio; }
    $j = json_decode((string) file_get_contents($arq), true);
    if (!is_array($j)) { return $vazio; }

    $seg_dia  = max(1, (int) ($j['seg_dia'] ?? 86400));
    $seg_fora = (int) ($j['seg_fora'] ?? 0);
    return array_merge($vazio, [
        'existe'     => true,
        'estimado'   => !empty($j['estimado']),
        'checagens'  => (int) ($j['checagens'] ?? 0),
        'falhas'     => (int) ($j['falhas'] ?? 0),
        'blips'      => (int) ($j['blips'] ?? 0),
        'avisos'     => (int) ($j['avisos'] ?? 0),
        'seg_fora'   => $seg_fora,
        'seg_dia'    => $seg_dia,
        'disp'       => isset($j['disp']) ? (float) $j['disp'] : max(0, 100 - ($seg_fora / $seg_dia * 100)),
        'incidentes' => (array) ($j['incidentes'] ?? []),
        'status'     => $seg_fora > 0 ? 'fechado-com-queda' : 'fechado-ok',
        'detalhe'    => $seg_fora > 0
            ? (count((array) ($j['incidentes'] ?? [])) . ' incidente(s) · ' . dur($seg_fora) . ' fora do ar')
            : 'Dia inteiro no ar, sem incidentes',
    ]);
}

/* Série de dias (mais antigo -> mais novo) para o comparativo de período. */
function serie(int $qtd): array
{
    $out = [];
    for ($i = $qtd - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime('-' . $i . ' day'));
        $x = dia($d);
        if (!$x['existe']) { continue; }
        $out[] = $x;
    }
    return $out;
}

/* Downtime por hora (0..23), em segundos — base da linha do tempo de 24h. */
function por_hora(array $d): array
{
    $horas = array_fill(0, 24, 0);
    $ini_dia = (int) strtotime($d['data'] . ' 00:00:00');
    foreach ($d['incidentes'] as $i) {
        $a = (int) ($i['inicio'] ?? 0);
        $b = (int) ($i['fim'] ?? 0);
        if ($a <= 0) { continue; }
        if ($b <= 0) { $b = $d['hoje'] ? time() : $ini_dia + 86400; }  // incidente aberto
        for ($hx = 0; $hx < 24; $hx++) {
            $h0 = $ini_dia + $hx * 3600;
            $h1 = $h0 + 3600;
            $sobre = min($b, $h1) - max($a, $h0);
            if ($sobre > 0) { $horas[$hx] += min(3600, $sobre); }
        }
    }
    return $horas;
}

/* ===== SELEÇÃO DE DATA / PERÍODO (query string) ===== */
$hoje_str = date('Y-m-d');
$sel = (string) ($_GET['d'] ?? $hoje_str);
if (!data_valida($sel) || $sel > $hoje_str) { $sel = $hoje_str; }

$periodo = (int) ($_GET['p'] ?? 30);
if (!in_array($periodo, [7, 30, 90], true)) { $periodo = 30; }

$busca = trim((string) ($_GET['q'] ?? ''));

$D = dia($sel);

/* Vizinhos para as setas ← → : só dias que realmente têm dado. */
function vizinhos(string $sel): array
{
    $hoje_str = date('Y-m-d');
    $disp = dias_arquivados();
    if (!in_array($hoje_str, $disp, true)) { $disp[] = $hoje_str; }
    sort($disp);
    $pos = array_search($sel, $disp, true);
    return [
        ($pos !== false && $pos > 0) ? $disp[$pos - 1] : '',
        ($pos !== false && $pos < count($disp) - 1) ? $disp[$pos + 1] : '',
        $disp ? $disp[0] : $hoje_str,
    ];
}
list($anterior, $proximo, $primeiro) = vizinhos($sel);

/* Incidentes já filtrados pela busca e do mais recente para o mais antigo. */
function incidentes_visiveis(array $d, string $busca): array
{
    $lista = array_reverse($d['incidentes']);
    if ($busca === '') { return $lista; }
    $alvo = mb_strtolower($busca, 'UTF-8');
    $out  = [];
    foreach ($lista as $i) {
        $texto = mb_strtolower(mascarar((string) ($i['motivo'] ?? '')) . ' '
               . date('H:i', (int) ($i['inicio'] ?? 0)) . ' '
               . inc_id((int) ($i['inicio'] ?? 0)), 'UTF-8');
        if (mb_strpos($texto, $alvo) !== false) { $out[] = $i; }
    }
    return $out;
}

/* ===== EXPORTAÇÃO =====
 * ?export=csv|json  &d=<dia>            -> incidentes do dia
 * ?export=csv|json  &escopo=periodo&p=N -> resumo diário do período           */
if (isset($_GET['export'])) {
    $fmt    = ((string) $_GET['export'] === 'json') ? 'json' : 'csv';
    $escopo = ((string) ($_GET['escopo'] ?? 'dia') === 'periodo') ? 'periodo' : 'dia';
    $nome   = 'sentinela-' . ($escopo === 'periodo' ? ('ultimos-' . $periodo . 'dias') : $sel);

    if ($escopo === 'periodo') {
        $linhas = [];
        foreach (serie($periodo) as $x) {
            $linhas[] = [
                'data'            => $x['data'],
                'disponibilidade' => round($x['disp'], 4),
                'seg_fora'        => $x['seg_fora'],
                'incidentes'      => count($x['incidentes']),
                'checagens'       => $x['checagens'],
                'falhas'          => $x['falhas'],
                'isoladas'        => $x['blips'],
                'alertas'         => $x['avisos'],
                'estimado'        => $x['estimado'] ? 1 : 0,
            ];
        }
    } else {
        $linhas = [];
        foreach (incidentes_visiveis($D, $busca) as $i) {
            $ini = (int) ($i['inicio'] ?? 0);
            $fim = (int) ($i['fim'] ?? 0);
            $linhas[] = [
                'incidente' => inc_id($ini),
                'data'      => $sel,
                'inicio'    => $ini ? date('H:i:s', $ini) : '',
                'fim'       => $fim ? date('H:i:s', $fim) : '(em andamento)',
                'segundos'  => (int) ($i['seg'] ?? 0),
                'duracao'   => $fim ? dur((int) ($i['seg'] ?? 0)) : 'em andamento',
                'motivo'    => mascarar((string) ($i['motivo'] ?? '')),
                'camada'    => (string) (($i['evidencia']['camada'] ?? '') ?: ''),
                'http'      => (string) (($i['evidencia']['code'] ?? '') ?: ''),
                'cf_ray'    => (string) (($i['evidencia']['cf_ray'] ?? '') ?: ''),
                'sonda_origem' => (string) (($i['evidencia']['origem_texto'] ?? '') ?: ''),
            ];
        }
    }

    if ($fmt === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '.json"');
        echo json_encode(['gerado_em' => date('c'), 'escopo' => $escopo,
                          'dados' => $linhas], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");                    // BOM: Excel abre com acento certo
    if ($linhas) {
        fputcsv($saida, array_keys($linhas[0]), ';');
        foreach ($linhas as $l) { fputcsv($saida, $l, ';'); }
    } else {
        fputcsv($saida, ['sem dados'], ';');
    }
    fclose($saida);
    exit;
}

/* ===== RENDER (compartilhado entre load inicial e AJAX) ===== */
function render_status(array $m): string
{
    // Nada de match()/PHP 8 aqui: o vhost desta pasta roda PHP 7.4.
    if ($m['status'] === 'ok') {
        list($icone, $rotulo, $classe) = ['fa-circle-check', 'No ar', 'ok'];
    } elseif ($m['status'] === 'down') {
        list($icone, $rotulo, $classe) = ['fa-circle-xmark', 'Fora do ar', 'down'];
    } elseif ($m['status'] === 'fechado-ok') {
        list($icone, $rotulo, $classe) = ['fa-circle-check', 'Dia sem quedas', 'ok'];
    } elseif ($m['status'] === 'fechado-com-queda') {
        list($icone, $rotulo, $classe) = ['fa-triangle-exclamation', 'Dia com incidentes', 'warn'];
    } else {
        list($icone, $rotulo, $classe) = ['fa-circle-question',
            $m['hoje'] ? 'Sem dados do monitor' : 'Sem registro para este dia', 'unknown'];
    }

    if (!$m['hoje']) {
        $sub = $m['existe']
            ? ('Dia fechado · ' . num($m['disp'], 2) . '% de disponibilidade'
               . ($m['estimado'] ? ' · reconstruído do log' : ''))
            : 'Este dia é anterior ao início do monitoramento ou o monitor não rodou.';
    } elseif ($m['status'] === 'desconhecido') {
        $sub = 'Última checagem há ' . dur($m['atraso']) . ' — o monitor pode estar parado';
    } else {
        $sub = $m['desde'] > 0
            ? 'Neste estado há ' . dur($m['ha']) . ' · desde ' . date('d/m H:i', $m['desde'])
            : 'Aguardando primeira checagem';
    }

    $causa = ($m['status'] === 'down') ? render_causa((array) ($m['evidencia'] ?? [])) : '';

    return '<div class="estado ' . $classe . '">'
         . '<div class="estado-ico"><i class="fa-solid ' . $icone . '"></i></div>'
         . '<div class="estado-txt"><h2>' . $rotulo . '</h2><p>' . h($sub) . '</p>'
         . '<span class="detalhe">' . h($m['detalhe']) . '</span>' . $causa
         . '</div></div>';
}

function render_cards(array $m): string
{
    $suf   = $m['hoje'] ? ' hoje' : ' no dia';
    $meta  = $m['disp'] >= META_DISP ? 'meta de ' . num(META_DISP, 1) . '% atingida'
                                     : 'abaixo da meta de ' . num(META_DISP, 1) . '%';
    $cards = [
        ['fa-chart-line',  'Disponibilidade' . $suf, num($m['disp'], 2) . '%',
            $m['existe'] ? $meta : 'sem dados'],
        ['fa-clock',       'Tempo fora' . $suf, $m['seg_fora'] > 0 ? dur($m['seg_fora']) : 'nenhum',
            count($m['incidentes']) . ' incidente(s)'],
        ['fa-heart-pulse', 'Checagens' . $suf, num($m['checagens']),
            $m['falhas'] . ' falhas · ' . $m['blips'] . ' isoladas'
            . ($m['estimado'] ? ' (estimado)' : '')],
        ['fa-paper-plane', 'Alertas enviados', (string) $m['avisos'],
            'no Direct' . ($m['hoje'] ? ', hoje' : '')],
    ];
    $out = '';
    foreach ($cards as $c) {
        list($ico, $label, $valor, $rodape) = $c;
        $out .= '<div class="col-6 col-lg-3"><div class="card-soft stat">'
              . '<div class="ch"><i class="fa-solid ' . $ico . '"></i> ' . h($label) . '</div>'
              . '<div class="num">' . h($valor) . '</div>'
              . ($rodape !== '' ? '<div class="sub">' . h($rodape) . '</div>' : '')
              . '</div></div>';
    }
    return $out;
}

/* Linha do tempo de 24h: uma coluna por hora, cor pela gravidade da hora. */
function render_timeline(array $m): string
{
    if (!$m['existe']) {
        return '<div class="vazio"><i class="fa-solid fa-calendar-xmark" style="color:var(--cor-texto-sec)"></i>'
             . '<p>Sem dados para montar a linha do tempo.</p></div>';
    }
    $horas   = por_hora($m);
    $agora_h = (int) date('G');
    $out = '<div class="tl">';
    for ($i = 0; $i < 24; $i++) {
        $seg = $horas[$i];
        $futuro = $m['hoje'] && $i > $agora_h;
        if ($futuro)        { $cls = 'f'; $tit = 'ainda não aconteceu'; }
        elseif ($seg === 0) { $cls = 'ok'; $tit = 'no ar a hora inteira'; }
        elseif ($seg < 60)  { $cls = 'w1'; $tit = dur($seg) . ' fora'; }
        elseif ($seg < 600) { $cls = 'w2'; $tit = dur($seg) . ' fora'; }
        else                { $cls = 'w3'; $tit = dur($seg) . ' fora'; }
        $out .= '<div class="tl-h ' . $cls . '" title="'
              . h(str_pad((string) $i, 2, '0', STR_PAD_LEFT) . 'h — ' . $tit) . '">'
              . '<span>' . ($i % 3 === 0 ? str_pad((string) $i, 2, '0', STR_PAD_LEFT) : '') . '</span>'
              . '</div>';
    }
    $out .= '</div><div class="tl-leg">'
          . '<span><i class="q ok"></i> no ar</span>'
          . '<span><i class="q w1"></i> &lt; 1min fora</span>'
          . '<span><i class="q w2"></i> &lt; 10min</span>'
          . '<span><i class="q w3"></i> 10min+</span>'
          . ($m['hoje'] ? '<span><i class="q f"></i> a acontecer</span>' : '')
          . '</div>';
    return $out;
}

function render_incidentes(array $m, string $busca = ''): string
{
    $lista = incidentes_visiveis($m, $busca);
    if (!$lista) {
        if ($busca !== '') {
            return '<div class="vazio"><i class="fa-solid fa-magnifying-glass" style="color:var(--cor-texto-sec)"></i>'
                 . '<p>Nenhum incidente combina com “' . h($busca) . '”.</p></div>';
        }
        if (!$m['existe']) {
            return '<div class="vazio"><i class="fa-solid fa-calendar-xmark" style="color:var(--cor-texto-sec)"></i>'
                 . '<p>Sem registro para este dia.</p></div>';
        }
        return '<div class="vazio"><i class="fa-solid fa-shield-heart"></i>'
             . '<p>Nenhum incidente registrado ' . ($m['hoje'] ? 'hoje' : 'neste dia') . '.</p></div>';
    }
    $out = '';
    foreach ($lista as $i) {
        $ini    = (int) ($i['inicio'] ?? 0);
        $fim    = (int) ($i['fim'] ?? 0);
        $aberto = $fim === 0;
        $dura   = $aberto ? 'em andamento' : dur((int) ($i['seg'] ?? 0));
        $faixa  = date('H:i:s', $ini) . ($aberto ? ' → agora' : ' → ' . date('H:i:s', $fim));
        $out .= '<div class="inc' . ($aberto ? ' aberto' : '') . '">'
              . '<div class="inc-ico"><i class="fa-solid '
              . ($aberto ? 'fa-triangle-exclamation' : 'fa-circle-check') . '"></i></div>'
              . '<div class="inc-txt"><strong>' . h($faixa) . '</strong>'
              . '<span class="badge-status ' . ($aberto ? 'b-down' : 'b-ok') . '">' . h($dura) . '</span>'
              . '<span class="inc-id">' . h(inc_id($ini)) . '</span>'
              . (!empty($i['aberto_na_virada']) ? '<span class="inc-id">cruzou a meia-noite</span>' : '')
              . '<p>' . h(mascarar((string) ($i['motivo'] ?? ''))) . '</p>'
              . render_causa((array) ($i['evidencia'] ?? []))
              . '</div></div>';
    }
    return $out;
}

/* Rótulo do dia selecionado e setas de navegação — recalculados também no
 * AJAX, senão a barra de filtro ficaria falando do dia anterior. */
function render_rotulo(string $sel, array $D): string
{
    return '<i class="fa-regular fa-calendar" style="color:var(--cor-primaria)"></i> '
         . h(data_br($sel))
         . ($D['estimado']
             ? '<span class="tag" title="Reconstruído a partir do log do monitor">estimado</span>' : '');
}

function render_nav(string $sel, int $periodo): string
{
    list($ant, $prox) = vizinhos($sel);
    $hoje_str = date('Y-m-d');
    $seta = function ($data, $titulo, $icone) use ($periodo) {
        return $data !== ''
            ? '<a href="?d=' . $data . '&p=' . $periodo . '" title="' . $titulo . '">'
              . '<i class="fa-solid ' . $icone . '"></i></a>'
            : '<span><i class="fa-solid ' . $icone . '"></i></span>';
    };
    return $seta($ant, 'Dia anterior', 'fa-chevron-left')
         . '<a href="?d=' . $hoje_str . '&p=' . $periodo . '" title="Hoje">Hoje</a>'
         . $seta($prox, 'Próximo dia', 'fa-chevron-right');
}

/* Causa raiz do incidente: o que a evidência gravada pelo monitor permite
 * afirmar. Incidentes anteriores à v1.4 não têm evidência — e aí a linha
 * simplesmente não aparece, em vez de inventar um diagnóstico. */
function render_causa(array $ev): string
{
    if (!$ev) { return ''; }
    $camada = (string) ($ev['camada'] ?? '');
    if ($camada === 'origem') {
        list($cls, $rot) = ['c-origem', 'servidor de origem'];
    } elseif ($camada === 'borda') {
        list($cls, $rot) = ['c-borda', 'Cloudflare ↔ origem'];
    } elseif ($camada === 'rede') {
        list($cls, $rot) = ['c-rede', 'rede / sem resposta'];
    } else {
        list($cls, $rot) = ['c-ind', 'camada indeterminada'];
    }

    $partes = [];
    if (!empty($ev['explica']))      { $partes[] = (string) $ev['explica']; }
    if (!empty($ev['origem_texto'])) { $partes[] = 'Sonda: ' . $ev['origem_texto']; }
    if (!empty($ev['etapa']))        { $partes[] = 'etapa: ' . $ev['etapa']; }
    if (!empty($ev['ms']))           { $partes[] = $ev['ms'] . 'ms'; }

    return '<div class="causa"><span class="camada ' . $cls . '">'
         . '<i class="fa-solid fa-crosshairs"></i> ' . h($rot) . '</span>'
         . ($partes ? '<span class="causa-txt">' . h(implode(' · ', $partes)) . '</span>' : '')
         . (!empty($ev['cf_ray'])
             ? '<span class="ray" title="Identificador da requisição na Cloudflare — use ao abrir chamado">'
               . h((string) $ev['cf_ray']) . '</span>' : '')
         . '</div>';
}

/* Comparativo: barras por dia (altura = indisponibilidade) + números do período. */
function render_periodo(array $dias, string $sel, int $periodo): string
{
    if (!$dias) {
        return '<div class="vazio"><i class="fa-solid fa-chart-column" style="color:var(--cor-texto-sec)"></i>'
             . '<p>Ainda não há dias fechados no histórico.</p></div>';
    }
    $fora_total = 0; $inc_total = 0; $dias_perfeitos = 0;
    $pior = null; $soma_disp = 0.0;
    foreach ($dias as $d) {
        $fora_total += $d['seg_fora'];
        $inc_total  += count($d['incidentes']);
        $soma_disp  += $d['disp'];
        if ($d['seg_fora'] === 0) { $dias_perfeitos++; }
        if ($pior === null || $d['disp'] < $pior['disp']) { $pior = $d; }
    }
    $media = $soma_disp / count($dias);

    // Escala das barras pelo pior dia (mínimo 1% para não achatar tudo).
    $maior_ind = max(0.05, 100 - $pior['disp']);
    $barras = '';
    foreach ($dias as $d) {
        $ind = 100 - $d['disp'];
        $alt = $ind <= 0 ? 3 : max(6, (int) round($ind / $maior_ind * 100));
        if ($ind <= 0)        { $cls = 'ok'; }
        elseif ($d['disp'] >= META_DISP) { $cls = 'w1'; }
        elseif ($d['disp'] >= 99)        { $cls = 'w2'; }
        else                             { $cls = 'w3'; }
        $barras .= '<a class="bar' . ($d['data'] === $sel ? ' sel' : '') . '" href="?d=' . $d['data']
                 . '&p=' . $periodo . '" title="' . h(date('d/m', strtotime($d['data'])) . ' — '
                 . num($d['disp'], 2) . '% · ' . ($d['seg_fora'] ? dur($d['seg_fora']) . ' fora' : 'sem quedas')
                 . ($d['estimado'] ? ' (estimado)' : '')) . '">'
                 . '<i class="' . $cls . '" style="height:' . $alt . '%"></i>'
                 . '<span>' . h(date('d/m', strtotime($d['data']))) . '</span></a>';
    }

    $resumo = [
        ['Disponibilidade média', num($media, 3) . '%', count($dias) . ' dia(s) com dado'],
        ['Tempo fora acumulado',  $fora_total ? dur($fora_total) : 'nenhum', $inc_total . ' incidente(s)'],
        ['Dias 100% no ar',       $dias_perfeitos . '/' . count($dias),
            num($dias_perfeitos / count($dias) * 100, 0) . '% dos dias'],
        ['Pior dia',              date('d/m', strtotime($pior['data'])),
            num($pior['disp'], 2) . '% · ' . ($pior['seg_fora'] ? dur($pior['seg_fora']) : 'sem quedas')],
    ];
    $cards = '';
    foreach ($resumo as $r) {
        $cards .= '<div class="col-6 col-lg-3"><div class="mini">'
                . '<div class="ch">' . h($r[0]) . '</div><div class="num">' . h($r[1]) . '</div>'
                . '<div class="sub">' . h($r[2]) . '</div></div></div>';
    }

    return '<div class="row g-2 mb-3">' . $cards . '</div>'
         . '<div class="grafico">' . $barras . '</div>';
}

/* ===== ENDPOINT AJAX =====
 * Só faz sentido para HOJE (dia ao vivo). Para dias fechados o cliente nem
 * chama — o conteúdo é estático. */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    /* Atualização forçada de quem já estava com a página aberta.
     * O cliente novo manda &v=<versao>; o antigo (JS anterior) não manda nada.
     * - cliente ANTIGO: injeta no fragmento um <img onerror> que recarrega a aba.
     *   Handlers inline colados via innerHTML executam — é o único gancho que o JS
     *   antigo nos dá, já que <script> em innerHTML não roda.
     * - cliente NOVO: compara versões e se recarrega sozinho (ver JS no fim).
     * Depois do reload o cliente passa a mandar &v, então não há laço infinito. */
    $cliente_v   = (string) ($_GET['v'] ?? '');
    $estado_html = render_status($D);
    if ($cliente_v === '') {
        $estado_html .= '<img src="data:," alt="" style="display:none"'
                      . ' onerror="location.reload(true)">';
    }
    echo json_encode([
        'ok'         => true,
        'versao'     => VERSAO,
        'status'     => $D['status'],
        'estado'     => $estado_html,
        'cards'      => render_cards($D),
        'timeline'   => render_timeline($D),
        'incidentes' => render_incidentes($D, $busca),
        'atualizado' => date('H:i:s'),
        /* --- navegação por AJAX (v1.5) --- */
        'sel'        => $sel,
        'p'          => $periodo,
        'q'          => $busca,
        'aovivo'     => $D['hoje'],
        'rotulo'     => render_rotulo($sel, $D),
        'nav'        => render_nav($sel, $periodo),
        'periodo'    => render_periodo(serie($periodo), $sel, $periodo),
        'titulo_inc' => $D['hoje'] ? 'de hoje' : ('do dia ' . date('d/m', strtotime($sel))),
        'titulo_per' => 'Últimos ' . $periodo . ' dias',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$serie_periodo = serie($periodo);

/* Sem cache no HTML: senão o reload forçado poderia reentregar a versão velha. */
header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sentinela · monitoramento.top</title>
<!-- Favicon embutido (SVG em data:URI): sem isso o navegador pede /favicon.ico
     na RAIZ do domínio, que não existe, e o console fica com um 404 fixo.
     O escudo usa o azul do padrão visual; o .ico é declarado vazio de propósito
     para o Safari/IE não caírem no /favicon.ico do host. -->
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='5' fill='%232563EB'/%3E%3Cpath d='M12 4.2 6.4 6.5v4.6c0 3.6 2.4 6.9 5.6 8.1 3.2-1.2 5.6-4.5 5.6-8.1V6.5L12 4.2z' fill='%23fff'/%3E%3Cpath d='m9.6 11.8 1.7 1.8 3.3-3.6' stroke='%232563EB' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="apple-touch-icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='5' fill='%232563EB'/%3E%3Cpath d='M12 4.2 6.4 6.5v4.6c0 3.6 2.4 6.9 5.6 8.1 3.2-1.2 5.6-4.5 5.6-8.1V6.5L12 4.2z' fill='%23fff'/%3E%3C/svg%3E">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --cor-primaria:#2563EB; --cor-fundo:#F5F5F5; --cor-card:#FFF;
  --cor-texto:#333; --cor-texto-sec:#777; --cor-acao:#2B2B2B;
  --cor-borda:#ECECEC; --raio:10px;
  --ok:#1e9e57; --ok-bg:#e6f7ee; --down:#d9433a; --down-bg:#fdecec;
  --warn:#c47f12; --warn-bg:#fff4e0;
  --w1:#f0c419; --w2:#e8873a; --w3:#d9433a; --fut:#E4E4E4;
}
*{-webkit-tap-highlight-color:transparent}
body{background:var(--cor-fundo);color:var(--cor-texto);
  font-family:'Inter',system-ui,-apple-system,sans-serif;padding-bottom:40px}
.wrap{max-width:1000px;margin:0 auto;padding:26px 16px}
.topo{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px;flex-wrap:wrap}
.topo h1{font-size:1.45rem;font-weight:800;margin:0}
.topo h1 i{color:var(--cor-primaria);margin-right:8px}
.topo p{margin:2px 0 0;color:var(--cor-texto-sec);font-size:.86rem}
.ao-vivo{display:inline-flex;align-items:center;gap:7px;background:var(--cor-card);
  border:1px solid var(--cor-borda);border-radius:30px;padding:7px 14px;font-size:.78rem;font-weight:600}
.ao-vivo .pulse{width:8px;height:8px;border-radius:50%;background:var(--ok);
  animation:pulse 1.8s infinite}
.ao-vivo.hist .pulse{background:var(--cor-texto-sec);animation:none}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
.card-soft{background:var(--cor-card);border-radius:var(--raio);
  box-shadow:0 1px 3px rgba(0,0,0,.05),0 6px 18px rgba(0,0,0,.04);padding:18px}

/* ---- barra de filtro ---- */
.filtro{background:var(--cor-card);border-radius:var(--raio);padding:12px 14px;margin-bottom:18px;
  box-shadow:0 1px 3px rgba(0,0,0,.05),0 6px 18px rgba(0,0,0,.04);
  display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.filtro .grupo{display:flex;align-items:center;gap:8px}
.filtro label{font-size:.74rem;font-weight:600;color:var(--cor-texto-sec)}
.filtro input[type=date],.filtro input[type=search],.filtro select{
  border:1px solid var(--cor-borda);border-radius:8px;padding:7px 10px;font-size:.83rem;
  font-family:inherit;color:var(--cor-texto);background:#fff;min-width:0}
.filtro input:focus,.filtro select:focus{outline:2px solid rgba(37,99,235,.25);border-color:var(--cor-primaria)}
.filtro input[type=search]{width:190px}
.nav-dia{display:inline-flex;border:1px solid var(--cor-borda);border-radius:8px;overflow:hidden}
.nav-dia a,.nav-dia span{padding:7px 11px;font-size:.83rem;color:var(--cor-texto);text-decoration:none;
  background:#fff;border-right:1px solid var(--cor-borda)}
.nav-dia a:last-child,.nav-dia span:last-child{border-right:0}
.nav-dia a:hover{background:var(--cor-fundo);color:var(--cor-primaria)}
.nav-dia span{color:#CFCFCF}
.rotulo-dia{font-weight:700;font-size:.95rem;margin-right:auto}
.rotulo-dia .tag{font-size:.68rem;font-weight:600;background:var(--warn-bg);color:var(--warn);
  border-radius:20px;padding:3px 9px;margin-left:8px;vertical-align:middle}

.estado{display:flex;align-items:center;gap:18px;background:var(--cor-card);
  border-radius:var(--raio);padding:22px;margin-bottom:18px;
  box-shadow:0 1px 3px rgba(0,0,0,.05),0 6px 18px rgba(0,0,0,.04);
  border-left:5px solid var(--cor-texto-sec)}
.estado.ok{border-left-color:var(--ok)} .estado.down{border-left-color:var(--down)}
.estado.unknown,.estado.warn{border-left-color:var(--warn)}
.estado-ico{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;
  font-size:1.7rem;flex:0 0 58px}
.estado.ok .estado-ico{background:var(--ok-bg);color:var(--ok)}
.estado.down .estado-ico{background:var(--down-bg);color:var(--down)}
.estado.unknown .estado-ico,.estado.warn .estado-ico{background:var(--warn-bg);color:var(--warn)}
.estado-txt h2{font-size:1.3rem;font-weight:800;margin:0 0 3px}
.estado-txt p{margin:0;color:var(--cor-texto-sec);font-size:.88rem}
.detalhe{display:inline-block;margin-top:8px;font-size:.78rem;color:var(--cor-texto-sec);
  background:var(--cor-fundo);border:1px solid var(--cor-borda);border-radius:20px;padding:4px 11px}
.stat .ch{font-size:.78rem;font-weight:600;color:var(--cor-texto-sec);margin-bottom:6px}
.stat .ch i{color:var(--cor-primaria);margin-right:5px}
.stat .num{font-size:1.8rem;font-weight:800;line-height:1.1}
.stat .sub{font-size:.72rem;color:var(--cor-texto-sec);margin-top:3px}
h3.sec{font-size:1rem;font-weight:700;margin:26px 0 12px;display:flex;align-items:center;
  gap:8px;flex-wrap:wrap}
h3.sec i{color:var(--cor-primaria)}
h3.sec .acoes{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap}

/* ---- linha do tempo 24h ---- */
.tl{display:flex;gap:3px;align-items:flex-end}
.tl-h{flex:1;height:52px;border-radius:4px;position:relative;background:var(--ok);cursor:default}
.tl-h.ok{background:#7ed0a5} .tl-h.w1{background:var(--w1)} .tl-h.w2{background:var(--w2)}
.tl-h.w3{background:var(--w3)} .tl-h.f{background:var(--fut)}
.tl-h span{position:absolute;bottom:-18px;left:0;right:0;text-align:center;
  font-size:.6rem;color:var(--cor-texto-sec)}
.tl-leg{display:flex;gap:14px;flex-wrap:wrap;margin-top:26px;font-size:.72rem;color:var(--cor-texto-sec)}
.tl-leg .q{width:10px;height:10px;border-radius:3px;display:inline-block;margin-right:5px}
.q.ok{background:#7ed0a5} .q.w1{background:var(--w1)} .q.w2{background:var(--w2)}
.q.w3{background:var(--w3)} .q.f{background:var(--fut)}

/* ---- comparativo de período ---- */
.mini{background:var(--cor-fundo);border:1px solid var(--cor-borda);border-radius:8px;padding:11px 13px;height:100%}
.mini .ch{font-size:.7rem;font-weight:600;color:var(--cor-texto-sec)}
.mini .num{font-size:1.25rem;font-weight:800;line-height:1.2;margin-top:2px}
.mini .sub{font-size:.68rem;color:var(--cor-texto-sec)}
.grafico{display:flex;align-items:flex-end;gap:3px;height:110px;overflow-x:auto;padding-bottom:16px}
.grafico .bar{flex:1 0 14px;height:100%;display:flex;flex-direction:column;justify-content:flex-end;
  align-items:center;text-decoration:none;position:relative;border-radius:5px;padding:0 1px}
.grafico .bar:hover{background:rgba(37,99,235,.06)}
.grafico .bar.sel{background:rgba(37,99,235,.12);outline:1px solid rgba(37,99,235,.35)}
.grafico .bar i{display:block;width:100%;border-radius:3px 3px 0 0;min-height:3px}
.grafico .bar i.ok{background:#7ed0a5} .grafico .bar i.w1{background:var(--w1)}
.grafico .bar i.w2{background:var(--w2)} .grafico .bar i.w3{background:var(--w3)}
.grafico .bar span{position:absolute;bottom:-15px;font-size:.55rem;color:var(--cor-texto-sec);
  white-space:nowrap}

.inc{display:flex;gap:13px;padding:13px 0;border-bottom:1px solid var(--cor-borda)}
.inc:last-child{border-bottom:0}
.inc-ico{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;
  background:var(--ok-bg);color:var(--ok);flex:0 0 38px}
.inc.aberto .inc-ico{background:var(--down-bg);color:var(--down)}
.inc-txt p{margin:4px 0 0;font-size:.82rem;color:var(--cor-texto-sec)}
.causa{margin-top:7px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.camada{font-size:.68rem;font-weight:700;border-radius:20px;padding:3px 9px;white-space:nowrap}
.camada i{margin-right:4px}
.c-origem{background:var(--down-bg);color:var(--down)}
.c-borda{background:var(--warn-bg);color:var(--warn)}
.c-rede{background:#EDE9FE;color:#6D28D9}
.c-ind{background:var(--cor-fundo);color:var(--cor-texto-sec)}
.causa-txt{font-size:.72rem;color:var(--cor-texto-sec)}
.ray{font-size:.62rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  color:var(--cor-texto-sec);background:var(--cor-fundo);border:1px solid var(--cor-borda);
  border-radius:5px;padding:2px 6px}
.inc-id{font-size:.66rem;color:var(--cor-texto-sec);background:var(--cor-fundo);
  border:1px solid var(--cor-borda);border-radius:20px;padding:3px 8px;margin-left:6px}
.badge-status{padding:4px 10px;border-radius:30px;font-weight:600;font-size:.72rem;margin-left:6px}
.b-ok{background:var(--ok-bg);color:var(--ok)} .b-down{background:var(--down-bg);color:var(--down)}
.vazio{text-align:center;padding:26px 10px;color:var(--cor-texto-sec)}
.vazio i{font-size:1.7rem;color:var(--ok);margin-bottom:8px;display:block}
.rodape{margin-top:26px;text-align:center;color:var(--cor-texto-sec);font-size:.76rem}
.rodape a{color:var(--cor-primaria);text-decoration:none;font-weight:600}
.btn-push,.btn-outline-soft{border-radius:30px;padding:8px 16px;font-size:.8rem;font-weight:600;
  border:1px solid var(--cor-borda);background:var(--cor-card);color:var(--cor-texto);cursor:pointer;
  text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-mini{padding:5px 12px;font-size:.72rem}
.btn-push{background:var(--cor-acao);color:#fff;border-color:var(--cor-acao)}
.btn-push.ativo{background:var(--ok-bg);color:var(--ok);border-color:#c9ebd8}
.btn-push:disabled,.btn-outline-soft:disabled{opacity:.55;cursor:default}
.btn-push:active,.btn-outline-soft:active{transform:scale(.97)}
.btn-outline-soft:hover{border-color:var(--cor-primaria);color:var(--cor-primaria)}
.flash{margin:0 0 16px;padding:11px 15px;border-radius:var(--raio);font-size:.84rem;font-weight:500}
.flash.ok{background:var(--ok-bg);color:var(--ok)} .flash.erro{background:var(--down-bg);color:var(--down)}
/* ---- feedback visual do AJAX ---- */
.progresso{position:fixed;top:0;left:0;height:3px;width:100%;z-index:9999;
  background:linear-gradient(90deg,var(--cor-primaria),#60A5FA);transform-origin:0 50%;
  transform:scaleX(0);transition:transform .25s ease}
.progresso.andando{animation:barra 1.1s ease-in-out infinite}
@keyframes barra{0%{transform:scaleX(0)}50%{transform:scaleX(.7)}100%{transform:scaleX(1);opacity:.25}}
/* Dimming leve: mostra que o conteúdo é o ANTIGO, sem escondê-lo (piscar para
   branco é pior que esperar 200ms vendo o dado anterior). */
.trocavel{transition:opacity .22s ease}
/* Dimming discreto: o suficiente para dizer "isto ainda é o dado antigo", sem
   apagar a tela. A dessaturação saiu — ela é que fazia parecer "desligado". */
body.carregando .trocavel{opacity:.85;pointer-events:none}
body.carregando .filtro{cursor:progress}
.entrando{animation:entra .22s ease}
@keyframes entra{from{opacity:.55;transform:translateY(2px)}to{opacity:1;transform:none}}
.spin{width:15px;height:15px;border-radius:50%;border:2px solid var(--cor-borda);
  border-top-color:var(--cor-primaria);animation:gira .7s linear infinite;flex:0 0 15px}
.spin[hidden]{display:none}
@keyframes gira{to{transform:rotate(360deg)}}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.filtro.erro{outline:2px solid var(--down);outline-offset:2px}
@media(max-width:880px){.wrap{padding:18px 13px}.topo h1{font-size:1.2rem}
  .estado{gap:13px;padding:18px}.stat .num{font-size:1.45rem}
  .filtro input[type=search]{width:100%}.rotulo-dia{width:100%;margin-bottom:2px}
  .tl-h span{font-size:.5rem}}
@media(prefers-reduced-motion:reduce){.ao-vivo .pulse{animation:none}
  .progresso.andando,.spin{animation:none}.entrando{animation:none}
  .progresso.andando{transform:scaleX(.6)}}
@media print{.filtro,.topo .btn-push,.topo .btn-outline-soft,h3.sec .acoes{display:none}
  body{background:#fff}.card-soft,.estado{box-shadow:none;border:1px solid #DDD}}
</style>
</head>
<body>
<!-- Feedback do AJAX: barra de progresso no topo (como a de navegador) e um
     aviso invisível para leitor de tela — dimming sozinho não comunica nada
     a quem não enxerga a tela. -->
<div id="progresso" class="progresso" hidden></div>
<p id="leitor" class="sr" role="status" aria-live="polite"></p>
<div class="wrap">

  <div class="topo">
    <div>
      <h1><i class="fa-solid fa-shield-halved"></i>Sentinela</h1>
      <p>Monitor de disponibilidade de <strong>monitoramento.top</strong> · checagem a cada 5s</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <button id="btn-push" class="btn-push" type="button">
        <i class="fa-solid fa-bell"></i> <span>Ativar notificações</span>
      </button>
      <button id="btn-teste" class="btn-outline-soft" type="button" hidden>
        <i class="fa-solid fa-paper-plane"></i> Testar
      </button>
      <span class="ao-vivo<?= $D['hoje'] ? '' : ' hist' ?>" id="selo-vivo"><span class="pulse"></span>
        <span id="selo-txt"><?= $D['hoje'] ? 'ao vivo' : 'histórico' ?></span>
        <span id="hora" style="color:var(--cor-texto-sec);font-weight:400"></span></span>
    </div>
  </div>

  <div id="flash" class="flash" hidden></div>

  <!-- ===== FILTRO POR DATA ===== -->
  <form class="filtro" method="get" id="form-filtro">
    <span class="rotulo-dia" id="rotulo"><?= render_rotulo($sel, $D) ?></span>

    <span class="nav-dia" id="nav"><?= render_nav($sel, $periodo) ?></span>

    <!-- Spinner do AJAX: sai do fluxo quando oculto, para a barra não "pular". -->
    <span class="spin" id="spin" hidden aria-hidden="true"></span>

    <span class="grupo">
      <label for="d">Data</label>
      <input type="date" id="d" name="d" value="<?= h($sel) ?>"
             min="<?= h($primeiro) ?>" max="<?= h($hoje_str) ?>">
    </span>

    <span class="grupo">
      <label for="p">Período</label>
      <select id="p" name="p">
        <?php foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $v => $rot): ?>
          <option value="<?= $v ?>"<?= $periodo === $v ? ' selected' : '' ?>><?= $rot ?></option>
        <?php endforeach; ?>
      </select>
    </span>

    <span class="grupo">
      <input type="search" name="q" value="<?= h($busca) ?>" placeholder="Buscar no motivo do incidente…">
    </span>

    <noscript><button class="btn-outline-soft btn-mini" type="submit">Filtrar</button></noscript>
  </form>

  <div id="estado" class="trocavel"><?= render_status($D) ?></div>

  <div class="row g-3 trocavel" id="cards"><?= render_cards($D) ?></div>

  <h3 class="sec"><i class="fa-solid fa-timeline"></i> Linha do tempo · 24h</h3>
  <div class="card-soft trocavel" id="timeline"><?= render_timeline($D) ?></div>

  <h3 class="sec"><i class="fa-solid fa-clock-rotate-left"></i>
    Incidentes <span id="titulo-inc"><?= $D['hoje'] ? 'de hoje' : 'do dia ' . h(date('d/m', strtotime($sel))) ?></span>
    <span class="acoes">
      <a class="btn-outline-soft btn-mini" id="exp-csv"
         href="?d=<?= $sel ?>&q=<?= urlencode($busca) ?>&export=csv">
        <i class="fa-solid fa-file-csv"></i> CSV do dia</a>
      <a class="btn-outline-soft btn-mini" id="exp-json"
         href="?d=<?= $sel ?>&q=<?= urlencode($busca) ?>&export=json">
        <i class="fa-solid fa-code"></i> JSON</a>
    </span>
  </h3>
  <div class="card-soft trocavel" id="incidentes"><?= render_incidentes($D, $busca) ?></div>

  <h3 class="sec"><i class="fa-solid fa-chart-column"></i>
    <span id="titulo-per">Últimos <?= $periodo ?> dias</span>
    <span class="acoes">
      <a class="btn-outline-soft btn-mini" id="exp-per" href="?p=<?= $periodo ?>&escopo=periodo&export=csv">
        <i class="fa-solid fa-file-csv"></i> CSV do período</a>
    </span>
  </h3>
  <div class="card-soft trocavel" id="periodo"><?= render_periodo($serie_periodo, $sel, $periodo) ?></div>

  <div class="rodape">
    Alertas e relatório diário via Direct do Instagram ·
    <a href="https://monitoramento.top/" target="_blank" rel="noopener">abrir monitoramento.top</a><br>
    Histórico guardado por dia · <?= count(dias_arquivados()) ?> dia(s) arquivado(s) ·
    Sentinela v<?= VERSAO ?>
  </div>
</div>

<script>
/* ============ ESTADO DA TELA + NAVEGAÇÃO POR AJAX (v1.5) ============
   Trocar de dia, de período ou buscar NÃO recarrega mais a página: busca os
   fragmentos já renderizados pelo PHP e substitui só o que mudou, com barra de
   progresso, dimming do conteúdo antigo e URL atualizada (history) — o botão
   voltar do navegador continua funcionando. O <form> segue válido: sem JS ele
   envia por GET normalmente. */
const VERSAO_CLIENTE = '<?= VERSAO ?>';
const HOJE = '<?= $hoje_str ?>';
let filtro = {d: '<?= h($sel) ?>', p: '<?= $periodo ?>', q: <?= json_encode($busca) ?>};
let aoVivo = <?= $D['hoje'] ? 'true' : 'false' ?>;
let assinatura = '';
let carregando = false;
let pedido = 0;                      // descarta resposta de requisição vencida

const $ = (id) => document.getElementById(id);
const barra = $('progresso'), spin = $('spin'), leitor = $('leitor');

function mostrarCarga(ativo) {
  carregando = ativo;
  document.body.classList.toggle('carregando', ativo);
  spin.hidden = !ativo;
  if (ativo) {
    barra.hidden = false;
    barra.classList.add('andando');
  } else {
    barra.classList.remove('andando');
    barra.hidden = true;
  }
}

function trocar(el, html) {
  if (!el || el.innerHTML === html) return false;
  el.innerHTML = html;
  el.classList.remove('entrando');
  void el.offsetWidth;               // reinicia a animação
  el.classList.add('entrando');
  return true;
}

function aplicar(d, completo) {
  trocar($('estado'), d.estado);
  trocar($('cards'), d.cards);
  trocar($('timeline'), d.timeline);
  trocar($('incidentes'), d.incidentes);
  if (completo) {
    trocar($('periodo'), d.periodo);
    $('rotulo').innerHTML = d.rotulo;
    $('nav').innerHTML = d.nav;
    $('titulo-inc').textContent = d.titulo_inc;
    $('titulo-per').textContent = d.titulo_per;
    const qs = 'd=' + encodeURIComponent(d.sel) + '&q=' + encodeURIComponent(d.q);
    $('exp-csv').href = '?' + qs + '&export=csv';
    $('exp-json').href = '?' + qs + '&export=json';
    $('exp-per').href = '?p=' + d.p + '&escopo=periodo&export=csv';
    aoVivo = !!d.aovivo;
    $('selo-vivo').classList.toggle('hist', !aoVivo);
    $('selo-txt').textContent = aoVivo ? 'ao vivo' : 'histórico';
  }
  $('hora').textContent = aoVivo ? '· ' + d.atualizado : '';
}

async function buscar(completo) {
  const meu = ++pedido;
  const url = '?ajax=1&v=' + encodeURIComponent(VERSAO_CLIENTE)
            + '&d=' + encodeURIComponent(filtro.d)
            + '&p=' + encodeURIComponent(filtro.p)
            + '&q=' + encodeURIComponent(filtro.q);
  const r = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store'});
  const d = await r.json();
  if (meu !== pedido) return null;   // chegou tarde: já tem pedido mais novo
  if (!d.ok) throw new Error('resposta inválida');
  // Painel atualizado no servidor: recarrega para pegar o novo HTML/JS/CSS.
  if (d.versao && d.versao !== VERSAO_CLIENTE) { location.reload(true); return null; }
  aplicar(d, completo);
  return d;
}

/* Navegação (data, período, busca, clique numa barra do gráfico). */
async function navegar(novos, empilhar) {
  Object.assign(filtro, novos);
  mostrarCarga(true);
  form.classList.remove('erro');
  leitor.textContent = 'Carregando…';
  try {
    const d = await buscar(true);
    if (!d) return;
    if (empilhar !== false) {
      const qs = '?d=' + encodeURIComponent(filtro.d) + '&p=' + filtro.p
               + (filtro.q ? '&q=' + encodeURIComponent(filtro.q) : '');
      history.pushState({...filtro}, '', qs);
    }
    assinatura = d.estado + d.cards + d.incidentes + d.timeline;
    leitor.textContent = d.titulo_inc + ' carregado.';
  } catch (e) {
    // Falhou: o conteúdo antigo continua legível e a barra sinaliza o erro.
    form.classList.add('erro');
    aviso('Não consegui carregar esse período. Tente de novo.', 'erro');
    leitor.textContent = 'Falha ao carregar.';
  } finally {
    mostrarCarga(false);
  }
}

/* Polling do dia de hoje: silencioso, sem barra de progresso nem dimming. */
async function atualizar() {
  if (!aoVivo || document.hidden || carregando) return;
  try {
    const d = await buscar(false);
    if (!d) return;
    const nova = d.estado + d.cards + d.incidentes + d.timeline;
    assinatura = nova;                // aplicar() já evita troca sem mudança
  } catch (e) { /* silencioso: falha de rede não quebra a tela */ }
}

setInterval(atualizar, 5000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) atualizar(); });

/* ---- ligações da interface ---- */
const form = $('form-filtro');
form.addEventListener('submit', (ev) => { ev.preventDefault(); navegar({}); });
form.querySelector('#d').addEventListener('change', (ev) => navegar({d: ev.target.value}));
form.querySelector('#p').addEventListener('change', (ev) => navegar({p: ev.target.value}));

let td = null;
form.querySelector('input[type=search]').addEventListener('input', (ev) => {
  clearTimeout(td);
  const v = ev.target.value;
  td = setTimeout(() => navegar({q: v}), 450);
});

/* Setas do filtro e barras do gráfico são links reais (funcionam sem JS);
   aqui só interceptamos o clique. Delegação, porque o HTML é re-renderizado. */
document.addEventListener('click', (ev) => {
  const a = ev.target.closest('#nav a, #periodo a.bar');
  if (!a || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;
  ev.preventDefault();
  const u = new URL(a.getAttribute('href'), location.href);
  navegar({d: u.searchParams.get('d') || HOJE,
           p: u.searchParams.get('p') || filtro.p});
});

/* Voltar/avançar do navegador. */
window.addEventListener('popstate', (ev) => {
  const e = ev.state || {};
  const u = new URLSearchParams(location.search);
  filtro = {d: e.d || u.get('d') || HOJE, p: e.p || u.get('p') || 30, q: e.q || u.get('q') || ''};
  form.querySelector('#d').value = filtro.d;
  form.querySelector('#p').value = filtro.p;
  form.querySelector('input[type=search]').value = filtro.q;
  navegar({}, false);
});

/* Atalhos: ← → navegam dias, H volta para hoje. */
document.addEventListener('keydown', (ev) => {
  if (ev.target.matches('input, select, textarea') || carregando) return;
  const ir = (sel) => { const a = document.querySelector(sel); if (a) a.click(); };
  if (ev.key === 'ArrowLeft')  ir('#nav a[title="Dia anterior"]');
  if (ev.key === 'ArrowRight') ir('#nav a[title="Próximo dia"]');
  if (ev.key.toLowerCase() === 'h') ir('#nav a[title="Hoje"]');
});

history.replaceState({...filtro}, '');
atualizar();

/* ===================== WEB PUSH ===================== */
const btnPush = document.getElementById('btn-push');
const btnTeste = document.getElementById('btn-teste');
const flash = document.getElementById('flash');
let registro = null, inscricao = null;

function aviso(msg, tipo) {
  flash.textContent = msg;
  flash.className = 'flash ' + (tipo || 'ok');
  flash.hidden = false;
  setTimeout(() => { flash.hidden = true; }, 4000);
}

function b64ToUint8(b64) {
  const pad = '='.repeat((4 - b64.length % 4) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

function pintar(ativo) {
  btnPush.classList.toggle('ativo', ativo);
  btnPush.querySelector('span').textContent = ativo ? 'Notificações ativas' : 'Ativar notificações';
  btnPush.querySelector('i').className = ativo ? 'fa-solid fa-bell-slash' : 'fa-solid fa-bell';
  btnTeste.hidden = !ativo;
}

async function iniciarPush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    btnPush.disabled = true;
    btnPush.querySelector('span').textContent = 'Push não suportado';
    return;
  }
  /* URL versionada de propósito: tem Cloudflare na frente impondo
     Browser Cache TTL de 4h ao sw.js, acima do Cache-Control da origem. Trocar a
     query a cada versão garante script novo (o escopo vem do caminho, não da query). */
  registro = await navigator.serviceWorker.register('sw.js?v=' + VERSAO_CLIENTE);
  registro.update().catch(() => {});
  inscricao = await registro.pushManager.getSubscription();
  pintar(!!inscricao);
}

btnPush.addEventListener('click', async () => {
  btnPush.disabled = true;
  try {
    if (inscricao) {                                  // já ativo -> desativar
      const end = inscricao.endpoint;
      await inscricao.unsubscribe();
      await fetch('?push=remover', {method: 'POST', body: JSON.stringify({endpoint: end})});
      inscricao = null;
      pintar(false);
      aviso('Notificações desativadas neste aparelho.', 'ok');
    } else {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') { aviso('Permissão negada no navegador.', 'erro'); return; }
      const k = await (await fetch('?push=vapid')).json();
      if (!k.ok) { aviso('Não foi possível obter a chave VAPID.', 'erro'); return; }
      inscricao = await registro.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: b64ToUint8(k.chave)
      });
      const r = await (await fetch('?push=sub', {
        method: 'POST', body: JSON.stringify(inscricao.toJSON())
      })).json();
      pintar(true);
      aviso(r.msg || 'Notificações ativadas.', r.ok ? 'ok' : 'erro');
    }
  } catch (e) {
    aviso('Erro ao configurar notificações: ' + e.message, 'erro');
  } finally {
    btnPush.disabled = false;
  }
});

btnTeste.addEventListener('click', async () => {
  if (!inscricao) return;
  btnTeste.disabled = true;
  try {
    const r = await (await fetch('?push=teste', {
      method: 'POST', body: JSON.stringify({endpoint: inscricao.endpoint})
    })).json();
    aviso(r.msg || '—', r.ok ? 'ok' : 'erro');
  } catch (e) {
    aviso('Falha no teste: ' + e.message, 'erro');
  } finally {
    btnTeste.disabled = false;
  }
});

iniciarPush();
</script>
</body>
</html>
