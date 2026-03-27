<?php

namespace Hopper\Rides\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'public_id'     => $this->public_id,
            'name'          => $this->name,
            'phone'         => $this->phone,
            'photo_url'     => $this->photo_url,
            'rating'        => data_get($this->meta, 'rating'),
            'reviews_count' => data_get($this->meta, 'reviews_count', 0),
        ];
    }
}
