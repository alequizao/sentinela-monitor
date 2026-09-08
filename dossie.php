<?php
declare(strict_types=1);

/**
 * Dossiê de incidentes — https://publishdev.com.br/monitoramentotop/dossie.php
 * ---------------------------------------------------------------------------
 * Lê <instância>/dossie/INC-*.json gravados pelo monitor.php (lib_dossie.php).
 * Lista todos os incidentes (mais recente primeiro) e abre um prontuário por
 * ?id=INC-XXXX com: diagnóstico, causa, ação recomendada, evidência técnica,
 * linha do tempo, avisos disparados e notas do operador. ?id=X&json=1 exporta.
 *
 * Wrapper por instância: nova/dossie.php define as constantes e dá require aqui.
 * Vhost em PHP 7.4 — sem sintaxe 8.x. Padrão visual Designi Alequizao.
 */

date_default_timezone_set('America/Maceio');

if (!defined('ALVO_NOME'))   { define('ALVO_NOME', 'monitoramento.top'); }
if (!defined('ALVO_URL'))    { define('ALVO_URL', 'https://monitoramento.top/'); }
if (!defined('INST_DIR'))    { define('INST_DIR', __DIR__); }
if (!defined('PAINEL_PATH')) { define('PAINEL_PATH', '/monitoramentotop/'); }

require_once dirname(__FILE__) . '/lib_dossie.php';

const INSTANCIAS = [
    ['nome' => 'monitoramento.top',      'path' => '/monitoramentotop/'],
    ['nome' => 'nova.monitoramento.top', 'path' => '/monitoramentotop/nova/'],
];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function dbr(int $ts): string { return date('d/m/Y H:i:s', $ts); }
/* Esconde IPs internos/senhas do texto que vai para a tela. */
function limpar(string $s): string
{
    return preg_replace('/senha\s*=\s*\S+/i', 'senha=***', $s) ?? $s;
}
/* Trechos entre crases viram <code>. */
function fmt(string $s): string
{
    return preg_replace('/`([^`]+)`/', '<code>$1</code>', h($s)) ?? h($s);
}

$id   = isset($_GET['id']) ? preg_replace('/[^A-Za-z0-9\-]/', '', (string) $_GET['id']) : '';
$json = isset($_GET['json']);
$busca = trim((string) ($_GET['q'] ?? ''));

