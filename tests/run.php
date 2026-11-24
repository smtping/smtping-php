<?php
// php tests/run.php : the SDK against a local fake API, no dependency.
declare(strict_types=1);

use Smtping\Client;
use Smtping\Exception\AuthenticationException;
use Smtping\Exception\ValidationException;

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Smtping\\') === 0) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    }
});

$failed = 0;
function same(string $what, $expected, $actual): void
{
    global $failed;
    if ($expected !== $actual) {
        $failed++;
        echo "FAIL $what: expected " . json_encode($expected) . ', got ' . json_encode($actual) . PHP_EOL;
    }
}
function throws(string $what, string $class, callable $call): void
{
    try {
        $call();
        same($what, $class, null);
    } catch (Throwable $e) {
        same($what, $class, get_class($e));
    }
}

$port = 8099;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/api.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

try {
    $c = new Client('sk_test', ['baseUrl' => "http://127.0.0.1:$port", 'maxRetries' => 0]);

    // The band of the API wins; the status is only the fallback.
    same('server band', 'judgement', $c->verify('typo@example.com')['band']);
    same('no band', 'safe', $c->verify('old@example.com')['band']);
    same('unknown band', 'safe', $c->verify('other@example.com')['band']);
    same('no_reply', 'avoid', $c->verify('noreply@example.com')['band']);
    same('bulk', ['judgement', 'avoid'], array_column($c->bulk->run(['a@example.com', 'b@example.com'], ['interval' => 1]), 'band'));
    same('many', ['judgement', 'safe'], array_column($c->verifyMany(['typo@example.com', 'old@example.com']), 'band'));

    foreach (['valid' => 'safe', 'alias' => 'safe', 'invalid' => 'avoid', 'spamtrap' => 'avoid', 'catchall' => 'judgement', 'unknown' => 'judgement'] as $status => $band) {
        same("band($status)", $band, Client::band($status));
    }
    throws('empty email', ValidationException::class, fn () => $c->verify(' '));
    throws('bad key', AuthenticationException::class, fn () => (new Client('nope', ['baseUrl' => "http://127.0.0.1:$port"]))->verify('a@example.com'));
} finally {
    proc_terminate($server);
}

echo $failed ? "$failed failed" . PHP_EOL : 'ok' . PHP_EOL;
exit($failed ? 1 : 0);
