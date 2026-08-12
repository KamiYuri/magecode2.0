<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SectionTransferLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Student moves between sections (D-50). Append-only.
 *
 * @property int $id
 * @property int $user_id
 * @property int $from_section_id
 * @property int $to_section_id
 * @property int $transferred_by
 * @property Carbon $transferred_at
 */
class SectionTransferLog extends Model
{
    /** @use HasFactory<SectionTransferLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['user_id', 'from_section_id', 'to_section_id', 'transferred_by', 'transferred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function fromSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'from_section_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function toSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'to_section_id');
    }

    /** @return BelongsTo<User, $this> */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
