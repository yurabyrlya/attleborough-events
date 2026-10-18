<?php

namespace App\Ingestion\Ics;

use App\Support\SafeLinks;
use Normalizer;
use Sabre\VObject\Component;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;

/**
 * Text rules for ICS item properties. sabre has already unfolded the lines
 * and unescaped TEXT values ("\," → ","); this strips markup, decodes HTML
 * entities, normalises to Unicode NFC and collapses whitespace.
 *
 * Feed text is untrusted: it is only ever shown escaped. ORGANIZER is read
 * here for the internal payload and never mapped to public fields (D-17).
 */
final class IcsItemNormaliser
{
    /** A UK postcode anywhere in a LOCATION value. */
    private const string POSTCODE_PATTERN = '/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i';

    /**
     * A one-line text value (SUMMARY, LOCATION, CATEGORIES): every run of
     * whitespace becomes one space. Null when nothing is left.
     */
    public function line(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = $this->plain($value);
        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * DESCRIPTION, kept in full for review: line breaks survive (at most one
     * blank line in a row), other whitespace runs collapse to one space.
     */
    public function description(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $value);
        $text = str_replace(["\r\n", "\r"], "\n", $this->plain($value));
        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/[^\S\n]+|\p{Z}+/u', ' ', $line)),
            explode("\n", $text),
        );
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return $text === '' ? null : $text;
    }

    /**
     * LOCATION split into venue name (first segment), address (the rest)
     * and a UK postcode (normalised "NR17 2AS").
     *
     * @return array{name: ?string, address: ?string, postcode: ?string}
     */
    public function location(?string $value): array
    {
        $location = $this->line($value === null ? null : str_replace(["\r\n", "\n", "\r"], ', ', $value));

        if ($location === null) {
            return ['name' => null, 'address' => null, 'postcode' => null];
        }

        $segments = array_values(array_filter(array_map(trim(...), explode(',', $location)), fn (string $part): bool => $part !== ''));
        $name = $segments[0] ?? null;
        $address = count($segments) > 1 ? implode(', ', array_slice($segments, 1)) : null;
        $postcode = null;

        if (preg_match(self::POSTCODE_PATTERN, $location, $m) === 1) {
            $postcode = strtoupper($m[1]).' '.strtoupper($m[2]);
        }

        return ['name' => $name, 'address' => $address, 'postcode' => $postcode];
    }

    /**
     * The item's own page (D-16): http/https with a host only.
     */
    public function url(?string $value): ?string
    {
        $url = $value === null ? null : trim($value);

        return SafeLinks::http($url) !== null ? $url : null;
    }

    /**
     * Every CATEGORIES value, normalised, in feed order, without repeats.
     *
     * @param  Component<mixed>  $component
     * @return list<string>
     */
    public function categories(Component $component): array
    {
        $categories = [];

        foreach ($component->select('CATEGORIES') as $property) {
            if (! $property instanceof Property) {
                continue;
            }

            foreach ($property->getParts() as $part) {
                $category = $this->line(is_scalar($part) ? (string) $part : null);

                if ($category !== null && ! in_array($category, $categories, true)) {
                    $categories[] = $category;
                }
            }
        }

        return $categories;
    }

    /**
     * ORGANIZER's CN and mailto address, internal only (D-17).
     *
     * @param  Component<mixed>  $component
     * @return array{name: ?string, email: ?string}|null
     */
    public function organiser(Component $component): ?array
    {
        $property = $component->select('ORGANIZER')[0] ?? null;

        if (! $property instanceof Property) {
            return null;
        }

        $cn = $property['CN'];
        $name = $cn instanceof Parameter ? $this->line($cn->getValue()) : null;
        $value = trim((string) $property->getValue());
        $email = preg_match('/^mailto:(.+)$/i', $value, $m) === 1 ? trim($m[1]) : null;

        return $name === null && $email === null ? null : ['name' => $name, 'email' => $email];
    }

    private function plain(string $value): string
    {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalised = Normalizer::normalize($text, Normalizer::FORM_C);

        return $normalised === false ? $text : $normalised;
    }
}
