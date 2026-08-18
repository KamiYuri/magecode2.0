<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| D-82: every five minutes, end the batches nobody answered for.
|
| NOTE: no process in `docker-compose.yml` runs `schedule:run` — the api
| container is nginx + FPM only — so this fires in development and in tests but
| not yet in a deployment. A scheduler process belongs to E8/G-phase.
*/
Schedule::command('analysis:sweep-timeouts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
