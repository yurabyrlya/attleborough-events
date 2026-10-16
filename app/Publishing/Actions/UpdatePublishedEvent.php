<?php

namespace App\Publishing\Actions;

use App\Models\Event;
use App\Models\User;
use App\Publishing\Data\PublishableEventData;

final class UpdatePublishedEvent
{
    public function __invoke(Event $event, PublishableEventData $data, User $editor): Event
    {
        return $event; // RED scaffold: implemented in GREEN
    }
}
