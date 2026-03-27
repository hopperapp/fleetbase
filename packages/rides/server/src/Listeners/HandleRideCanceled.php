<?php

namespace Hopper\Rides\Listeners;

use Hopper\Rides\Events\RideCanceled;

class HandleRideCanceled
{
    /**
     * Handle the RideCanceled event.
     *
     * If the ride has a linked FleetOps Order, cancel that too.
     */
    public function handle(RideCanceled $event): void
    {
        $ride = $event->ride;

        // Cancel the linked FleetOps Order if it exists
        if ($ride->order_uuid && $ride->order) {
            $ride->order->cancel();
        }

        // Future: Send push notifications
        // Future: Process refund if payment was pre-authorized
    }
}
