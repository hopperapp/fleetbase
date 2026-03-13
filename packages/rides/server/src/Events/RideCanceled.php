<?php

namespace Hopper\Rides\Events;

use Hopper\Rides\Models\Ride;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideCanceled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Ride $ride;
    public string $canceledBy;

    public function __construct(Ride $ride, string $canceledBy)
    {
        $this->ride = $ride;
        $this->canceledBy = $canceledBy;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('ride.' . $this->ride->uuid),
        ];

        if ($this->ride->driver_uuid) {
            $channels[] = new Channel('driver.' . $this->ride->driver_uuid . '.rides');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.canceled';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_id'      => $this->ride->public_id,
            'canceled_by'  => $this->canceledBy,
            'cancel_reason' => $this->ride->cancel_reason,
        ];
    }
}
