<?php

namespace App\Casts;

use App\Ingestion\Pipeline\ChangeDetector;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * event_drafts.diff as an array whose keys come back in the fixed D-06
 * order (ChangeDetector::FIELDS), then any other keys (cancelled_keys,
 * status) as stored. jsonb keeps object keys in its own order (shortest
 * first), so the order the update draft was written in is restored on
 * read, for the review page and for comparisons.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
final class SourceDiff implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $decoded = is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? self::ordered($decoded) : null;
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $diff
     * @return array<string, mixed>
     */
    public static function ordered(array $diff): array
    {
        $ordered = [];

        foreach (ChangeDetector::FIELDS as $field) {
            if (array_key_exists($field, $diff)) {
                $ordered[$field] = $diff[$field];
            }
        }

        foreach ($diff as $field => $change) {
            if (! array_key_exists((string) $field, $ordered)) {
                $ordered[(string) $field] = $change;
            }
        }

        return $ordered;
    }
}
