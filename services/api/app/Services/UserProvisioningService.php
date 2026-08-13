<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an email address into an account. Staff invited to an organization and
 * students arriving through a roster import (B7) are the same problem: the
 * person exists institutionally before they exist here, so the account is
 * created with a generated password and `is_first_time_register`, and they
 * take ownership through `auth.first-time-setup`.
 */
class UserProvisioningService
{
    private const MAX_USERNAME_PROBES = 50;

    /**
     * @return array{user: User, invited: bool, password: string|null}
     */
    public function findOrInvite(string $email): array
    {
        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            return ['user' => $existing, 'invited' => false, 'password' => null];
        }

        $password = Str::password(16);
        $localPart = Str::before($email, '@');

        try {
            // A nested transaction is a savepoint, so a unique violation here
            // rolls back only the failed insert. Without it the enclosing
            // batch transaction would be poisoned by Postgres.
            $user = DB::transaction(
                fn (): User => $this->createInvitee($email, $this->availableUsername($localPart), $password, $localPart)
            );
        } catch (UniqueConstraintViolationException $collision) {
            $raced = User::where('email', $email)->first();

            if ($raced !== null) {
                return ['user' => $raced, 'invited' => false, 'password' => null];
            }

            // Someone took the username between the probe and the insert.
            $unique = Str::limit($this->slug($localPart), 42, '').'-'.Str::lower(Str::random(6));
            $user = DB::transaction(fn (): User => $this->createInvitee($email, $unique, $password, $localPart));
        }

        return ['user' => $user, 'invited' => true, 'password' => $password];
    }

    private function createInvitee(string $email, string $username, string $password, string $localPart): User
    {
        return User::create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            // A placeholder name the invitee overwrites during first-time
            // setup; both columns are NOT NULL and nothing else is known yet.
            'first_name' => Str::limit(Str::title(str_replace(['.', '_', '-'], ' ', $localPart)), 100, ''),
            'last_name' => '',
            'is_first_time_register' => true,
        ]);
    }

    /** `newcomer`, then `newcomer-2`, `newcomer-3`… until one is free. */
    private function availableUsername(string $localPart): string
    {
        $base = $this->slug($localPart);

        for ($probe = 1; $probe <= self::MAX_USERNAME_PROBES; $probe++) {
            $suffix = $probe === 1 ? '' : "-{$probe}";
            $candidate = Str::limit($base, 50 - strlen($suffix), '').$suffix;

            if (! User::where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return Str::limit($base, 42, '').'-'.Str::lower(Str::random(6));
    }

    private function slug(string $localPart): string
    {
        $slug = Str::of($localPart)->lower()->replaceMatches('/[^a-z0-9._-]/', '')->trim('.-_')->value();

        return $slug === '' ? 'user' : $slug;
    }
}
