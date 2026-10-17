<?php

namespace App\Calendar;

use App\Enums\DateFilter;
use App\Models\Category;
use Illuminate\Http\Request;

/**
 * Validated public filter state parsed from the query string (CAL-02..06).
 *
 * Only whitelisted params survive: unknown params, page and invalid values
 * never reach chip or pagination links. Array-valued params are ignored.
 */
final readonly class CalendarFilters
{
    private const KEYS = ['when', 'category', 'free', 'family'];

    private const SLUG_PATTERN = '/^[a-z0-9-]{1,64}$/';

    public function __construct(
        public ?DateFilter $when = null,
        public ?Category $category = null,
        public bool $free = false,
        public bool $family = false,
        public bool $hasQueryString = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $when = self::stringParam($request, 'when');

        return new self(
            when: $when !== null ? DateFilter::tryFrom($when) : null,
            category: self::category(self::stringParam($request, 'category')),
            free: self::stringParam($request, 'free') === '1',
            family: self::stringParam($request, 'family') === '1',
            hasQueryString: $request->query->count() > 0,
        );
    }

    /**
     * Resolve a category slug (lowercase ASCII). A malformed value 404s
     * before any lookup; an unknown slug 404s. The raw value is never echoed.
     */
    private static function category(?string $value): ?Category
    {
        if ($value === null) {
            return null;
        }

        $slug = strtolower($value);

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            abort(404);
        }

        return Category::query()->where('slug', $slug)->first() ?? abort(404);
    }

    public static function none(): self
    {
        return new self;
    }

    private static function stringParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Active params only, in the fixed order when, category, free, family.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'when' => $this->when?->value,
            'category' => $this->category?->slug,
            'free' => $this->free ? '1' : null,
            'family' => $this->family ? '1' : null,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * The current params with $changes applied (null removes a key). Never
     * includes page or unknown params.
     *
     * @param  array<string, string|null>  $changes
     * @return array<string, string>
     */
    public function queryWith(array $changes): array
    {
        $merged = array_merge($this->toQuery(), $changes);
        $query = [];

        foreach (self::KEYS as $key) {
            if (isset($merged[$key])) {
                $query[$key] = $merged[$key];
            }
        }

        return $query;
    }

    public function hasAttributeFilters(): bool
    {
        return $this->attributeFilterCount() > 0;
    }

    /**
     * How many Category / Show only filters are active (0-3): the count on
     * the closed "More filters" summary.
     */
    public function attributeFilterCount(): int
    {
        return (int) ($this->category !== null) + (int) $this->free + (int) $this->family;
    }

    public function hasAny(): bool
    {
        return $this->when !== null || $this->hasAttributeFilters();
    }

    /**
     * "This weekend · Music · Free", built from resolved values only.
     */
    public function summary(): string
    {
        return implode(' · ', array_filter([
            $this->when?->label(),
            $this->category?->name,
            $this->free ? 'Free' : null,
            $this->family ? 'Family-friendly' : null,
        ]));
    }
}
