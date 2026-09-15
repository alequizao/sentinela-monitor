<?php
declare(strict_types=1);
/*
 * Sentinela — monitor do Traccar · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/** Dossiê da instância "nova" — wrapper fino sobre ../dossie.php. */
define('ALVO_NOME',   'nova.monitoramento.top');
define('ALVO_URL',    'https://nova.monitoramento.top/');
define('INST_DIR',    __DIR__);
define('PAINEL_PATH', '/monitoramentotop/nova/');
require dirname(__DIR__) . '/dossie.php';
