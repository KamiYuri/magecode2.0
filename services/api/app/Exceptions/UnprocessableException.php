<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * 422 for a rule the payload cannot satisfy — as opposed to a malformed field,
 * which stays with the validator and its `errors` map.
 */
final class UnprocessableException extends ApiException
{
    /** Core fields are frozen once the problem is closed to submissions. */
    public static function problemLocked(): self
    {
        return new self(
            'PROBLEM_LOCKED',
            'This problem is locked; test cases, limits and languages can no longer be changed.'
        );
    }

    /** D-51: one section of a course per semester, per student. */
    public static function duplicateEnrollment(string $section): self
    {
        return new self(
            'DUPLICATE_ENROLLMENT',
            "This student is already enrolled in section {$section} of the same course this semester."
        );
    }

    /** Only a student enrolment is transferable; staff hold their own rows. */
    public static function transferNotAStudent(): self
    {
        return new self(
            'TRANSFER_NOT_A_STUDENT',
            'Only a student enrolment can be transferred between sections.'
        );
    }

    /** The target section belongs to a different semester (D-50). */
    public static function transferOutsideSemester(): self
    {
        return new self(
            'TRANSFER_OUTSIDE_SEMESTER',
            'A student can only be transferred to another section of the same semester.'
        );
    }

    /** The semester forbids per-problem overrides and the caller is not an Org Admin. */
    public static function policyOverrideDenied(): self
    {
        return new self(
            'POLICY_OVERRIDE_DENIED',
            'The semester policy does not allow this problem to be overridden.'
        );
    }

    protected function status(): int
    {
        return JsonResponse::HTTP_UNPROCESSABLE_ENTITY;
    }
}
