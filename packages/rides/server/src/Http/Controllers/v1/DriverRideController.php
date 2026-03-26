<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\Ride;
use Hopper\Rides\Events\RideStatusChanged;
use Hopper\Rides\Events\RideCanceled;
use Hopper\Rides\Http\Resources\v1\RideResource;
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
            ->with(['vehicleCategory', 'pickupPlace', 'dropoffPlace', 'customer']);

        // Limit by driver's vehicle category if possible
        // if ($driver->vehicle && isset($driver->vehicle->meta['vehicle_category_uuid'])) {
        //     $query->where('vehicle_category_uuid', $driver->vehicle->meta['vehicle_category_uuid']);
        // }

        $rides = $query->latest()->take(50)->get();

        return RideResource::collection($rides);
    }

    /**
     * Show ride details for the driver.
     */
    public function show(string $id)
    {
        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })
            ->with(['customer', 'vehicleCategory', 'pickupPlace', 'dropoffPlace', 'order'])
            ->firstOrFail();

        // Security: Ensure driver is authorized to see this specific ride
        // (Allows assigned driver OR any driver if ride is still seeking bids)
        $driverUuid = session('driver');
        if ($ride->driver_uuid && $ride->driver_uuid !== $driverUuid) {
            return response()->error('Unauthorized.', 403);
        }

        return response()->json([
            'ride' => new RideResource($ride)
        ]);
    }

    /**
     * Accept a ride (for auto/fixed pricing strategy).
     */
    public function accept(Request $request, string $id)
    {
        $driverUuid = session('driver');
        $vehicleUuid = session('driver_vehicle');
        $companyUuid = session('company');

        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

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
        // Fetch the network to also link it to the Network Dashboard
        $network = \Fleetbase\Storefront\Models\Network::where('uuid', $ride->network_uuid)->first();

        $order = \Fleetbase\FleetOps\Models\Order::create([
            'company_uuid'          => $ride->company_uuid,
            'order_config_uuid'     => $orderConfig ? $orderConfig->uuid : null,
            'payload_uuid'          => $payload->uuid,
            'customer_uuid'         => $ride->customer_uuid,
            'customer_type'         => 'Fleetbase\FleetOps\Models\Contact',
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
                'storefront_network'    => $network ? $network->name : null,
                'storefront_network_id' => $network ? $network->public_id : null,
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

        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();

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
     * Dynamic Trip Status Update: Accepts any status code and syncs with FleetOps.
     */
    public function updateStatus(Request $request, string $id)
    {
        $ride = Ride::where(function ($q) use ($id) {
            $q->where('public_id', $id)->orWhere('uuid', $id);
        })->firstOrFail();
        
        if ($ride->driver_uuid !== session('driver')) {
            return response()->error('Unauthorized.', 403);
        }

        $statusCode = $request->input('status', $request->input('status_code')); 
        if (!$statusCode) {
            return response()->error('Status code is required.', 422);
        }

        $previous = $ride->status;
        $rideUpdate = ['status' => $statusCode];

        // Custom model-level logic for Ride-Hailing Stage Mapping
        if ($statusCode === 'started' || $statusCode === Ride::STATUS_IN_TRANSIT) {
             $rideUpdate['status'] = Ride::STATUS_IN_TRANSIT;
             $rideUpdate['started_at'] = now();
        }
        
        if ($statusCode === 'completed' || $statusCode === Ride::STATUS_COMPLETED) {
             $rideUpdate['status'] = Ride::STATUS_COMPLETED;
             $rideUpdate['completed_at'] = now();
        }

        if ($statusCode === 'arrived' || $statusCode === Ride::STATUS_ARRIVED_AT_PICKUP) {
             $rideUpdate['status'] = Ride::STATUS_ARRIVED_AT_PICKUP;
        }

        $ride->update($rideUpdate);

        // Sync with Core FleetOps Order
        if ($ride->order) {
            // FleetOps updateStatus handles the activity insertion and core status sync
            // We check if the status is already updated (to prevent double-logging from the listener)
            if ($ride->order->status !== $statusCode) {
                $ride->order->updateStatus($statusCode);
            }
            
            // Sync financials to the core order so dashboards show correct revenue
            if ($statusCode === 'completed' && $ride->final_price) {
                $ride->order->update(['amount' => $ride->final_price]);
            }

            // Explicit flag fallbacks (safety for external sync)
            if ($statusCode === 'started' && !$ride->order->started) {
                $ride->order->update(['started' => true, 'started_at' => now()]);
            }
        }

        event(new RideStatusChanged($ride, $previous));

        return response()->json([
            'message' => 'Status updated successfully.',
            'ride'    => new RideResource($ride->fresh(['order.orderConfig', 'customer', 'driver']))
        ]);
    }



    /**
     * Driver rates the passenger.
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

        if ($ride->driver_uuid !== session('driver')) {
            return response()->error('Unauthorized.', 403);
        }

        if ($ride->status !== Ride::STATUS_COMPLETED) {
            return response()->error('Cannot rate until trip is completed.', 422);
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

        // Sync stats to customer meta
        \Hopper\Rides\Models\RideReview::syncStats($ride->customer_uuid, 'customer');

        return response()->json(['message' => 'Passenger rated successfully.']);
    }
}
