<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Section;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a roster names an address with no account yet — typically a
 * student arriving through an import. The generated password is the only way
 * in, so it travels in the message and is exchanged at first-time setup.
 */
class SectionInvitation extends Notification
{
    public function __construct(
        private readonly Section $section,
        private readonly string $temporaryPassword,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $semester = $this->section->semester;
        $course = $semester->course;

        return (new MailMessage)
            ->subject(__('You have been enrolled in :course', ['course' => $course->code]))
            ->greeting(__('Welcome to MageCode'))
            ->line(__('You have been enrolled in :course :section (:semester).', [
                'course' => $course->code,
                'section' => $this->section->name,
                'semester' => $semester->name,
            ]))
            ->line(__('Username: :username', ['username' => $notifiable->username]))
            ->line(__('Temporary password: :password', ['password' => $this->temporaryPassword]))
            ->action(__('Sign in'), config('app.url').'/login')
            ->line(__('You will be asked to choose your own password when you first sign in.'));
    }
}
