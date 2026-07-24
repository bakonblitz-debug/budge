<?php

use App\Http\Middleware\DevAutoLogin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireOnboarding;
use App\Http\Middleware\VerifyHmacSignature;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('networth:snapshot')->dailyAt('23:30');
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            DevAutoLogin::class,
            RequireOnboarding::class,
            HandleInertiaRequests::class,
        ]);

        // HMAC signing for sensitive routes
        $middleware->alias([
            'hmac' => VerifyHmacSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
