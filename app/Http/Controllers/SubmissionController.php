<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionRequest;
use App\Models\Category;
use App\Models\Venue;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The public "Add your event" form (SUB-01, SUB-02, SUB-03).
 *
 * Public URL contract (costly to change): /submit is what organisers
 * bookmark and share, and /submit/thanks is its confirmation (D-04).
 *
 * POST-redirect-GET: store() writes ONE pending_review draft through
 * DraftWriter and redirects to /submit/thanks with the reference flashed, so
 * a refresh of the thanks page creates nothing. thanks() without the flashed
 * "submitted" marker goes back to the form.
 *
 * Nothing here writes events or occurrences, and nothing calls PublishEvent
 * or ApproveDraft: a submission reaches the calendar only when the admin
 * approves its draft (SUB-02 prohibition). Every submitted value is rendered
 * through escaped Blade only (T-02-21); the form carries @csrf (T-02-22);
 * links must be http(s) and are only shown after approval (T-02-23). The
 * spam layers run in StoreSubmissionRequest (02-09) and the rate limit
 * (02-10) sits in front of store().
 *
 * A filled honeypot gets the same thank-you page with no reference and
 * nothing is written, so a bot learns nothing (SUB-04). The Turnstile
 * widget is rendered only when a site key is set (D-17); in production
 * without keys the form is replaced by the "resting" notice.
 */
final class SubmissionController
{
    public function create(): View
    {
        $siteKey = config('services.turnstile.site_key');

        return view('submit.create', [
            'formResting' => StoreSubmissionRequest::formIsResting(),
            'turnstileSiteKey' => is_string($siteKey) && $siteKey !== '' ? $siteKey : null,
            'venues' => Venue::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('sort')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreSubmissionRequest $request, DraftWriter $writer): RedirectResponse
    {
        if ($request->isBot()) {
            return redirect()->route('submit.thanks')->with('submitted', true);
        }

        $draft = $writer->create(DraftData::fromSubmission($request->validated()));

        return redirect()->route('submit.thanks')->with([
            'submitted' => true,
            'reference' => $draft->reference,
        ]);
    }

    public function thanks(): View|RedirectResponse
    {
        if (! session('submitted')) {
            return redirect()->route('submit.create');
        }

        $reference = session('reference');

        return view('submit.thanks', [
            'reference' => is_string($reference) ? $reference : null,
        ]);
    }
}
