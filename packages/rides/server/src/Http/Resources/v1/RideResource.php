<?php

namespace Hopper\Rides\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

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
            'customer'  => [
                'name'  => $this->customer_name,
                'phone' => $this->customer?->phone,
            ],
            'driver'    => [
                'name'  => $this->driver_name,
                'phone' => $this->driver?->phone,
            ],
            'order'     => [
                'status'       => $this->order?->status,
                'order_config' => [
                    'flow' => $this->order?->orderConfig?->flow,
                ],
            ],
        ];
    }
}
