<?php

namespace App\Support;

use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of the outreach numbers, used by every page (the SQA report found Dashboard, Analytics,
 * Campaigns and Outbox each counting "delivered" and "reply rate" differently, up to a 200% reply rate).
 *
 *   sent       e-mails that left (sent_at set)
 *   delivered  sent and accepted by the receiving server: not bounced (SMTP gives no later receipt)
 *   replied    e-mailed prospects who answered, auto-replies (out of office) not counted
 *   reply rate replied prospects / e-mailed prospects
 */
class OutreachMetrics
{
    public const POSITIVE = ['interested', 'request_info', 'meeting_request', 'positive'];
    public const NOT_A_REPLY = ['out_of_office'];

    /** @param \Closure(Builder): void|null $leadFilter extra conditions on the prospect (country, service ...) */
    public static function summary(?Carbon $from = null, ?\Closure $leadFilter = null, ?int $campaignId = null): array
    {
        $sent = CampaignRecipient::query()->whereNotNull('sent_at')
            ->when($from, fn ($q) => $q->where('sent_at', '>=', $from))
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($leadFilter, fn ($q) => $q->whereHas('lead', $leadFilter));
        $sentCount = (clone $sent)->count();
        $bounced = (clone $sent)->where(fn ($q) => $q->whereNotNull('bounced_at')->orWhere('status', 'bounced'))->count();
        $leadIds = (clone $sent)->distinct()->pluck('lead_id');
        $answers = InboundReply::whereIn('lead_id', $leadIds)->get(['lead_id', 'category', 'classification'])
            ->reject(fn ($r) => in_array($r->category ?? $r->classification, self::NOT_A_REPLY, true));
        $replied = $answers->pluck('lead_id')->unique()->count();
        $positive = $answers->filter(fn ($r) => in_array($r->category ?? $r->classification, self::POSITIVE, true))->pluck('lead_id')->unique()->count();
        $emailed = $leadIds->count();

        return [
            'sent' => $sentCount,
            'delivered' => $sentCount - $bounced,
            'bounced' => $bounced,
            'emailed_prospects' => $emailed,
            'replied' => $replied,
            'positive' => $positive,
            'delivery_rate' => $sentCount ? round(100 * ($sentCount - $bounced) / $sentCount, 1) : 0,
            'bounce_rate' => $sentCount ? round(100 * $bounced / $sentCount, 1) : 0,
            'reply_rate' => $emailed ? round(100 * $replied / $emailed, 1) : 0,
            'positive_rate' => $replied ? round(100 * $positive / $replied, 1) : 0,
        ];
    }
}
