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
        // One row per ordered pair (schema doc §5.5), which halves storage
        // versus writing both directions.
        Schema::create('similarity_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_problem_id')->constrained('analysis_problems')->cascadeOnDelete();
            $table->foreignId('submission_a_id')->constrained('submissions')->restrictOnDelete();
            $table->foreignId('submission_b_id')->constrained('submissions')->restrictOnDelete();
            $table->decimal('similarity', 5, 4);
            $table->integer('longest_fragment')->nullable();
            $table->integer('total_overlap')->nullable();
            $table->string('match_type', 20);
            $table->text('a_regions')->nullable();
            $table->text('b_regions')->nullable();
            $table->timestamp('created_at');

            $table->unique(['analysis_problem_id', 'submission_a_id', 'submission_b_id']);
            $table->index(['analysis_problem_id', 'submission_a_id']);
            $table->index(['analysis_problem_id', 'submission_b_id']);
            $table->index(['match_type', 'similarity']);
        });

        // The unique pair above only dedupes when the ordering invariant the
        // doc mandates actually holds, so enforce it in the database rather
        // than trusting every writer.
        DB::statement('
            ALTER TABLE similarity_results
            ADD CONSTRAINT chk_similarity_pair_order
            CHECK (submission_a_id < submission_b_id)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('similarity_results');
    }
};
