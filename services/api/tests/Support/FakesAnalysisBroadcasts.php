<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Events\AnalysisCompleted;
use App\Events\AnalysisProgress;
use Illuminate\Support\Facades\Event;

/**
 * Silences the two analysis frames for suites that are about rows, not pushes.
 *
 * The suite runs against the **reverb** broadcaster on purpose (C7: the null
 * driver skips channel authorisation, so the U-8 matrix would pass against
 * nothing), and that driver dials a Reverb server the tests do not run. Faking
 * the two events selectively — rather than `Event::fake()` — leaves model
 * events and `MessageLogged` alone, which several of these suites assert on.
 *
 * `BatchCompletionTest` is where the frames themselves are checked.
 */
trait FakesAnalysisBroadcasts
{
    protected function fakeAnalysisBroadcasts(): void
    {
        Event::fake([AnalysisProgress::class, AnalysisCompleted::class]);
    }
}
