<?php

namespace Hopper\Rides\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

class RideBidResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'public_id'             => $this->public_id,
            'amount'                => $this->amount,
            'currency'              => $this->currency,
            'estimated_arrival_min' => $this->estimated_arrival_min,
            'status'                => $this->status,
            'driver'                => new DriverResource($this->driver),
        ];
    }
}
