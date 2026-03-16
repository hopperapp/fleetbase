<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Hopper\Rides\Events\RideRequested;
use Hopper\Rides\Events\RideCanceled;
use Hopper\Rides\Events\RideBidAccepted;
use Hopper\Rides\Models\Ride;
use Hopper\Rides\Models\RideBid;
use Hopper\Rides\Models\VehicleCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerRideController extends Controller
{
    /**
     * Request a new ride.
     */
    public function request(Request $request)
    {
        $request->validate([
            'vehicle_category_uuid' => 'required|uuid|exists:vehicle_categories,uuid',
            'pickup_latitude'       => 'required|numeric',
            'pickup_longitude'      => 'required|numeric',
            'dropoff_latitude'      => 'required|numeric',
            'dropoff_longitude'     => 'required|numeric',
            'pickup_address'        => 'nullable|string',
            'dropoff_address'       => 'nullable|string',
            'distance_meters'       => 'required|integer',
            'duration_seconds'      => 'required|integer',
            'pricing_method'        => 'required|in:auto,fixed,bidding',
            'customer_price'        => 'required_if:pricing_method,fixed|nullable|integer',
            'payment_method'        => 'nullable|in:cash,transfer,wallet',
            'passenger_count'       => 'nullable|integer|min:1',
            'currency'              => 'nullable|string|size:3',
            'meta'                  => 'nullable|array',
        ]);

        $companyUuid = session('company');
        $storeUuid = session('rides_store');
        $customerUuid = session('customer');

        if (!$companyUuid || !$customerUuid) {
            return response()->error('Authentication failed: Missing company or customer context.', 401);
        }

        // Get the vehicle category to estimate max price based on their system
        $category = VehicleCategory::find($request->input('vehicle_category_uuid'));
        $estimatedPrice = $category ? $category->calculateFare($request->input('distance_meters'), $request->input('duration_seconds')) : 0;

        // Initialize places (create inline since we have lat/lng)
        $pickupPlace = Place::create([
            'company_uuid' => $companyUuid,
            'name'         => 'Pickup Location',
            'location'     => new \Fleetbase\LaravelMysqlSpatial\Types\Point($request->input('pickup_latitude'), $request->input('pickup_longitude')),
            'address'      => $request->input('pickup_address', 'Unknown Address'),
        ]);

        $dropoffPlace = Place::create([
            'company_uuid' => $companyUuid,
            'name'         => 'Dropoff Location',
            'location'     => new \Fleetbase\LaravelMysqlSpatial\Types\Point($request->input('dropoff_latitude'), $request->input('dropoff_longitude')),
            'address'      => $request->input('dropoff_address', 'Unknown Address'),
        ]);

        // Fallback to auto if they selected fixed but gave no price
        $pricingMethod = $request->input('pricing_method');
        if ($pricingMethod === 'fixed' && !$request->filled('customer_price')) {
            $pricingMethod = 'auto';
        }

        // Determine the actual status for the ride
        $status = Ride::STATUS_SEARCHING;
        if ($pricingMethod === 'bidding') {
            $status = Ride::STATUS_BIDDING;
        }

        $ride = Ride::create([
            'company_uuid'           => $companyUuid,
            'store_uuid'             => $storeUuid,
            'network_uuid'           => session('rides_network'),
            'customer_uuid'          => $customerUuid,
            'vehicle_category_uuid'  => $request->input('vehicle_category_uuid'),
            'pricing_method'         => $pricingMethod,
            'estimated_price'        => $estimatedPrice,
            'customer_price'         => $request->input('customer_price'),
            'currency'               => $request->input('currency', session('rides_currency', 'YER')),
            'payment_method'         => $request->input('payment_method', 'cash'),
            'distance_meters'        => $request->input('distance_meters'),
            'duration_seconds'       => $request->input('duration_seconds'),
            'pickup_latitude'        => $request->input('pickup_latitude'),
            'pickup_longitude'       => $request->input('pickup_longitude'),
            'pickup_address'         => $request->input('pickup_address'),
            'dropoff_latitude'       => $request->input('dropoff_latitude'),
            'dropoff_longitude'      => $request->input('dropoff_longitude'),
            'dropoff_address'        => $request->input('dropoff_address'),
            'pickup_place_uuid'      => $pickupPlace->uuid,
            'dropoff_place_uuid'     => $dropoffPlace->uuid,
            'status'                 => $status,
            'passenger_count'        => $request->input('passenger_count', 1),
            'meta'                   => $request->input('meta', []),
        ]);

        // Dispatch broadcast to drivers
        event(new RideRequested($ride));

        return response()->json([
            'ride' => $ride->load(['vehicleCategory', 'pickupPlace', 'dropoffPlace']),
        ], 201);
    }

    /**
     * Get the customer's active ride.
     */
    public function active(Request $request)
    {
        $ride = Ride::where('customer_uuid', session('customer'))
            ->active()
            ->with(['driver.user', 'vehicleCategory', 'pickupPlace', 'dropoffPlace', 'bids' => function ($query) {
                // If it's bidding, return active bids
                $query->where('status', 'pending');
            }])
            ->latest()
            ->first();

        if (!$ride) {
            return response()->json(['message' => 'No active ride found.', 'ride' => null]);
        }

        return response()->json(['ride' => $ride]);
    }

    /**
     * Get the customer's ride history.
     */
    public function history(Request $request)
    {
        $limit = $request->input('limit', 20);

        $rides = Ride::where('customer_uuid', session('customer'))
            ->whereIn('status', [Ride::STATUS_COMPLETED, Ride::STATUS_CANCELED])
            ->with(['driver.user', 'vehicleCategory', 'pickupPlace', 'dropoffPlace'])
            ->latest()
            ->paginate($limit);

        return response()->json($rides);
    }

    /**
     * Show a specific ride.
     */
    public function show(string $id)
    {
        $customerUuid = session('customer');

        $ride = Ride::where('public_id', $id)
            ->with(['driver.user', 'vehicleCategory', 'pickupPlace', 'dropoffPlace'])
            ->firstOrFail();

        // Ensure this customer owns the ride
        if ($ride->customer_uuid !== $customerUuid) {
            return response()->error('Unauthorized to view this ride.', 403);
        }

        return response()->json(['ride' => $ride]);
    }

    /**
     * Cancel the ride.
     */
    public function cancel(Request $request, string $id)
    {
        $request->validate([
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        $ride = Ride::where('public_id', $id)->firstOrFail();

        // Ensure authorization
        if ($ride->customer_uuid !== session('customer')) {
            return response()->error('Unauthorized.', 403);
        }

        // Validate cancel state
        if (!$ride->isCancelable()) {
            return response()->error('This ride cannot be canceled at this stage.', 422);
        }

        // Actually cancel it
        $ride->update([
            'status'        => Ride::STATUS_CANCELED,
            'canceled_by'   => 'customer',
            'canceled_at'   => now(),
            'cancel_reason' => $request->input('cancel_reason', 'Canceled by passenger'),
        ]);

        // Fire event to notify drivers + sync FleetOps orders
        event(new RideCanceled($ride, 'customer'));

        return response()->json([
            'message' => 'Ride canceled successfully.',
            'ride'    => $ride,
        ]);
    }

    /**
     * View all active bids for a requested ride.
     */
    public function bids(Request $request, string $id)
    {
        $ride = Ride::where('public_id', $id)->firstOrFail();

        if ($ride->customer_uuid !== session('customer')) {
            return response()->error('Unauthorized.', 403);
        }

        if ($ride->status !== Ride::STATUS_BIDDING) {
            return response()->error('Ride is not in a bidding state.', 422);
        }

        $bids = RideBid::where('ride_uuid', $ride->uuid)
            ->where('status', RideBid::STATUS_PENDING)
            ->with(['driver.user', 'vehicle'])
            ->orderBy('amount', 'asc') // sort by cheapest first
            ->get();

        return response()->json(['bids' => $bids]);
    }

    /**
     * Accept a specific bid for the ride.
     */
    public function acceptBid(Request $request, string $id)
    {
        $request->validate([
            'bid_public_id' => 'required|string|exists:ride_bids,public_id',
        ]);

        $ride = Ride::where('public_id', $id)->firstOrFail();

        if ($ride->customer_uuid !== session('customer')) {
            return response()->error('Unauthorized.', 403);
        }

        if ($ride->status !== Ride::STATUS_BIDDING) {
            return response()->error('Ride is no longer accepting bids.', 422);
        }

        $bid = RideBid::findByPublicId($request->input('bid_public_id'));

        if (!$bid || $bid->ride_uuid !== $ride->uuid || !$bid->isActive()) {
            return response()->error('Invalid or expired bid selected.', 422);
        }

        // Accept the bid (this auto-rejects other bids logic inside the model)
        $bid->accept();

        // Update the Ride completely
        $ride->update([
            'status'      => Ride::STATUS_ACCEPTED,
            'driver_uuid' => $bid->driver_uuid,
            'vehicle_uuid'=> $bid->vehicle_uuid,
            'final_price' => $bid->amount,
            'currency'    => $bid->currency,
            'accepted_at' => now(),
        ]);

        // **CRITICAL FLEETOPS SYNC**
        // Now that a driver is confirmed, we construct the native FleetOps Order.
        $payload = Payload::create([
            'company_uuid'       => $ride->company_uuid,
            'pickup_place_uuid'  => $ride->pickup_place_uuid,
            'dropoff_place_uuid' => $ride->dropoff_place_uuid,
        ]);

        $orderConfig = \Fleetbase\FleetOps\Models\OrderConfig::where('company_uuid', $ride->company_uuid)
            ->where('key', 'passenger-transport') // Assumes passenger-transport order config exists
            ->first();

        $order = Order::create([
            'company_uuid'          => $ride->company_uuid,
            'order_config_uuid'     => $orderConfig ? $orderConfig->uuid : null,
            'payload_uuid'          => $payload->uuid,
            'customer_uuid'         => $ride->customer_uuid,
            'customer_type'         => 'fleet-ops:contact',
            'driver_assigned_uuid'  => $bid->driver_uuid,
            'vehicle_assigned_uuid' => $bid->vehicle_uuid,
            'type'                  => 'passenger-transport',
            'status'                => 'created',
            'adhoc'                 => false,
            'meta'                  => [
                'ride_uuid'        => $ride->uuid,
                'ride_public_id'   => $ride->public_id,
                'vehicle_category' => $ride->vehicleCategory?->name,
                'pricing_method'   => $ride->pricing_method,
                'final_price'      => $ride->final_price,
                'payment_method'   => $ride->payment_method,
            ],
        ]);

        // Link the native Order to our Ride
        $ride->update(['order_uuid' => $order->uuid]);

        // Dispatch the Order in FleetOps (triggers native lifecycle)
        $order->dispatchWithActivity();

        // Broadcast to drivers and customers
        event(new RideBidAccepted($bid));

        return response()->json([
            'message' => 'Bid accepted successfully.',
            'ride'    => $ride->load(['driver.user', 'vehicle']),
        ]);
    }
}
