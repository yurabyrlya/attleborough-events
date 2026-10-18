<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a fetch or run failed. Values match source_runs_failure_kind_check
 * (03-01). Labels are the only text a failure message is built from, so a
 * remote server's response text never reaches logs or the admin UI.
 */
enum FetchFailureKind: string implements HasLabel
{
    case Network = 'network';
    case Timeout = 'timeout';
    case HttpStatus = 'http_status';
    case RateLimited = 'rate_limited';
    case TooLarge = 'too_large';
    case ContentInvalid = 'content_invalid';
    case ParseError = 'parse_error';
    case SsrfBlocked = 'ssrf_blocked';
    case Abandoned = 'abandoned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Network => 'Network error',
            self::Timeout => 'Timed out',
            self::HttpStatus => 'HTTP error',
            self::RateLimited => 'Rate limited by the site',
            self::TooLarge => 'Response too large',
            self::ContentInvalid => 'Content invalid (not a feed)',
            self::ParseError => 'Could not be read',
            self::SsrfBlocked => 'Address not allowed',
            self::Abandoned => 'Abandoned',
        };
    }
}
