<?php
declare(strict_types=1);

/**
 * Instância "nova" do Sentinela — Traccar novo (VPS 179.199.136.173).
 * Wrapper fino: define o alvo e reaproveita TODO o monitor da instância
 * original. Estado, histórico, log e inscrições push ficam NESTA pasta.
 */
define('ALVO_URL',   'https://nova.monitoramento.top/');
define('ALVO_NOME',  'nova.monitoramento.top');
define('INST_DIR',   __DIR__);
define('PAINEL_URL', is_dir('/www/wwwroot/alequizao.com/agendamentos') ? 'https://alequizao.com/monitoramentotop/nova/' : 'https://publishdev.com.br/monitoramentotop/nova/');
define('CRED_ARQ',   '/etc/monitor-traccar-nova.conf');

require dirname(__DIR__) . '/monitor.php';
