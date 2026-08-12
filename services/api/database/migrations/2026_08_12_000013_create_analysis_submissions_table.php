<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The three per-service status columns are accepted tech debt: they
        // are hardcoded for the known services. A fifth service means
        // refactoring to an analysis_service_statuses table (schema doc §5.3).
        Schema::create('analysis_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('analysis_problem_id')->constrained('analysis_problems')->cascadeOnDelete();
            $table->string('plagiarism_status', 20)->default('in_queue');
            $table->string('ai_detection_status', 20)->default('in_queue');
            $table->string('vuln_scan_status', 20)->default('in_queue');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('analysis_problem_id');
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_submissions');
    }
};
