<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeZone;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * D-88's unified log line, from api's side.
 *
 * The shape is shared with `shared/go/logger` and
 * `services/ai-detector/src/log.py`: `timestamp`, `level`, `service`,
 * `message`, plus the optional `trace_id`, `data` and `error`. Loki queries
 * and the Grafana dashboards read all of them together, so a key that differs
 * here is a query that works for the workers and silently returns nothing for
 * api.
 *
 * Callers keep logging a flat context array — `['submission_id' => 8,
 * 'trace_id' => ..., 'error' => ...]` — and this lifts the two reserved keys
 * out of it, leaving the domain fields under `data`.
 */
class JsonFormatter extends NormalizerFormatter
{
    /**
     * Monolog has eight levels and slog has four. Mapping the extras down
     * rather than passing them through keeps `level="error"` a complete
     * query: an alert logged by api would otherwise be invisible to a filter
     * written against the Go services.
     */
    /** @var array<string, string> */
    private const LEVELS = [
        'debug' => 'debug',
        'info' => 'info',
        'notice' => 'info',
        'warning' => 'warn',
        'error' => 'error',
        'critical' => 'error',
        'alert' => 'error',
        'emergency' => 'error',
    ];

    public function __construct()
    {
        // D-88: UTC ISO-8601 with milliseconds, matching slog's timestamp.
        parent::__construct('Y-m-d\TH:i:s.v\Z');
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;

        $entry = [
            'timestamp' => $record->datetime->setTimezone(new DateTimeZone('UTC'))->format($this->dateFormat),
            'level' => self::LEVELS[strtolower($record->level->getName())],
            'service' => (string) config('logging.service', 'api'),
            'message' => $record->message,
        ];

        if (isset($context['trace_id'])) {
            $entry['trace_id'] = (string) $context['trace_id'];
            unset($context['trace_id']);
        }

        $error = $this->extractError($context);
        unset($context['error'], $context['exception']);

        if ($context !== []) {
            $entry['data'] = $this->normalize($context);
        }

        if ($error !== null) {
            $entry['error'] = $error;
        }

        return $this->toJson($entry, true)."\n";
    }

    /**
     * `error` is the convention across the codebase; `exception` is what
     * Laravel's own handler attaches, and both mean the same field here.
     */
    /** @param  array<string, mixed>  $context */
    private function extractError(array $context): ?string
    {
        foreach (['error', 'exception'] as $key) {
            $value = $context[$key] ?? null;

            if ($value instanceof Throwable) {
                return $value::class.': '.$value->getMessage();
            }

            if ($value !== null) {
                return (string) $value;
            }
        }

        return null;
    }
}
