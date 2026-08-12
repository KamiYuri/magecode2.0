<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Roles are never stored here: they come from organization_members.role and
 * section_members.role, so the same person can be an instructor in one
 * section and a student in another (D-04).
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $password
 * @property string $first_name
 * @property string $last_name
 * @property string|null $student_id
 * @property string|null $avatar_path
 * @property Carbon|null $email_verified_at
 * @property bool $is_first_time_register
 * @property bool $is_system_admin
 */
#[Fillable(['username', 'email', 'password', 'first_name', 'last_name', 'student_id', 'avatar_path', 'is_first_time_register'])]
#[Hidden(['password'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mirrors the column default so a freshly created model reports the flag
     * rather than null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_system_admin' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_first_time_register' => 'boolean',
            // Deliberately absent from #[Fillable]: granted only by
            // `php artisan magecode:make-system-admin`, never by a request.
            'is_system_admin' => 'boolean',
        ];
    }

    /** @return HasMany<Organization, $this> */
    public function createdOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'creator_id');
    }

    /** @return HasMany<OrganizationMember, $this> */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /** @return HasMany<SectionMember, $this> */
    public function sectionMemberships(): HasMany
    {
        return $this->hasMany(SectionMember::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'creator_id');
    }

    /** @return HasMany<Problem, $this> */
    public function createdProblems(): HasMany
    {
        return $this->hasMany(Problem::class, 'creator_id');
    }

    /** @return HasMany<BankProblem, $this> */
    public function authoredBankProblems(): HasMany
    {
        return $this->hasMany(BankProblem::class, 'author_id');
    }

    /** @return HasMany<AnalysisProblem, $this> */
    public function triggeredAnalyses(): HasMany
    {
        return $this->hasMany(AnalysisProblem::class, 'analyst_id');
    }
}
