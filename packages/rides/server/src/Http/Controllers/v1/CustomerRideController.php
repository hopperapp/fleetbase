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
use Hopper\Rides\Models\RideReview;
use Hopper\Rides\Http\Resources\v1\RideBidResource;
use Hopper\Rides\Models\VehicleCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerRideController extends Controller
{
    /**
     * Request a new ride.
     */
    public function store(Request $request)
    {
        $request->validate([
            'vehicle_category_uuid'      => 'required|uuid|exists:vehicle_categories,uuid',
            'pickup_latitude'            => 'required_without:pickup_place_uuid|nullable|numeric',
            'pickup_longitude'           => 'required_without:pickup_place_uuid|nullable|numeric',
            'dropoff_latitude'           => 'required_without:dropoff_place_uuid|nullable|numeric',
            'dropoff_longitude'          => 'required_without:dropoff_place_uuid|nullable|numeric',
            'pickup_address'             => 'nullable|string',
            'dropoff_address'            => 'nullable|string',
            'distance_meters'            => 'required|integer',
            'duration_seconds'           => 'required|integer',
            'pricing_method'             => 'required|in:auto,bidding',
            'offered_fare'               => 'required_if:pricing_method,bidding|nullable|numeric',
            'payment_method'             => 'nullable|in:cash,transfer,wallet,card',
            'passenger_count'            => 'nullable|integer|min:1',
            'currency'                   => 'nullable|string|size:3',
            'currency'                   => 'nullable|string|size:3',
            'vehicle_sub_category_uuid'  => 'nullable|string',
            'store_uuid'                 => 'nullable|string',
            'pickup_place_uuid'          => 'nullable|string',
            'dropoff_place_uuid'         => 'nullable|string',
            'is_scheduled'               => 'nullable|boolean',
            'scheduled_at'               => 'nullable|date',
            'meta'                       => 'nullable|array',
        ]);

        $companyUuid   = session('company');
        $customerUuid  = session('customer');
        $storeUuid     = $request->input('store_uuid', session('rides_store'));
        $networkUuid   = $request->input('network_uuid', session('rides_network'));
        $pricingMethod = $request->input('pricing_method');

        if (!$companyUuid || !$customerUuid) {
            return response()->error('Authentication failed: Missing company or customer context.', 401);
        }

        $distance = (int) $request->input('distance_meters', 5000);
        $duration = (int) $request->input('duration_seconds', 600);

        // Get the vehicle category to estimate max price based on their system
        $category = VehicleCategory::find($request->input('vehicle_category_uuid'));
        $estimatedPrice = $category ? $category->calculateFare($distance, $duration) : 0;

        // Resolve or Initialize Pickup Place
        if ($request->filled('pickup_place_uuid')) {
            $pickupPlace = Place::where('uuid', $request->input('pickup_place_uuid'))
                                ->where('owner_uuid', $customerUuid)
                                ->firstOrFail();
            // Automatically inherit coordinates from the saved place (since payload expects coords from ride model)
            $request->merge([
                'pickup_latitude'  => $pickupPlace->location->getLat(),
                'pickup_longitude' => $pickupPlace->location->getLng(),
            ]);
        } else {
            $pickupPlace = Place::create([
                'company_uuid' => $companyUuid,
                'name'         => 'Pickup Location',
                'location'     => new \Fleetbase\LaravelMysqlSpatial\Types\Point($request->input('pickup_latitude'), $request->input('pickup_longitude')),
                'address'      => $request->input('pickup_address', 'Unknown Address'),
            ]);
        }

        // Resolve or Initialize Dropoff Place
        if ($request->filled('dropoff_place_uuid')) {
            $dropoffPlace = Place::where('uuid', $request->input('dropoff_place_uuid'))
                                 ->where('owner_uuid', $customerUuid)
                                 ->firstOrFail();
            // Automatically inherit coordinates
            $request->merge([
                'dropoff_latitude'  => $dropoffPlace->location->getLat(),
                'dropoff_longitude' => $dropoffPlace->location->getLng(),
            ]);
        } else {
            $dropoffPlace = Place::create([
                'company_uuid' => $companyUuid,
                'name'         => 'Dropoff Location',
                'location'     => new \Fleetbase\LaravelMysqlSpatial\Types\Point($request->input('dropoff_latitude'), $request->input('dropoff_longitude')),
                'address'      => $request->input('dropoff_address', 'Unknown Address'),
            ]);
        }

        // Determine pricing method and status
        $pricingMethod = $request->input('pricing_method', 'auto');
        $status = Ride::STATUS_SEARCHING;
        if ($pricingMethod === 'bidding') {
            $status = Ride::STATUS_BIDDING;
        }

        $ride = Ride::create([
            'company_uuid'              => $companyUuid,
            'store_uuid'                => $storeUuid,
            'network_uuid'              => $networkUuid,
            'customer_uuid'             => $customerUuid,
            'vehicle_category_uuid'     => $request->input('vehicle_category_uuid'),
            'vehicle_sub_category_uuid' => $request->input('vehicle_sub_category_uuid'),
            'pricing_method'            => $pricingMethod,
            'estimated_price'           => $estimatedPrice,
            'customer_price'            => $request->input('offered_fare'),
            'currency'                  => $request->input('currency', session('rides_currency', 'YER')),
            'payment_method'            => $request->input('payment_method', 'cash'),
            'distance_meters'           => $distance,
            'duration_seconds'          => $duration,
            'pickup_latitude'           => $request->input('pickup_latitude'),
            'pickup_longitude'          => $request->input('pickup_longitude'),
            'pickup_address'            => $request->input('pickup_address'),
            'dropoff_latitude'          => $request->input('dropoff_latitude'),
            'dropoff_longitude'         => $request->input('dropoff_longitude'),
            'dropoff_address'           => $request->input('dropoff_address'),
            'pickup_place_uuid'         => $pickupPlace->uuid,
            'dropoff_place_uuid'        => $dropoffPlace->uuid,
            'status'                    => $status,
            'is_scheduled'              => $request->boolean('is_scheduled'),
            'scheduled_at'              => $request->input('scheduled_at'),
            'passenger_count'           => $request->input('passenger_count', 1),
            'meta'                      => $request->input('meta', []),
        ]);

        // **SCHEDULED RIDES - FLEETOPS SCHEDULER SYNC**
        // If this ride is explicitly scheduled for later, we must immediately create the native Order
        // so that it maps straight to the Fleetbase Dispatch board (without a driver yet).
        if ($ride->is_scheduled && $ride->scheduled_at) {
            $payload = Payload::create([
                'company_uuid'       => $ride->company_uuid,
                'pickup_uuid'        => $ride->pickup_place_uuid,
                'dropoff_uuid'       => $ride->dropoff_place_uuid,
            ]);

            $store = \Fleetbase\Storefront\Models\Store::where('uuid', $ride->store_uuid)->first();

            $orderConfig = null;
            if ($store && $store->order_config_uuid) {
                $orderConfig = \Fleetbase\FleetOps\Models\OrderConfig::where('uuid', $store->order_config_uuid)->first();
            }

            if (!$orderConfig) {
                $orderConfig = \Fleetbase\FleetOps\Models\OrderConfig::where('company_uuid', $ride->company_uuid)
                    ->where(function ($q) {
                        $q->where('key', 'passenger-transport')
                        ->orWhere('name', 'like', '%passenger%')
                        ->orWhere('name', 'like', '%ride%')
                        ->orWhere('name', 'like', '%transport%');
                    })
                    ->first();
            }

            if (!$orderConfig) {
                $orderConfig = \Fleetbase\FleetOps\Models\OrderConfig::where('company_uuid', $ride->company_uuid)->first();
            }

            $network = \Fleetbase\Storefront\Models\Network::where('uuid', $ride->network_uuid)->first();

            $order = Order::create([
                'company_uuid'          => $ride->company_uuid,
                'order_config_uuid'     => $orderConfig ? $orderConfig->uuid : null,
                'payload_uuid'          => $payload->uuid,
                'customer_uuid'         => $ride->customer_uuid,
                'customer_type'         => 'Fleetbase\FleetOps\Models\Contact',
                'driver_assigned_uuid'  => null, // Unassigned natively 
                'vehicle_assigned_uuid' => null, // Unassigned natively
                'type'                  => 'passenger-transport',
                'status'                => 'created',
                'scheduled_at'          => \Carbon\Carbon::parse($ride->scheduled_at)->toDateTimeString(),
                'adhoc'                 => false,
                'meta'                  => [
                    'ride_uuid'             => $ride->uuid,
                    'ride_public_id'        => $ride->public_id,
                    'pricing_method'        => $ride->pricing_method,
                    'storefront'            => $store ? $store->name : null,
                    'storefront_id'         => $store ? $store->public_id : null,
                ],
            ]);

            $ride->update(['order_uuid' => $order->uuid]);
        }

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

        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })
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

        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

        // Ensure authorization
        if ($ride->customer_uuid !== session('customer')) {
            return response()->error('Unauthorized.', 403);
        }

        // Validate cancel state
        if (!$ride->isCancelable()) {
            return response()->error('This ride cannot be canceled at this stage.', 422);
        }

        // **Whitelist Safe Cancellation Approach**
        // A customer can only cancel while the ride is safely in these pre-transit stages.
        $allowedCancellableStatuses = [Ride::STATUS_PENDING, Ride::STATUS_SEARCHING, Ride::STATUS_BIDDING, Ride::STATUS_ACCEPTED];
        if (!in_array($ride->status, $allowedCancellableStatuses)) {
            return response()->error('You cannot cancel this ride at its current stage. Please contact your assigned driver directly.', 422);
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
        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

        if ($ride->customer_uuid !== session('customer')) {
            return response()->error('Unauthorized.', 403);
        }

        if ($ride->status !== Ride::STATUS_BIDDING) {
            return response()->error('Ride is not in a bidding state.', 422);
        }

        $bids = RideBid::where('ride_uuid', $ride->uuid)
            ->where('status', RideBid::STATUS_PENDING)
            ->with(['driver.user', 'vehicle'])
            ->orderBy('amount', 'asc')
            ->get();

        return RideBidResource::collection($bids);
    }

    /**
     * Accept a specific bid for the ride.
     */
    public function acceptBid(Request $request, string $id)
    {
        $request->validate([
            'bid_public_id' => 'nullable|string',
            'bid_uuid'      => 'nullable|string',
        ]);

        $bidId = $request->input('bid_uuid') ?: $request->input('bid_public_id');

        if (!$bidId) {
            return response()->error('A bid ID is required.', 422);
        }

        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

        if ($ride->customer_uuid !== session('customer')) {
            return response()->error('Unauthorized.', 403);
        }

        if ($ride->status !== Ride::STATUS_BIDDING) {
            return response()->error('Ride is no longer accepting bids.', 422);
        }

        $bid = RideBid::where(function ($q) use ($bidId) {
            $q->where('public_id', $bidId)->orWhere('uuid', $bidId);
        })->first();

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
        // If the order was already pre-created (e.g., Scheduled Rides), simply update the driver assignment.
        // Otherwise, construct the native FleetOps Order right now.
        if ($ride->order_uuid && ($order = Order::where('uuid', $ride->order_uuid)->first())) {
            $order->update([
                'driver_assigned_uuid'  => $bid->driver_uuid,
                'vehicle_assigned_uuid' => $bid->vehicle_uuid,
            ]);
            
            // Optionally update Order details in meta
            $meta = $order->meta ?? [];
            $meta['final_price'] = $ride->final_price;
            $order->update(['meta' => $meta]);
        } else {
            $payload = Payload::create([
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
            // Fetch the network to also link it to the Network Dashboard
            $network = \Fleetbase\Storefront\Models\Network::where('uuid', $ride->network_uuid)->first();

            $order = Order::create([
                'company_uuid'          => $ride->company_uuid,
                'order_config_uuid'     => $orderConfig ? $orderConfig->uuid : null,
                'payload_uuid'          => $payload->uuid,
                'customer_uuid'         => $ride->customer_uuid,
                'customer_type'         => 'Fleetbase\FleetOps\Models\Contact',
                'driver_assigned_uuid'  => $bid->driver_uuid,
                'vehicle_assigned_uuid' => $bid->vehicle_uuid,
                'type'                  => 'passenger-transport',
                'status'                => 'created',
                'adhoc'                 => false,
                'meta'                  => [
                    'ride_uuid'             => $ride->uuid,
                    'ride_public_id'        => $ride->public_id,
                    'vehicle_category'      => $ride->vehicleCategory?->name,
                    'pricing_method'        => $ride->pricing_method,
                    'final_price'           => $ride->final_price,
                    'payment_method'        => $ride->payment_method,
                    'storefront'            => $store ? $store->name : null,
                    'storefront_id'         => $store ? $store->public_id : null,
                    'storefront_network'    => $network ? $network->name : null,
                    'storefront_network_id' => $network ? $network->public_id : null,
                ],
            ]);

            // Link the native Order to our Ride
            $ride->update(['order_uuid' => $order->uuid]);
        }

        // Dispatch the Order in FleetOps (triggers native lifecycle)
        $order->dispatchWithActivity();

        // Broadcast to drivers and customers
        event(new RideBidAccepted($bid));

        return response()->json([
            'message' => 'Bid accepted successfully.',
            'ride'    => $ride->load(['driver.user', 'vehicle']),
        ]);
    }

    /**
     * Customer rates the driver.
     */
    public function rate(Request $request, string $id)
    {
        $request->validate([
            'rating'  => 'nullable|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'tags'    => 'nullable|array',
        ]);

        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

        if ($ride->customer_uuid !== session('customer')) {
            return response()->error('Unauthorized.', 403);
        }

        if ($ride->status !== Ride::STATUS_COMPLETED) {
            return response()->error('Cannot rate until trip is completed.', 422);
        }

        RideReview::create([
            'company_uuid'  => $ride->company_uuid,
            'ride_uuid'     => $ride->uuid,
            'reviewer_uuid' => session('customer'),
            'reviewer_type' => 'customer',
            'reviewee_uuid' => $ride->driver_uuid,
            'reviewee_type' => 'driver',
            'rating'        => $request->input('rating'),
            'comment'       => $request->input('comment'),
            'tags'          => $request->input('tags', []),
        ]);

        // Sync stats to driver meta
        RideReview::syncStats($ride->driver_uuid, 'driver');

        return response()->json(['message' => 'Driver rated successfully.']);
    }

    /**
     * Estimate the cost of a ride for all available vehicle categories.
     */
    public function estimate(Request $request)
    {
        $request->validate([
            'distance_meters'           => 'nullable|integer',
            'duration_seconds'          => 'nullable|integer',
            'pickup_lat'                => 'nullable|numeric',
            'pickup_lng'                => 'nullable|numeric',
            'dropoff_lat'               => 'nullable|numeric',
            'dropoff_lng'               => 'nullable|numeric',
            'currency'                  => 'nullable|string|size:3',
            'vehicle_sub_category_uuid' => 'nullable|uuid',
        ]);

        $distance = $request->input('distance_meters');
        $duration = $request->input('duration_seconds');
        $requestedSubUuid = $request->input('vehicle_sub_category_uuid');

        // If coordinates provided but no distance, use a simple mock calculation
        if (!$distance && $request->filled(['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'])) {
            $distance = 5000; // Mock 5km
            $duration = 600;  // Mock 10 mins
        }

        if (!$distance || !$duration) {
            return response()->error('Insufficient data for estimation. Provide distance/duration or coordinates.', 422);
        }

        $currency = $request->input('currency', session('rides_currency', 'YER'));
        $storeUuid = session('rides_store');
        $companyUuid = session('company');

        $categoriesQuery = VehicleCategory::where('is_active', true);

        if ($storeUuid) {
            $categoriesQuery->where('store_uuid', $storeUuid);
        } elseif ($companyUuid) {
            $categoriesQuery->where('company_uuid', $companyUuid)
                            ->whereNull('store_uuid');
        }

        $categories = $categoriesQuery->orderBy('sort_order', 'asc')->get();
        $estimates = [];

        foreach ($categories as $category) {
            $name = $category->name;
            $fareMultiplier = 100;
            $subCategoryUuid = null;

            // Check if a specifically requested sub-category belongs to this parent category
            if ($requestedSubUuid) {
                $subCategory = $category->subCategories->firstWhere('uuid', $requestedSubUuid);
                if ($subCategory) {
                    $name = $subCategory->name;
                    $fareMultiplier = $subCategory->fare_multiplier;
                    $subCategoryUuid = $subCategory->uuid;
                }
            }

            $estimates[] = [
                'vehicle_category_uuid'     => $category->uuid,
                'vehicle_sub_category_uuid' => $subCategoryUuid,
                'public_id'                 => $category->public_id,
                'name'                      => $name,
                'key'                       => $category->key,
                'description'               => $category->description,
                'icon'                      => $category->icon,
                'base_fare'                 => $category->base_fare,
                'per_km_fare'               => $category->per_km_fare,
                'per_min_fare'              => $category->per_min_fare,
                'min_fare'                  => $category->min_fare,
                'currency'                  => $category->currency ?? $currency,
                'estimated_fare'            => $category->calculateFare($distance, $duration, $fareMultiplier),
            ];
        }

        return response()->json([
            'distance_meters'  => $distance,
            'duration_seconds' => $duration,
            'currency'         => $currency,
            'estimates'        => $estimates,
        ]);
    }
}
