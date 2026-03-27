<?php

namespace Hopper\Rides\Events;

use Hopper\Rides\Models\Ride;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Ride $ride;

    public function __construct(Ride $ride)
    {
        $this->ride = $ride;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('company.' . $this->ride->company_uuid . '.rides'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_id'          => $this->ride->public_id,
            'pickup_latitude'  => $this->ride->pickup_latitude,
            'pickup_longitude' => $this->ride->pickup_longitude,
            'pickup_address'   => $this->ride->pickup_address,
            'dropoff_address'  => $this->ride->dropoff_address,
            'vehicle_category' => $this->ride->vehicleCategory?->key,
            'pricing_method'   => $this->ride->pricing_method,
            'estimated_price'  => $this->ride->estimated_price,
            'currency'         => $this->ride->currency,
        ];
    }
}
