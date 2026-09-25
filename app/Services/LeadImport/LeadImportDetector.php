<?php

namespace App\Services\LeadImport;

/**
 * Everything a bulk lead file needs before its first row can mean anything:
 * where its header row is, which sheet carries lead data, which column feeds
 * which field, and how certain any of that is.
 *
 * One vocabulary (FIELD_ALIASES) drives three different jobs so they can never
 * disagree — the reader scores header rows and sheets by looking for these
 * words, metadata maps them onto fields, and the confidence then reports
 * whether a mapping came from a recognised header or had to be found from the
 * data itself.
 *
 * Detection works in layers, strongest first:
 *
 *   1. a recognised header word names the field exactly (HIGH confidence),
 *   2. the file's own vocabulary — the CRM's project/source/stage words — says
 *      what a column holds when almost every value is a known project, source
 *      or stage (taxonomy detection, HIGH),
 *   3. the values themselves look like the field's data — ten-digit phones,
 *      person names, dates, email addresses (MEDIUM),
 *   4. everything else is LOW, and the flow only ever asks a human when a
 *      REQUIRED field (name or mobile) is missing or genuinely ambiguous.
 */
class LeadImportDetector
{
    /** How deep a sheet's scan goes while looking for a header row. */
    public const SCAN_LIMIT = 30;

    /**
     * Sheet-name words that mark a tab as report furniture — a summary, a
     * phone lookup, a dashboard — rather than raw lead rows. They are only a
     * soft negative: a tab named one of these still scores when it clearly
     * carries name + mobile + rows.
     */
    private const NEGATIVE_SHEET_WORDS = ['summary', 'dashboard', 'lookup', 'phonelookup', 'reference', 'master', 'pivot', 'report', 'instructions'];

    /**
     * Column-header guesses, tried in this order against whatever the file
     * calls them. Priorities matter: a full-name column wins over separate
     * First / Middle / Last columns, so a file that has both keeps the
     * combined one and the mapping validation rejects the conflict instead of
     * silently guessing both.
     */
    public const FIELD_ALIASES = [
        'full_name' => ['name', 'full name', 'full_name', 'customer name', 'customer_name', 'client name', 'client_name', 'lead name', 'lead_name', 'contact person', 'contact name', 'contact_person', 'prospect name', 'prospect_name', 'prospect', 'client', 'customer', 'lead'],
        'first_name' => ['first_name', 'first name', 'firstname', 'first', 'fname', 'given name', 'given_name'],
        'middle_name' => ['middle_name', 'middle name', 'middlename', 'middle', 'mname'],
        'last_name' => ['last_name', 'last name', 'lastname', 'last', 'surname', 'family name', 'family_name', 'lname'],
        'mobile_number' => ['mobile_number', 'mobile', 'mobileno', 'mobile_no', 'mobile number', 'mobile number', 'mobile no', 'phone', 'phone_number', 'phone number', 'phone no', 'contact', 'contact_number', 'contact number', 'contact no', 'cell', 'cell phone', 'cellphone', 'cell number', 'whatsapp', 'whatsapp number', 'whatsapp no', 'primary number', 'primary phone'],
        'email' => ['email', 'email_address', 'email address', 'emailid', 'email id', 'mail', 'mail id', 'mailid'],
        'project' => ['project', 'project_name', 'project name', 'property', 'property name', 'property_name', 'site', 'scheme', 'development'],
        'source' => ['source', 'lead_source', 'lead source', 'campaign source', 'campaign_source', 'campaign', 'medium', 'platform', 'enquiry source', 'enquiry_source', 'inquiry source', 'inquiry_source'],
        'stage' => ['stage', 'status', 'lead_stage', 'lead stage', 'lead_sub_status', 'lead status', 'lead_status', 'current status', 'current_status', 'current stage', 'current_stage', 'sub status', 'sub_status', 'disposition', 'pipeline stage', 'pipeline_stage', 'funnel stage', 'funnel_stage', 'deal stage', 'deal_stage'],
        'requirement' => ['requirement', 'requirements', 'configuration', 'bhk', 'unitrequirement', 'unit requirement', 'unit_requirement', 'type', 'unit type'],
        'broker_name' => ['broker', 'broker_name', 'broker name', 'channel_partner', 'channel partner', 'agent', 'agent name', 'agent_name'],
        'assigned_user' => ['sales person', 'salesperson', 'sales_person', 'assigned to', 'assigned user', 'assigned_user', 'sales executive', 'sales_executive', 'sales rep', 'sales_rep', 'executive', 'owner', 'telecaller'],
        'created_at' => ['created_at', 'date', 'enquiry_date', 'enquiry date', 'lead_date', 'lead date', 'created_date', 'created date', 'created', 'inquiry date', 'inquiry_date', 'received date', 'received_date', 'generated date', 'generated_date', 'entry date', 'date created'],
        'follow_up_datetime' => ['follow-up datetime', 'followup datetime', 'follow_up_datetime', 'scheduled at', 'scheduled datetime', 'next action', 'datetime', 'date time'],
        'follow_up_date' => ['follow-up date', 'followup date', 'follow_up_date', 'scheduled date', 'due date', 'next followup', 'followup', 'follow up date'],
        'follow_up_time' => ['follow-up time', 'followup time', 'follow_up_time', 'scheduled time', 'next action time', 'follow up time', 'time'],
    ];

