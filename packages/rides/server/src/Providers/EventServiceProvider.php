<?php

namespace Hopper\Rides\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the extension.
     *
     * @var array
     */
    protected $listen = [
        \Hopper\Rides\Events\RideRequested::class => [
            \Hopper\Rides\Listeners\HandleRideRequested::class,
        ],
        \Hopper\Rides\Events\RideBidReceived::class => [
            \Hopper\Rides\Listeners\HandleRideBidReceived::class,
        ],
        \Hopper\Rides\Events\RideBidAccepted::class => [
            \Hopper\Rides\Listeners\HandleRideBidAccepted::class,
        ],
        \Hopper\Rides\Events\RideStatusChanged::class => [
            \Hopper\Rides\Listeners\HandleRideStatusChanged::class,
        ],
        \Hopper\Rides\Events\RideCanceled::class => [
            \Hopper\Rides\Listeners\HandleRideCanceled::class,
        ],
    ];

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
