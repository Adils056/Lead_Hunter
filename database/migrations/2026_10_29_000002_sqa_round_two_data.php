<?php

use App\Models\Lead;
use App\Services\Discovery\ProspectIngestor;
use App\Support\ScoreBands;
use Illuminate\Database\Migrations\Migration;

/**
 * SQA report round 2, stored data:
 *  - bug 11: leads left "Qualified" after their score fell below the threshold go back to "New";
 *  - bug 6: shared-inbox leads whose address is "first.last@" become that named person (tender contacts excepted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Lead::where('lifecycle_stage', 'qualified')->where('overall_score', '<', ScoreBands::qualified())->update(['lifecycle_stage' => 'new']);

        Lead::where('contact_status', 'generic_inbox')->whereNull('first_name')->whereNotNull('email')
            ->where(fn ($q) => $q->whereNull('job_title')->orWhere('job_title', '!=', 'Tender contact (published)'))
            ->each(function (Lead $l) {
                $local = strtolower((string) strstr((string) $l->email, '@', true));
                if (preg_match('/^([a-z]{2,20})[._]([a-z]{2,20})$/', $local, $m) && !preg_match(ProspectIngestor::NOT_A_NAME, $m[1]) && !preg_match(ProspectIngestor::NOT_A_NAME, $m[2])) {
                    $l->update(['first_name' => ucfirst($m[1]), 'last_name' => ucfirst($m[2]), 'full_name' => ucfirst($m[1]) . ' ' . ucfirst($m[2]), 'contact_status' => 'identified']);
                }
            });
    }

    public function down(): void
    {
        // data repair: nothing to undo
    }
};
