<?php

namespace Hopper\Rides\Listeners;

use Hopper\Rides\Events\RideBidAccepted;

class HandleRideBidAccepted
{
    /**
     * Handle the RideBidAccepted event.
     *
     * The event handles broadcasting. This listener can be used for
     * additional side effects like notifying rejected drivers.
     */
    public function handle(RideBidAccepted $event): void
    {
        // Future: Send push notification to winning driver
        // Future: Send push notification to rejected drivers
    }
}
