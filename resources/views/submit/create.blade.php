@extends('layouts.app')

@php
    /**
     * The public "Add your event" form (SUB-01, SUB-03, D-01, D-03, D-08).
     * Plain POST, no Livewire or Alpine: it works with JavaScript off. The
     * "Somewhere else" venue fields are always in the HTML.
     *
     * Every value is echoed escaped, including old() input re-shown after
     * an error (T-02-21). Errors: a summary at the top links to each field;
     * each field carries aria-invalid and aria-describedby to its hint and
     * error, and the error text starts with a visually hidden "Error:", so
     * nothing relies on colour alone.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\Venue> $venues
     * @var \Illuminate\Support\Collection<int, \App\Models\Category> $categories
     */

    // The element an error summary link jumps to, when it isn't the field name.
    $fieldIds = ['price_type' => 'price_type_free'];

    // aria-describedby for a field: its hint (when it has one) and its error (when there is one).
    $describedBy = function (string $field, ?string $hintId = null) use ($errors): ?string {
        $ids = array_filter([$hintId, $errors->has($field) ? $field.'-error' : null]);

        return $ids === [] ? null : implode(' ', $ids);
    };

    $input = 'tap-target w-full rounded-md border border-line bg-bg px-2 text-ink aria-[invalid=true]:border-2 aria-[invalid=true]:border-cancel';
    $label = 'text-sm text-muted';
    $hint = 'text-sm text-muted';
    $legend = 'text-lg font-semibold text-ink';
    $check = 'size-5 shrink-0 accent-accent';
    $notSure = \App\Http\Requests\StoreSubmissionRequest::CATEGORY_NOT_SURE;
    $venueOther = \App\Http\Requests\StoreSubmissionRequest::VENUE_OTHER;
@endphp

@section('title', 'Add your event')
@section('description', "Send your Attleborough event to What's On Attleborough. We check every event before it goes on the calendar.")

@push('head')
    {{-- The only script on the page: the first-party venue reveal (D-01). The form works without it. --}}
    @vite('resources/js/submit.js')
@endpush

