<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->text('input');
            $table->text('expected_output');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['problem_id', 'order']);
            $table->index(['problem_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_cases');
    }
};
