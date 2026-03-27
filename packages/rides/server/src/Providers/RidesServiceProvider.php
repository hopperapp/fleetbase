<?php

namespace Hopper\Rides\Providers;

use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Storefront\Providers\StorefrontServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Rides extension cannot be loaded without `fleetbase/core-api` installed!');
}

if (!class_exists(FleetOpsServiceProvider::class)) {
    throw new \Exception('Rides extension cannot be loaded without `fleetbase/fleetops-api` installed!');
}

if (!class_exists(StorefrontServiceProvider::class)) {
    throw new \Exception('Rides extension cannot be loaded without `fleetbase/storefront-api` installed!');
}

/**
 * Hopper Rides service provider.
 */
class RidesServiceProvider extends CoreServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [];

    /**
     * The middleware groups registered with the service provider.
     *
     * @var array
     */
    public $middleware = [
        'rides.api' => [
            \Illuminate\Session\Middleware\StartSession::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Hopper\Rides\Http\Middleware\SetRideSession::class,
            \Fleetbase\Http\Middleware\ConvertStringBooleans::class,
            \Fleetbase\Http\Middleware\SetGlobalHeaders::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Fleetbase\Http\Middleware\LogApiRequests::class,
        ],
    ];

    /**
     * The console commands registered with the service provider.
     *
     * @var array
     */
    public $commands = [];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);
        $this->app->register(FleetOpsServiceProvider::class);
        $this->app->register(StorefrontServiceProvider::class);

        $this->mergeConfigFrom(__DIR__ . '/../../../config/rides.php', 'rides');
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerMiddleware();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');

        // Register Morph Map for polymorphic relationships
        Relation::morphMap([
            'customer' => \Fleetbase\FleetOps\Models\Contact::class,
            'driver'   => \Fleetbase\FleetOps\Models\Driver::class,
            'ride'     => \Hopper\Rides\Models\Ride::class,
        ]);
    }
}
