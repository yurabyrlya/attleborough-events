<?php

namespace App\Support;

/**
 * Links built from admin-entered values (T-01-58): only http/https URLs
 * with a host, mailto: for a valid email address, tel: for a phone-like
 * value. Anything else stays plain text.
 */
final class SafeLinks
{
    /**
     * @return array{url: string, host: string}|null
     */
    public static function http(?string $url): ?array
    {
        $url = self::blankToNull($url);

        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return ['url' => $url, 'host' => (string) preg_replace('/^www\./', '', $host)];
    }

    public static function mailto(?string $contact): ?string
    {
        $contact = self::blankToNull($contact);

        return $contact !== null && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false
            ? 'mailto:'.$contact
            : null;
    }

    /**
     * "+44 1953 123456" → "tel:+441953123456"; "01953 123456" → "tel:01953123456".
     */
    public static function tel(?string $contact): ?string
    {
        $contact = self::blankToNull($contact);

        if ($contact === null || preg_match('/^\+?[0-9 ()-]{7,20}$/', $contact) !== 1) {
            return null;
        }

        return 'tel:'.(str_starts_with($contact, '+') ? '+' : '').preg_replace('/\D/', '', $contact);
    }

    public static function contactHref(?string $contact): ?string
    {
        return self::mailto($contact) ?? self::tel($contact);
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
