<?php

namespace App\Review\Exceptions;

use DomainException;

/**
 * Review CR-02: a review save refused because the locked draft is no longer
 * the version the edit page opened (ingestion replaced it with the feed's
 * new facts, or another tab saved it). Saving would write the page's stale
 * values over the newer ones. The message is shown to the admin as is.
 */
final class DraftChangedMeanwhile extends DomainException
{
    public const string MESSAGE = 'This draft changed while you were editing — reload to see the latest version.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
