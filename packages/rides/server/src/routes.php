<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hopper Rides API Routes
|--------------------------------------------------------------------------
|
| Routes for the Ride-Hailing extension. Split into Customer-facing
| and Driver-facing groups, both protected by the rides.api middleware.
|
*/

Route::prefix(config('rides.api.routing.prefix', 'rides'))->namespace('Hopper\Rides\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Customer API Routes
        |--------------------------------------------------------------------------
        |
        | Customer-facing endpoints for requesting rides, viewing estimates,
        | managing bids, and tracking active rides.
        |
        */
        Route::prefix('v1/customer')
            ->middleware('rides.api')
            ->namespace('v1')
            ->group(function ($router) {
                // Vehicle categories
                $router->group(['prefix' => 'vehicles'], function () use ($router) {
                    $router->get('/', 'CustomerVehicleController@index');
                    $router->get('{key}', 'CustomerVehicleController@show');
                });

                // Price estimation
                $router->post('estimate', 'CustomerEstimateController@estimate');

                // Ride management
                $router->group(['prefix' => 'rides'], function () use ($router) {
                    $router->post('/', 'CustomerRideController@request');
                    $router->get('active', 'CustomerRideController@active');
                    $router->get('history', 'CustomerRideController@history');
                    $router->get('{id}', 'CustomerRideController@show');
                    $router->post('{id}/cancel', 'CustomerRideController@cancel');
                    $router->get('{id}/bids', 'CustomerRideController@bids');
                    $router->post('{id}/accept-bid', 'CustomerRideController@acceptBid');
                    $router->post('{id}/review', 'CustomerRideController@submitReview');
                });
            });

        /*
        |--------------------------------------------------------------------------
        | Driver API Routes
        |--------------------------------------------------------------------------
        |
        | Driver-facing endpoints for discovering rides, submitting bids,
        | accepting/declining rides, and updating trip status.
        |
        */
        Route::prefix('v1/driver')
            ->middleware('rides.api')
            ->namespace('v1')
            ->group(function ($router) {
                // Ride discovery & actions
                $router->group(['prefix' => 'rides'], function () use ($router) {
                    $router->get('available', 'DriverRideController@available');
                    $router->get('{id}', 'DriverRideController@show');
                    $router->post('{id}/accept', 'DriverRideController@accept');
                    $router->post('{id}/decline', 'DriverRideController@decline');
                    $router->post('{id}/cancel', 'DriverRideController@cancel');

                    // Trip status flow (Dynamic)
                    $router->post('{id}/status', 'DriverRideController@updateStatus');

                    // Individual status endpoints (Deprecated in favor of /status)
                    $router->post('{id}/en-route', 'DriverRideController@enRoute');
                    $router->post('{id}/arrived', 'DriverRideController@arrived');
                    $router->post('{id}/start', 'DriverRideController@start');
                    $router->post('{id}/complete', 'DriverRideController@complete');

                    // Review
                    $router->post('{id}/review', 'DriverRideController@submitReview');
                });

                // Bidding
                $router->group(['prefix' => 'bids'], function () use ($router) {
                    $router->post('{rideId}', 'DriverBidController@submit');
                    $router->put('{rideId}', 'DriverBidController@update');
                    $router->delete('{rideId}', 'DriverBidController@withdraw');
                });
            });

        /*
        |--------------------------------------------------------------------------
        | Admin API Routes
        |--------------------------------------------------------------------------
        |
        | Admin endpoints for managing categories and system settings.
        |
        */
        Route::prefix('v1/admin')
            ->middleware('fleetbase.api')
            ->namespace('v1')
            ->group(function ($router) {
                $router->group(['prefix' => 'vehicle-categories'], function () use ($router) {
                    $router->get('/', 'AdminVehicleCategoryController@index');
                    $router->post('/', 'AdminVehicleCategoryController@store');
                    $router->get('{id}', 'AdminVehicleCategoryController@show');
                    $router->put('{id}', 'AdminVehicleCategoryController@update');
                    $router->delete('{id}', 'AdminVehicleCategoryController@destroy');
                });
                
                $router->group(['prefix' => 'vehicle-sub-categories'], function () use ($router) {
                    $router->get('/', 'AdminVehicleSubCategoryController@index');
                    $router->post('/', 'AdminVehicleSubCategoryController@store');
                    $router->get('{id}', 'AdminVehicleSubCategoryController@show');
                    $router->put('{id}', 'AdminVehicleSubCategoryController@update');
                    $router->delete('{id}', 'AdminVehicleSubCategoryController@destroy');
                });
            });
    }
);
