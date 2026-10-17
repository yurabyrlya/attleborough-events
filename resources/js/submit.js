/**
 * "Add your event" form module (D-01). First-party, no dependencies, no
 * network calls. Progressive enhancement only: the form works without this
 * file, and the "Somewhere else" venue fields are always in the HTML.
 *
 * - While a known venue is picked, the "Somewhere else" fields are hidden;
 *   they show again for "Somewhere else" or when no venue is picked. The
 *   server ignores them unless "Somewhere else" is chosen, so a hidden,
 *   half-typed value is never stored.
 * - When the page comes back with errors, focus moves to the error summary
 *   so keyboard and screen-reader users start there.
 *
 * No inline handlers; nothing is written into the page as HTML.
 */
for (const picker of document.querySelectorAll('[data-venue-picker]')) {
    const select = picker.querySelector('select');
    const other = picker.querySelector('[data-venue-other]');

    if (!select || !other) {
        continue;
    }

    const update = () => {
        other.hidden = select.value !== '' && select.value !== 'other';
    };

    select.addEventListener('change', update);
    update();
}

const summary = document.querySelector('[data-error-summary]');

if (summary) {
    summary.focus();
}
