<?php

namespace Hopper\Rides\Jobs;

use Fleetbase\FleetOps\Models\Driver;
use Hopper\Rides\Models\Ride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BroadcastRideToDrivers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Ride $ride;

    /**
     * Create a new job instance.
     */
    public function __construct(Ride $ride)
    {
        $this->ride = $ride;
        $this->onQueue('rides');
    }

    /**
     * Find nearby online drivers that match the ride's vehicle category
     * and broadcast the ride request to each one.
     */
    public function handle(): void
    {
        $ride = $this->ride;
        $radiusMeters = config('rides.bidding.driver_radius_meters', 10000);

        if (!$ride->pickup_latitude || !$ride->pickup_longitude) {
            Log::warning("Ride {$ride->public_id}: Cannot broadcast — missing pickup coordinates.");
            return;
        }

        // Find online drivers within radius for this company
        $drivers = Driver::where('company_uuid', $ride->company_uuid)
            ->where('online', true)
            ->whereNotNull('location')
            ->get();

        // Filter by distance using PHP (since driver location is a POINT spatial type)
        $nearbyDrivers = $drivers->filter(function ($driver) use ($ride, $radiusMeters) {
            if (!$driver->location) {
                return false;
            }

            $driverLat = $driver->location->getLat();
            $driverLng = $driver->location->getLng();

            $distance = $this->haversineDistance(
                $ride->pickup_latitude,
                $ride->pickup_longitude,
                $driverLat,
                $driverLng
            );

            return $distance <= $radiusMeters;
        });

        Log::info("Ride {$ride->public_id}: Broadcasting to {$nearbyDrivers->count()} nearby drivers.");

        // Broadcast to each nearby driver via SocketCluster
        foreach ($nearbyDrivers as $driver) {
            broadcast(new \Hopper\Rides\Events\RideRequested($ride))->toOthers();
        }
    }

    /**
     * Calculate the distance between two points using the Haversine formula.
     *
     * @return float Distance in meters
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
