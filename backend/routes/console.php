<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(
        Inspiring::quote()
    );
})->purpose(
    'Display an inspiring quote'
);

/*
 * Expiration correctness does not depend on a single
 * scheduler node: the Cart row lock makes lifecycle
 * transitions idempotent and serialized.
 *
 * withoutOverlapping limits unnecessary duplicate work
 * on one scheduler while retaining correctness if more
 * than one application node runs the scheduler.
 *
 * The 5-minute stale lock is intentionally bounded so a
 * crashed worker cannot suppress cleanup for a full day.
 */
Schedule::command(
    'carts:expire --batch=100'
)
    ->everyMinute()
    ->withoutOverlapping(5);
