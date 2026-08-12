<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            // Visibility policy per D-16; problems may override when allowed.
            $table->string('publish_mode', 10)->default('auto');
            $table->string('lock_mode', 10)->default('auto');
            $table->boolean('allow_publish_override')->default(true);
            $table->boolean('allow_lock_override')->default(true);
            $table->decimal('similarity_threshold', 3, 2)->default(0.70);
            $table->decimal('ai_detection_threshold', 3, 2)->default(0.80);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semesters');
    }
};