    /** @var array<string, list<string>> */
    private array $normalisedAliases;

    public function __construct()
    {
        $this->normalisedAliases = [];
        foreach (self::FIELD_ALIASES as $field => $aliases) {
            $this->normalisedAliases[$field] = array_map($this->normalise(...), $aliases);
        }
    }

    /** Letters and digits only — "Follow-up Date", "Follow_up_date" and "followup date" are the same word. */
    public function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($value)) ?? '';
    }

    /** Which field, if any, a column header names. */
    public function aliasField(string $normalised): ?string
    {
        foreach ($this->normalisedAliases as $field => $aliases) {
            if (in_array($normalised, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The best header row in a sheet's scanned grid, or null when none looks
     * like a header. Title and noise rows — a one-cell "Monthly Leads" line, a
     * repeated token — are skipped, and the earliest best row wins a tie.
     *
     * Recognised column words dominate the score; a row is also preferred when
     * several of its cells are actually populated and when real records sit
     * below it, so a data row full of phone numbers cannot outscore a plain
     * header such as row one of a name/mobile file.
     *
     * @param  array<int, array<int, string>>  $grid  absolute row => column => value
     */
    public function detectHeaderRow(array $grid): ?int
    {
        $best = null;
        $bestScore = 0;
        foreach ($grid as $row => $cells) {
            $values = array_values(array_filter($cells, fn (string $value): bool => trim($value) !== ''));
            if (count($values) < 2) {
                continue;
            }
            $text = 0;
            $aliases = 0;
            $seen = [];
            foreach ($values as $value) {
                $value = trim($value);
                if ($this->isTextLike($value)) {
                    $text++;
                }
                $key = $this->normalise($value);
                $seen[$key] = true;
                if ($this->aliasField($key) !== null) {
                    $aliases++;
                }
            }
            if (count($seen) === 1) {
                continue;
            }
            $score = $aliases * 100 + $text * 5 + min(count($values), 6) * 8 + ($this->hasRecordsBelow($grid, $row) ? 20 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * How strongly a sheet's scanned grid reads as lead data, and the
     * evidence behind the verdict.
     *
     * A sheet is a STRONG lead candidate only when it pairs a name-like
     * column with a mobile/phone-like column AND has at least one data row
     * under the detected header. Header aliases are the strongest evidence —
     * "Customer Name" or "Phone" headers prove the column — while data-aware
     * checks cover weak, unlabelled headers such as "Person | Contact".
     * Secondary columns (project, source, stage, email, created date) and the
     * weight of real data rows feed only the tie-breaking score, never the
     * strong verdict, so a phone-reference or summary tab can never look like
     * lead data on their strength alone. Negative sheet names and hidden
     * sheets shave the score so helper tabs lose the last-resort tie.
     *
     * @param  array<int, array<int, string>>  $grid
     * @return array{headerRow: ?int, score: int, aliasHits: int, strong: bool, dataRows: int, negativeHits: int}
     */
    public function scoreSheet(array $grid, string $sheetName = '', bool $hidden = false): array
    {
        $headerRow = $this->detectHeaderRow($grid);
        if ($headerRow === null) {
            return ['headerRow' => null, 'score' => 0, 'aliasHits' => 0, 'strong' => false, 'dataRows' => 0, 'negativeHits' => 0];
        }
        $fieldOfColumn = [];
        $aliases = 0;
        $nameHeader = false;
        $mobileHeader = false;
        foreach ($grid[$headerRow] as $column => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $field = $this->aliasField($this->normalise($value));
            if ($field === null) {
                continue;
            }
            $aliases++;
            $fieldOfColumn[$column] = $field;
            if (in_array($field, ['full_name', 'first_name', 'last_name'], true)) {
                $nameHeader = true;
            } elseif ($field === 'mobile_number') {
                $mobileHeader = true;
            }
        }
        $dataRows = 0;
        $phones = 0;
        $dates = 0;
        $emails = 0;
        $populatedColumns = 0;
        $nameLike = [];
        $multiToken = [];
        $valueCount = [];
        foreach (array_keys($grid[$headerRow]) as $column) {
            if ($this->columnHasContent($grid, $headerRow, $column)) {
                $populatedColumns++;
            }
        }
        foreach ($grid as $row => $cells) {
            if ($row <= $headerRow) {
                continue;
            }
            $nonEmpty = array_filter($cells, fn (string $value): bool => trim($value) !== '');
            if ($nonEmpty === []) {
                continue;
            }
            $dataRows++;
            foreach ($nonEmpty as $column => $value) {
                if ($this->isNameDataLike($value)) {
                    $nameLike[$column] = ($nameLike[$column] ?? 0) + 1;
                    if ($this->hasMultipleNameTokens($value)) {
                        $multiToken[$column] = ($multiToken[$column] ?? 0) + 1;
                    }
                }
                $valueCount[$column] = ($valueCount[$column] ?? 0) + 1;
                if ($this->isPhoneLike($value)) {
                    $phones++;
                } elseif ($this->isDateLike($value)) {
                    $dates++;
                } elseif ($this->isEmailLike($value)) {
                    $emails++;
                }
            }
        }
        $nameData = $this->hasDataAwareName($nameLike, $multiToken, $valueCount, $fieldOfColumn);
        $mobileData = $this->hasDataAwarePhone($grid, $headerRow, $fieldOfColumn);
        $strong = ($nameHeader || $nameData) && ($mobileHeader || $mobileData) && $dataRows > 0;
        $negativeHits = $this->negativeSheetHits($sheetName);

        $score = 0;
        if ($nameHeader) {
            $score += 100;
        }
        if ($mobileHeader) {
            $score += 100;
        }
        if ($nameData) {
            $score += 40;
        }
        if ($mobileData) {
            $score += 50;
        }
        foreach ($fieldOfColumn as $field) {
            $score += match ($field) {
                'project', 'source', 'stage' => 20,
                'email' => 10,
                'created_at' => 10,
                default => 0,
            };
        }
        $score += min($dataRows, 10) * 4 + min($phones, 10) + min($dates, 10) + min($emails, 10) + $populatedColumns;
        $score -= $negativeHits * 120 + ($hidden ? 250 : 0);

        return ['headerRow' => $headerRow, 'score' => max(0, $score), 'aliasHits' => $aliases, 'strong' => $strong, 'dataRows' => $dataRows, 'negativeHits' => $negativeHits];
    }

    /**
     * field => column-header guesses: exact alias matches first, then
     * taxonomy-aware and data-aware fallbacks fill only the fields the header
     * words left open.
     *
     * $taxonomy carries the CRM vocabulary in play — active project names and
     * the source/stage keys + labels — so a column whose values are almost all
     * a known project, source or stage can be mapped even when its header is an
     * arbitrary external word ("Campaign Name" full of "Facebook", a site list
     * full of project names).
     *
     * @param  list<string>  $headers
     * @param  array<int, array<string, string>>  $rows
     * @param  array{projects?: list<string>, sources?: list<string>, stages?: list<string>, source_labels?: array<string, string>, stage_labels?: array<string, string>}  $taxonomy
     * @return array<string, string>
     */
    public function guessMapping(array $headers, array $rows, array $taxonomy = []): array
    {
        $mapping = [];
        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($headers as $header) {
                if (! in_array($header, $mapping, true) && in_array($this->normalise($header), $this->normalisedAliases[$field], true)) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }

        $claimed = fn (): array => array_values($mapping);
        $unclaimed = fn (): array => array_values(array_filter($headers, fn (string $header): bool => ! in_array($header, $mapping, true)));

        foreach (['project', 'source', 'stage'] as $field) {
            if (isset($mapping[$field])) {
                continue;
            }
            $tokens = $this->taxonomyTokens($field, $taxonomy);
            if ($tokens === []) {
                continue;
            }
            foreach ($unclaimed() as $header) {
                if ($this->matchingRatio($header, $rows, $tokens) >= 0.95) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }

        $pending = $unclaimed();
        if (! isset($mapping['created_at'])) {
            $candidate = $this->bestDateColumn($pending, $rows);
            if ($candidate !== null) {
                $mapping['created_at'] = $candidate;
                $pending = array_values(array_diff($pending, [$candidate]));
            }
        }
        if (! isset($mapping['email'])) {
            $candidate = $this->bestEmailColumn($pending, $rows);
            if ($candidate !== null) {
                $mapping['email'] = $candidate;
                $pending = array_values(array_diff($pending, [$candidate]));
            }
        }
        if (! isset($mapping['mobile_number'])) {
            $candidate = $this->bestPhoneColumn($pending, $rows);
            if ($candidate !== null) {
                $mapping['mobile_number'] = $candidate;
                $pending = array_values(array_diff($pending, [$candidate]));
            }
        }
        if (! array_intersect(['full_name', 'first_name', 'last_name'], array_keys($mapping))) {
            $candidate = $this->bestNameColumn($pending, $rows);
            if ($candidate !== null) {
                $mapping['full_name'] = $candidate;
            }
        }

        return $mapping;
    }

    /**
     * field => 'high', 'medium' or 'low'.
     *
     * High: the header word names the field, or taxonomy says the column's
     * values are almost all a known project/source/stage. Medium: the values
     * look strongly like the field's data (phones, names, dates, emails) with
     * no header word to lean on. Low: the evidence is thin or the mapping was
     * made by hand — worth a glance in the review.
     *
     * @param  array<string, string>  $mapping
     * @param  list<string>  $headers
     * @param  array<int, array<string, string>>  $rows
     * @param  array{projects?: list<string>, sources?: list<string>, stages?: list<string>, source_labels?: array<string, string>, stage_labels?: array<string, string>}  $taxonomy
     * @return array<string, string>
     */
    public function confidence(array $mapping, array $headers, array $rows = [], array $taxonomy = []): array
    {
        $_ = $headers;
        $confidence = [];
        foreach ($mapping as $field => $header) {
            $confidence[$field] = 'low';
            if ($this->aliasField($this->normalise($header)) === $field) {
                $confidence[$field] = 'high';
            } elseif (in_array($field, ['project', 'source', 'stage'], true)) {
                $tokens = $this->taxonomyTokens($field, $taxonomy);
                if ($tokens !== [] && $this->matchingRatio($header, $rows, $tokens) >= 0.95) {
                    $confidence[$field] = 'high';
                }
            } else {
                $ratio = $this->dataRatio($field, $header, $rows);
                if ($field === 'mobile_number') {
                    $confidence[$field] = $ratio >= 0.7 ? 'medium' : 'low';
                } elseif (in_array($field, ['full_name', 'created_at', 'email'], true) && $ratio > 0) {
                    $confidence[$field] = 'medium';
                }
            }
        }

        return $confidence;
    }

    /**
     * Only when a REQUIRED field has more than one credible source column,
     * list every candidate so the compact confirmation screen can ask. The
     * guessed mapping has already picked one — this merely surfaces the other
     * plausible column instead of silently trusting the first one in header
     * order.
     *
     * @param  list<string>  $headers
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, list<string>>
     */
    public function ambiguousRequired(array $headers, array $rows): array
    {
        $ambiguous = [];
        foreach (['full_name', 'mobile_number'] as $field) {
            $candidates = array_values(array_filter($headers, fn (string $header): bool => $this->aliasField($this->normalise($header)) === $field));
            if ($field === 'mobile_number') {
                foreach ($headers as $header) {
                    if ($this->phoneRatio($header, $rows) >= 0.5) {
                        $candidates[] = $header;
                    }
                }
                $candidates = array_values(array_unique($candidates));
            }
            if (count($candidates) > 1) {
                $ambiguous[$field] = $candidates;
            }
        }

        return $ambiguous;
    }

    /**
     * The fields a file cannot produce by itself and the flow therefore has to
     * ask for. first_name (or full_name) and mobile_number are hard
     * requirements today; project/source/stage stay satisfiable by the
     * follow-up batch defaults, so they are not listed here.
     *
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    public function unresolvedRequired(array $mapping): array
    {
        $missing = [];
        if (empty($mapping['first_name']) && empty($mapping['full_name'])) {
            $missing[] = 'first_name';
        }
        if (empty($mapping['mobile_number'])) {
            $missing[] = 'mobile_number';
        }

        return $missing;
    }

    /** The only column the data says is a phone column: mostly phone-like values, a clear winner. */
    private function bestPhoneColumn(array $headers, array $rows): ?string
    {
        $best = null;
        $bestRatio = 0.0;
        foreach ($headers as $header) {
            $ratio = $this->phoneRatio($header, $rows);
            if ($ratio >= 0.5 && $ratio > $bestRatio) {
                $bestRatio = $ratio;
                $best = $header;
            }
        }

        return $best;
    }

    /** Words only — hyphens, dots and apostrophes allowed, digits excluded — and a clear majority. */
    private function bestNameColumn(array $headers, array $rows): ?string
    {
        $best = null;
        $bestRatio = 0.0;
        foreach ($headers as $header) {
            $values = $this->columnValues($header, $rows);
            $nonEmpty = array_values(array_filter($values, fn (?string $value): bool => $value !== null && $value !== ''));
            if ($nonEmpty === []) {
                continue;
            }
            $nameLike = count(array_filter($nonEmpty, fn (string $value): bool => (bool) preg_match('/^[A-Za-z][A-Za-z .\'-]{0,99}$/', trim($value))));
            $ratio = $nameLike / count($nonEmpty);
            if ($ratio >= 0.7 && $ratio > $bestRatio) {
                $bestRatio = $ratio;
                $best = $header;
            }
        }

        return $best;
    }

    /**
     * A headerless column that is largely dates (ISO, dated/dated-numeric, or
     * Excel serials in the modern era). A column that is ONLY numeric is never
     * consumed as created_at purely because its amounts sit inside the serial
     * window — a "Budget" of 45000 must not become a 15-May-2023 date. A bare
     * numeric column needs an explicit date-ish header word first.
     */
    private function bestDateColumn(array $headers, array $rows): ?string
    {
        $best = null;
        $bestRatio = 0.0;
        foreach ($headers as $header) {
            $ratio = $this->valueRatio($header, $rows, $this->isDateLike(...));
            if ($ratio < 0.8 || $ratio <= $bestRatio) {
                continue;
            }
            if ($this->columnIsNumeric($header, $rows) && ! $this->hasDateHeaderHint((string) $this->normalise($header))) {
                continue;
            }
            $bestRatio = $ratio;
            $best = $header;
        }

        return $best;
    }

    private function columnIsNumeric(string $header, array $rows): bool
    {
        $values = array_filter($this->columnValues($header, $rows), fn (?string $value): bool => $value !== null);
        if ($values === []) {
            return false;
        }
        foreach ($values as $value) {
            /** @var string $value */
            if (! is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    private function hasDateHeaderHint(string $normalised): bool
    {
        foreach (['date', 'created', 'updated', 'month', 'birth', 'dob', 'moving', 'anniversary'] as $token) {
            if ($normalised !== '' && str_contains($normalised, $token)) {
                return true;
            }
        }

        return false;
    }

    /** A column that is largely email addresses. */
    private function bestEmailColumn(array $headers, array $rows): ?string
    {
        $best = null;
        $bestRatio = 0.0;
        foreach ($headers as $header) {
            $ratio = $this->valueRatio($header, $rows, $this->isEmailLike(...));
            if ($ratio >= 0.8 && $ratio > $bestRatio) {
                $bestRatio = $ratio;
                $best = $header;
            }
        }

        return $best;
    }

    private function phoneRatio(string $header, array $rows): float
    {
        $values = $this->columnValues($header, $rows);
        $nonEmpty = array_values(array_filter($values, fn (?string $value): bool => $value !== null && $value !== ''));
        if ($nonEmpty === []) {
            return 0.0;
        }
        $phoneLike = array_filter($nonEmpty, $this->isPhoneLike(...));
        $hasTenDigit = false;
        foreach ($phoneLike as $value) {
            if (strlen(preg_replace('/\D/', '', $value)) === 10) {
                $hasTenDigit = true;
                break;
            }
        }

        return ($phoneLike !== [] && $hasTenDigit) ? count($phoneLike) / count($nonEmpty) : 0.0;
    }

    /** Share of a column's non-empty values that satisfy the predicate. */
    private function valueRatio(string $header, array $rows, callable $test): float
    {
        $values = $this->columnValues($header, $rows);
        $nonEmpty = array_values(array_filter($values, fn (?string $value): bool => $value !== null && $value !== ''));
        if ($nonEmpty === []) {
            return 0.0;
        }

        return count(array_filter($nonEmpty, $test)) / count($nonEmpty);
    }

    /**
     * What fraction of a column's values are real phone/name/date/email data,
     * used to grade a mapping that had no header word to go on.
     */
    private function dataRatio(string $field, string $header, array $rows): float
    {
        return match ($field) {
            'mobile_number' => $this->phoneRatio($header, $rows),
            'full_name' => $this->valueRatio($header, $rows, fn (string $value): bool => (bool) preg_match('/^[A-Za-z][A-Za-z .\'-]{0,99}$/', trim($value))),
            'created_at' => $this->valueRatio($header, $rows, $this->isDateLike(...)),
            'email' => $this->valueRatio($header, $rows, $this->isEmailLike(...)),
            default => 0.0,
        };
    }

    /** The CRM words a field's values are compared against, all normalised. */
    private function taxonomyTokens(string $field, array $taxonomy): array
    {
        $labels = $taxonomy[$field === 'source' ? 'source_labels' : ($field === 'stage' ? 'stage_labels' : '')] ?? [];
        $names = $taxonomy[$field === 'project' ? 'projects' : ($field === 'source' ? 'sources' : 'stages')] ?? [];
        $tokens = array_map($this->normalise(...), $names);
        foreach ($labels as $label) {
            $tokens[] = $this->normalise((string) $label);
        }
        $tokens = array_values(array_unique(array_filter($tokens, fn (string $t): bool => $t !== '')));

        return $tokens;
    }

    /** Share of a column's non-empty values whose normalised form is a recognised vocabulary word. */
    private function matchingRatio(string $header, array $rows, array $tokens): float
    {
        if ($tokens === []) {
            return 0.0;
        }
        $values = $this->columnValues($header, $rows);
        $nonEmpty = array_values(array_filter($values, fn (?string $value): bool => $value !== null && $value !== ''));
        if ($nonEmpty === []) {
            return 0.0;
        }
        $hits = count(array_filter($nonEmpty, fn (string $value): bool => in_array($this->normalise($value), $tokens, true)));

        return $hits / count($nonEmpty);
    }

    /** A handful of a column's non-empty values, enough to judge what it holds. */
    private function columnValues(string $header, array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$header] ?? ''));
            $values[] = $value === '' ? null : $value;
            if (count($values) >= 50) {
                break;
            }
        }

        return $values;
    }

    private function columnHasContent(array $grid, int $headerRow, int $column): bool
    {
        foreach ($grid as $row => $cells) {
            if ($row < $headerRow) {
                continue;
            }
            if (trim((string) ($cells[$column] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * An unrecognised column is treated as a name column only when no other
     * field has claimed it, a strong majority of its values are name-shaped
     * text, and at least one of them carries two or more tokens — so a
     * single-word city, platform or project-code list can never impersonate
     * customer names.
     *
     * @param  array<int, int>  $nameLike
     * @param  array<int, int>  $multiToken
     * @param  array<int, int>  $valueCount
     * @param  array<int, string>  $fieldOfColumn
     */
    private function hasDataAwareName(array $nameLike, array $multiToken, array $valueCount, array $fieldOfColumn): bool
    {
        foreach ($nameLike as $column => $count) {
            if (array_key_exists($column, $fieldOfColumn)) {
                continue;
            }
            $total = $valueCount[$column] ?? 0;
            if ($total === 0 || $count / $total < 0.7) {
                continue;
            }
            if (($multiToken[$column] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * An unrecognised column whose values are largely phone-shaped — with at
     * least one ten-digit mobile in the mix — counts as phone evidence when no
     * other field has claimed the column.
     *
     * @param  array<int, array<int, string>>  $grid
     * @param  array<int, string>  $fieldOfColumn
     */
    private function hasDataAwarePhone(array $grid, int $headerRow, array $fieldOfColumn): bool
    {
        $counts = [];
        foreach ($grid as $row => $cells) {
            if ($row <= $headerRow) {
                continue;
            }
            foreach ($cells as $column => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $counts[$column]['total'] = ($counts[$column]['total'] ?? 0) + 1;
                if ($this->isPhoneLike($value)) {
                    $counts[$column]['phone'] = ($counts[$column]['phone'] ?? 0) + 1;
                    if (strlen(preg_replace('/\D/', '', $value)) === 10) {
                        $counts[$column]['ten'] = ($counts[$column]['ten'] ?? 0) + 1;
                    }
                }
            }
        }
        foreach ($counts as $column => $count) {
            if (array_key_exists($column, $fieldOfColumn) || ($count['total'] ?? 0) === 0) {
                continue;
            }
            $phone = $count['phone'] ?? 0;
            $tenDigit = $count['ten'] ?? 0;

            return $phone > 0 && $tenDigit > 0 && $phone / $count['total'] >= 0.5;
        }

        return false;
    }

    /** Letters, spaces, dots and apostrophes only — the shape a person's name survives as in an export. */
    private function isNameDataLike(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z .\'-]{0,99}$/', trim($value));
    }

    /** Whether a name-shaped value carries a first name plus more. */
    private function hasMultipleNameTokens(string $value): bool
    {
        return (bool) preg_match('/\s+/', trim($value));
    }

    /** How many furniture-style words appear in a sheet name — each one reduces how lead-like the tab reads. */
    private function negativeSheetHits(string $sheetName): int
    {
        $normalised = $this->normalise($sheetName);
        if ($normalised === '') {
            return 0;
        }
        $hits = 0;
        foreach (self::NEGATIVE_SHEET_WORDS as $word) {
            if (str_contains($normalised, $word)) {
                $hits++;
            }
        }

        return $hits;
    }

    private function hasRecordsBelow(array $grid, int $row): bool
    {
        foreach ($grid as $candidate => $cells) {
            if ($candidate > $row && array_filter($cells, fn (string $value): bool => trim($value) !== '') !== []) {
                return true;
            }
        }

        return false;
    }

    /** Ten to fourteen digits, the shape a mobile number survives as (with or without +, spaces, dashes). */
    private function isPhoneLike(string $value): bool
    {
        $digits = strlen(preg_replace('/\D/', '', $value));

        return $digits >= 10 && $digits <= 14;
    }

    /**
     * Whatever a date can survive as in an exported file: ISO with a time, a
     * day/month/year shape, or an Excel serial number between 2000 and 2060.
     * The serial window is deliberately narrow — a bare value such as "12345"
     * or a price like "1200000" is a 1933/3285 serial and is never mistaken
     * for a date, because an unrelated numeric column must never be consumed
     * by the date detector.
     */
    private function isDateLike(string $value): bool
    {
        $value = trim($value);
        if (is_numeric($value)) {
            $n = (float) $value;

            return $n >= 36526 && $n <= 58439;
        }

        return (bool) preg_match('~^(\d{4}-\d{1,2}-\d{1,2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?|\d{1,2}[-/]\d{1,2}[-/]\d{2,4})~', $value);
    }

    private function isEmailLike(string $value): bool
    {
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', trim($value));
    }

    private function isTextLike(string $value): bool
    {
        return ! is_numeric($value) && ! preg_match('/^\d{1,4}([-\/.])\d{1,2}\1\d{1,4}(?:\s|$|T)/', $value);
    }
}
