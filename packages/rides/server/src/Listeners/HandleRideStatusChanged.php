<?php

namespace Hopper\Rides\Listeners;

use Hopper\Rides\Events\RideStatusChanged;

class HandleRideStatusChanged
{
    /**
     * Handle the RideStatusChanged event.
     *
     * Syncs the ride status change with the linked FleetOps Order.
     */
    public function handle(RideStatusChanged $event): void
    {
        $ride = $event->ride;

        // If linked to a FleetOps Order, sync the status
        if ($ride->order_uuid && $ride->order) {
            $statusMap = [
                'driver_en_route'    => 'driver_en_route',
                'arrived_at_pickup'  => 'arrived_at_pickup',
                'passenger_onboard'  => 'passenger_onboard',
                'in_transit'         => 'in_transit',
                'dropped_off'        => 'dropped_off',
                'completed'          => 'completed',
                'canceled'           => 'canceled',
            ];

            $orderStatus = $statusMap[$ride->status] ?? null;
            
            // Only update if the order status is actually different to avoid double-logging activities
            if ($orderStatus && $ride->order->status !== $orderStatus) {
                $ride->order->updateStatus($orderStatus);
            }
        }
    }
}
