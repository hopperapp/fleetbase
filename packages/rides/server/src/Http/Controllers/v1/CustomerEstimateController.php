<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\Network;
use Hopper\Rides\Models\VehicleCategory;
use Illuminate\Http\Request;

class CustomerEstimateController extends Controller
{
    /**
     * Estimate the cost of a ride for all available vehicle categories.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function estimate(Request $request)
    {
        $request->validate([
            'distance_meters'  => 'required|integer|min:1',
            'duration_seconds' => 'required|integer|min:1',
            // Optional pickup/dropoff for service area checks in the future
            'pickup_lat'       => 'nullable|numeric',
            'pickup_lng'       => 'nullable|numeric',
            'dropoff_lat'      => 'nullable|numeric',
            'dropoff_lng'      => 'nullable|numeric',
            'currency'         => 'nullable|string|size:3',
        ]);

        $distance = $request->input('distance_meters');
        $duration = $request->input('duration_seconds');
        $currency = $request->input('currency', session('rides_currency', 'YER'));
        $storeUuid = session('rides_store');
        $companyUuid = session('company');

        // Query active vehicle categories
        $categoriesQuery = VehicleCategory::where('is_active', true)
                                          ->with('subCategories');

        if ($storeUuid) {
            $categoriesQuery->where('store_uuid', $storeUuid);
        } else if ($companyUuid) {
            $categoriesQuery->where('company_uuid', $companyUuid)
                            ->whereNull('store_uuid');
        }

        $categories = $categoriesQuery->orderBy('sort_order', 'asc')->get();

        $estimates = [];

        foreach ($categories as $category) {
            $categoryEstimate = [
                'vehicle_category_uuid' => $category->uuid,
                'public_id'             => $category->public_id,
                'name'                  => $category->name,
                'key'                   => $category->key,
                'description'           => $category->description,
                'icon'                  => $category->icon,
                'base_fare'             => $category->base_fare,
                'per_km_fare'           => $category->per_km_fare,
                'per_min_fare'          => $category->per_min_fare,
                'min_fare'              => $category->min_fare,
                'currency'              => $category->currency ?? $currency,
                'estimated_fare'        => $category->calculateFare($distance, $duration),
            ];

            // If it has subcategories, calculate for them too
            if ($category->subCategories->isNotEmpty()) {
                $subEstimates = [];
                foreach ($category->subCategories as $subCategory) {
                    $subEstimates[] = [
                        'vehicle_sub_category_uuid' => $subCategory->uuid,
                        'public_id'                 => $subCategory->public_id,
                        'name'                      => $subCategory->name,
                        'key'                       => $subCategory->key,
                        'description'               => $subCategory->description,
                        'icon'                      => $subCategory->icon,
                        'fare_multiplier'           => $subCategory->fare_multiplier,
                        'estimated_fare'            => $category->calculateFare($distance, $duration, $subCategory->fare_multiplier),
                    ];
                }
                $categoryEstimate['sub_categories'] = $subEstimates;
            }

            $estimates[] = $categoryEstimate;
        }

        return response()->json([
            'distance_meters'  => $distance,
            'duration_seconds' => $duration,
            'currency'         => $currency,
            'estimates'        => $estimates,
        ]);
    }
}
