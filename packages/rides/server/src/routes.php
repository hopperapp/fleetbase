<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hopper Rides API Routes
|--------------------------------------------------------------------------
|
| Routes for the Ride-Hailing extension. Split into Customer, Driver, and Admin.
| Both Customer and Driver routes use high-level session-based authentication
| for the mobile apps, while Admin routes use standard Fleetbase API guards.
|
*/

Route::prefix(config('rides.api.routing.prefix', 'rides'))->namespace('Hopper\Rides\Http\Controllers\v1')->group(
    function ($router) {

        /*
        |--------------------------------------------------------------------------
        | v1 Customer Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('v1/customer')
            ->middleware('rides.api')
            ->group(function ($router) {
                // Vehicles
                $router->get('vehicles', 'CustomerVehicleController@index');
                $router->get('vehicles/{key}', 'CustomerVehicleController@show');

                // Estimates
                $router->post('estimate', 'CustomerRideController@estimate');

                // Rides
                $router->group(['prefix' => 'rides'], function () use ($router) {
                    $router->post('/', 'CustomerRideController@store'); // Request a ride
                    $router->get('active', 'CustomerRideController@active');
                    $router->get('history', 'CustomerRideController@history');
                    $router->get('{id}', 'CustomerRideController@show');
                    $router->post('{id}/cancel', 'CustomerRideController@cancel');
                    $router->get('{id}/bids', 'CustomerRideController@bids');
                    $router->post('{id}/accept-bid', 'CustomerRideController@acceptBid');
                    $router->post('{id}/rate', 'CustomerRideController@rate');
                });
            });

        /*
        |--------------------------------------------------------------------------
        | v1 Driver Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('v1/driver')
            ->middleware('rides.api')
            ->group(function ($router) {
                
                // Discovery & Lifecycle
                $router->group(['prefix' => 'rides'], function () use ($router) {
                    $router->get('available', 'DriverRideController@available');
                    $router->get('{id}', 'DriverRideController@show');
                    $router->post('{id}/status', 'DriverRideController@updateStatus');
                    $router->post('{id}/rate', 'DriverRideController@rate');
                });

                // Bidding
                $router->post('bids/{rideId}', 'DriverBidController@submit');
                $router->put('bids/{rideId}', 'DriverBidController@update');
                $router->delete('bids/{rideId}', 'DriverBidController@withdraw');
            });

        /*
        |--------------------------------------------------------------------------
        | v1 Admin Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('v1/admin')
            ->middleware('fleetbase.api')
            ->group(function ($router) {
                // Category Management
                $router->apiResource('vehicle-categories', 'AdminVehicleCategoryController');
                $router->apiResource('vehicle-sub-categories', 'AdminVehicleSubCategoryController');

                // Driver Performance & Leaderboard
                $router->group(['prefix' => 'drivers'], function () use ($router) {
                    $router->get('leaderboard', 'AdminDriverController@leaderboard');
                    $router->get('{id}/stats', 'AdminDriverController@stats');
                });

            });

        /*
        |--------------------------------------------------------------------------
        | v1 Global Profile/Review Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('v1')->group(function ($router) {
             $router->get('profiles/{type}/{id}/reviews', 'ProfileController@reviews');
        });
    }
);

