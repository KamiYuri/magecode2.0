<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Submissions are never deleted (D-52), hence no soft delete and
        // RESTRICT on every parent.
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_id')->constrained()->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('programming_language_id')->constrained('programming_languages')->restrictOnDelete();
            $table->string('file_path', 500);
            $table->string('file_name');
            // Written by CES directly (D-81).
            $table->string('execution_status', 30)->default('in_queue');
            $table->integer('testcases_passed')->default(0);
            $table->integer('testcases_total')->default(0);
            $table->boolean('is_outdated')->default(false);
            $table->timestamps();

            $table->index(['problem_id', 'creator_id']);
            $table->index('creator_id');
            $table->index('execution_status');
            $table->index(['problem_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
