<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\Ride;
use Hopper\Rides\Models\RideBid;
use Hopper\Rides\Events\RideBidReceived;
use Illuminate\Http\Request;

class DriverBidController extends Controller
{
    /**
     * Submit a new bid for a ride.
     */
    public function submit(Request $request, string $rideId)
    {
        $request->validate([
            'amount'                => 'required|integer|min:1',
            'estimated_arrival_min' => 'nullable|integer|min:1',
            'note'                  => 'nullable|string|max:255',
        ]);

        $driverUuid = session('driver');
        $vehicleUuid = session('driver_vehicle');
        $companyUuid = session('company');

        if (!$driverUuid || !$companyUuid) {
            return response()->error('Authentication failed: Missing company or driver context.', 401);
        }

        $ride = Ride::where(function ($q) use ($rideId) {
            $q->where('public_id', $rideId)->orWhere('uuid', $rideId);
        })->firstOrFail();

        // Validate ride conditions
        if ($ride->company_uuid !== $companyUuid) {
            return response()->error('Unauthorized.', 403);
        }
        
        if ($ride->status !== Ride::STATUS_BIDDING) {
            return response()->error('This ride is no longer accepting bids.', 422);
        }

        // Check if driver already bid
        $existingBid = RideBid::where('ride_uuid', $ride->uuid)
            ->where('driver_uuid', $driverUuid)
            ->where('status', RideBid::STATUS_PENDING)
            ->first();

        if ($existingBid) {
            return response()->error('You have already placed a pending bid for this ride.', 422);
        }

        // Calculate expiration correctly with fallback
        if ($ride->is_scheduled && $ride->scheduled_at) {
            // Async Bidding: Valid until 60 minutes before the scheduled ride time
            $scheduledAt = \Carbon\Carbon::parse($ride->scheduled_at);
            $expiresAt = $scheduledAt->subMinutes(60);
            if ($expiresAt->lt(now())) {
                $expiresAt = now()->addMinutes(15); // Fallback buffer if bidding super close to cutoff
            }
        } else {
            $ttlMin = is_numeric(config('rides.bidding.ttl_minutes')) ? (int)config('rides.bidding.ttl_minutes') : 5;
            $expiresAt = now()->addMinutes($ttlMin);
        }

        // Create the bid
        $bid = RideBid::create([
            'company_uuid'          => $companyUuid,
            'ride_uuid'             => $ride->uuid,
            'driver_uuid'           => $driverUuid,
            'vehicle_uuid'          => $vehicleUuid,
            'amount'                => $request->input('amount'),
            'currency'              => $ride->currency,
            'estimated_arrival_min' => $request->input('estimated_arrival_min'),
            'note'                  => $request->input('note'),
            'status'                => RideBid::STATUS_PENDING,
            'expires_at'            => $expiresAt,
        ]);

        // Broadcast the bid to the customer
        event(new RideBidReceived($bid));

        return response()->json([
            'message' => 'Bid submitted successfully.',
            'bid'     => $bid->load(['driver', 'vehicle']),
        ], 201);
    }

    /**
     * Update an existing pending bid.
     */
    public function update(Request $request, string $rideId)
    {
        $request->validate([
            'amount'                => 'required|integer|min:1',
            'estimated_arrival_min' => 'nullable|integer|min:1',
            'note'                  => 'nullable|string|max:255',
        ]);

        $driverUuid = session('driver');
        $ride = Ride::where(function ($q) use ($rideId) {
            $q->where('public_id', $rideId)->orWhere('uuid', $rideId);
        })->firstOrFail();

        $bid = RideBid::where('ride_uuid', $ride->uuid)
            ->where('driver_uuid', $driverUuid)
            ->where('status', RideBid::STATUS_PENDING)
            ->firstOrFail();

        if (!$bid->isActive()) {
            return response()->error('Bid is no longer active and cannot be updated.', 422);
        }

        $bid->update([
            'amount'                => $request->input('amount'),
            'estimated_arrival_min' => $request->input('estimated_arrival_min'),
            'note'                  => $request->input('note'),
        ]);

        // Resend broadcast so frontend knows the bid was modified
        event(new RideBidReceived($bid));

        return response()->json([
            'message' => 'Bid updated successfully.',
            'bid'     => $bid->load(['driver', 'vehicle']),
        ]);
    }

    /**
     * Withdraw a bid.
     */
    public function withdraw(Request $request, string $rideId)
    {
        $driverUuid = session('driver');
        $ride = Ride::where(function ($q) use ($rideId) {
            $q->where('public_id', $rideId)->orWhere('uuid', $rideId);
        })->firstOrFail();

        $bid = RideBid::where('ride_uuid', $ride->uuid)
            ->where('driver_uuid', $driverUuid)
            ->firstOrFail();

        if ($bid->status === RideBid::STATUS_ACCEPTED) {
            return response()->error('Cannot withdraw an accepted bid. Cancel the ride instead.', 422);
        }

        $bid->update(['status' => RideBid::STATUS_WITHDRAWN]);

        // We can optionally fire an event to remove it from the customer UI here...

        return response()->json([
            'message' => 'Bid successfully withdrawn.',
        ]);
    }
}
