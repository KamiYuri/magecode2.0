<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reference table mapping one language to its Judge0, Monaco, Dolos
        // and CodeQL identifiers. Seeded from schema doc §3.2.
        Schema::create('programming_languages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('version', 20)->nullable();
            $table->integer('judge0_id');
            $table->string('monaco_language', 30);
            $table->string('dolos_language', 30)->nullable();
            $table->string('codeql_language', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programming_languages');
    }
};
