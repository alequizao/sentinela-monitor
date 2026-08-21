<?php
declare(strict_types=1);

/**
 * Reconstrução do histórico a partir do monitor.log  (uso: php historico_backfill.php [--forcar])
 * ---------------------------------------------------------------------------------------------
 * O arquivamento por dia (arquivar_dia() no monitor.php) só começa a valer da
 * próxima virada de dia em diante. Este utilitário CLI olha o monitor.log e
 * reconstrói os dias que ficaram para trás, para o filtro por data do painel já
 * nascer com histórico.
 *
 * PRECISÃO: o log só registra mudanças de estado, falhas isoladas e o resumo do
 * último ciclo — então incidentes e tempo fora saem fiéis, mas o TOTAL de
 * checagens do dia é uma estimativa (INTERVALO_SEG entre ciclos). Cada arquivo
 * reconstruído leva "origem":"log" e "estimado":true, e o painel marca esses
 * dias como reconstruídos. Dias que já têm arquivo não são tocados (a não ser
 * com --forcar), para nunca sobrescrever número real com estimativa.
 *
 * Não envia nada, não altera estado.json e nunca mexe no dia de hoje (que o
 * painel lê ao vivo do estado.json).
 */

date_default_timezone_set('America/Maceio');

const INTERVALO_SEG = 5;    // mesmo passo do monitor.php (para estimar checagens)
const LACUNA_MAX    = 300;  // s sem log = monitor parado; não conta como fora

$dir    = __DIR__;
$log    = $dir . '/monitor.log';
$hist   = $dir . '/historico';
$forcar = in_array('--forcar', $argv, true);

if (!is_readable($log)) { fwrite(STDERR, "monitor.log não encontrado.\n"); exit(1); }
if (!is_dir($hist) && !mkdir($hist, 0755, true) && !is_dir($hist)) {
    fwrite(STDERR, "não consegui criar {$hist}\n"); exit(1);
}

/* ---- 1) Lê o log em eventos (ts, estado, detalhe) ---- */
$eventos = [];
foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+) :: (.*)$/', $linha, $m)) {
        continue;
    }
    $ts   = (int) strtotime($m[1]);
    $tipo = $m[2];
    $det  = preg_replace('/ :: AVISO\(.*\)$/', '', $m[3]);
    if ($tipo !== 'OK' && $tipo !== 'DOWN' && $tipo !== 'SUSPEITA') { continue; }
    $eventos[] = ['ts' => $ts, 'tipo' => $tipo, 'det' => (string) $det];
}
if (!$eventos) { fwrite(STDERR, "nenhuma linha reconhecida no log.\n"); exit(1); }

/* ---- 2) Varre em ordem montando incidentes (DOWN … até o próximo OK) ---- */
$dias = [];   // data => acumulador
function dia_ref(array &$dias, string $data): array
{
    if (!isset($dias[$data])) {
        $dias[$data] = ['falhas' => 0, 'blips' => 0, 'seg_fora' => 0,
                        'incidentes' => [], 'primeiro' => 0, 'ultimo' => 0];
    }
    return $dias[$data];
}

$aberto = null;   // ['inicio'=>ts,'motivo'=>...]
foreach ($eventos as $ev) {
    $data = date('Y-m-d', $ev['ts']);
    dia_ref($dias, $data);
    if ($dias[$data]['primeiro'] === 0) { $dias[$data]['primeiro'] = $ev['ts']; }
    $dias[$data]['ultimo'] = $ev['ts'];

    if ($ev['tipo'] === 'SUSPEITA') { $dias[$data]['blips']++; continue; }

    if ($ev['tipo'] === 'DOWN') {
        $dias[$data]['falhas']++;
        if ($aberto === null) { $aberto = ['inicio' => $ev['ts'], 'motivo' => $ev['det']]; }
        continue;
    }

    // OK: fecha o incidente aberto, se houver.
    if ($aberto !== null) {
        $ini = (int) $aberto['inicio'];
        $fim = $ev['ts'];
        // Lacuna gigante = monitor parado, não queda comprovada: corta o excesso.
        if ($fim - $ini > LACUNA_MAX * 4) { $fim = $ini + LACUNA_MAX; }
        // Incidente que cruza a meia-noite é dividido entre os dois dias.
        $cursor = $ini;
        while ($cursor < $fim) {
            $d        = date('Y-m-d', $cursor);
            $fim_dia  = (int) strtotime($d . ' 23:59:59') + 1;
            $corte    = min($fim, $fim_dia);
            dia_ref($dias, $d);
            $dias[$d]['seg_fora'] += max(0, $corte - $cursor);
            $dias[$d]['incidentes'][] = [
                'inicio' => $cursor, 'fim' => $corte,
                'seg'    => max(0, $corte - $cursor),
                'motivo' => (string) $aberto['motivo'],
                'aberto_na_virada' => ($corte < $fim),
            ];
            $cursor = $corte;
        }
        $aberto = null;
    }
}

/* ---- 3) Grava um JSON por dia (nunca o de hoje: esse é ao vivo) ---- */
$hoje  = date('Y-m-d');
$feitos = $pulados = 0;
ksort($dias);
foreach ($dias as $data => $d) {
    if ($data >= $hoje) { continue; }
    $arq = $hist . '/' . $data . '.json';
    if (is_file($arq) && !$forcar) {
        // Já existe: só sobrescreve se o que está lá também é reconstruído.
        $atual = json_decode((string) file_get_contents($arq), true);
        if (!is_array($atual) || ($atual['origem'] ?? '') !== 'log') { $pulados++; continue; }
    }

    $ini_dia  = (int) strtotime($data . ' 00:00:00');
    $fim_dia  = (int) strtotime($data . ' 23:59:59');
    // Cobertura real observada no log (o primeiro e o último dia são parciais).
    $cob_ini  = max($ini_dia, $d['primeiro'] ?: $ini_dia);
    $cob_fim  = min($fim_dia, $d['ultimo'] ?: $fim_dia);
    $seg_dia  = max(1, $cob_fim - $cob_ini + 1);
    $seg_fora = min($d['seg_fora'], $seg_dia);

    $resumo = [
        'data'       => $data,
        'checagens'  => (int) floor($seg_dia / INTERVALO_SEG),   // estimativa
        'falhas'     => $d['falhas'],
        'blips'      => $d['blips'],
        'seg_fora'   => $seg_fora,
        'seg_dia'    => $seg_dia,
        'disp'       => round(max(0, 100 - ($seg_fora / $seg_dia * 100)), 4),
        'avisos'     => 0,
        'incidentes' => $d['incidentes'],
        'status_fim' => 'ok',
        'gerado_em'  => date('Y-m-d H:i:s'),
        'origem'     => 'log',
        'estimado'   => true,
        'versao'     => 1,
    ];
    file_put_contents($arq, json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $feitos++;
    printf("%s  disp %6.2f%%  fora %5ds  incidentes %2d  (estimado)\n",
        $data, $resumo['disp'], $seg_fora, count($d['incidentes']));
}

echo "\n{$feitos} dia(s) reconstruído(s), {$pulados} preservado(s) (já tinham dado real).\n";
