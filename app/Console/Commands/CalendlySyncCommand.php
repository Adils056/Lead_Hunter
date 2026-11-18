<?php

namespace App\Console\Commands;

use App\Services\Integrations\CalendlyClient;
use App\Services\Sales\CalendlyBookings;
use Illuminate\Console\Command;

/** Reads new and cancelled Calendly bookings and records them on their leads (every 10 minutes). */
class CalendlySyncCommand extends Command
{
    protected $signature = 'leadpilot:calendly-sync {--dry-run : List what would be recorded} {--check : Only test the token}';

    protected $description = 'Record Calendly bookings as meetings on their leads (stage, follow-ups, tasks, alerts)';

    public function handle(CalendlyBookings $bookings, CalendlyClient $client): int
    {
        if ($this->option('check')) {
            if (!$client->isConfigured()) {
                $this->error('CALENDLY_TOKEN is not set in .env.');

                return 1;
            }
            try {
                $me = $client->me();
                $this->info('Calendly connected: ' . ($me['name'] ?? '?') . ' <' . ($me['email'] ?? '?') . '>, booking page ' . ($me['scheduling_url'] ?? '?'));

                return 0;
            } catch (\Throwable $e) {
                $this->error('Calendly refused the token: ' . $e->getMessage());

                return 1;
            }
        }

        $r = $bookings->sync((bool) $this->option('dry-run'));
        if (isset($r['skipped'])) {
            $this->line('Skipped: ' . $r['skipped']);

            return 0;
        }
        if (isset($r['error'])) {
            $this->error('Calendly error: ' . $r['error']);

            return 1;
        }
        foreach (['booked' => 'Meeting booked', 'cancelled' => 'Cancelled', 'unmatched' => 'Not matched to a lead'] as $k => $label) {
            foreach ($r[$k] as $line) {
                $this->line("{$label}: {$line}");
            }
        }
        $this->info(count($r['booked']) . ' booked, ' . count($r['cancelled']) . ' cancelled, ' . count($r['unmatched']) . ' not matched.');

        return 0;
    }
}
