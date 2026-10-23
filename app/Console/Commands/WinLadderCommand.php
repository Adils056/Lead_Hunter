<?php

namespace App\Console\Commands;

use App\Services\Outreach\WinLadderService;
use Illuminate\Console\Command;

class WinLadderCommand extends Command
{
    protected $signature = 'leadpilot:win-ladder
        {--tier=A : Rung to list: R, A, B, S, C or D (or "all")}
        {--limit=30 : Leads per rung}
        {--no-refresh : Do not recompute; list what is stored}
        {--csv= : Also write the listed rung(s) to this CSV path}';

    protected $description = 'Win Ladder: rank every lead into Replied / Email now / One fix away / In sequence / Nurture / Do not email, best first';

    public function handle(WinLadderService $ladder): int
    {
        if (!$this->option('no-refresh')) {
            $t = microtime(true);
            $counts = $ladder->refreshAll();
            $this->info('Recomputed ' . array_sum($counts) . ' lead(s) in ' . round(microtime(true) - $t, 1) . ' s.');
        } else {
            $counts = $ladder->counts();
        }

        $this->table(['Rung', 'Meaning', 'Leads'], array_map(fn ($k) => [$k, WinLadderService::TIERS[$k]['label'], $counts[$k] ?? 0], WinLadderService::ORDER));

        $tiers = strtolower((string) $this->option('tier')) === 'all' ? WinLadderService::ORDER : [strtoupper((string) $this->option('tier'))];
        $rows = [];
        foreach ($tiers as $tier) {
            if (!isset(WinLadderService::TIERS[$tier])) {
                $this->error("Unknown rung {$tier}. Use one of: " . implode(', ', WinLadderService::ORDER));

                return 1;
            }
            $leads = $ladder->ranked($tier, (int) $this->option('limit'));
            $this->newLine();
            $this->line("<fg=cyan>Rung {$tier} — " . WinLadderService::TIERS[$tier]['label'] . "</> ({$leads->count()} shown, best first)");
            if ($leads->isEmpty()) {
                $this->line('  (none)');
                continue;
            }
            $i = 0;
            $this->table(['#', 'Company', 'Contact', 'Email', 'Score', 'Points', 'Why (top 2)', 'Blocker / next action'], $leads->map(function ($l) use (&$i, &$rows, $tier) {
                $i++;
                $why = implode(' · ', array_slice((array) $l->win_reasons, 0, 2));
                $next = $l->win_blockers ? implode(' · ', (array) $l->win_blockers) : (string) $l->win_next_action;
                $rows[] = [$tier, $i, $l->company?->name, $l->full_name, $l->job_title, $l->email, $l->email_verification_status, $l->overall_score, $l->win_points, implode(' | ', (array) $l->win_reasons), implode(' | ', (array) $l->win_blockers), $l->win_next_action];

                return [$i, mb_substr((string) $l->company?->name, 0, 28), mb_substr(trim($l->full_name . ' — ' . $l->job_title, ' —'), 0, 30),
                    mb_substr((string) $l->email, 0, 30) . ' (' . $l->email_verification_status . ')', $l->overall_score, $l->win_points, mb_substr($why, 0, 60), mb_substr($next, 0, 60)];
            })->all());
        }

        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fputcsv($fh, ['Rung', 'Rank', 'Company', 'Contact', 'Job title', 'Email', 'Email status', 'Score', 'Points', 'Why', 'Blockers', 'Next action']);
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->info("CSV written: {$csv} (" . count($rows) . ' rows)');
        }
        $this->newLine();
        $this->line('Points rank leads inside a rung by evidence (request > audit gap > role > deliverability > score > freshness). They are not a win probability.');

        return 0;
    }
}
