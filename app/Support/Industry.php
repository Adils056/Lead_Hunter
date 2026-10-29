<?php

namespace App\Support;

/**
 * Maps the raw category a source gives ("Accountants", "Logistics company", "Builder", "Dentist") onto one short,
 * fixed list for filters and reports. The raw value stays in companies.industry, because scoring and lookalike
 * search match on it; companies.industry_group holds the group.
 */
class Industry
{
    public const OTHER = 'Other';

    /** group => regex on the lower-cased raw category; first match wins, so specific groups come first. */
    public const GROUPS = [
        'Dental' => '/dent|orthodont/',
        'Healthcare' => '/clinic|hospital|doctor|medical|health|physio|pharmac|chemist|optic|optometr|laborator|diagnost|nursing|veterinar|\bvet\b|hearing|skin care|dermatolog|psycholog|therap/',
        'Beauty & Wellness' => '/beauty|salon|hair|barber|spa\b|nail|cosmetic|massage|tattoo|wellness/',
        'Fitness & Sports' => '/gym|fitness|sport|yoga|martial|swimming|dance|golf|stadium/',
        'Restaurants & Cafes' => '/restaurant|cafe|café|coffee|fast.?food|food court|pub\b|\bbar\b|bistro|pizza|grill|diner|ice.?cream|juice|tea house/',
        'Food & Bakery' => '/baker|confection|pastry|butcher|grocer|supermarket|convenience|deli|food|beverage|greengrocer|sweets|catering/',
        'Hotels & Travel' => '/hotel|motel|guest.?house|hostel|resort|travel|tour|hospitality|apartment hotel/',
        'Fashion & Jewellery' => '/cloth|fashion|boutique|tailor|shoe|jewel|watch|bag|textile|garment/',
        'Furniture & Home' => '/furniture|interior|kitchen|bathroom|carpet|curtain|bed|home.?(goods|decor)|houseware|doityourself|hardware|paint|lighting/',
        'Automotive' => '/\bcar\b|cars|car_|auto|motor|vehicle|tyre|tire|garage|car.?wash|dealership/',
        'Electronics & Mobile' => '/electronic|mobile.?phone|computer shop|appliance|camera/',
        'Real Estate' => '/real.?estate|estate.?agent|property|letting|realtor/',
        'Construction & Architecture' => '/construct|builder|contractor|architect|engineer(ing)? firm|plumb|electrician|roof|carpent|surveyor/',
        'Legal' => '/lawyer|legal|solicitor|attorney|notary|law firm|barrister/',
        'Accounting & Finance' => '/account|tax|bookkeep|audit|financ|bank|\batm\b|insur|money|credit|invest|exchange|payment/',
        'Education' => '/school|college|universit|academ|educat|tutor|training|kindergarten|nursery|institute|driving school/',
        'Software & IT' => '/software|\bit\b|i\.t\.|information technology|computer|tech|saas|internet|web|digital|data|telecom|startup/',
        'Marketing & Media' => '/marketing|advertis|agency|media|design|print|photograph|publish|\bpr\b|studio/',
        'Logistics & Transport' => '/logistic|transport|courier|shipping|freight|cargo|warehouse|delivery|moving|taxi|airline/',
        'Manufacturing & Industry' => '/manufactur|factory|industrial|industry|chemical|plastic|steel|machinery|mill\b/',
        'Public Sector' => '/public sector|government|council|ministry|municipal|authority|police|embassy|townhall/',
        'Non-profit & Religious' => '/non.?profit|charity|ngo|foundation|association|mosque|church|temple|religio|place of worship|worship|community/',
        'Retail' => '/shop|store|retail|mall|market|boutique|gift|toy|baby|book|comic|stationer|florist|pet|variety|outlet|trade/',
    ];

    /** Categories that are not a trading business at all: skip at import. */
    public const NOT_A_BUSINESS = '/^(vacant|vacant shop|disused|abandoned|closed|empty|demolished|construction site)$/';

    public static function group(?string $raw): ?string
    {
        $raw = strtolower(trim(str_replace('_', ' ', (string) $raw)));
        if ($raw === '') {
            return null;
        }
        foreach (self::GROUPS as $group => $re) {
            if (preg_match($re, $raw)) {
                return $group;
            }
        }

        return self::OTHER;
    }

    public static function isNotABusiness(?string $raw): bool
    {
        return (bool) preg_match(self::NOT_A_BUSINESS, strtolower(trim(str_replace('_', ' ', (string) $raw))));
    }
}
