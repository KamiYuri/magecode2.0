<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per changed field (D-40), written by the Problem observer.
        // old/new are TEXT because description changes flow through here.
        Schema::create('problem_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_id')->constrained()->restrictOnDelete();
            $table->foreignId('edited_by')->constrained('users')->restrictOnDelete();
            $table->string('field_changed', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('edited_at');

            $table->index('problem_id');
        });

        Schema::create('section_transfer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_section_id')->constrained('sections')->restrictOnDelete();
            $table->foreignId('to_section_id')->constrained('sections')->restrictOnDelete();
            $table->foreignId('transferred_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('transferred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_transfer_logs');
        Schema::dropIfExists('problem_edit_logs');
    }
};
