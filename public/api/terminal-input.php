<?php
// This endpoint is intentionally session-free.
// terminal-output.php holds the PHP session lock for the duration of the SSH
// stream, so any session_start() here would block indefinitely.
// Security: the token is 128 bits of random hex, obtained only after admin
// authentication in terminal-start.php — it is the auth credential itself.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}

$raw   = file_get_contents('php://input');
$body  = json_decode($raw, true);
$token = preg_replace('/[^a-f0-9]/', '', $body['token'] ?? '');
$data  = $body['data'] ?? '';

if (strlen($token) !== 32 || $data === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid token/data']);
    exit;
}

$decoded = base64_decode($data, true);
if ($decoded === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid base64 data']);
    exit;
}

$inputFile = sys_get_temp_dir() . '/term_in_' . $token;

$fp = fopen($inputFile, 'a+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not open session file']);
    exit;
}

flock($fp, LOCK_EX);
fwrite($fp, base64_encode($decoded) . "\n");
flock($fp, LOCK_UN);
fclose($fp);

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
