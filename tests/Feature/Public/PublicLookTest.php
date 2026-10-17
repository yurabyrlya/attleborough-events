<?php

use App\Enums\PriceType;
use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Support\Facades\Blade;

use function Pest\Laravel\get;

/*
 * D-06 restyle of the public calendar (CAL-01, CAL-06, CAL-07): tap targets,
 * skip link, non-colour chip state, category icon placeholders, a visible
 * text "Cancelled" badge, wrap-anywhere titles, no empty wrappers, the D-05
 * empty states and still no JavaScript.
 */

beforeEach(function () {
    $this->seed(CategorySeeder::class);
    freezeLondon('2026-10-28 12:00');
});

function lookDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/**
 * @return list<string>
 */
function lookClasses(?Element $element): array
{
    return preg_split('/\s+/', trim((string) $element?->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function lookText(?Node $node): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $node?->textContent));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function lookEvent(string $categorySlug, array $attributes = [], string $start = '2026-10-31 19:30', string $status = 'scheduled'): void
{
    makeOccurrence(array_merge([
        'category_id' => Category::where('slug', $categorySlug)->firstOrFail()->id,
    ], $attributes), $start, status: $status);
}

it('gives every interactive element outside running text the tap-target utility', function () {
    config(['calendar.page_size' => 1]);
    lookEvent('music', ['title' => 'Brass Band']);
    lookEvent('sport', ['title' => 'Fun Run'], '2026-10-31 20:00');

    $document = lookDocument((string) get('/')->assertOk()->getContent());

    $targets = [
        ...iterator_to_array($document->querySelectorAll('nav[aria-label="Filter events"] a')),
        $document->querySelector('header a[href="/"]'),
        $document->querySelector('a[href="#main"]'),
        $document->querySelector('nav[aria-label="More events"] a'),
    ];

    $empty = lookDocument((string) get('/?when=today')->assertOk()->getContent());
    $targets = [...$targets, ...iterator_to_array($empty->querySelectorAll('.empty-state a'))];

    expect(count($targets))->toBeGreaterThan(20);

    foreach ($targets as $target) {
        expect($target)->not->toBeNull()
            ->and(lookClasses($target))->toContain('tap-target');
    }

    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    preg_match('/@utility tap-target\s*\{([^}]*)\}/', $css, $block);

    preg_match('/min-height:\s*([\d.]+)rem/', $block[1] ?? '', $height);
    preg_match('/min-width:\s*([\d.]+)rem/', $block[1] ?? '', $width);

    expect((float) ($height[1] ?? 0))->toBeGreaterThanOrEqual(1.5)
        ->and((float) ($width[1] ?? 0))->toBeGreaterThanOrEqual(1.5);
});

it('starts the body with a skip link to the single main#main', function () {
    $document = lookDocument((string) get('/')->assertOk()->getContent());

    $first = $document->querySelector('body a[href], body button, body input, body select, body textarea, body [tabindex]');
    $mains = $document->querySelectorAll('main');

    expect($first?->getAttribute('href'))->toBe('#main')
        ->and(lookText($first))->toBe('Skip to main content')
        ->and($mains->length)->toBe(1)
        ->and($mains->item(0)->getAttribute('id'))->toBe('main');
});

it('keys chip styles to aria-current and marks active chips with a check icon', function () {
    $document = lookDocument((string) get('/?category=music')->assertOk()->getContent());
    $chips = $document->querySelectorAll('nav[aria-label="Filter events"] a');

    expect($chips->length)->toBeGreaterThan(10);

    $active = 0;

    foreach ($chips as $chip) {
        $keyed = array_filter(lookClasses($chip), fn (string $c): bool => str_starts_with($c, 'aria-[current=true]:'));
        $svgs = $chip->querySelectorAll('svg');

        expect($keyed)->not->toBeEmpty(lookText($chip).' has no aria-current keyed style');

        if ($chip->getAttribute('aria-current') === 'true') {
            $active++;
            expect($svgs->length)->toBe(1, lookText($chip).' active chip needs one check icon')
                ->and($svgs->item(0)->getAttribute('aria-hidden'))->toBe('true');
        } else {
            expect($svgs->length)->toBe(0, lookText($chip).' inactive chip must not show the check icon');
        }
    }

    expect($active)->toBe(2); // All upcoming + Music
});

it('keeps the When chips visible and folds Category and Show only into a closed More filters disclosure', function (string $uri) {
    $document = lookDocument((string) get($uri)->assertOk()->getContent());
    $nav = $document->querySelector('nav[aria-label="Filter events"]');
    $disclosures = $nav?->querySelectorAll('details');

    expect($disclosures?->length)->toBe(1);

    $details = $disclosures->item(0);
    $summary = $details->firstElementChild;

    expect($details->hasAttribute('open'))->toBeFalse()
        ->and($summary?->tagName)->toBe('SUMMARY')
        ->and(lookText($summary))->toContain('More filters')
        ->and(lookClasses($summary))->toContain('tap-target')
        ->and($nav->querySelector('[aria-labelledby="chips-when"]')?->closest('details'))->toBeNull()
        ->and($details->querySelector('[aria-labelledby="chips-category"]'))->not->toBeNull()
        ->and($details->querySelector('[aria-labelledby="chips-only"]'))->not->toBeNull();
})->with(['/', '/?when=weekend']);

it('renders the More filters disclosure open while a category, Free or Family-friendly filter is active', function (string $uri, string $activeLabel) {
    $document = lookDocument((string) get($uri)->assertOk()->getContent());
    $details = $document->querySelector('nav[aria-label="Filter events"] details');

    expect($details?->hasAttribute('open'))->toBeTrue();

    $activeChips = array_map(
        lookText(...),
        array_filter(
            iterator_to_array($details->querySelectorAll('a')),
            fn (Element $chip): bool => $chip->getAttribute('aria-current') === 'true',
        ),
    );

    expect($activeChips)->toContain($activeLabel);
})->with([
    'category' => ['/?category=music', 'Music'],
    'free' => ['/?free=1', 'Free'],
    'family' => ['/?family=1', 'Family-friendly'],
]);

it('shows one decorative category icon per card, picked from the slug allow-list', function () {
    lookEvent('music', ['title' => 'Brass Band']);
    lookEvent('food-drink', ['title' => 'Cake Sale'], '2026-10-31 20:00');

    $document = lookDocument((string) get('/')->assertOk()->getContent());
    $seen = [];

    foreach ($document->querySelectorAll('article') as $article) {
        $icons = $article->querySelectorAll('svg[data-category]');

        expect($icons->length)->toBe(1)
            ->and($icons->item(0)->getAttribute('aria-hidden'))->toBe('true')
            ->and($icons->item(0)->getAttribute('focusable'))->toBe('false');

        $seen[lookText($article->querySelector('h3'))] = $icons->item(0)->getAttribute('data-category');
    }

    expect($seen)->toBe(['Brass Band' => 'music', 'Cake Sale' => 'food-drink']);

    foreach (['../../.env', '', 'unknown', 'MUSIC'] as $slug) {
        $icon = lookDocument(Blade::render('<x-category-icon :slug="$slug" />', ['slug' => $slug]))->querySelector('svg');

        expect($icon?->getAttribute('data-category'))->toBe('other', "slug '{$slug}'");
    }
});

it('shows the Cancelled badge once, as visible text inside the title', function () {
    lookEvent('music', ['title' => 'Brass Band'], status: 'cancelled');

    $html = (string) get('/')->assertOk()->getContent();
    $document = lookDocument($html);

    expect(substr_count($html, 'Cancelled'))->toBe(1);

    $badge = null;

    foreach ($document->querySelectorAll('article *') as $element) {
        if (lookText($element) === 'Cancelled' && $element->childElementCount === 0) {
            $badge = $element;
        }
    }

    expect($badge)->not->toBeNull()
        ->and($badge->closest('h3'))->not->toBeNull();

    for ($node = $badge; $node !== null; $node = $node->parentElement) {
        foreach (lookClasses($node) as $class) {
            $last = substr((string) strrchr(':'.$class, ':'), 1);

            expect($last)->not->toBeIn(['hidden', 'sr-only', 'invisible'], "'{$class}' hides the badge");
        }

        if ($node->tagName === 'ARTICLE') {
            break;
        }
    }
});

it('keeps the title before the time element with the 01-06 datetime', function () {
    lookEvent('music', ['title' => 'Brass Band']);
    lookEvent('sport', ['title' => 'Fun Run'], '2026-11-01 10:00', status: 'cancelled');

    $cards = cardsIn(get('/')->assertOk());

    expect($cards)->toHaveCount(2)
        ->and($cards[0])->toContain('<time datetime="2026-10-31T19:30:00+00:00">Sat 31 Oct, 7:30pm</time>')
        ->and($cards[1])->toContain('<time datetime="2026-11-01T10:00:00+00:00">Sun 1 Nov, 10am</time>');

    foreach ($cards as $card) {
        expect(strpos($card, '<h3'))->toBeLessThan(strpos($card, '<time'));
    }
});

it('escapes ampersands and apostrophes exactly once', function () {
    $html = (string) get('/')->assertOk()->getContent();

    expect($html)->toContain('Food &amp; Drink')
        ->and($html)->toContain('What&#039;s On Attleborough')
        ->and($html)->not->toContain('&amp;amp;')
        ->and($html)->not->toContain('&amp;#039;');
});

it('lets a long unbroken title wrap anywhere', function () {
    $title = str_repeat('Attleborough', 7).'Abcd'; // 88 characters, no spaces
    lookEvent('music', ['title' => substr($title, 0, 80)]);

    $h3 = lookDocument((string) get('/')->assertOk()->getContent())->querySelector('article h3');

    expect(strlen(lookText($h3)))->toBe(80)
        ->and(lookClasses($h3))->toContain('wrap-anywhere');
});

it('leaves no empty wrappers on a card with only the required fields', function () {
    lookEvent('market', [
        'title' => 'Market Day',
        'description' => '',
        'organiser_name' => null,
        'organiser_contact' => null,
        'source_url' => null,
        'price_type' => PriceType::Unknown,
        'price_text' => null,
        'family_friendly' => false,
    ]);

    $article = lookDocument((string) get('/')->assertOk()->getContent())->querySelector('article');

    expect($article?->querySelectorAll('svg[data-category="market"]')->length)->toBe(1);

    foreach ($article->querySelectorAll('p, span, li, dd, div') as $element) {
        $empty = trim((string) $element->textContent) === '' && $element->childElementCount === 0;

        expect($empty)->toBeFalse("empty <{$element->localName} class=\"{$element->getAttribute('class')}\">");
    }
});

it('keeps the D-05 empty state inside the styled page with the chips still shown', function () {
    lookEvent('music', ['title' => 'Brass Band']);

    $document = lookDocument((string) get('/?when=today')->assertOk()->getContent());
    $links = $document->querySelectorAll('.empty-state a');

    expect(lookText($document->querySelector('main')))->toContain('Nothing on today. Why not check the weekend?')
        ->and($document->querySelector('nav[aria-label="Filter events"]'))->not->toBeNull()
        ->and($links->length)->toBe(1)
        ->and(lookClasses($links->item(0)))->toContain('tap-target');
});

it('links the contact email in the footer', function () {
    $document = lookDocument((string) get('/')->assertOk()->getContent());

    expect($document->querySelector('footer a[href="mailto:'.config('site.contact_email').'"]'))->not->toBeNull();
});

it('still ships no script, form or Livewire markup', function (string $uri) {
    lookEvent('music', ['title' => 'Brass Band'], '2026-10-31 19:30');

    $html = (string) get($uri)->assertOk()->getContent();
    $document = lookDocument($html);

    expect($document->querySelectorAll('script')->length)->toBe(0)
        ->and($document->querySelectorAll('form')->length)->toBe(0)
        ->and($html)->not->toContain('wire:');
})->with(['/', '/?when=weekend&category=music']);
