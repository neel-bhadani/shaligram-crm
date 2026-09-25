<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class RecordSearch
{
    /**
     * Columns are supplied by application code, never by the request. Every
     * alternative stays inside one AND group so filters and visibility survive.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $names
     * @param  list<string>  $phones
     */
    public static function apply(Builder $query, ?string $search, array $columns, array $names = [], array $phones = []): Builder
    {
        $search = trim($search ?? '');

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $matches) use ($search, $columns, $names, $phones): void {
            foreach ($columns as $column) {
                $wrapped = $matches->getQuery()->getGrammar()->wrap($column);
                $matches->orWhereRaw("LOWER({$wrapped}) LIKE ? ESCAPE '!'", [self::pattern($search)]);
            }

            $words = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

            if ($names !== [] && count($words) > 1) {
                $matches->orWhere(function (Builder $name) use ($words, $names): void {
                    foreach ($words as $word) {
                        self::apply($name, $word, $names);
                    }
                });
            }

            /** Only phone-shaped input gets a digits-only alternative. */
            if (preg_match('/^[0-9+\s().\-]+$/u', $search)) {
                $digits = preg_replace('/[^0-9]/', '', $search);

                if ($digits !== '') {
                    foreach ($phones as $column) {
                        $expression = $matches->getQuery()->getGrammar()->wrap($column);
                        $bindings = [];

                        foreach ([' ', '+', '-', '(', ')', '.', "\t", "\r", "\n", "\u{00A0}"] as $separator) {
                            $expression = "REPLACE({$expression}, ?, '')";
                            $bindings[] = $separator;
                        }

                        $bindings[] = '%'.$digits.'%';
                        $matches->orWhereRaw("{$expression} LIKE ? ESCAPE '!'", $bindings);
                    }
                }
            }
        });
    }

    /** The list and export must agree, including brokers found by parent firm. */
    public static function channelPartners(Builder $query, ?string $search): Builder
    {
        if (trim($search ?? '') === '') {
            return $query;
        }

        return $query->where(function (Builder $matches) use ($search): void {
            self::apply($matches, $search,
                ['name', 'contact_person', 'phone', 'alt_phone', 'email'],
                ['name', 'contact_person'], ['phone', 'alt_phone']);
            $matches->orWhereHas('parent', fn (Builder $parent) => self::apply($parent, $search, ['name'], ['name']));
        });
    }

    private static function pattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
    }
}
