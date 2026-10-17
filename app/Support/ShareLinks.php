<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventOccurrence;

/**
 * Share data for the event page (EVT-07): the share text and plain
 * WhatsApp, Facebook and email URLs that work without JavaScript and send
 * nothing until the visitor taps them (T-01-63). No third-party widgets.
 *
 * Text (D-01, D-05): "{Cancelled: }{title}{ – next dated time} at {venue},
 * on What's On Attleborough". The date is always named, also for all-day
 * events ("Sat 7 Nov, all day"), because a shared message has no date
 * heading above it. The URL is the canonical from PublicUrl
 * (config('app.url')), never the Host header (T-01-68). Every value is
 * percent-encoded with rawurlencode (RFC 3986), so spaces are %20.
 */
final readonly class ShareLinks
{
    public function __construct(
        public string $url,
        public string $title,
        public string $text,
        public string $whatsapp,
        public string $facebook,
        public string $email,
    ) {}

    public static function for(Event $event, ?EventOccurrence $next): self
    {
        $url = PublicUrl::event($event);
        $siteName = (string) config('site.name');

        $text = ($event->status === EventStatus::Cancelled ? 'Cancelled: ' : '')
            .$event->title
            .($next !== null ? ' – '.LondonFormatter::datedTime($next) : '')
            .' at '.trim($event->venue->name)
            .', on '.$siteName;

        return new self(
            url: $url,
            title: $event->title,
            text: $text,
            whatsapp: 'https://wa.me/?text='.rawurlencode($text.' '.$url),
            facebook: 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($url),
            email: 'mailto:?subject='.rawurlencode($event->title.' | '.$siteName)
                .'&body='.rawurlencode($text."\r\n\r\n".$url),
        );
    }
}
