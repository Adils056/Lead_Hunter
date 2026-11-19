<?php

namespace App\Console\Commands;

use App\Services\Sales\AutoReplier;
use Illuminate\Console\Command;

/** Answers prospects who replied (e-mail, WhatsApp) with an AI-written, rule-checked answer. Every 5 minutes. */
class AutoReplyCommand extends Command
{
    protected $signature = 'leadpilot:auto-reply {--dry-run : Write the answers and show them, send nothing (works while it is off)} {--limit=5}';

    protected $description = 'Answer prospects who replied by e-mail or WhatsApp (AI reads each reply; safe rules)';

    public function handle(AutoReplier $replier): int
    {
        $r = $replier->run((bool) $this->option('dry-run'), max(1, (int) $this->option('limit')));
        if (isset($r['disabled'])) {
            $this->line($r['disabled']);

            return 0;
        }
        foreach (['sent' => $this->option('dry-run') ? 'Would answer' : 'Answered', 'held' => 'Left to a person', 'skipped' => 'Skipped'] as $k => $label) {
            foreach ($r[$k] as $line) {
                $this->line("{$label}: {$line}");
            }
        }
        $this->info(count($r['sent']) . ($this->option('dry-run') ? ' would be answered' : ' answered') . ', ' . count($r['held']) . ' left to a person, '
            . count($r['skipped']) . ' skipped' . ($r['waiting'] ? ", {$r['waiting']} for the next run" : '') . '.');

        return 0;
    }
}
