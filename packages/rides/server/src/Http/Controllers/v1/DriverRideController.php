<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\Ride;
use Hopper\Rides\Events\RideStatusChanged;
use Hopper\Rides\Events\RideCanceled;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DriverRideController extends Controller
{
    /**
     * Discover nearby available rides matching the driver's vehicle category.
     */
    public function available(Request $request)
    {
        $driverUuid = session('driver');
        $vehicleUuid = session('driver_vehicle');
        $companyUuid = session('company');

        if (!$driverUuid || !$companyUuid) {
            return response()->error('Authentication failed: Missing company or driver context.', 401);
        }

        // We need the driver model to know their location and vehicle category
        $driver = \Fleetbase\FleetOps\Models\Driver::with(['vehicle'])->where('uuid', $driverUuid)->first();

        if (!$driver || !$driver->location) {
            return response()->json(['rides' => []]);
        }

        // Ideally, vehicle category should be attached to the driver's vehicle meta or a direct relationship
        // Assuming we store it in vehicle meta or we just return all nearby for now
        $radiusMeters = config('rides.bidding.driver_radius_meters', 10000);

        $query = Ride::where('company_uuid', $companyUuid)
            ->whereIn('status', [Ride::STATUS_SEARCHING, Ride::STATUS_BIDDING])
            ->whereDoesntHave('bids', function ($q) use ($driverUuid) {
                // Exclude rides this driver already bid on
                $q->where('driver_uuid', $driverUuid);
            })
            ->nearPickup($driver->location->getLat(), $driver->location->getLng(), $radiusMeters)
            ->with(['vehicleCategory', 'pickupPlace', 'dropoffPlace']);

        // Limit by driver's vehicle category if possible
        // if ($driver->vehicle && isset($driver->vehicle->meta['vehicle_category_uuid'])) {
        //     $query->where('vehicle_category_uuid', $driver->vehicle->meta['vehicle_category_uuid']);
        // }

        $rides = $query->latest()->take(50)->get();

        return response()->json(['rides' => $rides]);
    }

    /**
     * Show ride details.
     */
    public function show(string $id)
    {
        $ride = Ride::where('public_id', $id)
            ->with(['customer', 'vehicleCategory', 'pickupPlace', 'dropoffPlace'])
            ->firstOrFail();

        return response()->json(['ride' => $ride]);
    }

    /**
     * Accept a ride (for auto/fixed pricing strategy).
     */
    public function accept(Request $request, string $id)
    {
        $driverUuid = session('driver');
        $vehicleUuid = session('driver_vehicle');
        $companyUuid = session('company');

        $ride = Ride::where('public_id', $id)->firstOrFail();

        if ($ride->status !== Ride::STATUS_SEARCHING) {
            return response()->error('This ride is no longer available to accept.', 422);
        }

        if ($ride->pricing_method === 'bidding') {
            return response()->error('This ride requires bidding.', 422);
        }

        // Automatically assign and create FleetOps Order (similar to CustomerRideController@acceptBid)
        $ride->update([
            'status'      => Ride::STATUS_ACCEPTED,
            'driver_uuid' => $driverUuid,
            'vehicle_uuid'=> $vehicleUuid,
            // the customer price is either fixed or estimated based on pricing method
            'final_price' => $ride->pricing_method === 'fixed' ? $ride->customer_price : $ride->estimated_price,
            'accepted_at' => now(),
        ]);

        $payload = \Fleetbase\FleetOps\Models\Payload::create([
            'company_uuid'       => $ride->company_uuid,
            'pickup_uuid'        => $ride->pickup_place_uuid,
            'dropoff_uuid'       => $ride->dropoff_place_uuid,
        ]);

        // Fetch Store to attach to Storefront View
        $store = \Fleetbase\Storefront\Models\Store::where('uuid', $ride->store_uuid)->first();

        // 1. Try to get the specific OrderConfig linked to this exact Store
        $orderConfig = null;
        if ($store && $store->order_config_uuid) {
            $orderConfig = \Fleetbase\FleetOps\Models\OrderConfig::where('uuid', $store->order_config_uuid)->first();
        }

        // 2. Fallback to searching the company's configs for keywords
        if (!$orderConfig) {
            $orderConfig = \Fleetbase\FleetOps\Models\OrderConfig::where('company_uuid', $ride->company_uuid)
                ->where(function ($q) {
                    $q->where('key', 'passenger-transport') // Standard fallback
                      ->orWhere('name', 'like', '%passenger%')
                      ->orWhere('name', 'like', '%ride%')
                      ->orWhere('name', 'like', '%transport%');
                })
                ->first();
        }

        // 3. Absolute fallback to the first custom order config found for this company
        if (!$orderConfig) {
            $orderConfig = \Fleetbase\FleetOps\Models\OrderConfig::where('company_uuid', $ride->company_uuid)->first();
        }

        $order = \Fleetbase\FleetOps\Models\Order::create([
            'company_uuid'          => $ride->company_uuid,
            'order_config_uuid'     => $orderConfig ? $orderConfig->uuid : null,
            'payload_uuid'          => $payload->uuid,
            'customer_uuid'         => $ride->customer_uuid,
            'customer_type'         => 'Fleetbase\FleetOps\Models\Contact',
            'facilitator_uuid'      => $store ? $store->uuid : null,
            'facilitator_type'      => $store ? 'Fleetbase\Storefront\Models\Store' : null,
            'driver_assigned_uuid'  => $driverUuid,
            'vehicle_assigned_uuid' => $vehicleUuid,
            'type'                  => 'passenger-transport',
            'status'                => 'created',
            'adhoc'                 => false,
            'meta'                  => [
                'ride_uuid'             => $ride->uuid,
                'ride_public_id'        => $ride->public_id,
                'storefront'            => $store ? $store->name : null,
                'storefront_id'         => $store ? $store->public_id : null,
            ],
        ]);

        $ride->update(['order_uuid' => $order->uuid]);
        $order->dispatchWithActivity();

        event(new RideStatusChanged($ride, Ride::STATUS_SEARCHING));

        return response()->json([
            'message' => 'Ride accepted successfully.',
            'ride'    => $ride->fresh(['customer', 'pickupPlace', 'dropoffPlace']),
        ]);
    }

    /**
     * Decline a ride (hide it from driver's view).
     */
    public function decline(Request $request, string $id)
    {
        // Typically handled app-side by hiding the ride from their UI list,
        // but can optionally log a 'decline' record to stop sending them this ride.
        return response()->json(['message' => 'Ride declined locally.']);
    }

    /**
     * Driver cancels the ride.
     */
    public function cancel(Request $request, string $id)
    {
        $request->validate([
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        $ride = Ride::where('public_id', $id)->firstOrFail();

        if ($ride->driver_uuid !== session('driver')) {
            return response()->error('Unauthorized.', 403);
        }

        if (!$ride->isCancelable()) {
            return response()->error('This ride cannot be canceled at this stage.', 422);
        }

        $ride->update([
            'status'        => Ride::STATUS_CANCELED,
            'canceled_by'   => 'driver',
            'canceled_at'   => now(),
            'cancel_reason' => $request->input('cancel_reason', 'Canceled by driver'),
        ]);

        event(new RideCanceled($ride, 'driver'));

        return response()->json([
            'message' => 'Ride canceled successfully.',
            'ride'    => $ride,
        ]);
    }

    /**
     * Trip Status Update: Driver is en route to pickup.
     */
    public function enRoute(Request $request, string $id)
    {
        return $this->updateTripStatus($id, Ride::STATUS_DRIVER_EN_ROUTE);
    }

    /**
     * Trip Status Update: Driver has arrived at pickup.
     */
    public function arrived(Request $request, string $id)
    {
        return $this->updateTripStatus($id, Ride::STATUS_ARRIVED_AT_PICKUP);
    }

    /**
     * Trip Status Update: Passenger onboard, trip started.
     */
    public function start(Request $request, string $id)
    {
        $ride = Ride::where('public_id', $id)->firstOrFail();
        
        if ($ride->driver_uuid !== session('driver')) {
            return response()->error('Unauthorized.', 403);
        }

        $previous = $ride->status;
        $ride->update([
            'status'     => Ride::STATUS_IN_TRANSIT,
            'started_at' => now(),
        ]);

        event(new RideStatusChanged($ride, $previous));

        return response()->json(['message' => 'Trip started.', 'ride' => $ride]);
    }

    /**
     * Trip Status Update: Arrived at destination.
     */
    public function complete(Request $request, string $id)
    {
        $ride = Ride::where('public_id', $id)->firstOrFail();
        
        if ($ride->driver_uuid !== session('driver')) {
            return response()->error('Unauthorized.', 403);
        }

        $previous = $ride->status;
        $ride->update([
            'status'       => Ride::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        event(new RideStatusChanged($ride, $previous));

        return response()->json(['message' => 'Trip completed.', 'ride' => $ride]);
    }

    /**
     * Helper to update trip status.
     */
    private function updateTripStatus(string $id, string $newStatus)
    {
        $ride = Ride::where('public_id', $id)->firstOrFail();

        if ($ride->driver_uuid !== session('driver')) {
            return response()->error('Unauthorized.', 403);
        }

        $previous = $ride->status;
        if ($previous === $newStatus) {
            return response()->json(['ride' => $ride]);
        }

        $ride->update(['status' => $newStatus]);
        event(new RideStatusChanged($ride, $previous));

        return response()->json(['message' => 'Status updated.', 'ride' => $ride]);
    }

    /**
     * Submit a review for the customer.
     */
    public function submitReview(Request $request, string $id)
    {
        $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'tags'    => 'nullable|array',
        ]);

        $ride = Ride::where('public_id', $id)->firstOrFail();

        if ($ride->driver_uuid !== session('driver')) {
            return response()->error('Unauthorized.', 403);
        }

        if ($ride->status !== Ride::STATUS_COMPLETED) {
            return response()->error('Cannot review until trip is completed.', 422);
        }

        \Hopper\Rides\Models\RideReview::create([
            'company_uuid'  => $ride->company_uuid,
            'ride_uuid'     => $ride->uuid,
            'reviewer_uuid' => session('driver'),
            'reviewer_type' => 'driver',
            'reviewee_uuid' => $ride->customer_uuid,
            'reviewee_type' => 'customer',
            'rating'        => $request->input('rating'),
            'comment'       => $request->input('comment'),
            'tags'          => $request->input('tags', []),
        ]);

        return response()->json(['message' => 'Review submitted successfully.']);
    }
}
