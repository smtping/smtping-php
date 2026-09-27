<?php

declare(strict_types=1);

namespace Smtping;

use Smtping\Exception\JobFailedException;
use Smtping\Exception\TimeoutException;
use Smtping\Exception\ValidationException;

final class Bulk
{
    public function __construct(private Client $client)
    {
    }

    /**
     * @param string[] $emails
     * @return array<string, mixed> Contains jobId and status.
     */
    public function create(array $emails): array
    {
        $list = [];
        foreach ($emails as $e) {
            $e = strtolower(trim((string) $e));
            if (Client::isEmail($e)) {
                $list[$e] = true;
            }
        }
        $list = array_keys($list);
        if (!$list) {
            throw new ValidationException('No valid email address in the list');
        }
        if (count($list) > Client::BULK_MAX) {
            throw new ValidationException(sprintf('A bulk job accepts up to %d addresses', Client::BULK_MAX));
        }
        $job = $this->client->request('POST', '/verify/bulk', ['emails' => $list]) ?? [];
        $job['totalEmails'] ??= count($list);
        return $job;
    }

    /** @return array<string, mixed> */
    public function get(string $jobId): array
    {
        $r = $this->client->request('GET', '/verify/bulk/' . rawurlencode($jobId)) ?? [];
        return ['jobId' => $jobId] + $r;
    }

    /** @return array<int, array<string, mixed>> */
    public function results(string $jobId): array
    {
        $r = $this->client->request('GET', '/verify/bulk/' . rawurlencode($jobId) . '/result');
        $rows = is_array($r) && array_is_list($r) ? $r : ($r['results'] ?? []);
        return array_map([Client::class, 'withBand'], $rows);
    }

    /**
     * Polls until the job finishes and returns its results.
     *
     * @param array{timeout?:int, interval?:int, onProgress?:callable} $options timeout and interval in seconds
     * @return array<int, array<string, mixed>>
     */
    public function wait(string $jobId, array $options = []): array
    {
        $deadline = time() + ($options['timeout'] ?? 1800);
        $delay = (float) ($options['interval'] ?? 5);
        $onProgress = $options['onProgress'] ?? null;
        while (true) {
            $st = $this->get($jobId);
            if ($onProgress) {
                $onProgress($st);
            }
            $status = $st['status'] ?? '';
            if ($status === 'Succeeded') {
                return $this->results($jobId);
            }
            if ($status === 'Failed' || $status === 'Cancelled') {
                $msg = sprintf('Job %s %s', $jobId, strtolower($status));
                if (!empty($st['errorMessage'])) {
                    $msg .= ': ' . $st['errorMessage'];
                }
                throw new JobFailedException($msg, $st);
            }
            if (time() + $delay > $deadline) {
                throw new TimeoutException(sprintf('Job %s still running after %d s', $jobId, $options['timeout'] ?? 1800));
            }
            usleep((int) ($delay * 1000000));
            $delay = min($delay * 1.5, 30);
        }
    }

    /**
     * Creates a job and waits for its results.
     *
     * @param string[] $emails
     * @param array{timeout?:int, interval?:int, onProgress?:callable} $options
     * @return array<int, array<string, mixed>>
     */
    public function run(array $emails, array $options = []): array
    {
        return $this->wait($this->create($emails)['jobId'], $options);
    }
}
