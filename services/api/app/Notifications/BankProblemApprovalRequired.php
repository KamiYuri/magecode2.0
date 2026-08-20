<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BankProblem;
use Illuminate\Notifications\Notification;

/**
 * D-70: a course with `require_bank_approval` has a new entry waiting.
 *
 * Stored only, like D-41's: the Org Admin acts on it when they next look, and
 * a bank entry nobody has approved blocks nothing that is already running.
 */
class BankProblemApprovalRequired extends Notification
{
    public function __construct(private readonly BankProblem $bankProblem) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'bank_problem_id' => $this->bankProblem->id,
            'bank_problem_name' => $this->bankProblem->name,
            'course_id' => $this->bankProblem->course_id,
            'version' => $this->bankProblem->version,
        ];
    }
}
