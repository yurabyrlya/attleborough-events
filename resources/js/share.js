/**
 * Event page share module (EVT-07). First-party, no dependencies, no
 * network calls. Progressive enhancement only: the WhatsApp, Facebook and
 * Email links and the link box work without this file and are never
 * hidden or changed here.
 *
 * - "Share…" is revealed only where the Web Share API exists (and accepts
 *   the payload). navigator.share runs inside the click handler (transient
 *   activation); further taps are ignored while a share is open; AbortError
 *   (the visitor cancelled) is a silent no-op; any other failure is
 *   reported in the polite status region.
 * - "Copy link" is revealed only in a secure context with the async
 *   Clipboard API.
 * - Status text is set with textContent only.
 */
for (const root of document.querySelectorAll('[data-share]')) {
    const data = {
        title: root.dataset.shareTitle || document.title,
        text: root.dataset.shareText || '',
        url: root.dataset.shareUrl || location.href,
    };
    const status = root.querySelector('[data-share-status]');
    const say = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const native = root.querySelector('[data-share-native]');

    if (native && typeof navigator.share === 'function' && (typeof navigator.canShare !== 'function' || navigator.canShare(data))) {
        let sharing = false;

        native.hidden = false;
        native.addEventListener('click', async () => {
            if (sharing) {
                return;
            }

            sharing = true;
            say('');

            try {
                await navigator.share(data);
            } catch (error) {
                if (!error || error.name !== 'AbortError') {
                    say("Sharing didn't work. Use one of the links below.");
                }
            } finally {
                sharing = false;
            }
        });
    }

    const input = root.querySelector('[data-share-input]');

    if (input) {
        input.addEventListener('focus', () => input.select());
    }

    const copy = root.querySelector('[data-share-copy]');

    if (copy && window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        copy.hidden = false;
        copy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(data.url);
                say('Link copied.');
            } catch {
                say("Couldn't copy. Press and hold the link box above to copy it.");
            }
        });
    }
}
