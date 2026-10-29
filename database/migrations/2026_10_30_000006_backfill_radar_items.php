<?php

use App\Models\BuyingSignal;
use App\Services\Radar\RadarService;
use Illuminate\Database\Migrations\Migration;

/** Put the buying signals that already exist on the Buyer's Radar once. */
return new class extends Migration
{
    public function up(): void
    {
        $radar = app(RadarService::class);
        BuyingSignal::with('company')->where('verification_status', '<>', 'dismissed')->orderBy('id')
            ->chunkById(500, fn ($signals) => $signals->each(fn ($s) => $radar->mirror($s)));
    }

    public function down(): void
    {
    }
};
