<?php

declare(strict_types=1);

namespace App\Providers;

use App\Messaging\AmqpPublisher;
use App\Messaging\JobPublisher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton so one connection serves the request rather than one per
        // publish; a test swaps this binding for FakeJobPublisher.
        $this->app->singleton(JobPublisher::class, fn (): AmqpPublisher => new AmqpPublisher(
            config('amqp')
        ));
    }

    public function boot(): void
    {
        $this->configureRateLimiters();

        // Loaded here rather than through withBroadcasting(), which also
        // registers the auth endpoint for both GET and POST. Pusher clients
        // POST, an undeclared GET fails route conformance, and the endpoint
        // belongs under /api/v1 anyway (U-3) — so routes/api.php declares it.
        require base_path('routes/channels.php');
    }

    /**
     * Limits from the rate-limit table in openapi.yml.
     *
     * Everything but `auth` is keyed per user, not per IP: a lab full of
     * students shares an address, and one of them exhausting a limit must not
     * close the door on the rest (U-3). `auth` is the deliberate exception —
     * before login there is no user to key on, and per-IP is the point.
     *
     * The submission, analysis and upload limiters are defined here before
     * their endpoints exist, so C2, D1, B7 and B12 attach a name instead of
     * choosing a number.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        foreach (['api' => 120, 'submissions' => 10, 'analysis' => 5, 'uploads' => 10] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute($perMinute)
                ->by($this->callerKey($request)));
        }
    }

    private function callerKey(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
