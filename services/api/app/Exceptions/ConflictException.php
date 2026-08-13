<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A 409 with the machine-readable `code` openapi.yml declares. Duplicates are
 * reported here rather than as 422 validation errors because the contract says
 * so, and because the same conflict must be reportable from inside a
 * transaction — where a FormRequest can no longer speak.
 *
 * Laravel calls `render()` on the exception itself, so no handler wiring is
 * needed, and throwing rolls the surrounding transaction back for free.
 */
final class ConflictException extends RuntimeException
{
    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function duplicateCourseCode(): self
    {
        return new self('DUPLICATE_COURSE_CODE', 'A course with this code already exists in this organization.');
    }

    public static function duplicateSemesterName(): self
    {
        return new self('DUPLICATE_SEMESTER_NAME', 'A semester with this name already exists in this course.');
    }

    public static function duplicateSectionName(): self
    {
        return new self('DUPLICATE_SECTION_NAME', 'A section with this name already exists in this semester.');
    }

    public static function lastAdmin(): self
    {
        return new self('LAST_ADMIN', 'The organization must keep at least one admin.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->errorCode],
            JsonResponse::HTTP_CONFLICT
        );
    }
}
