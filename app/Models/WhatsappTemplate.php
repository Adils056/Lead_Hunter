<?php

namespace App\Models;

use App\Support\CompanyName;
use Illuminate\Database\Eloquent\Model;

/** A WhatsApp message with {placeholders}; filled per lead and opened in WhatsApp for a person to send. */
class WhatsappTemplate extends Model
{
    public const PURPOSES = ['first_contact' => 'First contact', 'follow_up' => 'Follow-up', 'reply' => 'Answer a reply', 'meeting' => 'Meeting / call'];

    public const PLACEHOLDERS = ['first_name', 'company', 'finding', 'service', 'sender', 'agency', 'phone', 'booking_url'];

    protected $fillable = ['name', 'purpose', 'body', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public const STARTERS = [
        ['First contact — website finding', 'first_contact', "Hi {first_name}, this is {sender} from {agency}. I was looking at {company} and noticed {finding}. We fix exactly this for local businesses. Would a 10-minute call this week help?"],
        ['Follow-up after email', 'follow_up', "Hi {first_name}, {sender} from {agency} here. I emailed about {finding}. Happy to share the 3 fixes I would make first, free. Shall I send them here?"],
        ['Answer an interested reply', 'reply', "Thanks {first_name}! Great to hear from you. When suits you for a short call? You can also pick a time here: {booking_url}"],
        ['Confirm a meeting', 'meeting', "Hi {first_name}, confirming our call about {service}. I will call you on this number. If the time no longer works, just reply here. — {sender}, {agency}"],
    ];

    public static function ensureStarters(): void
    {
        if (!self::exists()) {
            foreach (self::STARTERS as [$name, $purpose, $body]) {
                self::create(['name' => $name, 'purpose' => $purpose, 'body' => $body]);
            }
        }
    }

    /** The message for one lead. A placeholder with no value is replaced by a neutral word, never left as {braces}. */
    public function render(Lead $lead): string
    {
        $c = $lead->company;
        $settings = OutreachSetting::current();
        $facts = app(\App\Services\Sales\ConversionAssistantService::class)->facts($lead);
        $values = [
            'first_name' => $lead->contact_status === 'identified' && $lead->first_name ? $lead->first_name : 'there',
            'company' => CompanyName::forEmail($c?->name) ?? ($c?->domain ?: 'your business'),
            'finding' => $facts[0]['text'] ?? 'a few quick wins on your website',
            'service' => \App\Support\ServiceCatalog::label(($lead->relevant_services ?? [])[0] ?? null) ?: 'your project',
            'sender' => config('leadpilot.agency.sender_name'),
            'agency' => config('leadpilot.agency.name'),
            'phone' => $settings->contact_phone ?: '',
            'booking_url' => $settings->booking_url ?: (config('leadpilot.agency.booking_url') ?: config('leadpilot.agency.website')),
        ];
        $text = preg_replace_callback('/\{(' . implode('|', self::PLACEHOLDERS) . ')\}/', fn ($m) => (string) $values[$m[1]], $this->body);

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
    }
}
