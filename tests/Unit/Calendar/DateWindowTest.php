<?php

use App\Calendar\DateWindow;
use App\Enums\DateFilter;
use Carbon\CarbonImmutable;

/*
 * CAL-02 date windows, pinned in UTC across both clock changes
 * (BST ends Sun 25 Oct 2026, BST starts Sun 28 Mar 2027). Half-open
 * [start, end): "Sun 23:59" is an exclusive Mon 00:00 end.
 */

function windowAt(string $londonNow, DateFilter $filter): DateWindow
{
    return DateWindow::for($filter, CarbonImmutable::parse($londonNow, 'Europe/London'));
}

/**
 * @return array{0: string, 1: string, 2: float}
 */
function utcSpan(DateWindow $window): array
{
    return [
        $window->startUtc()->toIso8601ZuluString(),
        $window->endUtc()->toIso8601ZuluString(),
        $window->startUtc()->diffInHours($window->endUtc()),
    ];
}

it('computes Fri 23 Oct 2026 15:00 windows across the October clock change', function () {
    expect(utcSpan(windowAt('2026-10-23 15:00', DateFilter::Today)))
        ->toBe(['2026-10-22T23:00:00Z', '2026-10-23T23:00:00Z', 24.0])
        ->and(utcSpan(windowAt('2026-10-23 15:00', DateFilter::Weekend)))
        ->toBe(['2026-10-23T23:00:00Z', '2026-10-26T00:00:00Z', 49.0])
        ->and(utcSpan(windowAt('2026-10-23 15:00', DateFilter::Week)))
        ->toBe(['2026-10-22T23:00:00Z', '2026-10-26T00:00:00Z', 73.0]);
});

it('computes Sun 25 Oct 2026 12:00 windows: 25h today, current weekend, week is today only', function () {
    expect(utcSpan(windowAt('2026-10-25 12:00', DateFilter::Today)))
        ->toBe(['2026-10-24T23:00:00Z', '2026-10-26T00:00:00Z', 25.0])
        ->and(utcSpan(windowAt('2026-10-25 12:00', DateFilter::Weekend)))
        ->toBe(['2026-10-23T23:00:00Z', '2026-10-26T00:00:00Z', 49.0])
        ->and(utcSpan(windowAt('2026-10-25 12:00', DateFilter::Week)))
        ->toBe(['2026-10-24T23:00:00Z', '2026-10-26T00:00:00Z', 25.0]);
});

it('computes Mon 26 Oct 2026 08:00 windows: next weekend and the week to Mon 2 Nov', function () {
    expect(utcSpan(windowAt('2026-10-26 08:00', DateFilter::Weekend)))
        ->toBe(['2026-10-31T00:00:00Z', '2026-11-02T00:00:00Z', 48.0])
        ->and(utcSpan(windowAt('2026-10-26 08:00', DateFilter::Week)))
        ->toBe(['2026-10-26T00:00:00Z', '2026-11-02T00:00:00Z', 168.0]);
});

it('computes the 47h weekend on Sat 27 Mar 2027 10:00', function () {
    expect(utcSpan(windowAt('2027-03-27 10:00', DateFilter::Weekend)))
        ->toBe(['2027-03-27T00:00:00Z', '2027-03-28T23:00:00Z', 47.0]);
});

it('computes the 23h today on Sun 28 Mar 2027 22:00', function () {
    expect(utcSpan(windowAt('2027-03-28 22:00', DateFilter::Today)))
        ->toBe(['2027-03-28T00:00:00Z', '2027-03-28T23:00:00Z', 23.0]);
});

it('keeps start and end zoned in Europe/London', function () {
    $window = windowAt('2026-10-23 15:00', DateFilter::Weekend);

    expect($window->start->getTimezone()->getName())->toBe('Europe/London')
        ->and($window->start->format('D Y-m-d H:i'))->toBe('Sat 2026-10-24 00:00')
        ->and($window->end->format('D Y-m-d H:i'))->toBe('Mon 2026-10-26 00:00');
});

it('treats a UTC now that is already Saturday in London as the current weekend', function () {
    // 2026-10-23T23:30Z is still Friday in UTC but Sat 00:30 BST in London,
    // so the weekend is the current one starting today (London).
    $window = DateWindow::for(DateFilter::Weekend, CarbonImmutable::parse('2026-10-23T23:30:00Z'));

    expect(utcSpan($window))->toBe(['2026-10-23T23:00:00Z', '2026-10-26T00:00:00Z', 49.0]);
});
