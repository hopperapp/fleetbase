<?php

namespace Hopper\Rides\Listeners;

use Hopper\Rides\Events\RideRequested;
use Hopper\Rides\Jobs\BroadcastRideToDrivers;

class HandleRideRequested
{
    /**
     * Handle the RideRequested event.
     *
     * Dispatches a queued job to find nearby drivers and notify them.
     */
    public function handle(RideRequested $event): void
    {
        // Dispatch async job to fan-out ride to nearby matching drivers
        BroadcastRideToDrivers::dispatch($event->ride);
    }
}
