<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_problem_test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_problem_id')->constrained()->cascadeOnDelete();
            $table->text('input');
            $table->text('expected_output');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_problem_test_cases');
    }
};
