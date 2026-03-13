<?php

namespace Hopper\Rides\Http\Middleware;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class SetRideSession
{
    /**
     * Handle an incoming request.
     *
     * Validates the Bearer token (store or network key), sets the company context,
     * and optionally sets customer or driver session from their respective tokens.
     */
    public function handle(Request $request, \Closure $next)
    {
        $key = $request->bearerToken();

        if (!$key) {
            return response()->error('No Rides API key found with this request.', 401);
        }

        if ($this->isValidKey($key)) {
            $this->setKey($key);
            $this->setupCustomerSession($request);
            $this->setupDriverSession($request);

            return $next($request);
        }

        return response()->error('The Rides API key provided was not valid.', 401);
    }

    /**
     * Checks if store/network key is valid.
     */
    public function isValidKey(string $key): bool
    {
        if (!Str::startsWith($key, ['network', 'store'])) {
            return false;
        }

        if (Str::startsWith($key, 'store')) {
            return Store::select(['key'])->where('key', $key)->exists();
        }

        return Network::select(['key'])->where('key', $key)->exists();
    }

    /**
     * Sets the store/network key to session with full context.
     */
    public function setKey(string $key): void
    {
        $session = ['rides_key' => $key];

        if (Str::startsWith($key, 'store')) {
            $store = Store::select(['uuid', 'public_id', 'company_uuid', 'currency'])
                ->where('key', $key)
                ->first();

            if ($store) {
                $session['rides_store']           = $store->uuid;
                $session['rides_store_public_id'] = $store->public_id;
                $session['rides_currency']        = $store->currency;
                $session['company']               = $store->company_uuid;
            }
        } elseif (Str::startsWith($key, 'network')) {
            $network = Network::select(['uuid', 'public_id', 'company_uuid', 'currency'])
                ->where('key', $key)
                ->first();

            if ($network) {
                $session['rides_network']            = $network->uuid;
                $session['rides_network_public_id']   = $network->public_id;
                $session['rides_currency']            = $network->currency;
                $session['company']                   = $network->company_uuid;
            }
        }

        $session['api_credential'] = $key;

        session($session);
    }

    /**
     * Set the customer context from the Customer-Token header.
     */
    public function setupCustomerSession(Request $request): void
    {
        $token = $request->header('Customer-Token');

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken) {
                $tokenable = $this->getTokenableFromAccessToken($accessToken);

                if (!$tokenable) {
                    return;
                }

                $contact = Contact::select(['uuid', 'public_id'])
                    ->where('user_uuid', $tokenable->uuid)
                    ->first();

                if ($contact) {
                    session([
                        'customer_id' => Str::replaceFirst('contact', 'customer', $contact->public_id),
                        'contact_id'  => $contact->public_id,
                        'customer'    => $contact->uuid,
                    ]);
                }
            }
        }
    }

    /**
     * Set the driver context from the Driver-Token header.
     */
    public function setupDriverSession(Request $request): void
    {
        $token = $request->header('Driver-Token');

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken) {
                $tokenable = $this->getTokenableFromAccessToken($accessToken);

                if (!$tokenable) {
                    return;
                }

                $driver = Driver::select(['uuid', 'public_id', 'vehicle_uuid'])
                    ->where('user_uuid', $tokenable->uuid)
                    ->first();

                if ($driver) {
                    session([
                        'driver_id'      => $driver->public_id,
                        'driver'         => $driver->uuid,
                        'driver_vehicle' => $driver->vehicle_uuid,
                    ]);
                }
            }
        }
    }

    /**
     * Resolve the tokenable model from an access token.
     */
    public function getTokenableFromAccessToken(PersonalAccessToken $personalAccessToken)
    {
        if ($personalAccessToken->tokenable) {
            return $personalAccessToken->tokenable;
        }

        return app($personalAccessToken->tokenable_type)
            ->where('uuid', $personalAccessToken->tokenable_id)
            ->withoutGlobalScopes()
            ->first();
    }
}
