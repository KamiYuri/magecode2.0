<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // D-12's System Admin is platform-scoped, so no membership row can
            // express it. Set by `php artisan magecode:make-system-admin`;
            // no request path ever writes this column.
            $table->boolean('is_system_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_system_admin');
        });
    }
};
