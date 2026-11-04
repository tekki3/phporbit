<?php

declare(strict_types=1);

$server = array_filter($_SERVER, is_string(...));
$path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = file_get_contents('php://input');
$data = is_string($body) ? json_decode($body, true) : null;
if ($path === '/fail') {
    http_response_code(429);
    header('Content-Type: application/json');
    echo '{"error":{"message":"synthetic private provider details"}}';
    return;
}
if ($path === '/timeout') {
    sleep(2);
}
if (!is_array($data) || ($data['stream'] ?? false) !== true) {
    header('Content-Type: application/json');
    echo '{"choices":[{"message":{"content":"Buffered answer"}}]}';
    return;
}
header('Content-Type: text/event-stream');
if ($path === '/large') {
    echo 'data: ' . str_repeat('a', 1048577);
    flush();
    return;
}
echo "data: {\"choices\":[{\"delta\":{\"content\":\"First \"}}]}\n\n";
flush();
usleep(700000);
echo "data: {\"choices\":[{\"delta\":{\"content\":\"Second\"}}]}\n\n";
if ($path !== '/truncated') {
    echo "data: [DONE]\n\n";
}
flush();
