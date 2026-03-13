<?php

namespace Hopper\Rides\Events;

use Hopper\Rides\Models\RideBid;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideBidAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public RideBid $bid;

    public function __construct(RideBid $bid)
    {
        $this->bid = $bid;
    }

    public function broadcastOn(): array
    {
        return [
            // Notify the winning driver
            new Channel('driver.' . $this->bid->driver_uuid . '.rides'),
            // Notify the ride channel (customer + all bidding drivers)
            new Channel('ride.' . $this->bid->ride_uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bid.accepted';
    }

    public function broadcastWith(): array
    {
        $ride = $this->bid->ride;

        return [
            'bid_id'     => $this->bid->public_id,
            'ride_id'    => $ride?->public_id,
            'driver_id'  => $this->bid->driver?->public_id,
            'amount'     => $this->bid->amount,
            'currency'   => $this->bid->currency,
            'status'     => 'accepted',
        ];
    }
}
