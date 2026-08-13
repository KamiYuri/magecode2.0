<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SubmissionFileException;
use Database\Factories\ProgrammingLanguageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $version
 * @property int $judge0_id
 * @property string $monaco_language
 * @property string|null $dolos_language
 * @property string|null $codeql_language
 * @property list<string> $file_extensions
 */
class ProgrammingLanguage extends Model
{
    /** @use HasFactory<ProgrammingLanguageFactory> */
    use HasFactory;

    protected $fillable = ['name', 'version', 'judge0_id', 'monaco_language', 'dolos_language', 'codeql_language', 'file_extensions'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['file_extensions' => 'array'];
    }

    /**
     * Upload allowlist per U-4. Normalisation lives here so request validation,
     * the storage service and the client-facing resource all answer alike.
     */
    public function allowsExtension(string $extension): bool
    {
        return in_array(self::normaliseExtension($extension), $this->file_extensions, true);
    }

    /**
     * Names the file when a submission arrives as JSON with no filename of its
     * own — the first configured extension is the canonical one.
     */
    public function defaultExtension(): string
    {
        $extension = $this->file_extensions[0] ?? null;

        if ($extension === null) {
            throw SubmissionFileException::languageHasNoExtensions($this->name);
        }

        return $extension;
    }

    public static function normaliseExtension(string $extension): string
    {
        return mb_strtolower(ltrim(trim($extension), '.'));
    }

    /** @return BelongsToMany<Problem, $this> */
    public function problems(): BelongsToMany
    {
        return $this->belongsToMany(Problem::class, 'problem_programming_languages');
    }

    /** @return BelongsToMany<BankProblem, $this> */
    public function bankProblems(): BelongsToMany
    {
        return $this->belongsToMany(BankProblem::class, 'bank_problem_programming_languages');
    }
}
