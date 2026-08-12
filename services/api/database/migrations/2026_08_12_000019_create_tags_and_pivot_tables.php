<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tags are course-scoped (D-15), not global.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('name', 50);
            $table->string('color', 7)->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'name']);
        });

        Schema::create('problem_programming_languages', function (Blueprint $table) {
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programming_language_id')->constrained()->cascadeOnDelete();

            $table->primary(['problem_id', 'programming_language_id']);
        });

        Schema::create('bank_problem_programming_languages', function (Blueprint $table) {
            $table->foreignId('bank_problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programming_language_id')->constrained()->cascadeOnDelete();

            $table->primary(['bank_problem_id', 'programming_language_id']);
        });

        Schema::create('bank_problem_tags', function (Blueprint $table) {
            $table->foreignId('bank_problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['bank_problem_id', 'tag_id']);
        });

        Schema::create('problem_tags', function (Blueprint $table) {
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['problem_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_tags');
        Schema::dropIfExists('bank_problem_tags');
        Schema::dropIfExists('bank_problem_programming_languages');
        Schema::dropIfExists('problem_programming_languages');
        Schema::dropIfExists('tags');
    }
};
