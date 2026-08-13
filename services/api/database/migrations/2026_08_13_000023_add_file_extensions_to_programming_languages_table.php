<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source-code extensions accepted for each seeded language, keyed by the
     * Judge0 id that identifies the row across environments. The first entry is
     * the canonical one — it names the file when a submission arrives as a JSON
     * body with no filename of its own.
     *
     * @var array<int, list<string>>
     */
    private const EXTENSIONS = [
        71 => ['py'],
        62 => ['java'],
        50 => ['c'],
        54 => ['cpp', 'cc', 'cxx'],
    ];

    public function up(): void
    {
        Schema::table('programming_languages', function (Blueprint $table) {
            // U-4's upload allowlist has to come from somewhere the API, the
            // request validation and the frontend picker can all read. A default
            // is required because deployed databases already hold rows.
            $table->jsonb('file_extensions')->default('[]');
        });

        // Backfilled here rather than left to the seeder so `make migrate` alone
        // leaves a database that can accept submissions.
        foreach (self::EXTENSIONS as $judge0Id => $extensions) {
            DB::table('programming_languages')
                ->where('judge0_id', $judge0Id)
                ->update(['file_extensions' => json_encode($extensions)]);
        }
    }

    public function down(): void
    {
        Schema::table('programming_languages', function (Blueprint $table) {
            $table->dropColumn('file_extensions');
        });
    }
};
