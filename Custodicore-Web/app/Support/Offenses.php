<?php

namespace App\Support;

/**
 * Offenses the Record Officer picks from when recording a PDL's case.
 *
 * The PDL's classification (which decides their visiting days) is derived
 * from these: any case under RA 9165 (Comprehensive Dangerous Drugs Act)
 * makes the PDL drug-related; everything else is non-drug-related. See
 * Pdl::refreshClassification().
 *
 * Sample list of common Philippine offenses — replace or extend it with the
 * facility's own list. Keys are stored on pdl_legal_records.offense_code,
 * so don't rename a key once it's in use (add a new one instead).
 */
class Offenses
{
    public const OTHER = 'other';

    /** group label => [code => [label, drug-related?]] */
    public const GROUPS = [
        'Dangerous Drugs (RA 9165)' => [
            'ra9165_sale' => ['Sale / trading of dangerous drugs (Sec. 5)', true],
            'ra9165_possession' => ['Possession of dangerous drugs (Sec. 11)', true],
            'ra9165_paraphernalia' => ['Possession of drug paraphernalia (Sec. 12)', true],
            'ra9165_use' => ['Use of dangerous drugs (Sec. 15)', true],
            'ra9165_den' => ['Maintaining a drug den (Sec. 6)', true],
            'ra9165_manufacture' => ['Manufacture of dangerous drugs (Sec. 8)', true],
            'ra9165_cultivation' => ['Cultivation of marijuana / drug plants (Sec. 16)', true],
            'ra9165_other' => ['Other RA 9165 violation', true],
        ],
        'Crimes Against Persons' => [
            'murder' => ['Murder', false],
            'homicide' => ['Homicide', false],
            'parricide' => ['Parricide', false],
            'frustrated_attempted_murder' => ['Frustrated / attempted murder or homicide', false],
            'physical_injuries' => ['Physical injuries', false],
        ],
        'Crimes Against Property' => [
            'theft' => ['Theft', false],
            'qualified_theft' => ['Qualified theft', false],
            'robbery' => ['Robbery', false],
            'estafa' => ['Estafa / swindling', false],
            'carnapping' => ['Carnapping (RA 10883)', false],
            'malicious_mischief' => ['Malicious mischief', false],
        ],
        'Crimes Against Chastity, Women & Children' => [
            'rape' => ['Rape', false],
            'acts_of_lasciviousness' => ['Acts of lasciviousness', false],
            'vawc' => ['Violence Against Women and Children (RA 9262)', false],
            'child_abuse' => ['Child abuse (RA 7610)', false],
            'trafficking' => ['Trafficking in persons (RA 9208 / 10364)', false],
        ],
        'Other Special Laws' => [
            'illegal_firearms' => ['Illegal possession of firearms (RA 10591)', false],
            'illegal_gambling' => ['Illegal gambling (PD 1602)', false],
            'cybercrime' => ['Cybercrime (RA 10175)', false],
            'bp22' => ['Bouncing checks (BP 22)', false],
        ],
    ];

    /** code => [label, drug] for every listed offense. */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function codes(): array
    {
        return [...array_keys(self::all()), self::OTHER];
    }

    public static function label(?string $code): ?string
    {
        return self::all()[$code][0] ?? null;
    }

    public static function isDrug(?string $code): bool
    {
        return (bool) (self::all()[$code][1] ?? false);
    }

    /**
     * For cases recorded before offenses were picked from this list (free
     * text only): treat obvious drug cases as drug-related.
     */
    public static function looksDrugRelated(?string $offenseText): bool
    {
        return (bool) preg_match('/9165|dangerous drug|shabu|marijuana|methamphetamine|drug den|paraphernalia/i', (string) $offenseText);
    }
}
