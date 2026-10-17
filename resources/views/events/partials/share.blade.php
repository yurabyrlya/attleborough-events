@php
    /**
     * Share block (EVT-07). Works without JavaScript: the WhatsApp, Facebook
     * and Email links and the link box are plain HTML and send nothing until
     * tapped. The "Share…" and "Copy link" buttons ship hidden; only the
     * first-party share module (loaded by events/show) reveals them, so a
     * visitor without JavaScript never sees a dead button. No inline
     * handlers, no inline script, no third-party widgets (T-01-63, T-01-66).
     *
     * @var \App\Support\EventPageData $page
     */
@endphp
<section class="mt-6" aria-labelledby="share-heading">
    <h2 id="share-heading" class="text-lg font-semibold text-ink">Share this event</h2>
    <div class="mt-2 flex flex-col gap-3" data-share data-share-title="{{ $page->share->title }}" data-share-text="{{ $page->share->text }}" data-share-url="{{ $page->share->url }}">
        <button type="button" data-share-native hidden class="tap-target self-start rounded-md border border-accent px-3 font-semibold text-accent">Share…</button>
        <ul class="flex flex-wrap gap-x-4 gap-y-2 [&_a]:text-accent [&_a]:underline [&_a]:underline-offset-2">
            <li><a href="{{ $page->share->whatsapp }}" rel="nofollow noopener" class="tap-target">WhatsApp</a></li>
            <li><a href="{{ $page->share->facebook }}" rel="nofollow noopener" class="tap-target">Facebook</a></li>
            <li><a href="{{ $page->share->email }}" rel="nofollow noopener" class="tap-target">Email</a></li>
        </ul>
        <div class="flex flex-col gap-1">
            <label for="share-url" class="text-sm text-muted">Link to this event</label>
            <input id="share-url" type="text" readonly value="{{ $page->share->url }}" data-share-input class="tap-target w-full rounded-md border border-line bg-bg px-2 text-ink">
        </div>
        <button type="button" data-share-copy hidden class="tap-target self-start rounded-md border border-accent px-3 font-semibold text-accent">Copy link</button>
        {{-- Empty until share.js reports something; a div so no empty paragraph is left on the page. --}}
        <div role="status" aria-live="polite" data-share-status class="text-sm text-ink"></div>
    </div>
</section>
