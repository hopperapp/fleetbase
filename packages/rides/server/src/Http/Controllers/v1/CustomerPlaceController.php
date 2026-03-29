<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;

class CustomerPlaceController extends Controller
{
    /**
     * List all saved places for the authenticated customer.
     */
    public function index(Request $request)
    {
        $customerUuid = session('customer');

        $places = Place::where('owner_uuid', $customerUuid)
            ->where('owner_type', 'Fleetbase\FleetOps\Models\Contact')
            ->latest()
            ->get();

        return response()->json($places);
    }

    /**
     * Create a new saved place natively bound to the customer.
     */
    public function store(Request $request)
    {
        $customerUuid = session('customer');
        $companyUuid  = session('company');

        $request->validate([
            'name'      => 'required|string|max:255',
            'street1'   => 'nullable|string|max:255',
            'building'  => 'nullable|string|max:255',
            'phone'     => 'nullable|string|max:50',
            'type'      => 'nullable|string|max:50',
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
            'meta'      => 'nullable|array',
        ]);

        $place = Place::create([
            'company_uuid' => $companyUuid,
            'owner_uuid'   => $customerUuid,
            'owner_type'   => 'Fleetbase\FleetOps\Models\Contact',
            'name'         => $request->input('name'),
            'street1'      => $request->input('street1'),
            'building'     => $request->input('building'),
            'phone'        => $request->input('phone'),
            'type'         => $request->input('type', 'destination'),
            'location'     => new Point($request->input('latitude'), $request->input('longitude')),
            'meta'         => $request->input('meta', []),
        ]);

        return response()->json(['message' => 'Place saved successfully.', 'place' => $place], 201);
    }

    /**
     * Update an existing saved place securely.
     */
    public function update(Request $request, string $id)
    {
        $customerUuid = session('customer');

        $place = Place::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

        // Enforce polymorphism strict ownership
        if ($place->owner_uuid !== $customerUuid || $place->owner_type !== 'Fleetbase\FleetOps\Models\Contact') {
            return response()->error('Unauthorized to edit this place.', 403);
        }

        $request->validate([
            'name'      => 'nullable|string|max:255',
            'street1'   => 'nullable|string|max:255',
            'building'  => 'nullable|string|max:255',
            'phone'     => 'nullable|string|max:50',
            'type'      => 'nullable|string|max:50',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'meta'      => 'nullable|array',
        ]);

        $updates = $request->only(['name', 'street1', 'building', 'phone', 'type', 'meta']);

        if ($request->filled('latitude') && $request->filled('longitude')) {
            $updates['location'] = new Point($request->input('latitude'), $request->input('longitude'));
        }

        $place->update($updates);

        return response()->json(['message' => 'Place updated successfully.', 'place' => $place]);
    }

    /**
     * Delete a saved place securely.
     */
    public function destroy(Request $request, string $id)
    {
        $customerUuid = session('customer');

        $place = Place::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

        // Enforce polymorphism strict ownership
        if ($place->owner_uuid !== $customerUuid || $place->owner_type !== 'Fleetbase\FleetOps\Models\Contact') {
            return response()->error('Unauthorized to delete this place.', 403);
        }

        $place->delete();

        return response()->json(['message' => 'Place deleted successfully.']);
    }
}
