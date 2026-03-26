<?php

namespace Hopper\Rides\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;
use Hopper\Rides\Http\Resources\v1\CustomerResource;
use Hopper\Rides\Http\Resources\v1\DriverResource;

class RideResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'public_id' => $this->public_id,
            'status'    => $this->status,
            'customer'  => new CustomerResource($this->customer),
            'driver'    => new DriverResource($this->driver),
            'order'     => $this->order ? [
                'status'                  => $this->order->status,
                'order_config'            => [
                    'flow' => $this->order->orderConfig?->flow,
                ],
                'next_activity'           => $this->order->orderConfig ? $this->order->orderConfig->nextFirstActivity($this->order)?->toArray() : null,
                'allowed_next_activities' => $this->order->orderConfig ? $this->order->orderConfig->nextActivity($this->order)->map(fn ($activity) => $activity->toArray())->toArray() : [],
            ] : null,
        ];
    }
}
