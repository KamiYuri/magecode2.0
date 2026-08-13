<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Problem;
use Illuminate\Notifications\Notification;

/**
 * D-41: the test cases moved after this student submitted, so their result no
 * longer reflects how the problem is graded. Stored only — the bell icon reads
 * it; nothing is emailed, because a re-run is the instructor's call, not an
 * action the student must take now.
 */
class TestCasesUpdated extends Notification
{
    public function __construct(private readonly Problem $problem) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'test_cases_updated',
            'problem_id' => $this->problem->id,
            'problem_name' => $this->problem->name,
            'section_id' => $this->problem->section_id,
        ];
    }
}
