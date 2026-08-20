<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Notifications\TestCasesUpdated;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The `Notification` schema in openapi.yml.
 *
 * Laravel stores the notification class in `type`; the contract declares a
 * dotted enum instead, so the two are mapped here. An unmapped class falls
 * back to a derived slug rather than throwing: a notification the frontend
 * does not recognise should render as a generic row, not break the bell.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /** @var array<class-string, string> */
    private const TYPES = [
        TestCasesUpdated::class => 'problem.test_cases_updated',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => self::TYPES[$this->type] ?? $this->derive($this->type),
            'data' => $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** `App\Notifications\BankProblemApproved` → `bank_problem_approved`. */
    private function derive(string $class): string
    {
        $base = (string) preg_replace('/^.*\\\\/', '', $class);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
    }
}
