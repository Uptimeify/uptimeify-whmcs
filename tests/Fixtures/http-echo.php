<?php

declare(strict_types=1);

// Router for the built-in PHP web server used by CurlHttpClientTest (inside the container only).
// Echoes the request back as JSON and answers with the status given in ?status=.

$status = (int) ($_GET['status'] ?? 200);
http_response_code($status);
header('Content-Type: application/json');
header('Retry-After: 7');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
    'xTest' => $_SERVER['HTTP_X_TEST'] ?? null,
    'body' => (string) file_get_contents('php://input'),
]);
