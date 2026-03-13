<?php

namespace Hopper\Rides\Events;

use Hopper\Rides\Models\RideBid;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideBidReceived implements ShouldBroadcast
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
            new Channel('ride.' . $this->bid->ride_uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bid.received';
    }

    public function broadcastWith(): array
    {
        return [
            'bid_id'               => $this->bid->public_id,
            'ride_id'              => $this->bid->ride?->public_id,
            'driver_name'          => $this->bid->driver_name,
            'driver_rating'        => $this->bid->driver_rating,
            'vehicle_info'         => $this->bid->vehicle_info,
            'amount'               => $this->bid->amount,
            'currency'             => $this->bid->currency,
            'estimated_arrival_min' => $this->bid->estimated_arrival_min,
            'note'                 => $this->bid->note,
        ];
    }
}
