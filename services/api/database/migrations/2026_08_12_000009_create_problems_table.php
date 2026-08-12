<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            // Cloned problems survive the bank entry being deleted (D-43).
            $table->foreignId('bank_problem_id')->nullable()->constrained('bank_problems')->nullOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('difficulty', 10)->default('medium');
            $table->string('group_label', 100)->nullable();
            $table->integer('order')->nullable();
            $table->integer('max_submissions')->nullable();
            $table->integer('time_limit');
            $table->integer('memory_limit');
            $table->timestamp('activation_time')->nullable();
            $table->timestamp('lock_time')->nullable();
            $table->string('publish_mode_override', 10)->nullable();
            $table->string('lock_mode_override', 10)->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_locked')->default(false);
            // Manual cross-section matching for SIM (D-58); set only when the
            // problem has no bank_problem_id to match on.
            $table->uuid('manual_match_group_id')->nullable();
            $table->timestamp('testcases_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('section_id');
            $table->index(['section_id', 'group_label']);
            $table->index('manual_match_group_id');
            $table->index('activation_time');
            $table->index('lock_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problems');
    }
};
