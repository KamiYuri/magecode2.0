<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\JsonFormatter;
use Monolog\DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

/**
 * D-88's line shape is shared with `shared/go/logger` and
 * `services/ai-detector/src/log.py`: a Loki query written against the workers
 * has to work against api too, which it only does if the keys match exactly.
 * These assertions are that contract, from api's side.
 */
class JsonFormatterTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function format(Level $level, string $message, array $context = []): array
    {
        $line = (new JsonFormatter)->format(new LogRecord(
            datetime: new DateTimeImmutable(true),
            channel: 'testing',
            level: $level,
            message: $message,
            context: $context,
        ));

        $this->assertStringEndsWith("\n", $line, 'One record per line, or Loki reads two as one');

        return json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_emits_the_four_required_fields(): void
    {
        $entry = $this->format(Level::Info, 'submission accepted');

        $this->assertSame(['timestamp', 'level', 'service', 'message'], array_keys($entry));
        $this->assertSame('info', $entry['level']);
        $this->assertSame('api', $entry['service']);
        $this->assertSame('submission accepted', $entry['message']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $entry['timestamp'],
            'D-88 timestamps are UTC ISO-8601 with milliseconds'
        );
    }

    /**
     * slog spells this level `warn`, and `log.py` rewrites Python's `warning`
     * to match. Monolog spells it `warning`, so api has to rewrite it too or
     * `level="warn"` silently misses every api line.
     */
    public function test_it_spells_the_warning_level_the_way_slog_does(): void
    {
        $this->assertSame('warn', $this->format(Level::Warning, 'nothing to do')['level']);
    }

    public function test_trace_id_and_error_are_lifted_out_of_the_context(): void
    {
        $entry = $this->format(Level::Error, 'could not queue submission for execution', [
            'trace_id' => 'a01ba2c1',
            'error' => 'Could not publish to queue code-executor.',
            'submission_id' => 8,
            'queue' => 'code-executor',
        ]);

        $this->assertSame('a01ba2c1', $entry['trace_id']);
        $this->assertSame('Could not publish to queue code-executor.', $entry['error']);

        // Everything else is caller data and belongs under `data`, exactly as
        // logger.KeyData carries it on the Go side.
        $this->assertSame(['submission_id' => 8, 'queue' => 'code-executor'], $entry['data']);
    }

    public function test_an_empty_context_produces_no_data_key(): void
    {
        $this->assertArrayNotHasKey('data', $this->format(Level::Info, 'consuming result-execution'));
    }

    public function test_a_throwable_in_the_context_becomes_the_error_string(): void
    {
        $entry = $this->format(Level::Error, 'broker refused the publish', [
            'exception' => new RuntimeException('connection closed'),
        ]);

        $this->assertStringContainsString('connection closed', $entry['error']);
        $this->assertArrayNotHasKey('data', $entry, 'The exception is the error, not caller data');
    }

    /**
     * The image runs api, reverb, the scheduler and both consumers, and Loki
     * separates them by this field alone — the Go workers each name
     * themselves, so these must too.
     */
    public function test_the_service_name_comes_from_configuration(): void
    {
        config(['logging.service' => 'reverb']);

        $this->assertSame('reverb', $this->format(Level::Info, 'started')['service']);
    }
}
