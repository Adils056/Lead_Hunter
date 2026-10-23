<?php

namespace App\Jobs;

use App\Services\Autopilot\HealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Proves a queue worker is actually processing jobs (not just that the scheduler is alive). */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(HealthService $health): void
    {
        $health->beat('queue_worker', ['pid' => getmypid()]);
    }
}
