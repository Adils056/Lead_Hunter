<?php

namespace App\Support;

/**
 * Safe "contains" search: a query-string value that is not a string (?search[]=x) is ignored instead of
 * crashing, and % / _ typed by the user match literally instead of matching every row.
 * '!' is the escape character because MySQL and SQLite both accept it in ESCAPE '...'.
 */
class Like
{
    /** The search term from the query string, or '' when it is missing or not a plain string. */
    public static function term(mixed $value): string
    {
        return is_string($value) ? trim(mb_substr($value, 0, 200)) : '';
    }

    public static function pattern(string $term): string
    {
        return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /** $query->where($column LIKE %term%) with the escaping above; $boolean 'or' for orWhere. */
    public static function where($query, string $column, string $term, string $boolean = 'and')
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return $query->whereRaw("{$wrapped} LIKE ? ESCAPE '!'", [self::pattern($term)], $boolean);
    }
}