if ($id !== '' && $json) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $id . '.json"');
    echo json_encode(dossie_ler($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tipos = [
    'cloudflare-tunnel' => ['Cloudflare Tunnel', 't-cf'],
    'borda'   => ['Cloudflare ↔ origem', 't-borda'],
    'origem'  => ['Servidor de origem', 't-origem'],
    'rede'    => ['Rede / sem resposta', 't-rede'],
    'login'   => ['Login', 't-login'],
];
function tipo_pill(string $tipo): string
{
    global $tipos;
    $t = isset($tipos[$tipo]) ? $tipos[$tipo] : ['Indeterminado', 't-ind'];
    return '<span class="pill ' . $t[1] . '">' . h($t[0]) . '</span>';
}
$icones = ['queda' => 'fa-triangle-exclamation', 'sonda' => 'fa-crosshairs', 'diagnostico' => 'fa-stethoscope',
           'mudanca' => 'fa-shuffle', 'evidencia' => 'fa-fingerprint', 'aviso' => 'fa-bell',
           'volta' => 'fa-circle-check', 'nota' => 'fa-pen'];

$D = $id !== '' ? dossie_ler($id) : [];
$lista = $id === '' ? dossie_listar(300) : [];
if ($busca !== '' && $lista) {
    $lista = array_values(array_filter($lista, static function ($d) use ($busca) {
        $txt = mb_strtolower(json_encode($d, JSON_UNESCAPED_UNICODE) ?: '', 'UTF-8');
        return mb_strpos($txt, mb_strtolower($busca, 'UTF-8')) !== false;
    }));
}
$abertos = 0; $total_seg = 0;
foreach ($lista as $d) { if ($d['status'] === 'aberto') { $abertos++; } $total_seg += (int) $d['seg']; }
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dossiê de incidentes · <?= h(ALVO_NOME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--azul:#2563EB;--azul-claro:#EFF4FF;--fundo:#F5F6FA;--card:#fff;--ink:#0F172A;--sec:#64748B;--linha:#E5E7EB;
--ok:#16A34A;--ok-bg:#ECFDF3;--down:#DC2626;--down-bg:#FEF2F2;--amarelo:#D97706;--amarelo-bg:#FFFBEB;--r:14px}
*{box-sizing:border-box}body{margin:0;background:var(--fundo);color:var(--ink);font-family:Inter,system-ui,sans-serif;font-size:14px;line-height:1.5}
a{color:var(--azul);text-decoration:none}a:hover{text-decoration:underline}
.wrap{max-width:1100px;margin:0 auto;padding:22px 16px 60px}
.topo{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:18px}
h1{font-size:22px;margin:0 0 4px;display:flex;align-items:center;gap:10px}h1 i{color:var(--azul)}
.topo p{margin:0;color:var(--sec)}
.instancias{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.inst-pill,.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid var(--linha);background:var(--card);color:var(--ink);font-weight:500;font-size:13px}
.inst-pill.ativo{background:var(--azul);border-color:var(--azul);color:#fff}
.btn:hover,.inst-pill:hover{text-decoration:none;border-color:var(--azul);color:var(--azul)}.inst-pill.ativo:hover{color:#fff}
.card{background:var(--card);border-radius:var(--r);box-shadow:0 1px 3px rgba(15,23,42,.06),0 8px 24px rgba(15,23,42,.04);padding:18px 20px;margin-bottom:16px}
.card h2{font-size:15px;margin:0 0 12px;display:flex;align-items:center;gap:8px}.card h2 i{color:var(--azul)}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.kpi{background:var(--card);border-radius:var(--r);padding:14px 16px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
.kpi small{display:block;color:var(--sec);font-size:12px}.kpi strong{font-size:22px;font-weight:700}
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.t-cf{background:#FFF1E6;color:#C2410C}.t-borda{background:#FEF3C7;color:#92400E}.t-origem{background:var(--down-bg);color:var(--down)}
.t-rede{background:#EDE9FE;color:#6D28D9}.t-login{background:#E0F2FE;color:#0369A1}.t-ind{background:#F1F5F9;color:var(--sec)}
.b-ok{background:var(--ok-bg);color:var(--ok)}.b-down{background:var(--down-bg);color:var(--down)}
.inc{display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;padding:12px 0;border-top:1px solid var(--linha)}
.inc:first-of-type{border-top:0}.inc-ico{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px}
.inc-ico.ok{background:var(--ok-bg);color:var(--ok)}.inc-ico.down{background:var(--down-bg);color:var(--down)}
.inc strong{display:block}.inc .sub{color:var(--sec);font-size:13px}.inc .id{font-family:ui-monospace,monospace;font-size:12px;color:var(--sec)}
.inc .dir{text-align:right;font-size:13px}
.busca{display:flex;gap:8px;margin-bottom:14px}.busca input{flex:1;padding:9px 12px;border:1px solid var(--linha);border-radius:10px;font:inherit}
.busca button{padding:9px 14px;border:0;border-radius:10px;background:var(--azul);color:#fff;font:inherit;font-weight:600}
.vazio{text-align:center;color:var(--sec);padding:30px 0}
.diag{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:720px){.diag{grid-template-columns:1fr}.inc{grid-template-columns:auto 1fr}.inc .dir{grid-column:2;text-align:left}}
.bloco{background:var(--fundo);border-radius:12px;padding:12px 14px}.bloco small{display:block;color:var(--sec);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:4px}
.bloco.acao{background:var(--azul-claro)}code{background:#E2E8F0;padding:1px 5px;border-radius:5px;font-size:12px}
.acao code{background:#DBEAFE}
table.ev{width:100%;border-collapse:collapse;font-size:13px}table.ev td{padding:6px 8px;border-top:1px solid var(--linha);vertical-align:top}table.ev td:first-child{color:var(--sec);width:34%}
.ray{font-family:ui-monospace,monospace}
.tl{list-style:none;margin:0;padding:0}.tl li{display:grid;grid-template-columns:70px 28px 1fr;gap:8px;padding:7px 0;border-top:1px solid var(--linha);align-items:start}
.tl li:first-child{border-top:0}.tl .h{font-family:ui-monospace,monospace;color:var(--sec);font-size:12px;padding-top:2px}.tl i{color:var(--azul);padding-top:3px}
.tl .queda i{color:var(--down)}.tl .volta i{color:var(--ok)}.tl .aviso i{color:var(--amarelo)}
.status-big{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.nota{background:var(--amarelo-bg);border-radius:10px;padding:10px 12px;margin-top:8px}.nota small{color:var(--sec)}
.dica{color:var(--sec);font-size:12px;margin-top:10px}
</style>
</head>
<body><div class="wrap">
<div class="topo">
  <div>
    <h1><i class="fa-solid fa-folder-open"></i>Dossiê de incidentes</h1>
    <p>Prontuário de cada queda de <strong><?= h(ALVO_NOME) ?></strong>: causa, evidência, linha do tempo e o que fazer.</p>
    <nav class="instancias">
      <?php foreach (INSTANCIAS as $inst): ?>
        <?php if ($inst['path'] === PAINEL_PATH): ?><span class="inst-pill ativo"><i class="fa-solid fa-satellite-dish"></i> <?= h($inst['nome']) ?></span>
        <?php else: ?><a class="inst-pill" href="<?= h($inst['path']) ?>dossie.php"><i class="fa-solid fa-satellite-dish"></i> <?= h($inst['nome']) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn" href="<?= h(PAINEL_PATH) ?>"><i class="fa-solid fa-shield-halved"></i> Painel Sentinela</a>
    <?php if ($id !== ''): ?><a class="btn" href="<?= h(PAINEL_PATH) ?>dossie.php"><i class="fa-solid fa-list"></i> Todos</a>
    <a class="btn" href="?id=<?= h($id) ?>&amp;json=1"><i class="fa-solid fa-download"></i> JSON</a><?php endif; ?>
  </div>
</div>

<?php if ($id !== ''): ?>
  <?php if (!$D): ?>
    <div class="card"><div class="vazio"><i class="fa-solid fa-file-circle-question"></i><p>Dossiê <?= h($id) ?> não encontrado nesta instância.</p></div></div>
  <?php else:
    $aberto = $D['status'] === 'aberto'; $dg = (array) $D['diagnostico']; $ev = (array) $D['evidencia']; ?>
    <div class="card">
      <div class="status-big">
        <span class="pill <?= $aberto ? 'b-down' : 'b-ok' ?>" style="font-size:13px;padding:5px 12px"><i class="fa-solid <?= $aberto ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i> <?= $aberto ? 'EM ANDAMENTO' : 'RESOLVIDO' ?></span>
        <?= tipo_pill((string) ($dg['tipo'] ?? '')) ?>
        <span class="id ray"><?= h($D['id']) ?></span>
      </div>
      <h2 style="font-size:18px;margin:12px 0 6px"><?= h((string) $dg['titulo']) ?></h2>
      <p style="margin:0;color:var(--sec)">
        Início <strong><?= dbr((int) $D['inicio']) ?></strong>
        <?= $aberto ? '· ainda fora · ' . h(dossie_dur(max(0, time() - (int) $D['inicio']))) . ' até agora'
                    : '· fim <strong>' . dbr((int) $D['fim']) . '</strong> · duração <strong>' . h(dossie_dur((int) $D['seg'])) . '</strong>' ?>
        · <?= (int) $D['ciclos_falha'] ?> checagens com falha
      </p>
    </div>

    <div class="card"><h2><i class="fa-solid fa-stethoscope"></i>Diagnóstico</h2>
      <div class="diag">
        <div class="bloco"><small>Causa provável</small><?= fmt(limpar((string) $dg['causa'])) ?></div>
        <div class="bloco acao"><small>O que fazer</small><?= fmt(limpar((string) $dg['acao'])) ?></div>
      </div>
      <p style="margin:12px 0 0"><?= fmt(limpar((string) $dg['explica'])) ?></p>
    </div>

    <div class="card"><h2><i class="fa-solid fa-fingerprint"></i>Evidência técnica</h2>
      <table class="ev">
        <tr><td>Sintoma reportado</td><td><?= h(limpar((string) $D['motivo'])) ?></td></tr>
        <tr><td>Etapa da checagem</td><td><?= h((string) ($ev['etapa'] ?? '-')) ?></td></tr>
        <tr><td>HTTP / tempo</td><td><?= (int) ($ev['code'] ?? 0) ?> · <?= (int) ($ev['ms'] ?? 0) ?> ms</td></tr>
        <?php if (!empty($ev['cf_erro'])): ?><tr><td>Erro Cloudflare</td><td><strong><?= (int) $ev['cf_erro'] ?></strong></td></tr><?php endif; ?>
        <tr><td>Servidor (header)</td><td><?= h((string) (($ev['server'] ?? '') !== '' ? $ev['server'] : '-')) ?></td></tr>
        <?php if (!empty($ev['cf_ray'])): ?><tr><td>CF-Ray</td><td class="ray"><?= h((string) $ev['cf_ray']) ?> <span class="dica" style="display:inline">(use ao abrir chamado na Cloudflare)</span></td></tr><?php endif; ?>
        <tr><td>Camada</td><td><?= h((string) ($ev['camada'] ?? '-')) ?></td></tr>
        <tr><td>Sonda direta na origem</td><td><?= h((string) ($ev['origem_texto'] ?? 'não rodou')) ?></td></tr>
        <?php if (count((array) $D['motivos']) > 1): ?>
        <tr><td>Sintomas ao longo do incidente</td><td><?php foreach ((array) $D['motivos'] as $m => $n): ?><div><?= (int) $n ?>× <?= h(limpar((string) $m)) ?></div><?php endforeach; ?></td></tr>
        <?php endif; ?>
      </table>
    </div>

    <div class="card"><h2><i class="fa-solid fa-timeline"></i>Linha do tempo</h2>
      <ul class="tl">
        <?php foreach ((array) $D['timeline'] as $t): $tp = (string) $t['tipo']; ?>
        <li class="<?= h($tp) ?>"><span class="h"><?= h((string) $t['hora']) ?></span><i class="fa-solid <?= h($icones[$tp] ?? 'fa-circle') ?>"></i><span><?= fmt(limpar((string) $t['texto'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card"><h2><i class="fa-solid fa-bell"></i>Avisos disparados</h2>
      <?php if (!$D['avisos']): ?><p class="vazio" style="padding:8px 0">Nenhum aviso saiu (carência de 5 min, cooldown ou janela do Direct fechada).</p>
      <?php else: ?><table class="ev"><?php foreach ((array) $D['avisos'] as $a): ?>
        <tr><td><?= h((string) $a['hora']) ?></td><td><strong><?= h((string) $a['nivel']) ?></strong> → <?= h((string) $a['resultado']) ?></td></tr>
      <?php endforeach; ?></table><?php endif; ?>
    </div>

    <div class="card"><h2><i class="fa-solid fa-pen"></i>Notas do operador</h2>
      <?php if (!$D['notas']): ?><p class="vazio" style="padding:8px 0">Sem notas.</p><?php endif; ?>
      <?php foreach ((array) $D['notas'] as $n): ?><div class="nota"><small><?= h((string) $n['hora']) ?> · <?= h((string) $n['autor']) ?></small><br><?= fmt(limpar((string) $n['texto'])) ?></div><?php endforeach; ?>
      <p class="dica">Para anotar: <code>php monitor.php --nota <?= h($D['id']) ?> "texto"</code> na pasta da instância.</p>
    </div>
  <?php endif; ?>

<?php else: ?>
  <div class="cards">
    <div class="kpi"><small>Dossiês</small><strong><?= count($lista) ?></strong></div>
    <div class="kpi"><small>Em andamento</small><strong style="color:<?= $abertos ? 'var(--down)' : 'var(--ok)' ?>"><?= $abertos ?></strong></div>
    <div class="kpi"><small>Tempo fora (total)</small><strong><?= h(dossie_dur($total_seg)) ?></strong></div>
    <div class="kpi"><small>Último</small><strong style="font-size:15px"><?= $lista ? dbr((int) $lista[0]['inicio']) : '—' ?></strong></div>
  </div>
  <div class="card">
    <form class="busca" method="get"><input type="search" name="q" value="<?= h($busca) ?>" placeholder="Buscar por INC, causa, CF-Ray, data…"><button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button></form>
    <?php if (!$lista): ?><div class="vazio"><i class="fa-solid fa-shield-heart"></i><p><?= $busca !== '' ? 'Nada combina com a busca.' : 'Nenhum incidente registrado ainda. O dossiê é criado automaticamente na primeira queda confirmada.' ?></p></div>
    <?php else: foreach ($lista as $d): $ab = $d['status'] === 'aberto'; $dg = (array) $d['diagnostico']; ?>
      <div class="inc">
        <div class="inc-ico <?= $ab ? 'down' : 'ok' ?>"><i class="fa-solid <?= $ab ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i></div>
        <div>
          <strong><a href="?id=<?= h($d['id']) ?>"><?= h((string) $dg['titulo']) ?></a></strong>
          <span class="sub"><?= dbr((int) $d['inicio']) ?> <?= $ab ? '→ agora' : '→ ' . date('H:i:s', (int) $d['fim']) ?> · <?= h(limpar((string) $dg['causa'])) ?></span>
        </div>
        <div class="dir"><?= tipo_pill((string) ($dg['tipo'] ?? '')) ?><br><span class="pill <?= $ab ? 'b-down' : 'b-ok' ?>" style="margin-top:4px"><?= $ab ? 'em andamento' : h(dossie_dur((int) $d['seg'])) ?></span><br><span class="id"><?= h($d['id']) ?></span></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
<?php endif; ?>
</div></body></html>
