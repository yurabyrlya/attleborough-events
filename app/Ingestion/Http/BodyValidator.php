<?php

namespace App\Ingestion\Http;

/**
 * Checks a fetched body is the kind of document the extraction method
 * expects, BEFORE extraction (SRC-06, RESEARCH Pitfall 8). A bot wall or
 * DNS-filter page arrives as 200 HTML; parsing it would report "0 items,
 * succeeded" and start missing-item detection, so it is content_invalid.
 *
 * The Content-Type header is only a hint and is never read here: the
 * Academy feed is served as "text/calendar;;charset=UTF-8".
 */
class BodyValidator
{
    /**
     * Text a bot challenge or wall page carries.
     */
    private const array CHALLENGE_MARKERS = [
        'just a moment...',
        'challenge-platform',
        'cf-chl-',
        'attention required!',
    ];

    /**
     * @return string|null why the body is invalid; null when it is valid
     */
    public function validate(string $method, string $body): ?string
    {
        return match ($method) {
            FetchRequest::METHOD_ICS => $this->validateIcs($body),
            default => 'Unsupported extraction method',
        };
    }

    /**
     * Valid only when the body (after a UTF-8 BOM and leading whitespace)
     * starts with BEGIN:VCALENDAR. A calendar may legitimately carry HTML in
     * X-ALT-DESC, so markup is only diagnosed in bodies that are not
     * calendars, to give the admin a clearer reason.
     */
    private function validateIcs(string $body): ?string
    {
        $text = ltrim($this->stripBom($body));

        if ($text === '') {
            return 'Empty response';
        }

        if (stripos($text, 'BEGIN:VCALENDAR') === 0) {
            return null;
        }

        $lower = strtolower($text);

        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return 'Bot challenge page instead of a calendar';
            }
        }

        if (str_starts_with($text, '<') || str_contains($lower, '<html')) {
            return 'HTML page instead of a calendar';
        }

        return 'Not a calendar (no BEGIN:VCALENDAR)';
    }

    private function stripBom(string $body): string
    {
        return str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
    }
}
