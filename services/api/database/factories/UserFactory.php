<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @var string|null Memoised so a bulk create only hashes once. */
    private static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'student_id' => null,
            'avatar_path' => null,
            'email_verified_at' => now(),
            'is_first_time_register' => false,
            'is_system_admin' => false,
        ];
    }

    public function student(): static
    {
        return $this->state(fn (): array => [
            'student_id' => (string) fake()->unique()->numberBetween(20210000, 20259999),
        ]);
    }

    /** Platform administrator (D-12); granted only by artisan in production. */
    public function systemAdmin(): static
    {
        return $this->state(fn (): array => ['is_system_admin' => true]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function firstTime(): static
    {
        return $this->state(fn (): array => [
            'is_first_time_register' => true,
            'email_verified_at' => null,
            'password' => Hash::make(Str::random(32)),
        ]);
    }
}
