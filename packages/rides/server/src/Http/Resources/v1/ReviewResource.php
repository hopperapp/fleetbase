<?php

namespace Hopper\Rides\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray($request)
    {
        $reviewerName = null;
        $reviewerPhoto = null;

        if ($this->reviewer_type === 'customer') {
             $reviewerName = $this->reviewer?->name;
             $reviewerPhoto = $this->reviewer?->photo_url;
        } else if ($this->reviewer_type === 'driver') {
             $reviewerName = $this->reviewer?->user?->name;
             $reviewerPhoto = $this->reviewer?->user?->avatar_url;
        }

        return [
            'public_id' => $this->public_id,
            'rating'    => $this->rating,
            'comment'   => $this->comment,
            'tags'      => $this->tags,
            'created_at'=> $this->created_at->toDateTimeString(),
            'reviewer'  => [
                'name'      => $reviewerName,
                'photo_url' => $reviewerPhoto,
            ]
        ];
    }
}
