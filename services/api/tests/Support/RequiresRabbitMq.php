<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Gates the tests that talk to a real broker, mirroring the Go side's
 * `RMQ_TEST_URL` convention (`shared/go/rmq`) and this suite's `RequiresMinio`.
 *
 * The URL is read from the process environment rather than app config for the
 * same reason as MinIO's: a developer's `RABBITMQ_HOST=127.0.0.1` must not leak
 * into a suite that runs inside the compose network. Parsing it here also keeps
 * one spelling of the broker address across the Go and PHP suites.
 */
trait RequiresRabbitMq
{
    /** @return array<string, mixed> connection config for AmqpPublisher */
    protected function requireRabbitMq(): array
    {
        $url = getenv('RMQ_TEST_URL');

        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'RMQ_TEST_URL is unset. Bring the stack up and run: '
                .'set -a && . ./.env && set +a && make test-api'
            );
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            $this->fail("RMQ_TEST_URL is not a URL: {$url}");
        }

        return [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 5672,
            'user' => urldecode($parts['user'] ?? 'guest'),
            'password' => urldecode($parts['pass'] ?? 'guest'),
            // amqp:// URLs carry the vhost as the path; an empty path means "/".
            'vhost' => ltrim($parts['path'] ?? '/', '/') ?: '/',
            'connect_timeout' => 3.0,
            'publish_timeout' => 5.0,
        ];
    }
}
