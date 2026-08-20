<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Every five minutes, end the work nobody answered for. The `scheduler`
| service in `docker-compose.yml` runs `schedule:work`, which is what fires
| these in a deployment (added by E8; before that the api container was FPM
| only and neither command ran outside development and tests).
|
| D-82 covers the batch. The submission sweeper is its counterpart, added
| because a grading job that dead-letters -- or a publish the broker refused --
| left a row unfinished for ever and a student's verdict strip waiting on an
| `execution.completed` that was never coming (v3 §7, 2026-08-20).
*/
Schedule::command('analysis:sweep-timeouts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('submissions:sweep-timeouts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
