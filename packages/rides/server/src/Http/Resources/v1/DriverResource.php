<?php

namespace Hopper\Rides\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'public_id'              => $this->public_id,
            'name'                   => $this->user?->name,
            'email'                  => $this->user?->email,
            'phone'                  => $this->user?->phone,
            'photo_url'              => $this->user?->avatar_url,
            'rating'                 => data_get($this->meta, 'rating'),
            'reviews_count'          => data_get($this->meta, 'reviews_count', 0),
            'completed_rides_count'  => data_get($this->meta, 'completed_rides_count', 0),
            'vehicle'                => [
                'make'         => $this->vehicle?->make,
                'model'        => $this->vehicle?->model,
                'year'         => $this->vehicle?->year,
                'color'        => data_get($this->vehicle?->meta, 'color'),
                'plate_number' => $this->vehicle?->plate_number,
            ],
        ];
    }
}
