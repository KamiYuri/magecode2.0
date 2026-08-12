<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SectionRole;
use Database\Factories\SectionMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Every section-scoped authorization check resolves through this table (D-04).
 *
 * @property int $id
 * @property int $section_id
 * @property int $user_id
 * @property SectionRole $role
 * @property int|null $added_by
 * @property Carbon $created_at
 */
class SectionMember extends Model
{
    /** @use HasFactory<SectionMemberFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['section_id', 'user_id', 'role', 'added_by', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => SectionRole::class, 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
