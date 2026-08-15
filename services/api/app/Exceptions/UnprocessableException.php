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

    /**
     * The problem is closed to work — either not open yet or past its lock.
     * One code covers both because the contract offers one, and a student who
     * cannot submit does not benefit from learning which end of the window
     * they missed.
     */
    public static function deadlinePassed(): self
    {
        return new self(
            'DEADLINE_PASSED',
            'This problem is not open for submissions.'
        );
    }

    /** D-36: `max_submissions` is spent. Rows count, not verdicts. */
    public static function maxSubmissions(int $limit): self
    {
        return new self(
            'MAX_SUBMISSIONS',
            "You have used all {$limit} submissions for this problem."
        );
    }

    /** The language is not among the problem's allowed set. */
    public static function languageNotAllowed(): self
    {
        return new self(
            'LANGUAGE_NOT_ALLOWED',
            'This problem does not accept submissions in that language.'
        );
    }

    /** v3 §7: one unfinished submission per student per problem. */
    public static function submissionProcessing(): self
    {
        return new self(
            'SUBMISSION_PROCESSING',
            'Your previous submission for this problem is still being graded.'
        );
    }

    /** Nothing in scope to analyse — no student has submitted yet. */
    public static function noSubmissions(): self
    {
        return new self(
            'NO_SUBMISSIONS',
            'No submissions exist for this group of problems yet.'
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
