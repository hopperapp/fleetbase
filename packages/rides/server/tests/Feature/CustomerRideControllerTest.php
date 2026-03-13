<?php

namespace Hopper\Rides\Tests\Feature;

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\FleetOps\Models\Contact;
use Hopper\Rides\Models\Ride;
use Hopper\Rides\Models\VehicleCategory;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class CustomerRideControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Store $store;
    protected Contact $customer;
    protected VehicleCategory $vehicleCategory;
    protected string $apiToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Scaffold core requirements
        $this->company = Company::factory()->create();
        $this->store = Store::factory()->create(['company_uuid' => $this->company->uuid]);
        $this->customer = Contact::factory()->create(['company_uuid' => $this->company->uuid]);
        
        $this->vehicleCategory = VehicleCategory::create([
            'company_uuid' => $this->company->uuid,
            'name'         => 'Standard Sedan',
            'key'          => 'standard',
            'base_fare'    => 500,
            'per_km_fare'  => 150,
            'per_min_fare' => 50,
            'min_fare'     => 1000,
            'is_active'    => true,
            'currency'     => 'YER',
        ]);

        // Mock a bearer token if API-key based, or session variables
        // Following SetRideSession middleware, we assume a bearer token for Store, and Customer-Token header
        $this->withHeaders([
            'Authorization'  => 'Bearer ' . $this->store->public_id, // Simplified for testing
            'Customer-Token' => $this->customer->uuid, // Simplified authentication
        ]);

        // Simulating the middleware setting the session
        session([
            'company' => $this->company->uuid,
            'rides_store' => $this->store->uuid,
            'customer' => $this->customer->uuid,
            'rides_currency' => 'YER',
        ]);
    }

    public function test_customer_can_request_ride()
    {
        $payload = [
            'vehicle_category_uuid' => $this->vehicleCategory->uuid,
            'pickup_latitude'       => 15.3694,
            'pickup_longitude'      => 44.1910,
            'dropoff_latitude'      => 15.3500,
            'dropoff_longitude'     => 44.2000,
            'pickup_address'        => 'Sana\'a City Center',
            'dropoff_address'       => 'Sana\'a Airport',
            'distance_meters'       => 5000,
            'duration_seconds'      => 600,
            'pricing_method'        => 'auto',
        ];

        $response = $this->postJson('/rides/v1/customer/rides', $payload);

        $response->assertStatus(201)
                 ->assertJsonStructure(['ride' => ['uuid', 'public_id', 'status', 'pricing_method']]);
                 
        $this->assertDatabaseHas('rides', [
            'customer_uuid'  => $this->customer->uuid,
            'status'         => Ride::STATUS_SEARCHING,
            'pricing_method' => 'auto',
        ]);
    }

    public function test_customer_can_request_bidding_ride()
    {
        $payload = [
            'vehicle_category_uuid' => $this->vehicleCategory->uuid,
            'pickup_latitude'       => 15.3694,
            'pickup_longitude'      => 44.1910,
            'dropoff_latitude'      => 15.3500,
            'dropoff_longitude'     => 44.2000,
            'distance_meters'       => 5000,
            'duration_seconds'      => 600,
            'pricing_method'        => 'bidding',
        ];

        $response = $this->postJson('/rides/v1/customer/rides', $payload);

        $response->assertStatus(201)
                 ->assertJsonFragment(['status' => Ride::STATUS_BIDDING]);
    }

    public function test_customer_can_cancel_ride()
    {
        $ride = Ride::create([
            'company_uuid'          => $this->company->uuid,
            'customer_uuid'         => $this->customer->uuid,
            'vehicle_category_uuid' => $this->vehicleCategory->uuid,
            'pricing_method'        => 'bidding',
            'pickup_latitude'       => 15.3694,
            'pickup_longitude'      => 44.1910,
            'dropoff_latitude'      => 15.3500,
            'dropoff_longitude'     => 44.2000,
            'distance_meters'       => 5000,
            'duration_seconds'      => 600,
            'status'                => Ride::STATUS_BIDDING,
        ]);

        $response = $this->postJson('/rides/v1/customer/rides/' . $ride->public_id . '/cancel', [
            'cancel_reason' => 'Wait time too long'
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment(['message' => 'Ride canceled successfully.']);

        $this->assertDatabaseHas('rides', [
            'uuid'          => $ride->uuid,
            'status'        => Ride::STATUS_CANCELED,
            'cancel_reason' => 'Wait time too long',
        ]);
    }
}
