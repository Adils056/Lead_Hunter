<?php

namespace App\Support;

/**
 * Branches of national chains and franchises (seen live: 6 Allstate agents, 8 State Farm offices, Premier Inn,
 * Kudu, 24 Hour Fitness) are run from head office: a local agency pitch to a branch never reaches a buyer.
 * Directory entries named only by their category ("Bakery", "Gym", "Various") are not a business we can address.
 */
class ChainBrand
{
    public const DOMAINS = [
        'allstate.com', 'statefarm.com', 'farmers.com', 'agents.farmers.com', 'nationwide.com', 'edwardjones.com', 'hrblock.com', 'libertymutual.com',
        'progressive.com', 'geico.com', 'aflac.com', 'remax.com', 'kw.com', 'century21.com', 'coldwellbanker.com', 'sothebysrealty.com', 'compass.com',
        'mcdonalds.com', 'kfc.com', 'subway.com', 'starbucks.com', 'dominos.com', 'pizzahut.com', 'burgerking.com', 'dunkindonuts.com', 'costa.co.uk', 'greggs.co.uk',
        '24hourfitness.com', 'anytimefitness.com', 'planetfitness.com', 'orangetheory.com', 'puregym.com', 'thegymgroup.com', 'goldsgym.com',
        'premierinn.com', 'travelodge.co.uk', 'marriott.com', 'hilton.com', 'ihg.com', 'hyatt.com', 'accor.com', 'kudu.com.sa', 'hardees.com.sa',
        'mydentist.co.uk', 'specsavers.co.uk', 'boots.com', 'superdrug.com', 'cvs.com', 'walgreens.com', 'aspendental.com', 'kumon.com', 'sylvanlearning.com',
        'ups.com', 'theupsstore.com', 'fedex.com', 'enterprise.com', 'hertz.com', 'avis.com', 'jiffylube.com', 'midas.com', 'meineke.com',
        // national enterprises: they buy from big vendors through procurement, never from a cold agency email (seen live: Schwab, FedEx, UPS, Amazon Locker)
        'schwab.com', 'amazon.com', 'amazon.co.uk', 'amazon.sa', 'amazon.ae', 'dhl.com', 'usps.com', 'royalmail.com', 'walmart.com', 'target.com', 'costco.com',
        'homedepot.com', 'lowes.com', 'bestbuy.com', 'ikea.com', 'tesco.com', 'sainsburys.co.uk', 'asda.com', 'chase.com', 'bankofamerica.com', 'wellsfargo.com',
        'citi.com', 'hsbc.com', 'hsbc.co.uk', 'barclays.co.uk', 'lloydsbank.com', 'natwest.com', 'santander.co.uk', 'apple.com', 'samsung.com', 'verizon.com',
        'att.com', 't-mobile.com', 'vodafone.co.uk', 'ee.co.uk', 'shell.com', 'bp.com', 'exxonmobil.com', 'aramco.com', 'stc.com.sa', 'etisalat.ae', 'du.ae',
        'jazz.com.pk', 'telenor.com.pk', 'zong.com.pk', 'ptcl.com.pk', 'hbl.com', 'ubldigital.com', 'mcb.com.pk', 'meezanbank.com',
    ];

    public const NAMES = '/^(allstate|state farm|farmers insurance|nationwide|edward jones|h&r block|liberty mutual|progressive|geico|re\/?max|keller williams|century 21|coldwell banker|mcdonald\'?s|kfc|subway|starbucks|domino\'?s|pizza hut|burger king|dunkin\'?|costa coffee|greggs|24 hour fitness|anytime fitness|planet fitness|orangetheory|puregym|the gym group|gold\'?s gym|premier inn|travelodge|marriott|hilton|holiday inn|hyatt|ibis|kudu|hardee\'?s|mydentist|specsavers|boots|superdrug|cvs|walgreens|aspen dental|kumon|sylvan|the ups store|fedex|enterprise rent-a-car|hertz|avis|jiffy lube|midas|meineke|united parcel service|ups store|charles schwab|amazon( locker| hub| fresh| warehouse)?|dhl|usps|royal mail|walmart|costco|home depot|best buy|ikea|tesco|sainsbury\'?s|asda|hsbc|barclays|lloyds bank|natwest|santander|verizon|vodafone|aramco)\b/i';

    /** Category words that are not a name. */
    public const GENERIC = '/^(various|bakery|gym|fitness|pharmacy|chemist|restaurant|cafe|café|coffee|hotel|salon|barber|dentist|dental surgery|dental clinic|clinic|hospital|school|academy|lawyer|law office|accountant|accountants|estate agent|real estate|shop|store|supermarket|grocery|mosque|church|office|company|business|atm|bank|vacant|parking)$/iu';

    public static function reason(?string $name, ?string $domain): ?string
    {
        $domain = strtolower((string) $domain);
        foreach (self::DOMAINS as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return "Branch of a national chain ({$d}): run from head office";
            }
        }
        $name = trim((string) $name);
        if ($name !== '' && preg_match(self::NAMES, $name)) {
            return "Branch of a national chain ({$name}): run from head office";
        }
        if ($name !== '' && preg_match(self::GENERIC, $name)) {
            return "\"{$name}\" is a category, not a business name";
        }

        return null;
    }
}
