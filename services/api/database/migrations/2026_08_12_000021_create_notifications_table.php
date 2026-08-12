<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Covers the unread-notifications query the bell icon runs on
            // every page load.
            $table->index(
                ['notifiable_id', 'notifiable_type', 'read_at', 'created_at'],
                'idx_notifications_unread'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
