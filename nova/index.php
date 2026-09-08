<?php
declare(strict_types=1);

/**
 * Painel da instância "nova" do Sentinela — Traccar novo (VPS 179.199.136.173).
 * Wrapper fino: define a instância e reaproveita TODO o painel original.
 * Lê estado.json/historico/ DESTA pasta, gravados pelo nova/monitor.php.
 */
define('ALVO_NOME',   'nova.monitoramento.top');
define('ALVO_LINK',   'https://nova.monitoramento.top/');
define('INST_DIR',    __DIR__);
define('PAINEL_PATH', '/monitoramentotop/nova/');

require dirname(__DIR__) . '/index.php';
