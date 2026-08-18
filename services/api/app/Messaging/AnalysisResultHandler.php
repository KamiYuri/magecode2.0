<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Enums\AnalysisService;
use App\Services\Analysis\SimResultIngestor;
use JsonException;

/**
 * Routes a `result-analysis` message to the service that knows how to store it.
 *
 * All three batch services share one queue (D-83), so `service` is the
 * discriminator — the same field the schema's `oneOf` branches on. api is the
 * single writer of these rows (D-80), which is why the message carries the
 * whole result and not an id to look up.
 */
class AnalysisResultHandler
{
    private const REQUIRED = ['service', 'status', 'trace_id'];

    public function __construct(private readonly SimResultIngestor $sim) {}

    /** @throws InvalidResultMessage */
    public function handle(string $body): void
    {
        $message = $this->decode($body);
        $service = (string) $message['service'];

        match (AnalysisService::tryFrom($service)) {
            AnalysisService::PlagiarismChecker => $this->sim->ingest($message),
            // A service api does not know reads no better on a retry, so the
            // consumer drops it rather than requeueing it into a loop.
            default => throw InvalidResultMessage::unknownService($service),
        };
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidResultMessage
     */
    private function decode(string $body): array
    {
        try {
            /** @var array<string, mixed> $message */
            $message = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw InvalidResultMessage::notJson($failure->getMessage());
        }

        $missing = array_values(array_diff(self::REQUIRED, array_keys($message)));

        if ($missing !== []) {
            throw InvalidResultMessage::missingFields($missing);
        }

        return $message;
    }
}
