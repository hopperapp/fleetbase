<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\VehicleCategory;
use Illuminate\Http\Request;

class AdminVehicleCategoryController extends Controller
{
    /**
     * Display a listing of vehicle categories.
     */
    public function index(Request $request)
    {
        $companyUuid = session('company');
        
        $query = VehicleCategory::where('company_uuid', $companyUuid)
            ->with('subCategories')
            ->orderBy('sort_order', 'asc');
            
        if ($request->has('store_uuid')) {
            $query->where('store_uuid', $request->input('store_uuid'));
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created vehicle category.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'         => 'required|string|max:255',
            'key'          => 'required|string|max:255|unique:vehicle_categories,key',
            'description'  => 'nullable|string',
            'icon'         => 'nullable|string',
            'base_fare'    => 'required|integer|min:0',
            'per_km_fare'  => 'required|integer|min:0',
            'per_min_fare' => 'required|integer|min:0',
            'min_fare'     => 'required|integer|min:0',
            'currency'     => 'nullable|string|size:3',
            'sort_order'   => 'nullable|integer',
            'is_active'    => 'nullable|boolean',
            'store_uuid'   => 'nullable|string',
        ]);

        $category = VehicleCategory::create(array_merge(
            $request->only([
                'name', 'key', 'description', 'icon', 'base_fare', 
                'per_km_fare', 'per_min_fare', 'min_fare', 'currency', 
                'sort_order', 'is_active', 'store_uuid'
            ]),
            [
                'company_uuid' => session('company'),
            ]
        ));

        return response()->json($category, 201);
    }

    /**
     * Display the specified vehicle category.
     */
    public function show(string $id)
    {
        $category = VehicleCategory::where('company_uuid', session('company'))
            ->where(function($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->with('subCategories')
            ->firstOrFail();

        return response()->json($category);
    }

    /**
     * Update the specified vehicle category.
     */
    public function update(Request $request, string $id)
    {
        $category = VehicleCategory::where('company_uuid', session('company'))
            ->where(function($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $request->validate([
            'name'         => 'sometimes|required|string|max:255',
            'key'          => 'sometimes|required|string|max:255|unique:vehicle_categories,key,' . $category->id,
            'description'  => 'nullable|string',
            'icon'         => 'nullable|string',
            'base_fare'    => 'sometimes|required|integer|min:0',
            'per_km_fare'  => 'sometimes|required|integer|min:0',
            'per_min_fare' => 'sometimes|required|integer|min:0',
            'min_fare'     => 'sometimes|required|integer|min:0',
            'currency'     => 'nullable|string|size:3',
            'sort_order'   => 'nullable|integer',
            'is_active'    => 'nullable|boolean',
            'store_uuid'   => 'nullable|string',
        ]);

        $category->update($request->only([
            'name', 'key', 'description', 'icon', 'base_fare', 
            'per_km_fare', 'per_min_fare', 'min_fare', 'currency', 
            'sort_order', 'is_active', 'store_uuid'
        ]));

        return response()->json($category);
    }

    /**
     * Remove the specified vehicle category.
     */
    public function destroy(string $id)
    {
        $category = VehicleCategory::where('company_uuid', session('company'))
            ->where(function($q) use ($id) {
                $q->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $category->delete();

        return response()->json(null, 204);
    }
}
