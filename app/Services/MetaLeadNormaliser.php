<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Meta's `field_data` turned into the columns a lead has.
 *
 * This is the part of the integration that cannot be made tidy, because the
 * input is not tidy: the client builds the lead form in Ads Manager and names
 * the questions themselves. One form asks "full_name", the next asks
 * "first_name" and "last_name", a third asks "your_name" — and every one of
 * them is a form somebody has already spent money advertising, so the importer
 * has to cope rather than insist.
 *
 * The rule throughout is: understand what you can, log what you cannot, and
 * never lose a lead over a question nobody anticipated. A lead with an
 * unrecognised "preferred_bhk" answer is still a lead worth calling.
 */
class MetaLeadNormaliser
{
    /**
     * @param  list<array{name?: string, values?: list<string>}>  $fieldData
     * @return array{first_name: string, last_name: string, mobile_number: string, email: ?string, unrecognised: list<string>}
     *
     * @throws RuntimeException when there is no usable phone number
     */
    public function normalise(array $fieldData, string $leadgenId): array
    {
        [$values, $unrecognised] = $this->bucket($fieldData);

        if ($unrecognised !== []) {
            /*
             | Logged, not failed, and logged with the lead's id so it can be
             | traced back. This is also the breadcrumb that tells an admin
             | their form is asking a question the CRM has nowhere to put —
             | which is a config change, not a bug.
             */
            Log::info('[integration:facebook] unrecognised lead form fields', [
                'leadgen_id' => $leadgenId,
                'fields' => $unrecognised,
            ]);
        }

        $phone = $this->phone($values['phone'] ?? null);

        if ($phone === null) {
            // the one field with no sensible default: a lead nobody can ring is
            // not a lead, and the mobile/project unique index needs ten digits
            throw new RuntimeException('The lead form returned no usable phone number.');
        }

        [$first, $last] = $this->names($values);

        return [
            'first_name' => $first,
            'last_name' => $last,
            'mobile_number' => $phone,
            'email' => $values['email'] ?? null,
            'unrecognised' => $unrecognised,
        ];
    }

    /**
     * Sort the answers into the buckets this application has columns for,
     * and collect the names of the ones it does not.
     *
     * @param  list<array{name?: string, values?: list<string>}>  $fieldData
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function bucket(array $fieldData): array
    {
        $aliases = config('integrations.meta.field_aliases');
        $values = [];
        $unrecognised = [];

        foreach ($fieldData as $field) {
            $name = strtolower(trim((string) ($field['name'] ?? '')));
            // Meta sends every answer as a list, even the single-answer ones
            $value = trim((string) (($field['values'] ?? [])[0] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $bucket = $this->bucketFor($name, $aliases);

            if ($bucket === null) {
                $unrecognised[] = $name;

                continue;
            }

            // first answer wins: a form asking the same thing twice is a form
            // mistake, and the earlier answer is the one the person meant
            $values[$bucket] ??= $value;
        }

        return [$values, $unrecognised];
    }

    /**
     * Which bucket a question name belongs to.
     *
     * Exact match first, then a contains test, because Ads Manager prefixes
     * question names with the locale or the form on some accounts —
     * "phone_number" arrives as "phone_number_en_US" often enough to be worth
     * handling, and never ambiguously.
     *
     * @param  array<string, list<string>>  $aliases
     */
    private function bucketFor(string $name, array $aliases): ?string
    {
        foreach ($aliases as $bucket => $names) {
            if (in_array($name, $names, true)) {
                return $bucket;
            }
        }

        foreach ($aliases as $bucket => $names) {
            foreach ($names as $candidate) {
                if (str_contains($name, $candidate)) {
                    return $bucket;
                }
            }
        }

        return null;
    }

    /**
     * A first and last name out of whatever the form asked for.
     *
     * `first_name`/`last_name` win when the form asked separately; otherwise
     * `full_name` is split through the same three-part rule the bulk importer
     * uses, then the middle is folded back onto the last name — "Bhavesh Kumar
     * Bhatt" is a person whose last name is "Kumar Bhatt" far more often than
     * it is a middle name the CRM should guess at.
     *
     * An empty last name is allowed and is not a hole: `leads.last_name` is NOT
     * NULL but takes an empty string, and Lead::getFullNameAttribute() builds
     * the displayed name from the parts that are actually there, so a
     * one-word name renders as one word rather than with a trailing space.
     *
     * @param  array<string, string>  $values
     * @return array{0: string, 1: string}
     */
    private function names(array $values): array
    {
        $first = $values['first_name'] ?? null;
        $last = $values['last_name'] ?? null;

        if ($first === null && isset($values['full_name'])) {
            [$first, $middle, $lastPart] = $this->splitNameParts($values['full_name']);
            $last ??= trim($middle !== '' ? $middle.' '.$lastPart : $lastPart);
        }

        return [
            // a form that asked for no name at all still produces a callable
            // lead; "Facebook lead" is what the row says until somebody rings it
            $first !== null && $first !== '' ? $first : 'Facebook lead',
            (string) ($last ?? ''),
        ];
    }

    /**
     * Split a full name into its first, middle and last parts.
     *
     * One word is a first name; two are first + last; three are first + middle
     * + last; four or more keep everything between the ends as the middle name,
     * because dropping prefixes or suffixes ("Mr.", "Jr.") loses information
     * the person wrote down. Whitespace is trimmed and no token is discarded.
     *
     * Shared by the Meta import (which folds the middle back into the last
     * name) and the bulk importer, so one column of names is parsed one way
     * across the whole application.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function splitNameParts(string $fullName): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: [], fn (string $part): bool => $part !== ''));

        return match (count($parts)) {
            0 => ['', '', ''],
            1 => [$parts[0], '', ''],
            2 => [$parts[0], '', $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2]],
            default => [$parts[0], implode(' ', array_slice($parts, 1, -1)), $parts[count($parts) - 1]],
        };
    }

    /**
     * The last ten digits, which is the only phone format this database has.
     *
     * Meta returns the number as the person's country dialled it —
     * "+919820012345", "0091 98200 12345", "98200-12345" — and `leads` carries
     * a unique index on (mobile_number, project_id) over bare ten-digit
     * strings. Storing "+919820012345" would not collide with the "9820012345"
     * already on that project, so the same customer would arrive twice and the
     * duplicate check that protects the telecaller from calling them twice
     * would never fire.
     *
     * Fewer than ten digits is not a number this application can dial, so it
     * is rejected rather than padded.
     *
     * Public so the bulk lead importer (App\Services\LeadImport\LeadImportPlanner)
     * can clean a file's phone column with the exact same rule a Meta lead's
     * phone answer gets — one definition of "a usable number", not two.
     */
    public function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}
