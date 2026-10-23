<?php

namespace App\Jobs;

use App\Models\IcpProfile;
use App\Services\Hunting\LeadHuntService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunHuntJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public IcpProfile $icp, public ?int $userId = null, public bool $audit = true, public ?string $onlySlug = null, public bool $paidApproved = false)
    {
    }

    public function uniqueId(): string
    {
        return 'hunt-' . $this->icp->id . '-' . ($this->onlySlug ?? 'all');
    }

    public function handle(LeadHuntService $hunter): void
    {
        $hunter->hunt($this->icp, $this->userId, $this->audit, 50, $this->onlySlug, $this->paidApproved);
    }
}
