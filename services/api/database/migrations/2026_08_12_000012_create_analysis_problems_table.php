<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One analysis batch. For SIM the scope is semester-wide: every
        // equivalent problem across sections, matched either by
        // bank_problem_id (auto) or manual_match_group_id (manual).
        Schema::create('analysis_problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_problem_id')->nullable()->constrained('bank_problems')->restrictOnDelete();
            $table->uuid('manual_match_group_id')->nullable();
            $table->foreignId('triggered_by_problem_id')->constrained('problems')->restrictOnDelete();
            $table->foreignId('analyst_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('services');
            $table->string('status', 20)->default('processing');
            $table->boolean('is_latest')->default(true);
            $table->boolean('is_partial')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['semester_id', 'bank_problem_id', 'is_latest']);
            $table->index(['status', 'started_at']);
        });

        // Partial unique indexes keep exactly one is_latest row per scope
        // (D-53). A concurrent re-trigger loses at INSERT rather than
        // silently creating a second "latest" batch.
        DB::statement('
            CREATE UNIQUE INDEX idx_analysis_problems_latest_bank
            ON analysis_problems (semester_id, bank_problem_id)
            WHERE is_latest = true AND bank_problem_id IS NOT NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX idx_analysis_problems_latest_manual
            ON analysis_problems (semester_id, manual_match_group_id)
            WHERE is_latest = true AND manual_match_group_id IS NOT NULL
        ');

        // Exactly one scope identifier must be set (schema doc §5.2). The two
        // are alternatives: the scope query resolves each in its own branch
        // with no precedence rule, so a row carrying both would name two
        // different sets of problems.
        DB::statement('
            ALTER TABLE analysis_problems
            ADD CONSTRAINT chk_analysis_scope
            CHECK (num_nonnulls(bank_problem_id, manual_match_group_id) = 1)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_problems');
    }
};
