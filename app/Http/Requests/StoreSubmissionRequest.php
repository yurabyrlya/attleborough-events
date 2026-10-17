<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The public "Add your event" form (SUB-01, SUB-03). Anyone can post it, so
 * authorize() is open; the spam layers (02-09) and the rate limit (02-10)
 * sit in front of the same controller.
 *
 * "Today" is the Europe/London date, never the app (UTC) date: between
 * 00:00 and 01:00 BST the UTC date is still yesterday (RESEARCH Pitfall 4).
 * Dates are strict Y-m-d and times strict H:i (native date/time inputs send
 * exactly these), so 2026-02-30 or 24:00 are refused rather than rolled over.
 *
 * Strings are trimmed and blanks become null before validation, so an
 * optional field left empty is stored as null and a whitespace-only title
 * counts as missing. Lengths are counted in characters (mb), not bytes.
 */
final class StoreSubmissionRequest extends FormRequest
{
    public const VENUE_OTHER = 'other';

    public const CATEGORY_NOT_SURE = 'not_sure';

    /**
     * Checkbox fields: absent means false.
     */
    private const CHECKBOXES = ['all_day', 'contact_public', 'family_friendly'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now('Europe/London')->startOfDay();
        $latest = $today->addMonthsNoOverflow(12);

        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'category_choice' => ['required', 'string', $this->categoryChoice(...)],
            'family_friendly' => ['boolean'],

            'start_date' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$today->toDateString(),
                'before_or_equal:'.$latest->toDateString(),
            ],
            'all_day' => ['boolean'],
            'start_time' => ['exclude_if:all_day,true', 'required', 'date_format:H:i'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'end_time' => ['exclude_if:all_day,true', 'nullable', 'date_format:H:i'],
            'repeats_text' => ['nullable', 'string', 'max:200'],

            'venue_choice' => ['required', 'string', $this->venueChoice(...)],
            'venue_name' => ['exclude_unless:venue_choice,'.self::VENUE_OTHER, 'required', 'string', 'max:120'],
            'venue_address' => ['exclude_unless:venue_choice,'.self::VENUE_OTHER, 'required', 'string', 'max:255'],
            'venue_postcode' => [
                'exclude_unless:venue_choice,'.self::VENUE_OTHER, 'nullable', 'string', 'max:10',
                'regex:/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/',
            ],

            'price_type' => ['required', 'in:free,paid,unknown'],
            'price_text' => ['nullable', 'string', 'max:60'],

            'organiser_name' => ['required', 'string', 'max:120'],
            'organiser_email' => ['nullable', 'required_without:organiser_website', 'string', 'max:255', 'email:rfc'],
            'organiser_website' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'contact_public' => ['boolean'],
            'source_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],

            'consent' => ['accepted'],
        ];
    }

    /**
     * Friendly, do-this-next messages in the site's voice.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $time = 'Enter the time as hours and minutes, for example 19:30.';
        $url = 'Enter the full web address, starting with https://.';

        return [
            'title.required' => 'Add the name of the event.',
            'title.max' => 'Keep the name to 120 characters or fewer.',
            'description.required' => 'Add a short description so people know what to expect.',
            'description.max' => 'Keep the description to 5,000 characters or fewer.',
            'category_choice.required' => "Choose a category, or pick 'Not sure'.",

            'start_date.required' => 'Add the date of the event.',
            'start_date.date_format' => 'Enter a real date, for example 2026-11-14.',
            'start_date.after_or_equal' => "The date can't be in the past.",
            'start_date.before_or_equal' => 'We list events up to 12 months ahead.',
            'start_time.required' => "Add a start time, or tick 'All day'.",
            'start_time.date_format' => $time,
            'end_date.date_format' => 'Enter a real date, for example 2026-11-14.',
            'end_date.after_or_equal' => "The end date can't be before the start date.",
            'end_time.date_format' => $time,
            'repeats_text.max' => 'Keep this to 200 characters or fewer.',

            'venue_choice.required' => "Choose a venue, or pick 'Somewhere else' and type the address.",
            'venue_name.required' => 'Add the name of the place.',
            'venue_name.max' => 'Keep the name of the place to 120 characters or fewer.',
            'venue_address.required' => 'Add the address of the place.',
            'venue_address.max' => 'Keep the address to 255 characters or fewer.',
            'venue_postcode.max' => 'Enter a UK postcode, for example NR17 2AB.',
            'venue_postcode.regex' => 'Enter a UK postcode, for example NR17 2AB.',

            'price_type.required' => "Say whether it's free or paid, or pick 'Not sure'.",
            'price_type.in' => "Say whether it's free or paid, or pick 'Not sure'.",
            'price_text.max' => 'Keep the price to 60 characters or fewer.',

            'organiser_name.required' => 'Add your name or the name of your group.',
            'organiser_name.max' => 'Keep the name to 120 characters or fewer.',
            'organiser_email.required_without' => 'Add an email address or a website so we can check the details.',
            'organiser_email.email' => 'Enter an email address like name@example.com.',
            'organiser_email.max' => 'Keep the email address to 255 characters or fewer.',
            'organiser_website.url' => $url,
            'organiser_website.max' => 'Keep the web address to 2,048 characters or fewer.',
            'source_url.url' => $url,
            'source_url.max' => 'Keep the web address to 2,048 characters or fewer.',

            'consent.accepted' => 'Please tick the box to say we can use these details.',
        ];
    }

    /**
     * Trim every string, turn blanks into null, uppercase the postcode and
     * read unticked checkboxes as false.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->all() as $key => $value) {
            if (is_string($value)) {
                $value = (string) preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $value);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if (isset($clean['venue_postcode'])) {
            $clean['venue_postcode'] = mb_strtoupper($clean['venue_postcode']);
        }

        foreach (self::CHECKBOXES as $checkbox) {
            $clean[$checkbox] = $this->boolean($checkbox);
        }

        $this->merge($clean);
    }

    /**
     * A known venue's id or "Somewhere else". An unknown id is refused like
     * a missing venue (SUB-03).
     */
    private function venueChoice(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === self::VENUE_OTHER) {
            return;
        }

        if (! is_string($value) || ! ctype_digit($value) || ! Venue::query()->whereKey((int) $value)->exists()) {
            $fail("Choose a venue, or pick 'Somewhere else' and type the address.");
        }
    }

    /**
     * A known category's id or "Not sure".
     */
    private function categoryChoice(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === self::CATEGORY_NOT_SURE) {
            return;
        }

        if (! is_string($value) || ! ctype_digit($value) || ! Category::query()->whereKey((int) $value)->exists()) {
            $fail("Choose a category, or pick 'Not sure'.");
        }
    }
}
