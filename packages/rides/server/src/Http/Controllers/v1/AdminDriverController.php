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
        $limit = $request->input('limit', 15);
        $sort = $request->input('sort', 'total_earnings');
        
        // Allowed sort columns
        if (!in_array($sort, ['total_earnings', 'completed_rides'])) {
            $sort = 'total_earnings';
        }

        $leaderboard = Driver::query()
            ->select('drivers.*')
            ->where('company_uuid', session('company'))
            ->with(['user', 'vehicle'])
            ->addSelect([
                'completed_rides' => Ride::selectRaw('count(*)')
                    ->whereColumn('driver_uuid', 'drivers.uuid')
                    ->where('status', Ride::STATUS_COMPLETED),
                'total_earnings' => Ride::selectRaw('coalesce(sum(final_price), 0)')
                    ->whereColumn('driver_uuid', 'drivers.uuid')
                    ->where('status', Ride::STATUS_COMPLETED),
            ])
            ->orderBy($sort, 'desc')
            ->paginate($limit);

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
