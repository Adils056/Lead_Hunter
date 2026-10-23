<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0-3 / P1-6: an MX lookup alone was stored as "verified" and the free probe's catch-all as "risky".
 * Adds the owner switch `allow_mx_only` (default off) and re-labels existing results to the EmailStatus vocabulary:
 *  - free check, verified without an SMTP answer  -> mx_only
 *  - free check, risky without an SMTP answer (unprobed free mailbox) -> mx_only
 *  - free check, risky with an SMTP answer (catch-all) -> catch_all
 * Each lead then takes the status of its address's latest verification. Leads still "mx_only" are re-probed by
 * `php artisan leadpilot:reverify-emails`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_settings', function (Blueprint $table) {
            $table->boolean('allow_mx_only')->default(false)->after('require_verified_email');
        });

        $free = fn () => DB::table('email_verifications')->where(fn ($q) => $q->where('provider', 'like', 'dns_check%')->orWhere('provider', 'like', 'smtp_check%'));
        $free()->where('status', 'verified')->where('has_smtp_check', false)->update(['status' => 'mx_only']);
        $free()->where('status', 'risky')->where('has_smtp_check', false)->update(['status' => 'mx_only']);
        $free()->where('status', 'risky')->where('has_smtp_check', true)->update(['status' => 'catch_all']);

        DB::table('leads')->whereNotNull('email')->whereIn('email_verification_status', ['verified', 'risky'])->orderBy('id')
            ->chunkById(500, function ($leads) {
                foreach ($leads as $lead) {
                    $latest = DB::table('email_verifications')->where('email', strtolower(trim($lead->email)))->orderByDesc('id')->value('status');
                    if ($latest && $latest !== $lead->email_verification_status && in_array($latest, ['mx_only', 'catch_all'], true)) {
                        DB::table('leads')->where('id', $lead->id)->update(['email_verification_status' => $latest]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('email_verifications')->where('status', 'mx_only')->update(['status' => 'verified']);
        DB::table('email_verifications')->where('status', 'catch_all')->where('provider', 'like', 'smtp_check%')->update(['status' => 'risky']);
        DB::table('leads')->where('email_verification_status', 'mx_only')->update(['email_verification_status' => 'verified']);

        Schema::table('outreach_settings', function (Blueprint $table) {
            $table->dropColumn('allow_mx_only');
        });
    }
};