@section('content')
    <h1 class="mt-6 text-2xl font-bold text-ink">Add your event</h1>
    <p class="mt-2 text-ink">Tell us about your event and we'll check it before it goes on the calendar.</p>

    @if ($errors->any())
        <div role="alert" aria-labelledby="error-summary-heading" tabindex="-1" data-error-summary class="mt-6 rounded-md border-2 border-cancel p-4">
            <h2 id="error-summary-heading" class="text-lg font-semibold text-ink">Please check these</h2>
            <ul class="mt-2 flex flex-col">
                @foreach ($errors->keys() as $field)
                    <li><a href="#{{ $fieldIds[$field] ?? $field }}" class="tap-target font-semibold text-cancel underline underline-offset-2">{{ $errors->first($field) }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('submit.store', absolute: false) }}" novalidate class="mt-6 flex flex-col gap-8">
        @csrf

        <fieldset class="flex flex-col gap-4">
            <legend class="{{ $legend }}">What's happening</legend>

            <div class="flex flex-col gap-1">
                <label for="title" class="{{ $label }}">Name of the event</label>
                @error('title')<p id="title-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="title" name="title" type="text" value="{{ old('title') }}" maxlength="120" required autocomplete="off" @error('title') aria-invalid="true" @enderror @if ($describedBy('title')) aria-describedby="{{ $describedBy('title') }}" @endif class="{{ $input }}">
            </div>

            <div class="flex flex-col gap-1">
                <label for="description" class="{{ $label }}">Description</label>
                <p id="description-hint" class="{{ $hint }}">What happens, who it's for and anything people should bring.</p>
                @error('description')<p id="description-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <textarea id="description" name="description" rows="6" maxlength="5000" required aria-describedby="{{ $describedBy('description', 'description-hint') }}" @error('description') aria-invalid="true" @enderror class="w-full rounded-md border border-line bg-bg p-2 text-ink aria-[invalid=true]:border-2 aria-[invalid=true]:border-cancel">{{ old('description') }}</textarea>
            </div>

            <div class="flex flex-col gap-1">
                <label for="category_choice" class="{{ $label }}">Category</label>
                @error('category_choice')<p id="category_choice-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <select id="category_choice" name="category_choice" required @error('category_choice') aria-invalid="true" @enderror @if ($describedBy('category_choice')) aria-describedby="{{ $describedBy('category_choice') }}" @endif class="{{ $input }}">
                    <option value="">Choose a category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_choice') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                    <option value="{{ $notSure }}" @selected(old('category_choice') === $notSure)>Not sure</option>
                </select>
            </div>

            <label for="family_friendly" class="tap-target gap-2 self-start text-ink">
                <input id="family_friendly" name="family_friendly" type="checkbox" value="1" @checked(old('family_friendly')) class="{{ $check }}">
                Good for families
            </label>
        </fieldset>

        <fieldset class="flex flex-col gap-4">
            <legend class="{{ $legend }}">When</legend>

            <div class="flex flex-col gap-1">
                <label for="start_date" class="{{ $label }}">Date</label>
                @error('start_date')<p id="start_date-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="start_date" name="start_date" type="date" value="{{ old('start_date') }}" required @error('start_date') aria-invalid="true" @enderror @if ($describedBy('start_date')) aria-describedby="{{ $describedBy('start_date') }}" @endif class="{{ $input }}">
            </div>

            <label for="all_day" class="tap-target gap-2 self-start text-ink">
                <input id="all_day" name="all_day" type="checkbox" value="1" @checked(old('all_day')) class="{{ $check }}">
                All day
            </label>

            <div class="flex flex-col gap-1">
                <label for="start_time" class="{{ $label }}">Start time</label>
                <p id="start_time-hint" class="{{ $hint }}">Leave blank if you ticked All day.</p>
                @error('start_time')<p id="start_time-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="start_time" name="start_time" type="time" value="{{ old('start_time') }}" aria-describedby="{{ $describedBy('start_time', 'start_time-hint') }}" @error('start_time') aria-invalid="true" @enderror class="{{ $input }}">
            </div>

            <div class="flex flex-col gap-1">
                <label for="end_date" class="{{ $label }}">End date (optional)</label>
                <p id="end_date-hint" class="{{ $hint }}">Only if it runs over more than one day.</p>
                @error('end_date')<p id="end_date-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="end_date" name="end_date" type="date" value="{{ old('end_date') }}" aria-describedby="{{ $describedBy('end_date', 'end_date-hint') }}" @error('end_date') aria-invalid="true" @enderror class="{{ $input }}">
            </div>

            <div class="flex flex-col gap-1">
                <label for="end_time" class="{{ $label }}">End time (optional)</label>
                @error('end_time')<p id="end_time-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="end_time" name="end_time" type="time" value="{{ old('end_time') }}" @error('end_time') aria-invalid="true" @enderror @if ($describedBy('end_time')) aria-describedby="{{ $describedBy('end_time') }}" @endif class="{{ $input }}">
            </div>

            <div class="flex flex-col gap-1">
                <label for="repeats_text" class="{{ $label }}">Does it repeat? (optional)</label>
                <p id="repeats_text-hint" class="{{ $hint }}">For example: every Tuesday until December, or the first Saturday of each month</p>
                @error('repeats_text')<p id="repeats_text-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="repeats_text" name="repeats_text" type="text" value="{{ old('repeats_text') }}" maxlength="200" autocomplete="off" aria-describedby="{{ $describedBy('repeats_text', 'repeats_text-hint') }}" @error('repeats_text') aria-invalid="true" @enderror class="{{ $input }}">
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4" data-venue-picker>
            <legend class="{{ $legend }}">Where</legend>

            <div class="flex flex-col gap-1">
                <label for="venue_choice" class="{{ $label }}">Venue</label>
                @error('venue_choice')<p id="venue_choice-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <select id="venue_choice" name="venue_choice" required data-venue-select @error('venue_choice') aria-invalid="true" @enderror @if ($describedBy('venue_choice')) aria-describedby="{{ $describedBy('venue_choice') }}" @endif class="{{ $input }}">
                    <option value="">Choose a venue</option>
                    @foreach ($venues as $venue)
                        <option value="{{ $venue->id }}" @selected(old('venue_choice') === (string) $venue->id)>{{ $venue->name }}</option>
                    @endforeach
                    <option value="{{ $venueOther }}" @selected(old('venue_choice') === $venueOther)>Somewhere else</option>
                </select>
            </div>

            <div class="flex flex-col gap-4" data-venue-other>
                <p id="venue_other-hint" class="{{ $hint }}">Only needed if you picked Somewhere else</p>

                <div class="flex flex-col gap-1">
                    <label for="venue_name" class="{{ $label }}">Name of the place</label>
                    @error('venue_name')<p id="venue_name-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                    <input id="venue_name" name="venue_name" type="text" value="{{ old('venue_name') }}" maxlength="120" aria-describedby="{{ $describedBy('venue_name', 'venue_other-hint') }}" @error('venue_name') aria-invalid="true" @enderror class="{{ $input }}">
                </div>

                <div class="flex flex-col gap-1">
                    <label for="venue_address" class="{{ $label }}">Address</label>
                    @error('venue_address')<p id="venue_address-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                    <input id="venue_address" name="venue_address" type="text" value="{{ old('venue_address') }}" maxlength="255" autocomplete="street-address" aria-describedby="{{ $describedBy('venue_address', 'venue_other-hint') }}" @error('venue_address') aria-invalid="true" @enderror class="{{ $input }}">
                </div>

                <div class="flex flex-col gap-1">
                    <label for="venue_postcode" class="{{ $label }}">Postcode (optional)</label>
                    @error('venue_postcode')<p id="venue_postcode-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                    <input id="venue_postcode" name="venue_postcode" type="text" value="{{ old('venue_postcode') }}" maxlength="10" autocomplete="postal-code" aria-describedby="{{ $describedBy('venue_postcode', 'venue_other-hint') }}" @error('venue_postcode') aria-invalid="true" @enderror class="{{ $input }} sm:w-40">
                </div>
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4">
            <legend class="{{ $legend }}">Price</legend>

            @error('price_type')<p id="price_type-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-x-6">
                @foreach (['free' => 'Free', 'paid' => 'Paid', 'unknown' => 'Not sure'] as $value => $text)
                    <label for="price_type_{{ $value }}" class="tap-target gap-2 text-ink">
                        <input id="price_type_{{ $value }}" name="price_type" type="radio" value="{{ $value }}" @checked(old('price_type') === $value) @error('price_type') aria-invalid="true" aria-describedby="price_type-error" @enderror class="{{ $check }}">
                        {{ $text }}
                    </label>
                @endforeach
            </div>

            <div class="flex flex-col gap-1">
                <label for="price_text" class="{{ $label }}">Price details (optional)</label>
                <p id="price_text-hint" class="{{ $hint }}">For example: £5, children free</p>
                @error('price_text')<p id="price_text-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="price_text" name="price_text" type="text" value="{{ old('price_text') }}" maxlength="60" autocomplete="off" aria-describedby="{{ $describedBy('price_text', 'price_text-hint') }}" @error('price_text') aria-invalid="true" @enderror class="{{ $input }}">
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4">
            <legend class="{{ $legend }}">Who's running it</legend>

            <div class="flex flex-col gap-1">
                <label for="organiser_name" class="{{ $label }}">Your name or group</label>
                @error('organiser_name')<p id="organiser_name-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="organiser_name" name="organiser_name" type="text" value="{{ old('organiser_name') }}" maxlength="120" required autocomplete="organization" @error('organiser_name') aria-invalid="true" @enderror @if ($describedBy('organiser_name')) aria-describedby="{{ $describedBy('organiser_name') }}" @endif class="{{ $input }}">
            </div>

            <p id="organiser_contact-hint" class="{{ $hint }}">Add an email address or a website (or both) so we can check the details.</p>

            <div class="flex flex-col gap-1">
                <label for="organiser_email" class="{{ $label }}">Email address</label>
                @error('organiser_email')<p id="organiser_email-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="organiser_email" name="organiser_email" type="email" value="{{ old('organiser_email') }}" maxlength="255" autocomplete="email" aria-describedby="{{ $describedBy('organiser_email', 'organiser_contact-hint') }}" @error('organiser_email') aria-invalid="true" @enderror class="{{ $input }}">
            </div>

            <div class="flex flex-col gap-1">
                <label for="organiser_website" class="{{ $label }}">Website</label>
                @error('organiser_website')<p id="organiser_website-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="organiser_website" name="organiser_website" type="url" value="{{ old('organiser_website') }}" maxlength="2048" autocomplete="url" placeholder="https://" aria-describedby="{{ $describedBy('organiser_website', 'organiser_contact-hint') }}" @error('organiser_website') aria-invalid="true" @enderror class="{{ $input }}">
            </div>

            <div class="flex flex-col gap-1">
                <label for="contact_public" class="tap-target gap-2 self-start text-ink">
                    <input id="contact_public" name="contact_public" type="checkbox" value="1" @checked(old('contact_public')) aria-describedby="contact_public-hint" class="{{ $check }}">
                    Show my contact publicly
                </label>
                <p id="contact_public-hint" class="{{ $hint }}">Your name and website are always shown. Your email is shown only if you tick this.</p>
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4">
            <legend class="{{ $legend }}">Anything else</legend>

            <div class="flex flex-col gap-1">
                <label for="source_url" class="{{ $label }}">Original link (optional)</label>
                <p id="source_url-hint" class="{{ $hint }}">A page about the event, for example on Facebook or your website.</p>
                @error('source_url')<p id="source_url-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <input id="source_url" name="source_url" type="url" value="{{ old('source_url') }}" maxlength="2048" autocomplete="url" placeholder="https://" aria-describedby="{{ $describedBy('source_url', 'source_url-hint') }}" @error('source_url') aria-invalid="true" @enderror class="{{ $input }}">
            </div>

            <div class="flex flex-col gap-1">
                @error('consent')<p id="consent-error" class="text-sm font-semibold text-cancel"><span class="sr-only">Error:</span> {{ $message }}</p>@enderror
                <div class="flex items-start gap-2">
                    <span class="tap-target justify-center"><input id="consent" name="consent" type="checkbox" value="1" required @checked(old('consent')) @error('consent') aria-invalid="true" aria-describedby="consent-error" @enderror class="{{ $check }}"></span>
                    <label for="consent" class="py-2.5 text-ink">I agree that {{ config('site.name') }} can use these details as described in the <a href="{{ route('privacy', absolute: false) }}" target="_blank" rel="noopener" class="text-accent underline underline-offset-2">privacy notice (opens in a new tab)</a>.</label>
                </div>
            </div>
        </fieldset>

        <button type="submit" class="tap-target self-start rounded-md bg-accent px-4 font-semibold text-accent-ink">Send for review</button>
    </form>
@endsection
