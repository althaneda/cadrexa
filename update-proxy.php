<?php
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

$url = 'https://func.vasebit.com/copyflow/files/update.html';

$ctx = stream_context_create([
    'http' => [
        'timeout' => 8,
        'user_agent' => 'CopyFlow/1.0',
    ],
    'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ],
]);

$content = @file_get_contents($url, false, $ctx);

if ($content === false) {
    http_response_code(502);
    echo '';
    exit;
}

echo $content;