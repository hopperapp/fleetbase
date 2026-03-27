<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\VehicleSubCategory;
use Hopper\Rides\Models\VehicleCategory;
use Illuminate\Http\Request;

class AdminVehicleSubCategoryController extends Controller
{
    /**
     * Display a listing of vehicle sub-categories.
     */
    public function index(Request $request)
    {
        $companyUuid = session('company');
        
        $query = VehicleSubCategory::whereHas('category', function($q) use ($companyUuid) {
            $q->where('company_uuid', $companyUuid);
        })->with('category')->orderBy('sort_order', 'asc');

        if ($request->has('vehicle_category_uuid')) {
            $query->where('vehicle_category_uuid', $request->input('vehicle_category_uuid'));
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created vehicle sub-category.
     */
    public function store(Request $request)
    {
        $request->validate([
            'vehicle_category_uuid' => 'required|uuid|exists:vehicle_categories,uuid',
            'name'                  => 'required|string|max:255',
            'key'                   => 'required|string|max:255|unique:vehicle_sub_categories,key',
            'description'           => 'nullable|string',
            'icon'                  => 'nullable|string',
            'fare_multiplier'       => 'nullable|numeric|min:0',
            'sort_order'            => 'nullable|integer',
            'is_active'             => 'nullable|boolean',
        ]);

        // Ensure the parent category belongs to this company
        $category = VehicleCategory::where('uuid', $request->input('vehicle_category_uuid'))
                                   ->where('company_uuid', session('company'))
                                   ->firstOrFail();

        $subCategory = VehicleSubCategory::create(array_merge(
            $request->only([
                'vehicle_category_uuid', 'name', 'key', 'description', 'icon', 
                'fare_multiplier', 'sort_order', 'is_active'
            ]),
            ['company_uuid' => session('company')]
        ));

        return response()->json($subCategory, 201);
    }

    /**
     * Display the specified vehicle sub-category.
     */
    public function show(string $id)
    {
        $companyUuid = session('company');

        $subCategory = VehicleSubCategory::where(function ($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->whereHas('category', function($q) use ($companyUuid) {
                $q->where('company_uuid', $companyUuid);
            })
            ->with('category')
            ->firstOrFail();

        return response()->json($subCategory);
    }

    /**
     * Update the specified vehicle sub-category.
     */
    public function update(Request $request, string $id)
    {
        $companyUuid = session('company');

        $subCategory = VehicleSubCategory::where(function ($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->whereHas('category', function($q) use ($companyUuid) {
                $q->where('company_uuid', $companyUuid);
            })
            ->firstOrFail();

        $request->validate([
            'vehicle_category_uuid' => 'sometimes|required|uuid|exists:vehicle_categories,uuid',
            'name'                  => 'sometimes|required|string|max:255',
            'key'                   => 'sometimes|required|string|max:255|unique:vehicle_sub_categories,key,' . $subCategory->id,
            'description'           => 'nullable|string',
            'icon'                  => 'nullable|string',
            'fare_multiplier'       => 'nullable|numeric|min:0',
            'sort_order'            => 'nullable|integer',
            'is_active'             => 'nullable|boolean',
        ]);

        if ($request->has('vehicle_category_uuid')) {
            VehicleCategory::where('uuid', $request->input('vehicle_category_uuid'))
                           ->where('company_uuid', session('company'))
                           ->firstOrFail();
        }

        $subCategory->update($request->only([
            'vehicle_category_uuid', 'name', 'key', 'description', 'icon', 
            'fare_multiplier', 'sort_order', 'is_active'
        ]));

        return response()->json($subCategory);
    }

    /**
     * Remove the specified vehicle sub-category.
     */
    public function destroy(string $id)
    {
        $companyUuid = session('company');

        $subCategory = VehicleSubCategory::where(function ($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->whereHas('category', function($q) use ($companyUuid) {
                $q->where('company_uuid', $companyUuid);
            })
            ->firstOrFail();

        $subCategory->delete();

        return response()->json(null, 204);
    }
}
