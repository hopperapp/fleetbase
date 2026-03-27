<?php

namespace Hopper\Rides\Tests\Feature;

use Fleetbase\Models\Company;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Hopper\Rides\Models\Ride;
use Hopper\Rides\Models\RideBid;
use Hopper\Rides\Models\VehicleCategory;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DriverRideControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Driver $driver;
    protected Vehicle $vehicle;
    protected VehicleCategory $vehicleCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        
        $this->vehicle = Vehicle::factory()->create([
            'company_uuid' => $this->company->uuid,
        ]);
        
        $this->driver = Driver::factory()->create([
            'company_uuid' => $this->company->uuid,
            'vehicle_uuid' => $this->vehicle->uuid,
            'online'       => true,
            'location'     => \Fleetbase\FleetOps\Models\Place::toPoint(15.3600, 44.1950), // Near Sana'a
        ]);

        $this->vehicleCategory = VehicleCategory::create([
            'company_uuid' => $this->company->uuid,
            'name'         => 'Standard Sedan',
            'key'          => 'standard',
        ]);

        // Mock sessions for the driver API endpoints
        session([
            'company' => $this->company->uuid,
            'driver' => $this->driver->uuid,
            'driver_vehicle' => $this->vehicle->uuid,
        ]);
    }

    public function test_driver_can_fetch_available_rides()
    {
        // Seed a nearby requested ride
        $ride = Ride::create([
            'company_uuid'          => $this->company->uuid,
            'customer_uuid'         => \Str::uuid(),
            'vehicle_category_uuid' => $this->vehicleCategory->uuid,
            'pricing_method'        => 'auto',
            'pickup_latitude'       => 15.3694,
            'pickup_longitude'      => 44.1910,
            'dropoff_latitude'      => 15.3500,
            'dropoff_longitude'     => 44.2000,
            'distance_meters'       => 5000,
            'duration_seconds'      => 600,
            'status'                => Ride::STATUS_SEARCHING,
        ]);

        $response = $this->getJson('/rides/v1/driver/rides/available');

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'rides')
                 ->assertJsonFragment(['public_id' => $ride->public_id]);
    }

    public function test_driver_can_submit_bid()
    {
        // Require bidding ride
        $ride = Ride::create([
            'company_uuid'          => $this->company->uuid,
            'customer_uuid'         => \Str::uuid(),
            'vehicle_category_uuid' => $this->vehicleCategory->uuid,
            'pricing_method'        => 'bidding',
            'pickup_latitude'       => 15.3694,
            'pickup_longitude'      => 44.1910,
            'dropoff_latitude'      => 15.3500,
            'dropoff_longitude'     => 44.2000,
            'distance_meters'       => 5000,
            'duration_seconds'      => 600,
            'status'                => Ride::STATUS_BIDDING,
            'currency'              => 'YER',
        ]);

        $payload = [
            'amount'                => 1500,
            'estimated_arrival_min' => 5,
            'note'                  => 'Clean car, arriving fast.',
        ];

        $response = $this->postJson('/rides/v1/driver/bids/' . $ride->public_id, $payload);

        $response->assertStatus(201)
                 ->assertJsonFragment(['amount' => 1500]);

        $this->assertDatabaseHas('ride_bids', [
            'ride_uuid'   => $ride->uuid,
            'driver_uuid' => $this->driver->uuid,
            'amount'      => 1500,
            'status'      => RideBid::STATUS_PENDING,
        ]);
    }

    public function test_driver_can_update_trip_status()
    {
        // Assigned ride
        $ride = Ride::create([
            'company_uuid'          => $this->company->uuid,
            'customer_uuid'         => \Str::uuid(),
            'driver_uuid'           => $this->driver->uuid,
            'vehicle_category_uuid' => $this->vehicleCategory->uuid,
            'pricing_method'        => 'auto',
            'pickup_latitude'       => 15.3694,
            'pickup_longitude'      => 44.1910,
            'dropoff_latitude'      => 15.3500,
            'dropoff_longitude'     => 44.2000,
            'distance_meters'       => 5000,
            'duration_seconds'      => 600,
            'status'                => Ride::STATUS_ACCEPTED, // En Route -> Arrived ...
        ]);

        $response = $this->postJson('/rides/v1/driver/rides/' . $ride->public_id . '/en-route');
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('rides', [
            'uuid'   => $ride->uuid,
            'status' => Ride::STATUS_DRIVER_EN_ROUTE,
        ]);

        $response = $this->postJson('/rides/v1/driver/rides/' . $ride->public_id . '/start');
        $response->assertStatus(200);
        $this->assertDatabaseHas('rides', [
            'uuid'   => $ride->uuid,
            'status' => Ride::STATUS_IN_TRANSIT,
        ]);
    }
}
