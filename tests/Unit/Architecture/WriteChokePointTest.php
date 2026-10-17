<?php

/*
 * The write choke point (REV-02, T-02-05): PublishEvent, the only creator of
 * Event rows, is reachable only from the Publishing actions (ApproveDraft
 * today). Every other namespace is covered, including ones that do not exist
 * yet (jobs, ingestion), so no controller, page, command or crawler can
 * publish without going through a draft.
 *
 * Not written as expect('App')->not->toUse(PublishEvent)->ignoring('App\Publishing\Actions'):
 * Pest's ignoring() skips matching targets AND dependencies, and PublishEvent
 * itself lives in App\Publishing\Actions, so that form can never fail (a
 * planted App\Jobs bypass passed it). toOnlyBeUsedIn() states the rule
 * directly and fails on that bypass.
 */

arch('publishes events only through the Publishing actions')
    ->expect('App\Publishing\Actions\PublishEvent')
    ->toOnlyBeUsedIn('App\Publishing\Actions');
