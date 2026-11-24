<?php
// Fake API for tests/run.php, served by the PHP built-in server.
declare(strict_types=1);

header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'sk_test') {
    http_response_code(401);
    echo '{"error":"bad key"}';
} elseif ($path === '/verify/single') {
    $email = json_decode((string) file_get_contents('php://input'), true)['email'];
    $rows = [
        'typo@example.com'  => ['status' => 'valid', 'band' => 'judgement'],
        'old@example.com'   => ['status' => 'valid'],
        'other@example.com' => ['status' => 'valid', 'band' => 'other'],
        'noreply@example.com' => ['status' => 'no_reply'],
    ];
    echo json_encode(['email' => $email] + $rows[$email]);
} elseif ($path === '/verify/bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo '{"jobId":"j1","status":"Queued"}';
} elseif ($path === '/verify/bulk/j1') {
    echo '{"jobId":"j1","status":"Succeeded"}';
} elseif ($path === '/verify/bulk/j1/result') {
    echo '[{"email":"a@example.com","status":"valid","band":"judgement"},{"email":"b@example.com","status":"no_reply"}]';
} else {
    http_response_code(404);
    echo '{"error":"not found"}';
}
