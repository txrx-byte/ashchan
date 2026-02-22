<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace App\Helper;

/**
 * Parse PostgreSQL text[] array strings into PHP arrays.
 *
 * PDO returns PostgreSQL array columns as strings like "{a,b,c}" or "{}".
 * This helper converts them to proper PHP arrays.
 */
final class PgArrayParser
{
    /**
     * Parse a PostgreSQL array string into a PHP array.
     *
     * @param mixed $value The PostgreSQL array string (e.g. "{a,b,c}")
     * @return array<string> PHP array of strings
     */
    public static function parse(mixed $value): array
    {
        if (is_array($value)) {
            return array_map(static fn(mixed $v): string => (string) $v, $value);
        }

        if (!is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        // Remove surrounding braces
        $inner = trim($value, '{}');
        if ($inner === '') {
            return [];
        }

        // Handle quoted elements and simple CSV
        return array_map('strval', array_filter(str_getcsv($inner), fn(?string $v): bool => $v !== null));
    }

    /**
     * Parse a specific property on each item in a collection.
     *
     * @param iterable<object> $items Collection of objects (e.g. from Db::table()->get())
     * @param string ...$properties Property names to parse
     * @return array<object> The same items with properties converted to arrays
     */
    public static function parseCollection(iterable $items, string ...$properties): array
    {
        $result = [];
        foreach ($items as $item) {
            foreach ($properties as $prop) {
                if (isset($item->$prop) || property_exists($item, $prop)) {
                    $item->$prop = self::parse($item->$prop ?? null);
                }
            }
            $result[] = $item;
        }
        return $result;
    }
}
