<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fork-model bank (D-07): the first version has original_id = NULL and
        // later versions point back at it, so a version chain is one query.
        Schema::create('bank_problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('original_id')->nullable()->constrained('bank_problems')->restrictOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('difficulty', 10)->default('medium');
            $table->integer('time_limit');
            $table->integer('memory_limit');
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('approved');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'status']);
            $table->index(['original_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_problems');
    }
};
