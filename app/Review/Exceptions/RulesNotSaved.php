<?php

namespace App\Review\Exceptions;

use DomainException;

/**
 * The Rules screen's save was refused; nothing was written. Every problem
 * found is listed, in plain English, so the admin can fix them all at once.
 */
final class RulesNotSaved extends DomainException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Rules not saved: '.implode(' ', $problems));
    }
}
