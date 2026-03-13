<?php

namespace Hopper\Rides\Events;

use Hopper\Rides\Models\Ride;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Ride $ride;
    public string $previousStatus;

    public function __construct(Ride $ride, string $previousStatus)
    {
        $this->ride = $ride;
        $this->previousStatus = $previousStatus;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('ride.' . $this->ride->uuid),
        ];

        // Also notify driver if assigned
        if ($this->ride->driver_uuid) {
            $channels[] = new Channel('driver.' . $this->ride->driver_uuid . '.rides');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ride.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_id'         => $this->ride->public_id,
            'previous_status' => $this->previousStatus,
            'new_status'      => $this->ride->status,
        ];
    }
}
