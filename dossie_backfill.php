<?php
declare(strict_types=1);
/* Cria dossiês para incidentes já registrados (estado.json de hoje + historico/).
 * Uso: php dossie_backfill.php [pasta-da-instancia]  — idempotente. */
date_default_timezone_set('America/Maceio');
$dir = realpath($argv[1] ?? __DIR__);
$cfg = ['ALVO_NOME' => 'monitoramento.top', 'ALVO_URL' => 'https://monitoramento.top/'];
if (basename($dir) === 'nova') { $cfg = ['ALVO_NOME' => 'nova.monitoramento.top', 'ALVO_URL' => 'https://nova.monitoramento.top/']; }
define('INST_DIR', $dir); define('ALVO_NOME', $cfg['ALVO_NOME']); define('ALVO_URL', $cfg['ALVO_URL']);
require __DIR__ . '/lib_dossie.php';
$incs = [];
foreach (glob($dir . '/historico/*.json') ?: [] as $h) { $j = json_decode((string) file_get_contents($h), true); foreach ((array) ($j['incidentes'] ?? []) as $i) { $incs[] = $i; } }
$e = json_decode((string) file_get_contents($dir . '/estado.json'), true);
foreach ((array) ($e['dia_incidentes'] ?? []) as $i) { $incs[] = $i; }
$n = 0;
foreach ($incs as $i) {
    $ini = (int) $i['inicio']; if ($ini <= 0) { continue; }
    if (dossie_ler(dossie_id_de($ini))) { continue; }
    dossie_abrir($ini, (string) $i['motivo'], (array) ($i['evidencia'] ?? []));
    if ((int) ($i['fim'] ?? 0) > 0 && empty($i['aberto_na_virada'])) { dossie_fechar($ini, (int) $i['fim'], (int) $i['seg']); }
    $n++;
}
echo ALVO_NOME . ": {$n} dossiês criados\n";
