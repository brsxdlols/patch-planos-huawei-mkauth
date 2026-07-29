<?php
declare(strict_types=1);

include('addons.class.php');
$link = isset($LOADMYSQL) && $LOADMYSQL instanceof mysqli ? $LOADMYSQL : null;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function failJson(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function formatRate(?float $bits): string
{
    if ($bits === null) {
        return 'Aguardando próximo Interim-Update';
    }

    $units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    $value = max(0, $bits);
    $unit = 0;
    while ($value >= 1000 && $unit < count($units) - 1) {
        $value /= 1000;
        $unit++;
    }
    return number_format($value, 2, ',', '.') . ' ' . $units[$unit];
}

function formatBytesValue(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, 2, ',', '.') . ' ' . $units[$unit];
}

function formatDuration(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return ($days > 0 ? $days . 'd ' : '') . sprintf('%02dh %02dm', $hours, $minutes);
}

if (!isset($link) || !($link instanceof mysqli)) {
    failJson('Conexão com o banco do MK-AUTH indisponível.', 500);
}

$nas = trim((string)($_GET['nas'] ?? ''));
if ($nas === '') {
    failJson('Selecione o NAS Huawei.');
}

$nasCheck = $link->prepare('SELECT shortname FROM nas WHERE nasname = ? LIMIT 1');
$nasCheck->bind_param('s', $nas);
$nasCheck->execute();
$nasRow = $nasCheck->get_result()->fetch_assoc();
$nasCheck->close();
if (!$nasRow) {
    failJson('NAS não cadastrado no MK-AUTH.');
}

$sql = <<<'SQL'
SELECT
    r.radacctid,
    r.acctuniqueid,
    r.username,
    COALESCE(c.nome, '') AS nome,
    COALESCE(c.plano, r.groupname, '') AS plano,
    r.framedipaddress,
    r.callingstationid,
    r.nasportid,
    r.acctstarttime,
    r.acctupdatetime,
    COALESCE(r.acctsessiontime, 0) AS acctsessiontime,
    COALESCE(r.acctinputoctets, 0) AS acctinputoctets,
    COALESCE(r.acctoutputoctets, 0) AS acctoutputoctets,
    COALESCE(r.acctinterval, 0) AS acctinterval,
    TIMESTAMPDIFF(SECOND, r.acctupdatetime, NOW()) AS stale_seconds
FROM radacct r
LEFT JOIN sis_cliente c ON BINARY c.login = BINARY r.username
WHERE r.acctstoptime IS NULL
  AND BINARY r.nasipaddress = BINARY ?
ORDER BY r.acctupdatetime DESC, r.username
SQL;

$stmt = $link->prepare($sql);
$stmt->bind_param('s', $nas);
$stmt->execute();
$result = $stmt->get_result();

$current = [];
$rows = [];
while ($row = $result->fetch_assoc()) {
    $key = (string)($row['acctuniqueid'] ?: $row['radacctid']);
    $current[$key] = [
        'updated' => (string)$row['acctupdatetime'],
        'input' => (int)$row['acctinputoctets'],
        'output' => (int)$row['acctoutputoctets'],
    ];
    $rows[$key] = $row;
}
$stmt->close();

$cacheFile = sys_get_temp_dir() . '/mkauth-huawei-online-' . hash('sha256', $nas) . '.json';
$lockFile = $cacheFile . '.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    failJson('Não foi possível bloquear o cache de medição.', 500);
}

$previous = [];
if (is_file($cacheFile)) {
    $decoded = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($decoded)) {
        $previous = $decoded;
    }
}

$sessions = [];
$totalInput = 0;
$totalOutput = 0;
$totalInputBps = 0.0;
$totalOutputBps = 0.0;
$measured = 0;

foreach ($rows as $key => $row) {
    $inputBps = null;
    $outputBps = null;
    if (isset($previous[$key])) {
        $oldTime = strtotime((string)$previous[$key]['updated']);
        $newTime = strtotime((string)$row['acctupdatetime']);
        $elapsed = $newTime - $oldTime;
        $inputDelta = (int)$row['acctinputoctets'] - (int)$previous[$key]['input'];
        $outputDelta = (int)$row['acctoutputoctets'] - (int)$previous[$key]['output'];
        if ($elapsed > 0 && $inputDelta >= 0 && $outputDelta >= 0) {
            $inputBps = ($inputDelta * 8) / $elapsed;
            $outputBps = ($outputDelta * 8) / $elapsed;
            $measured++;
            $totalInputBps += $inputBps;
            $totalOutputBps += $outputBps;
        }
    }

    $input = (int)$row['acctinputoctets'];
    $output = (int)$row['acctoutputoctets'];
    $totalInput += $input;
    $totalOutput += $output;

    $sessions[] = [
        'login' => (string)$row['username'],
        'nome' => (string)$row['nome'],
        'plano' => (string)$row['plano'],
        'ip' => (string)$row['framedipaddress'],
        'mac' => strtolower((string)$row['callingstationid']),
        'porta' => (string)$row['nasportid'],
        'inicio' => (string)$row['acctstarttime'],
        'atualizado' => (string)$row['acctupdatetime'],
        'intervalo' => (int)$row['acctinterval'],
        'atraso' => max(0, (int)$row['stale_seconds']),
        'online' => formatDuration((int)$row['acctsessiontime']),
        'upload_bytes' => $input,
        'download_bytes' => $output,
        'upload_total' => formatBytesValue($input),
        'download_total' => formatBytesValue($output),
        'upload_bps' => $inputBps,
        'download_bps' => $outputBps,
        'upload_taxa' => formatRate($inputBps),
        'download_taxa' => formatRate($outputBps),
    ];
}

$tmpFile = $cacheFile . '.' . getmypid();
file_put_contents($tmpFile, json_encode($current), LOCK_EX);
rename($tmpFile, $cacheFile);
flock($lock, LOCK_UN);
fclose($lock);

echo json_encode([
    'ok' => true,
    'nas' => $nas,
    'nas_nome' => (string)$nasRow['shortname'],
    'gerado_em' => date('Y-m-d H:i:s'),
    'resumo' => [
        'online' => count($sessions),
        'medidos' => $measured,
        'upload_bps' => $totalInputBps,
        'download_bps' => $totalOutputBps,
        'upload_taxa' => formatRate($measured ? $totalInputBps : null),
        'download_taxa' => formatRate($measured ? $totalOutputBps : null),
        'upload_total' => formatBytesValue($totalInput),
        'download_total' => formatBytesValue($totalOutput),
    ],
    'sessoes' => $sessions,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
