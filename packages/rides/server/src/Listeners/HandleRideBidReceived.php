<?php

namespace Hopper\Rides\Listeners;

use Hopper\Rides\Events\RideBidReceived;

class HandleRideBidReceived
{
    /**
     * Handle the RideBidReceived event.
     *
     * The event itself handles broadcasting. This listener can be used
     * for additional side-effects like push notifications.
     */
    public function handle(RideBidReceived $event): void
    {
        // Future: Send push notification to customer about new bid
    }
}
