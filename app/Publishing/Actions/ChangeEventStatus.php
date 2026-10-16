<?php

namespace App\Publishing\Actions;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

/**
 * RED scaffold: no-op.
 */
final class ChangeEventStatus
{
    public function __invoke(Event $event, EventStatus $to, User $actor): Event
    {
        return $event->refresh();
    }
}
