<?php

namespace App\Review\Exceptions;

use DomainException;

/**
 * A draft that cannot be published yet. Carries the plain-English blockers
 * (for example "Pick a category.") so the admin page can show them.
 */
final class DraftNotPublishable extends DomainException
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode('; ', $blockers));
    }
}
