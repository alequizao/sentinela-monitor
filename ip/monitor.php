<?php
declare(strict_types=1);
/**
 * Instância "ip" do Sentinela — Traccar da VPS nova DIRETO pelo IP (porta 8082),
 * sem passar pela Cloudflare. Separa "o servidor caiu" de "o domínio/túnel caiu".
 */
define('ALVO_URL',   'http://179.199.136.173:8082/');
define('ALVO_NOME',  '179.199.136.173:8082 (IP direto)');
define('INST_DIR',   __DIR__);
define('PAINEL_URL', is_dir('/www/wwwroot/alequizao.com/agendamentos') ? 'https://alequizao.com/monitoramentotop/ip/' : 'https://publishdev.com.br/monitoramentotop/ip/');
define('CRED_ARQ',   '/etc/monitor-traccar-nova.conf');
require dirname(__DIR__) . '/monitor.php';
