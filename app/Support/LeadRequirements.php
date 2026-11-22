<?php

namespace App\Support;

/**
 * The two tick-box groups in the lead form's Requirements section.
 *
 * Keyed by lead column; the options themselves live in config/crm.php. Keys
 * are strings throughout — PHP turns the floors' '1'..'14' into integers as
 * array keys, and a stored 1 beside a posted '1' would read as a change.
 */
class LeadRequirements
{
    /** Lead column => the config/crm.php list it is picked from. */
    private const OPTIONS = [
        'residence_types' => 'residence_types',
        'preferred_floors' => 'floors',
    ];

    /** @return list<string> */
    public static function keys(string $field): array
    {
        return array_map('strval', array_keys(self::list($field)));
    }

    /**
     * The options as the form renders them, in order. A list rather than a
     * map: a browser re-sorts an object's numeric keys.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function options(string $field): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'label' => self::list($field)[$key]],
            self::keys($field),
        );
    }

    /**
     * What is stored: known keys, once each, in the config's order — or null
     * when that leaves nothing.
     *
     * @param  array<int, mixed>|null  $selected
     * @return list<string>|null
     */
    public static function normalise(string $field, ?array $selected): ?array
    {
        return array_values(array_intersect(self::keys($field), array_map('strval', $selected ?? []))) ?: null;
    }

    /**
     * "2 BHK, 3 BHK", or null when nothing is selected. A key whose option has
     * since been removed from the config still shows, as the key itself.
     *
     * @param  array<int, mixed>|null  $selected
     */
    public static function labels(string $field, ?array $selected): ?string
    {
        $labels = array_map(fn ($key) => self::list($field)[$key] ?? (string) $key, $selected ?? []);

        return $labels === [] ? null : implode(', ', $labels);
    }

    /** @return array<int|string, string> */
    private static function list(string $field): array
    {
        return config('crm.'.self::OPTIONS[$field]);
    }
}
