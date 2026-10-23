<?php

namespace App\Services\Sales;

use App\Models\Meeting;
use Carbon\Carbon;

/**
 * Proposes real free slots from configured working hours (Asia/Karachi by default),
 * excluding meetings already in the CRM. It never books anything: a meeting is only recorded
 * when the prospect confirms a time (a human logs it, or a calendar integration confirms it).
 */
class MeetingSlotService
{
    /** @return Carbon[] in local timezone */
    public function slots(?int $count = null, ?Carbon $from = null): array
    {
        $cfg = config('leadpilot.meetings');
        $tz = config('leadpilot.timezone', 'Asia/Karachi');
        $count ??= (int) $cfg['slots_to_offer'];
        $dur = (int) $cfg['duration_minutes'];
        [$startH, $endH] = $cfg['working_hours'];
        $cursor = ($from ?? now())->copy()->timezone($tz)->addDay()->setTime($startH, 0);
        $busy = Meeting::where('scheduled_start', '>=', now()->subDay())->where('status', 'scheduled')
            ->get(['scheduled_start', 'scheduled_end'])
            ->map(fn ($m) => [Carbon::parse($m->scheduled_start), Carbon::parse($m->scheduled_end ?? Carbon::parse($m->scheduled_start)->addMinutes($dur))]);

        $out = [];
        $guard = 0;
        while (count($out) < $count && $guard++ < 500) {
            if (!in_array($cursor->dayOfWeekIso, $cfg['working_days'], true) || $cursor->hour >= $endH) {
                $cursor = $cursor->addDay()->setTime($startH, 0);
                continue;
            }
            $end = $cursor->copy()->addMinutes($dur);
            $clash = $busy->contains(fn ($b) => $cursor->lt($b[1]) && $end->gt($b[0]));
            // spread offers across different days (morning / afternoon variety)
            $sameDay = collect($out)->contains(fn ($s) => $s->isSameDay($cursor));
            if (!$clash && !$sameDay) {
                $out[] = $cursor->copy();
                $cursor = $cursor->copy()->addDay()->setTime($startH + (count($out) % 2 ? 4 : 0), 0);
                continue;
            }
            $cursor = $cursor->addMinutes(30);
        }

        return $out;
    }

    public function formatted(array $slots): string
    {
        $tz = config('leadpilot.timezone', 'Asia/Karachi');

        return implode("\n", array_map(fn (Carbon $s) => '• ' . $s->format('l j M, g:i A') . " ({$tz} / " . $s->copy()->utc()->format('g:i A') . ' UTC)', $slots));
    }
}
