# SMTPing for PHP

Official PHP SDK for the [SMTPing](https://smtping.com) email verification API.

- PHP 8.1+, no dependencies beyond `ext-curl` and `ext-json`
- Automatic retries on rate limits (429) and server errors (5xx)
- Bulk jobs up to 100,000 addresses, with polling built in

## Install

```bash
composer require smtping/smtping-php
```

Create an API key in the [SMTPing dashboard](https://app.smtping.com). Pass it to the client or set `SMTPING_API_KEY`.

## Verify one address

```php
<?php
require 'vendor/autoload.php';

$smtping = new Smtping\Client(); // reads SMTPING_API_KEY, or new Smtping\Client('sk_live_...')

$r = $smtping->verify('jane@example.com');
echo $r['status'], ' ', $r['band'], PHP_EOL; // valid safe
```

Every result carries a `band` field for simple routing:

| band | statuses | action |
| --- | --- | --- |
| `safe` | valid, alias | send |
| `avoid` | invalid, spamtrap, disposable, blacklisted, complainer, spambot, inbox_full | remove |
| `judgement` | catch_all, unknown, role and others | your call |

## Verify a list

```php
$job  = $smtping->bulk->create($emails);          // ['jobId' => ..., 'status' => ...]
$rows = $smtping->bulk->wait($job['jobId'], [
    'onProgress' => fn ($s) => print(($s['processedEmails'] ?? 0) . PHP_EOL),
]);

// or in one call
$rows = $smtping->bulk->run($emails);
$sendable = array_filter($rows, fn ($r) => $r['band'] === 'safe');
```

Check a job later with `$smtping->bulk->get($jobId)` and `$smtping->bulk->results($jobId)`. For a handful of addresses, `$smtping->verifyMany([...])` calls the single endpoint for each.

## Threat list checks

```php
$smtping->check('spamtrap', 'jane@example.com');
// also: 'disposable', 'spambot', 'complainer'
```

## Credits

```php
echo $smtping->credits()['remaining'];
```

## Errors

```php
use Smtping\Exception\InsufficientCreditsException;
use Smtping\Exception\SmtpingException;

try {
    $smtping->verify('jane@example.com');
} catch (InsufficientCreditsException $e) {
    // top up
} catch (SmtpingException $e) {
    echo $e->status, ' ', $e->getMessage();
}
```

Classes in `Smtping\Exception`: `SmtpingException` (base, with `status` and `body`), `AuthenticationException`, `InsufficientCreditsException`, `RateLimitException`, `ValidationException`, `JobFailedException`, `TimeoutException`.

## Options

```php
new Smtping\Client($apiKey, [
    'baseUrl'    => 'https://api.smtping.com/api/v1',
    'timeout'    => 60,  // seconds per request
    'maxRetries' => 3,   // network errors, 429, 5xx
]);
```

## Links

- [API documentation](https://smtping.com/docs)
- [Pricing](https://smtping.com/pricing)
- Support: support@smtping.com

MIT License
