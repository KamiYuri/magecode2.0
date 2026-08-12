<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only, written directly by CES (D-81). The unique pair is what
        // makes the CES upsert work on crash recovery: ON CONFLICT
        // (submission_id, test_case_id) DO UPDATE.
        Schema::create('code_execution_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('test_case_id')->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->text('actual_output')->nullable();
            $table->decimal('consumed_time_ms', 10, 3)->nullable();
            $table->integer('consumed_memory_kb')->nullable();
            $table->text('error_content')->nullable();
            $table->timestamp('created_at');

            $table->unique(['submission_id', 'test_case_id']);
            $table->index('submission_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_execution_results');
    }
};
