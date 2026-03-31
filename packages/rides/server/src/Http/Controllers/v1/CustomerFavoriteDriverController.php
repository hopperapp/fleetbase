<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Hopper\Rides\Http\Resources\v1\DriverResource;
use Illuminate\Http\Request;

class CustomerFavoriteDriverController extends Controller
{
    /**
     * List all favorite drivers for the authenticated customer.
     */
    public function index(Request $request)
    {
        $customerUuid = session('customer');
        if (!$customerUuid) {
            return response()->json(['error' => 'Authentication failed: Missing or invalid Customer-Token.'], 401);
        }

        $contact = Contact::where('uuid', $customerUuid)->firstOrFail();

        $favoriteDriverUuids = $contact->meta['favorite_drivers'] ?? [];

        if (empty($favoriteDriverUuids)) {
            return response()->json([]);
        }

        $drivers = Driver::with(['vehicle', 'user'])
            ->whereIn('uuid', $favoriteDriverUuids)
            ->get();

        return DriverResource::collection($drivers);
    }

    /**
     * Add a favorite driver.
     */
    public function store(Request $request, string $driverId)
    {
        $customerUuid = session('customer');
        if (!$customerUuid) {
            return response()->json(['error' => 'Authentication failed: Missing or invalid Customer-Token.'], 401);
        }

        $contact = Contact::where('uuid', $customerUuid)->firstOrFail();

        // Verify the driver exists
        $driver = Driver::where(function ($q) use ($driverId) {
            $q->where('public_id', $driverId)->orWhere('uuid', $driverId);
        })->firstOrFail();

        $meta = $contact->meta;
        if (!is_array($meta)) {
            $meta = [];
        }
        
        $favorites = $meta['favorite_drivers'] ?? [];

        if (!in_array($driver->uuid, $favorites)) {
            $favorites[] = $driver->uuid;
            $meta['favorite_drivers'] = $favorites;
            $contact->update(['meta' => $meta]);
        }

        return response()->json(['message' => 'Driver added to favorites successfully.']);
    }

    /**
     * Remove a favorite driver.
     */
    public function destroy(Request $request, string $driverId)
    {
        $customerUuid = session('customer');
        if (!$customerUuid) {
            return response()->json(['error' => 'Authentication failed: Missing or invalid Customer-Token.'], 401);
        }

        $contact = Contact::where('uuid', $customerUuid)->firstOrFail();

        // Resolve driver UUID if public_id was sent
        $driver = Driver::where(function ($q) use ($driverId) {
            $q->where('public_id', $driverId)->orWhere('uuid', $driverId);
        })->first();

        $targetUuid = $driver ? $driver->uuid : $driverId;

        $meta = $contact->meta;
        if (!is_array($meta)) {
            $meta = [];
        }
        
        $favorites = $meta['favorite_drivers'] ?? [];

        $favorites = array_values(array_diff($favorites, [$targetUuid]));
        $meta['favorite_drivers'] = $favorites;
        $contact->update(['meta' => $meta]);

        return response()->json(['message' => 'Driver removed from favorites.']);
    }
}
