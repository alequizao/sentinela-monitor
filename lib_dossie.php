<?php
declare(strict_types=1);

/**
 * Dossiê de incidentes do Sentinela
 * ---------------------------------
 * Cada queda confirmada vira UM arquivo em <instância>/dossie/INC-XXXX.json,
 * aberto no instante em que o monitor declara "down" e alimentado a cada ciclo
 * enquanto durar: linha do tempo, evidência técnica (código HTTP, CF-Ray, erro
 * Cloudflare, sonda na origem), diagnóstico em português com a causa provável
 * e a ação recomendada, avisos disparados e notas humanas.
 *
 * Diferença para historico/AAAA-MM-DD.json: o histórico é o RESUMO do dia
 * (números); o dossiê é o PRONTUÁRIO do incidente — o que estava acontecendo,
 * de que lado quebrou, o que foi tentado e como terminou. É lido por dossie.php.
 *
 * Usado pelo monitor.php (CLI, root) e pelo dossie.php (web, PHP 7.4) — nada
 * de sintaxe 8.x aqui.
 */

const DOSSIE_MAX = 500; // arquivos mantidos por instância

function dossie_dir(): string
{
    $dir = INST_DIR . '/dossie';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

function dossie_id_de(int $inicio): string
{
    return 'INC-' . strtoupper(base_convert((string) $inicio, 10, 36));
}

function dossie_arq(string $id): string
{
    return dossie_dir() . '/' . preg_replace('/[^A-Z0-9\-]/', '', strtoupper($id)) . '.json';
}

function dossie_ler(string $id): array
{
    $arq = dossie_arq($id);
    if (!is_file($arq)) { return []; }
    $d = json_decode((string) file_get_contents($arq), true);
    return is_array($d) ? $d : [];
}

function dossie_gravar(array $d): void
{
    $d['atualizado_em'] = date('Y-m-d H:i:s');
    $arq = dossie_arq((string) $d['id']);
    $tmp = $arq . '.tmp';
    file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @rename($tmp, $arq);
}

/* Lista (mais recente primeiro) com os campos de cabeçalho. */
function dossie_listar(int $limite = 200): array
{
    $out = [];
    foreach (glob(dossie_dir() . '/INC-*.json') ?: [] as $arq) {
        $d = json_decode((string) file_get_contents($arq), true);
        if (!is_array($d)) { continue; }
        $out[] = $d;
    }
    usort($out, static function ($a, $b) { return (int) $b['inicio'] - (int) $a['inicio']; });
    return array_slice($out, 0, $limite);
}

function dossie_podar(): void
{
    $todos = glob(dossie_dir() . '/INC-*.json') ?: [];
    if (count($todos) <= DOSSIE_MAX) { return; }
    usort($todos, static function ($a, $b) { return filemtime($a) - filemtime($b); });
    foreach (array_slice($todos, 0, count($todos) - DOSSIE_MAX) as $v) { @unlink($v); }
}

/* ===== DIAGNÓSTICO =====
 * Traduz a evidência crua em: título curto, causa provável, explicação e a
 * ação recomendada. As regras vão da mais específica (erro Cloudflare 1033 =
 * túnel desligado) para a mais genérica. `origem_ok` só existe quando a sonda
 * direta na origem rodou — é ela que separa "servidor caiu" de "Cloudflare
 * não chega no servidor". */
function dossie_diagnosticar(array $ev, string $motivo): array
{
    $code   = (int) ($ev['code'] ?? 0);
    $cferr  = (int) ($ev['cf_erro'] ?? 0);
    $etapa  = (string) ($ev['etapa'] ?? '');
    $sonda  = array_key_exists('origem_ok', $ev) ? $ev['origem_ok'] : null; // true|false|null
    $camada = (string) ($ev['camada'] ?? '');
    $vivaTx = $sonda === true  ? 'A sonda direta no IP de origem respondeu normalmente — o servidor está vivo. '
           : ($sonda === false ? 'A sonda direta no IP de origem também falhou — o servidor está inacessível. '
           : 'A sonda direta na origem não rodou (origem_ip não configurado), então o lado do servidor é incerto. ');

    // 1) Cloudflare Tunnel desligado (erro 1033) — foi a queda de 07/09/2026.
    if ($cferr === 1033 || ($code === 530 && $sonda !== false)) {
        return [
            'tipo'   => 'cloudflare-tunnel',
            'titulo' => 'Cloudflare Tunnel desconectado (erro 1033)',
            'causa'  => 'O DNS do domínio aponta para um Cloudflare Tunnel e nenhum cloudflared está conectado a ele.',
            'explica'=> 'A Cloudflare devolveu HTTP ' . $code . ' com o código 1033 ("Argo Tunnel error"): o registro do '
                      . 'domínio é um CNAME de túnel e a borda não encontrou nenhuma conexão viva do cloudflared. '
                      . $vivaTx . 'Causas típicas: serviço cloudflared parado ou removido na VPS, token do túnel '
                      . 'trocado/revogado, ingress do túnel sem este hostname ou servidor reiniciado sem o serviço habilitado.',
            'acao'   => 'Na VPS: `systemctl status cloudflared` e `journalctl -u cloudflared -n 50`; se parado, '
                      . '`systemctl restart cloudflared` (o watchdog em /etc/cron.d/cloudflared-watchdog faz isso sozinho '
                      . 'a cada minuto). Se o serviço não existe, reinstalar com `cloudflared service install <token>`. '
                      . 'No painel Zero Trust → Tunnels, conferir se o hostname está no ingress apontando para '
                      . 'http://179.199.136.173:8082. Alternativa sem túnel: registro A → 179.199.136.173 (proxy ligado).',
        ];
    }
    // 2) Cloudflare 52x com origem viva: firewall/rota/DNS errado.
    if ($code >= 520 && $code <= 530) {
        $nomes = [520 => 'resposta inválida da origem', 521 => 'origem recusou a conexão',
                  522 => 'tempo esgotado ao conectar na origem', 523 => 'origem inalcançável',
                  524 => 'origem demorou demais para responder', 525 => 'handshake SSL falhou',
                  526 => 'certificado da origem inválido', 530 => 'erro de roteamento/túnel'];
        $n = isset($nomes[$code]) ? $nomes[$code] : 'falha entre Cloudflare e origem';
        if ($sonda === true) {
            return ['tipo' => 'borda', 'titulo' => 'Cloudflare não alcança a origem (HTTP ' . $code . ', ' . $n . ')',
                'causa'  => 'O servidor está no ar, mas a Cloudflare não consegue chegar nele.',
                'explica'=> $vivaTx . 'Logo o problema está entre a borda da Cloudflare e o servidor: firewall bloqueando '
                          . 'os IPs da Cloudflare, registro DNS apontando para IP/túnel errado, porta 443/80 fechada '
                          . 'ou certificado de origem inválido.',
                'acao'   => 'Conferir o registro DNS do domínio no Cloudflare (A → 179.199.136.173 ou CNAME do túnel), '
                          . 'o ufw/aaPanel (80/443 abertas) e o modo SSL (Full). Se for túnel, ver cloudflared.'];
        }
        return ['tipo' => 'origem', 'titulo' => 'Servidor de origem fora (HTTP ' . $code . ', ' . $n . ')',
            'causa'  => 'A Cloudflare não alcançou o servidor e a sonda direta confirmou que ele não responde.',
            'explica'=> $vivaTx . 'Provável queda da VPS, do Apache ou da rede do datacenter.',
            'acao'   => 'Acessar a VPS por SSH; `systemctl status httpd traccar mysqld`; conferir `/var/log/traccar-watchdog.log`; '
                      . 'se o SSH também não entra, abrir a console do provedor e reiniciar a VPS.'];
    }
    // 3) Sem resposta nenhuma (timeout/DNS).
    if ($code === 0) {
        if ($sonda === true) {
            return ['tipo' => 'borda', 'titulo' => 'Domínio sem resposta, origem viva',
                'causa'  => 'O domínio não responde pela Cloudflare, mas o servidor responde direto no IP.',
                'explica'=> $vivaTx . 'Padrão de registro DNS apontando para um destino morto (servidor antigo desligado, '
                          . 'túnel sem cloudflared, IP trocado) ou de instabilidade na borda/rota até a Cloudflare. '
                          . 'Detalhe do curl: ' . (string) ($ev['explica'] ?? $motivo) . '.',
                'acao'   => 'Conferir no Cloudflare para onde o registro do domínio aponta; se for túnel, '
                          . '`systemctl status cloudflared` na VPS; se for IP antigo, trocar para 179.199.136.173.'];
        }
        return ['tipo' => ($sonda === false ? 'origem' : 'rede'), 'titulo' => 'Sem resposta (' . ($sonda === false ? 'servidor inacessível' : 'rede/DNS') . ')',
            'causa'  => 'Nenhuma resposta HTTP dentro do tempo limite.',
            'explica'=> $vivaTx . 'Detalhe do curl: ' . (string) ($ev['explica'] ?? $motivo) . '.',
            'acao'   => $sonda === false
                ? 'Verificar a VPS (console do provedor, ping 179.199.136.173, SSH).'
                : 'Configurar origem_ip no arquivo de credenciais do monitor para o próximo incidente ter veredito; '
                . 'checar DNS e conectividade deste servidor e da VPS.'];
    }
    // 4) Erro gerado pela própria origem.
    if ($code >= 500) {
        return ['tipo' => 'origem', 'titulo' => 'Origem respondeu erro HTTP ' . $code,
            'causa'  => 'O Apache/proxy da VPS respondeu, mas com erro — o Traccar (porta 8082) provavelmente caiu ou está reiniciando.',
            'explica'=> 'Erro 5xx produzido pelo servidor' . (stripos((string) ($ev['server'] ?? ''), 'cloudflare') !== false ? ' e repassado pela Cloudflare' : '')
                      . '. Etapa: ' . $etapa . '. ' . $vivaTx,
            'acao'   => '`systemctl status traccar`, `tail /opt/traccar/logs/tracker-server.log`, `/var/log/traccar-watchdog.log`; '
                      . 'o watchdog reinicia o Traccar sozinho em até 1 min; OOM/boot do Java leva ~90s.'];
    }
    // 5) Backend / login.
    if ($etapa === 'api/server') {
        return ['tipo' => 'origem', 'titulo' => 'Backend Traccar não responde (api/server HTTP ' . $code . ')',
            'causa'  => 'A página abre, mas a API do Traccar falha.',
            'explica'=> 'O Apache serve a interface, mas o processo Java do Traccar não respondeu na porta 8082 ou o banco MySQL está fora.',
            'acao'   => '`systemctl status traccar mysqld`; conferir HikariPool no log do Traccar; watchdog cobre reinício.'];
    }
    if ($etapa === 'api/session') {
        return ['tipo' => 'login', 'titulo' => 'Login falhou (HTTP ' . $code . ')',
            'causa'  => 'Site e API no ar, mas a autenticação do usuário de teste falhou.',
            'explica'=> 'HTTP 401/400 = senha/usuário alterados ou conta desativada; 429 = rate limit de login do Traccar; '
                      . '5xx = erro interno ao consultar o banco.',
            'acao'   => 'Conferir a credencial em /etc/monitor-traccar*.conf e o usuário no Traccar; se 429, aguardar.'];
    }
    return ['tipo' => $camada !== '' ? $camada : 'indeterminado', 'titulo' => $motivo,
        'causa' => 'Falha não classificada.', 'explica' => (string) ($ev['explica'] ?? $motivo) . ' ' . $vivaTx,
        'acao'  => 'Checar Traccar + Apache na VPS e o DNS no Cloudflare.'];
}

/* ===== CICLO DE VIDA ===== */

function dossie_abrir(int $inicio, string $motivo, array $ev): array
{
    $id = dossie_id_de($inicio);
    $d  = dossie_ler($id);
    if ($d) { return $d; } // já existe (reprocessamento)
    $diag = dossie_diagnosticar($ev, $motivo);
    $d = [
        'id'        => $id,
        'versao'    => 1,
        'alvo'      => ALVO_NOME,
        'alvo_url'  => ALVO_URL,
        'status'    => 'aberto',
        'inicio'    => $inicio,
        'inicio_txt'=> date('Y-m-d H:i:s', $inicio),
        'fim'       => 0,
        'fim_txt'   => '',
        'seg'       => 0,
        'motivo'    => $motivo,
        'evidencia' => $ev,
        'diagnostico' => $diag,
        'ciclos_falha'=> 1,
        'motivos'   => [$motivo => 1],
        'timeline'  => [[
            'ts' => $inicio, 'hora' => date('H:i:s', $inicio), 'tipo' => 'queda',
            'texto' => 'Queda confirmada (dupla checagem): ' . $motivo,
        ]],
        'avisos'    => [],
        'notas'     => [],
    ];
    if (!empty($ev['origem_texto'])) {
        $d['timeline'][] = ['ts' => $inicio, 'hora' => date('H:i:s', $inicio), 'tipo' => 'sonda',
            'texto' => 'Sonda na origem: ' . $ev['origem_texto']];
    }
    $d['timeline'][] = ['ts' => $inicio, 'hora' => date('H:i:s', $inicio), 'tipo' => 'diagnostico',
        'texto' => $diag['titulo'] . ' — ' . $diag['causa']];
    dossie_gravar($d);
    dossie_podar();
    return $d;
}

/* Chamado a cada ciclo em que o alvo segue fora. Conta ciclos, registra
 * mudança de motivo/camada e refina o diagnóstico se surgiu evidência melhor
 * (ex.: primeiro timeout, depois um 530 com CF-Ray). */
function dossie_atualizar(int $inicio, string $motivo, array $ev, int $ts): void
{
    $d = dossie_ler(dossie_id_de($inicio));
    if (!$d) { $d = dossie_abrir($inicio, $motivo, $ev); }
    $d['ciclos_falha'] = (int) ($d['ciclos_falha'] ?? 0) + 1;
    $d['motivos'][$motivo] = (int) ($d['motivos'][$motivo] ?? 0) + 1;
    $d['seg'] = max(0, $ts - $inicio);

    $mudouMotivo = ($motivo !== (string) $d['motivo']);
    $mudouRay    = !empty($ev['cf_ray']) && $ev['cf_ray'] !== (string) ($d['evidencia']['cf_ray'] ?? '');
    $mudouCamada = (string) ($ev['camada'] ?? '') !== (string) ($d['evidencia']['camada'] ?? '');
    if ($mudouMotivo || $mudouCamada) {
        $d['timeline'][] = ['ts' => $ts, 'hora' => date('H:i:s', $ts), 'tipo' => 'mudanca',
            'texto' => 'Sintoma mudou: ' . $motivo
                     . (!empty($ev['cf_ray']) ? ' (CF-Ray ' . $ev['cf_ray'] . ')' : '')];
        $d['motivo'] = $motivo;
        $novo = dossie_diagnosticar($ev, $motivo);
        if ($novo['tipo'] !== (string) ($d['diagnostico']['tipo'] ?? '')) {
            $d['diagnostico'] = $novo;
            $d['timeline'][] = ['ts' => $ts, 'hora' => date('H:i:s', $ts), 'tipo' => 'diagnostico',
                'texto' => 'Diagnóstico revisto: ' . $novo['titulo'] . ' — ' . $novo['causa']];
        }
    } elseif ($mudouRay) {
        $d['timeline'][] = ['ts' => $ts, 'hora' => date('H:i:s', $ts), 'tipo' => 'evidencia',
            'texto' => 'Novo CF-Ray: ' . $ev['cf_ray']];
    }
    // Evidência mais rica (com CF-Ray/erro CF) substitui a mais pobre.
    if ($mudouMotivo || $mudouCamada || $mudouRay || empty($d['evidencia'])) { $d['evidencia'] = $ev; }
    $d['timeline'] = array_slice($d['timeline'], -300);
    dossie_gravar($d);
}

function dossie_aviso(int $inicio, string $nivel, string $resultado, int $ts): void
{
    $d = dossie_ler(dossie_id_de($inicio));
    if (!$d) { return; }
    $d['avisos'][] = ['ts' => $ts, 'hora' => date('d/m H:i:s', $ts), 'nivel' => $nivel, 'resultado' => $resultado];
    $d['timeline'][] = ['ts' => $ts, 'hora' => date('H:i:s', $ts), 'tipo' => 'aviso',
        'texto' => 'Aviso "' . $nivel . '" → ' . $resultado];
    dossie_gravar($d);
}

function dossie_fechar(int $inicio, int $fim, int $seg): void
{
    $d = dossie_ler(dossie_id_de($inicio));
    if (!$d) { return; }
    $d['status']  = 'fechado';
    $d['fim']     = $fim;
    $d['fim_txt'] = date('Y-m-d H:i:s', $fim);
    $d['seg']     = $seg;
    $d['timeline'][] = ['ts' => $fim, 'hora' => date('H:i:s', $fim), 'tipo' => 'volta',
        'texto' => 'Normalizado: página, API e login respondendo. Duração total ' . dossie_dur($seg) . '.'];
    dossie_gravar($d);
}

/* Nota humana (CLI: php monitor.php --nota INC-XXXX "texto"). */
function dossie_nota(string $id, string $texto, string $autor = 'operador'): bool
{
    $d = dossie_ler($id);
    if (!$d) { return false; }
    $ts = time();
    $d['notas'][] = ['ts' => $ts, 'hora' => date('d/m H:i:s', $ts), 'autor' => $autor, 'texto' => $texto];
    $d['timeline'][] = ['ts' => $ts, 'hora' => date('H:i:s', $ts), 'tipo' => 'nota', 'texto' => $texto];
    dossie_gravar($d);
    return true;
}

function dossie_dur(int $seg): string
{
    if ($seg < 60)   { return $seg . 's'; }
    if ($seg < 3600) { return (int) floor($seg / 60) . 'min'; }
    return (int) floor($seg / 3600) . 'h' . str_pad((string) ((int) floor($seg / 60) % 60), 2, '0', STR_PAD_LEFT);
}
