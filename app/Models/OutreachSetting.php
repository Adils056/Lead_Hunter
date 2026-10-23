<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachSetting extends Model
{
    use HasFactory;

    protected $table = 'outreach_settings';

    protected $fillable = [
        'mode',
        'sender_name',
        'sender_email',
        'sending_domain',
        'reply_to_email',
        'allowed_countries',
        'approved_services',
        'min_lead_score',
        'min_evidence_confidence',
        'require_verified_email',
        'allow_shared_inbox',
        'max_daily_sends',
        'max_sends_per_recipient',
        'sending_window_start',
        'sending_window_end',
        'sending_timezone',
        'follow_up_delay_days',
        'max_follow_ups',
        'emergency_stop',
        'emergency_stop_reason',
        'controlled_pilot_recipient',
        'activated_at',
        'activated_by',
    ];

    protected $casts = [
        'allowed_countries' => 'array',
        'approved_services' => 'array',
        'min_lead_score' => 'integer',
        'min_evidence_confidence' => 'integer',
        'require_verified_email' => 'boolean',
        'allow_shared_inbox' => 'boolean',
        'max_daily_sends' => 'integer',
        'max_sends_per_recipient' => 'integer',
        'follow_up_delay_days' => 'integer',
        'max_follow_ups' => 'integer',
        'emergency_stop' => 'boolean',
        'activated_at' => 'datetime',
    ];

    /** True when APP_URL points at a local address: unsubscribe/approval links in emails would not open for a prospect. */
    public static function publicUrlIsLocal(): bool
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.local') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')
            || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host) === 1;
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * Singleton accessor for global outreach settings.
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'mode' => 'dry_run',
            'sender_name' => 'LeadPilot Outreach',
            'sender_email' => 'sales@leadpilot.test',
            'sending_domain' => 'leadpilot.test',
            'reply_to_email' => 'sales@leadpilot.test',
            'allowed_countries' => ['Pakistan', 'United States', 'United Kingdom', 'Canada', 'Australia', 'Germany'],
            'approved_services' => [
                'web_redesign',
                'conversion_optimization',
                'seo_technical',
                'laravel_php',
                'custom_software',
                'web_dev',
                'ecommerce',
                'erp_pos',
                'api_integration',
                'saas_mvp',
                'mobile_app',
                'ai_automation',
                'database_optimization',
                'maintenance_support',
            ],
            'min_lead_score' => \App\Support\ScoreBands::qualified(),
            'min_evidence_confidence' => 65,
            'require_verified_email' => true,
            'allow_shared_inbox' => false,
            'max_daily_sends' => 25,
            'max_sends_per_recipient' => 2,
            'sending_window_start' => '09:00',
            'sending_window_end' => '18:00',
            'sending_timezone' => 'Asia/Karachi',
            'follow_up_delay_days' => 3,
            'max_follow_ups' => 2,
            'emergency_stop' => false,
        ]);
    }

    public function isDryRun(): bool
    {
        return $this->mode === 'dry_run';
    }

    public function isControlledPilot(): bool
    {
        return $this->mode === 'controlled_pilot';
    }

    public function isLive(): bool
    {
        return $this->mode === 'live';
    }

    public function isEmergencyStopped(): bool
    {
        return (bool) $this->emergency_stop;
    }

    /**
     * Check if current time is within configured sending hours.
     */
    public function isWithinSendingWindow(): bool
    {
        $tz = $this->sending_timezone ?: 'Asia/Karachi';
        $now = Carbon::now($tz);

        // Don't send on weekends if desired, or strictly check time window
        $startTime = Carbon::createFromTimeString($this->sending_window_start, $tz);
        $endTime = Carbon::createFromTimeString($this->sending_window_end, $tz);

        return $now->between($startTime, $endTime);
    }
}
