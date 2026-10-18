<?php

namespace App\Enums;

use App\Models\Source;
use Filament\Support\Contracts\HasLabel;

/**
 * How an ICS feed's items are told apart across runs (RESEARCH F4), read
 * from sources.parser_config['identity']. The patterns are constants in code
 * and never admin input (no admin-written regex: ReDoS, invalid patterns).
 *
 *  - Uid: the VEVENT UID (Baptist Church: stable "E{id}@UK.endis.com").
 *  - TecPostId: the post id at the front of a The Events Calendar UID
 *    "{post id}-{start epoch}-{end epoch}@host" (Town Council). A moved time
 *    changes the epochs but not the post id (Pitfall 4).
 *  - SummaryStart: normalised SUMMARY + DTSTART (Academy: UIDs embed the
 *    request time and change on every fetch).
 */
enum IdentityStrategy: string implements HasLabel
{
    case Uid = 'uid';
    case TecPostId = 'tec_post_id';
    case SummaryStart = 'summary_start';

    /** The Events Calendar UID: "{post id}-{start epoch}-{end epoch}@host". */
    public const string TEC_POST_ID_PATTERN = '/^(\d+)-\d+-\d+@/';

    public static function forSource(Source $source): self
    {
        $configured = $source->parser_config['identity'] ?? null;

        return is_string($configured) ? (self::tryFrom($configured) ?? self::Uid) : self::Uid;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Uid => 'Event UID',
            self::TecPostId => 'Events Calendar post id',
            self::SummaryStart => 'Title and start time',
        };
    }
}
