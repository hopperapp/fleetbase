<?php

namespace Hopper\Rides\Http\Resources\v1;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray($request)
    {
        $categoryData = null;
        if ($this->vehicle && isset($this->vehicle->meta['vehicle_category_uuid'])) {
            $cat = \Hopper\Rides\Models\VehicleCategory::find($this->vehicle->meta['vehicle_category_uuid']);
            if ($cat) {
                $categoryData = [
                    'uuid' => $cat->uuid,
                    'name' => $cat->name,
                    'key'  => $cat->key,
                ];
            }
        }

        $subCategoryData = null;
        if ($this->vehicle && isset($this->vehicle->meta['vehicle_sub_category_uuid'])) {
            $subCat = \Hopper\Rides\Models\VehicleSubCategory::find($this->vehicle->meta['vehicle_sub_category_uuid']);
            if ($subCat) {
                $subCategoryData = [
                    'uuid' => $subCat->uuid,
                    'name' => $subCat->name,
                    'key'  => $subCat->key,
                ];
            }
        }

        $vehicleData = null;
        if ($this->vehicle) {
            $vehicleData = [
                'make'         => $this->vehicle->make,
                'model'        => $this->vehicle->model,
                'year'         => $this->vehicle->year,
                'color'        => data_get($this->vehicle->meta, 'color'),
                'plate_number' => $this->vehicle->plate_number,
                'category'     => $categoryData,
            ];

            if ($subCategoryData) {
                $vehicleData['sub_category'] = $subCategoryData;
            }
        }

        return [
            'public_id'              => $this->public_id,
            'name'                   => $this->user?->name,
            'email'                  => $this->user?->email,
            'phone'                  => $this->user?->phone,
            'photo_url'              => $this->user?->avatar_url,
            'rating'                 => data_get($this->meta, 'rating'),
            'reviews_count'          => data_get($this->meta, 'reviews_count', 0),
            'completed_rides_count'  => data_get($this->meta, 'completed_rides_count', 0),
            'vehicle'                => $vehicleData,
        ];
    }
}
