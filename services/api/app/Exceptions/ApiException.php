<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business-rule refusal carrying the machine-readable `code` openapi.yml
 * declares. Laravel calls `render()` on the exception itself, so no handler
 * wiring is needed, and throwing rolls back the surrounding transaction.
 *
 * Subclasses fix the status; the named constructors fix the code, so the set
 * of codes the API can emit is readable in one place per status.
 */
abstract class ApiException extends RuntimeException
{
    protected function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    abstract protected function status(): int;

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->errorCode],
            $this->status()
        );
    }
}
