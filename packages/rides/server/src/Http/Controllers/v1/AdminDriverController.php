<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\FleetOps\Models\Driver;
use Hopper\Rides\Models\Ride;
use Hopper\Rides\Http\Resources\v1\DriverResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDriverController extends Controller
{
    /**
     * Get detailed stats for a specific driver.
     */
    public function stats(string $id)
    {
        $driver = Driver::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        // Calculate stats on the fly for admin accuracy
        $completedRidesCount = Ride::where('driver_uuid', $driver->uuid)
            ->where('status', Ride::STATUS_COMPLETED)
            ->count();

        $totalEarnings = Ride::where('driver_uuid', $driver->uuid)
            ->where('status', Ride::STATUS_COMPLETED)
            ->sum('final_price');

        $avgRating = \Hopper\Rides\Models\RideReview::averageRatingFor($driver->uuid, 'driver');

        return response()->json([
            'driver' => new DriverResource($driver),
            'stats'  => [
                'completed_rides' => $completedRidesCount,
                'total_earnings'  => (float) $totalEarnings,
                'average_rating'  => $avgRating,
                'currency'        => $driver->company->currency ?? 'SAR'
            ]
        ]);
    }

    /**
     * Get a leaderboard of all active drivers.
     */
    public function leaderboard(Request $request)
    {
        $drivers = Driver::where('company_uuid', session('company'))
            ->where('status', 'active')
            ->with(['user', 'vehicle'])
            ->withCount(['jobs as completed_rides_count' => function($query) {
                // We use the jobs relationship (Order) but filter by ride completion logic
                // Or better, we count directly from the rides table via subquery
            }])
            ->get();
            
        // Using raw queries for high performance dashboard lists
        $leaderboard = Driver::query()
            ->select('drivers.*')
            ->where('drivers.company_uuid', session('company'))
            ->leftJoin('rides', function($join) {
                $join->on('drivers.uuid', '=', 'rides.driver_uuid')
                     ->where('rides.status', '=', Ride::STATUS_COMPLETED);
            })
            ->groupBy('drivers.uuid')
            ->selectRaw('COUNT(rides.uuid) as completed_rides')
            ->selectRaw('SUM(rides.final_price) as total_earnings')
            ->orderBy($request->input('sort', 'total_earnings'), 'desc')
            ->paginate($request->input('limit', 15));

        return response()->json([
            'leaderboard' => $leaderboard->items(),
            'meta' => [
                'current_page' => $leaderboard->currentPage(),
                'last_page'    => $leaderboard->lastPage(),
                'total'        => $leaderboard->total(),
            ]
        ]);
    }
}
