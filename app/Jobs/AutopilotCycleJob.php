<?php

namespace App\Jobs;

use App\Services\Autopilot\AutopilotRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class AutopilotCycleJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1700;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    public function backoff(): array
    {
        return [60, 300, 900]; // exponential-ish retry for transient failures
    }

    public function handle(AutopilotRunner $runner): void
    {
        $runner->run();
    }
}
