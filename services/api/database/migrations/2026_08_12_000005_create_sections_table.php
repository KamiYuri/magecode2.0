<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sections are the isolation boundary (D-04): every scoped query
        // resolves through section membership.
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->string('name', 50);
            $table->text('description')->nullable();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['semester_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
