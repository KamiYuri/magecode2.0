<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Roles live here rather than on users: the same person can be an
        // instructor in one section and a student in another (D-04).
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('section_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['section_id', 'user_id']);
            $table->index('user_id');
            $table->index(['section_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_members');
        Schema::dropIfExists('organization_members');
    }
};
