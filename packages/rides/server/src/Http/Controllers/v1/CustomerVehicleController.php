<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\VehicleCategory;
use Illuminate\Http\Request;

class CustomerVehicleController extends Controller
{
    /**
     * List all active vehicle categories.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $storeUuid = session('rides_store');
        $companyUuid = session('company');

        $query = VehicleCategory::where('is_active', true)
                                ->with(['subCategories' => function ($query) {
                                    $query->orderBy('sort_order', 'asc');
                                }]);

        if ($storeUuid) {
            $query->where('store_uuid', $storeUuid);
        } else if ($companyUuid) {
            $query->where('company_uuid', $companyUuid)
                  ->whereNull('store_uuid');
        }

        $categories = $query->orderBy('sort_order', 'asc')->get();

        return response()->json(['categories' => $categories]);
    }

    /**
     * Show a specific vehicle category.
     *
     * @param string $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(string $key)
    {
        $storeUuid = session('rides_store');
        $companyUuid = session('company');

        $query = VehicleCategory::where('key', $key)->with('subCategories');

        if ($storeUuid) {
            $query->where('store_uuid', $storeUuid);
        } else if ($companyUuid) {
            $query->where('company_uuid', $companyUuid);
        }

        $category = $query->firstOrFail();

        return response()->json(['category' => $category]);
    }
}
