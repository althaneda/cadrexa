<?php
header('Content-Type: application/json');
session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Calculate stats from speeds array
function computeStats(array $speeds): array {
    $n = count($speeds);
    if ($n === 0) return [
        'count' => 0, 'avgSpeed' => null, 'weightedAvg' => null,
        'maxSpeed' => null, 'minSpeed' => null, 'stability' => null, 'stddev' => null,
    ];

    $avg = array_sum($speeds) / $n;

    // Weighted average of last 10 speeds, more weight on recent ones
    $recent  = array_slice($speeds, -10);
    $m       = count($recent);
    $wSum = $wTotal = 0;
    foreach ($recent as $i => $s) { $w = $i + 1; $wSum += $s * $w; $wTotal += $w; }
    $weightedAvg = $wSum / $wTotal;

    // Stability: based on coefficient of variation (stddev/avg), scaled to 0-100
    $variance = array_sum(array_map(fn($s) => ($s - $avg) ** 2, $speeds)) / $n;
    $stddev   = sqrt($variance);
    $cv       = $avg > 0 ? $stddev / $avg : 0;
    $stability = max(0, min(100, (int)round(100 - $cv * 200)));

    return [
        'count'       => $n,
        'avgSpeed'    => round($avg, 3),
        'weightedAvg' => round($weightedAvg, 3),
        'maxSpeed'    => round(max($speeds), 3),
        'minSpeed'    => round(min($speeds), 3),
        'stability'   => $stability,
        'stddev'      => round($stddev, 3),
    ];
}

// Session history can grow indefinitely, so we trim it to the most recent 100 entries
function trimHistory(array $h, int $max = 100): array {
    return count($h) > $max ? array_slice($h, -$max) : $h;
}

try { switch ($action) {

    case 'set_lang':
        $lang = in_array($_POST['lang'] ?? '', ['zh','en']) ? $_POST['lang'] : 'zh';
        $_SESSION['lang'] = $lang;
        setcookie('cf_lang', $lang, time() + 86400 * 365, '/');
        echo json_encode(['ok' => true, 'lang' => $lang]);
        break;

    case 'get_data':
        $history = $_SESSION['speed_history'] ?? [];
        $speeds  = array_column($history, 'speed');
        $stats   = computeStats($speeds);
        echo json_encode(['ok' => true, 'history' => $history] + $stats);
        break;

    case 'save_speed':
        $speed = floatval($_POST['speed'] ?? 0);
        if ($speed <= 0 || $speed > 50) { echo json_encode(['ok' => false]); break; }

        $history   = $_SESSION['speed_history'] ?? [];
        $history[] = ['speed' => round($speed, 3), 'ts' => time()];
        $history   = trimHistory($history);
        $_SESSION['speed_history'] = $history;

        $speeds = array_column($history, 'speed');
        $stats  = computeStats($speeds);
        $_SESSION['avg_speed']  = $stats['weightedAvg'];
        $_SESSION['test_count'] = $stats['count'];

        echo json_encode(['ok' => true, 'lastSpeed' => round($speed, 3), 'history' => $history] + $stats);
        break;

    case 'clear_history':
        $_SESSION['speed_history'] = [];
        $_SESSION['test_count']    = 0;
        $_SESSION['avg_speed']     = null;
        echo json_encode(['ok' => true]);
        break;

    case 'get_stats':
        $history = $_SESSION['speed_history'] ?? [];
        $speeds  = array_column($history, 'speed');
        $stats   = computeStats($speeds);
        echo json_encode(['ok' => true, 'history' => $history] + $stats);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'unknown action']);

    case 'export_profile':
        $history = $_SESSION['speed_history'] ?? [];
        if (empty($history)) {
            echo json_encode(['ok' => false, 'error' => 'no_data']);
            break;
        }
        $speeds = array_column($history, 'speed');
        // Encode: version + speeds array, URL-safe base64
        $payload = json_encode(['v' => 1, 'speeds' => $speeds]);
        $code    = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        echo json_encode(['ok' => true, 'code' => $code]);
        break;

    case 'import_profile':
        $raw = trim($_POST['code'] ?? '');
        if (!$raw) { echo json_encode(['ok' => false, 'error' => 'empty']); break; }

        // Decode URL-safe base64
        $padded  = str_pad(strtr($raw, '-_', '+/'), strlen($raw) + (4 - strlen($raw) % 4) % 4, '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) { echo json_encode(['ok' => false, 'error' => 'invalid']); break; }

        $data = json_decode($decoded, true);
        if (!isset($data['v'], $data['speeds']) || !is_array($data['speeds'])) {
            echo json_encode(['ok' => false, 'error' => 'invalid']); break;
        }

        // Validate & merge speeds
        $imported = [];
        foreach ($data['speeds'] as $s) {
            $s = floatval($s);
            if ($s > 0 && $s <= 50) $imported[] = ['speed' => round($s, 3), 'ts' => time()];
        }
        if (empty($imported)) { echo json_encode(['ok' => false, 'error' => 'no_valid_speeds']); break; }

        $history   = $_SESSION['speed_history'] ?? [];
        $history   = array_merge($history, $imported);
        $history   = trimHistory($history);
        $_SESSION['speed_history'] = $history;

        $speeds = array_column($history, 'speed');
        $stats  = computeStats($speeds);
        $_SESSION['avg_speed']  = $stats['weightedAvg'];
        $_SESSION['test_count'] = $stats['count'];

        echo json_encode(['ok' => true, 'imported' => count($imported), 'history' => $history] + $stats);
        break;


}} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}