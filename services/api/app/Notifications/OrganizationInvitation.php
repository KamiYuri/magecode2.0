<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an organization admin adds an address that has no account yet.
 * The generated password is the only way into the account, so it travels in
 * the message body and is exchanged for a real one at first-time setup.
 */
class OrganizationInvitation extends Notification
{
    public function __construct(
        private readonly Organization $organization,
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
        return (new MailMessage)
            ->subject(__('You have been added to :organization', ['organization' => $this->organization->name]))
            ->greeting(__('Welcome to MageCode'))
            ->line(__('An account was created for you in :organization.', [
                'organization' => $this->organization->name,
            ]))
            ->line(__('Username: :username', ['username' => $notifiable->username]))
            ->line(__('Temporary password: :password', ['password' => $this->temporaryPassword]))
            ->action(__('Sign in'), config('app.url').'/login')
            ->line(__('You will be asked to choose your own password when you first sign in.'));
    }
}
