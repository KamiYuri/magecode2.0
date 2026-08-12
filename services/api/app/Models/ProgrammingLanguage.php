<?php

declare(strict_types=1);

namespace App\Models;

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
 */
class ProgrammingLanguage extends Model
{
    /** @use HasFactory<ProgrammingLanguageFactory> */
    use HasFactory;

    protected $fillable = ['name', 'version', 'judge0_id', 'monaco_language', 'dolos_language', 'codeql_language'];

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
