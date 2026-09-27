<?php

declare(strict_types=1);

namespace Smtping;

use Smtping\Exception\AuthenticationException;
use Smtping\Exception\InsufficientCreditsException;
use Smtping\Exception\RateLimitException;
use Smtping\Exception\SmtpingException;
use Smtping\Exception\TimeoutException;
use Smtping\Exception\ValidationException;

final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.smtping.com/api/v1';
    public const CHECKS = ['spamtrap', 'disposable', 'spambot', 'complainer'];
    public const BULK_MAX = 100000;

    private const SAFE = ['valid', 'alias'];
    private const AVOID = ['invalid', 'spamtrap', 'disposable', 'blacklisted', 'complainer', 'spambot', 'inbox_full'];

    public readonly Bulk $bulk;
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    /**
     * @param string|null $apiKey  Defaults to the SMTPING_API_KEY environment variable.
     * @param array{baseUrl?:string, timeout?:int, maxRetries?:int} $options
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $this->apiKey = $apiKey ?: (string) getenv('SMTPING_API_KEY');
        if ($this->apiKey === '') {
            throw new AuthenticationException('Missing API key. Pass it to the constructor or set SMTPING_API_KEY.');
        }
        $this->baseUrl = rtrim($options['baseUrl'] ?? (getenv('SMTPING_BASE_URL') ?: self::DEFAULT_BASE_URL), '/');
        $this->timeout = $options['timeout'] ?? 60;
        $this->maxRetries = $options['maxRetries'] ?? 3;
        $this->bulk = new Bulk($this);
    }

    public static function band(string $status): string
    {
        if (in_array($status, self::SAFE, true)) {
            return 'safe';
        }
        if (in_array($status, self::AVOID, true)) {
            return 'avoid';
        }
        return 'judgement';
    }

    public static function isEmail(string $value): bool
    {
        return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', trim($value));
    }

    /** @return array<string, mixed> */
    public function verify(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            throw new ValidationException('Email is required');
        }
        return self::withBand($this->request('POST', '/verify/single', ['email' => $email]));
    }

    /**
     * Verifies several addresses one by one. For more than a few hundred, use $client->bulk->run().
     *
     * @param string[] $emails
     * @return array<int, array<string, mixed>>
     */
    public function verifyMany(array $emails): array
    {
        $out = [];
        foreach (array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $emails))) as $email) {
            try {
                $out[] = $this->verify($email);
            } catch (SmtpingException $e) {
                $out[] = ['email' => $email, 'status' => 'error', 'statusDescription' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function check(string $type, string $email): array
    {
        if (!in_array($type, self::CHECKS, true)) {
            throw new ValidationException(sprintf('Unknown check "%s". Use one of: %s', $type, implode(', ', self::CHECKS)));
        }
        $email = trim($email);
        $r = $this->request('POST', '/checks/' . $type, ['email' => $email]) ?? [];
        return ['email' => $email, 'check' => $type] + $r;
    }

    /** @return array<string, mixed> */
    public function credits(): array
    {
        return $this->request('GET', '/credits') ?? [];
    }

    /**
     * Low-level call. Retries network errors, 429 and 5xx with backoff.
     *
     * @param array<string, mixed>|null $body
     * @return mixed
     */
    public function request(string $method, string $path, ?array $body = null)
    {
        $attempt = 0;
        while (true) {
            $ch = curl_init($this->baseUrl . $path);
            $headers = [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: smtping-php/' . self::VERSION,
            ];
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            }
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($raw === false) {
                if ($attempt++ < $this->maxRetries) {
                    usleep(self::backoff($attempt));
                    continue;
                }
                if ($errno === CURLE_OPERATION_TIMEDOUT) {
                    throw new TimeoutException(sprintf('Request timed out after %d s', $this->timeout));
                }
                throw new SmtpingException('Network error: ' . $error);
            }

            $head = substr((string) $raw, 0, $headerSize);
            $text = substr((string) $raw, $headerSize);
            $data = $text === '' ? null : json_decode($text, true);
            if ($text !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
                $data = ['raw' => $text];
            }

            if ($status >= 200 && $status < 300) {
                return $data;
            }
            if (($status === 429 || $status >= 500) && $attempt++ < $this->maxRetries) {
                $wait = preg_match('/^retry-after:\s*(\d+)/mi', $head, $m) ? (int) $m[1] * 1000000 : self::backoff($attempt);
                usleep($wait);
                continue;
            }
            throw self::errorFor($status, $data);
        }
    }

    /**
     * @param mixed $r
     * @return mixed
     */
    public static function withBand($r)
    {
        if (is_array($r) && isset($r['status']) && is_string($r['status']) && !isset($r['band'])) {
            $r['band'] = self::band($r['status']);
        }
        return $r;
    }

    private static function backoff(int $attempt): int
    {
        return (int) (min(2 ** ($attempt - 1), 15) * 1000000 + random_int(0, 250000));
    }

    /** @param mixed $body */
    private static function errorFor(int $status, $body): SmtpingException
    {
        $msg = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;
        $msg = $msg ?: sprintf('SMTPing API returned HTTP %d', $status);
        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException($msg, $status, $body),
            $status === 402 => new InsufficientCreditsException($msg, $status, $body),
            $status === 429 => new RateLimitException($msg, $status, $body),
            $status === 400, $status === 422 => new ValidationException($msg, $status, $body),
            default => new SmtpingException($msg, $status, $body),
        };
    }
}
