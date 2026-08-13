<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * 409: the request collides with something that already exists. Duplicates are
 * reported here rather than as 422 validation errors because the contract says
 * so, and because the same conflict must be reportable from inside a
 * transaction — where a FormRequest can no longer speak.
 */
final class ConflictException extends ApiException
{
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

    protected function status(): int
    {
        return JsonResponse::HTTP_CONFLICT;
    }
}
