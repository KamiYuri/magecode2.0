<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_detection_results', function (Blueprint $table) {
            $table->id();
            // One probability per analysed submission, hence the unique FK.
            $table->foreignId('analysis_submission_id')->constrained('analysis_submissions')->cascadeOnDelete();
            $table->decimal('probability', 5, 4);
            $table->timestamp('created_at');

            $table->unique('analysis_submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_detection_results');
    }
};
