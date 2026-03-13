<?php

namespace Hopper\Rides\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ride extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'rides';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'ride';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['public_id', 'pickup_address', 'dropoff_address', 'status'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'store_uuid',
        'network_uuid',
        'order_uuid',
        'customer_uuid',
        'driver_uuid',
        'vehicle_uuid',
        'pickup_place_uuid',
        'dropoff_place_uuid',
        'vehicle_category_uuid',
        'vehicle_sub_category_uuid',
        'pricing_method',
        'estimated_price',
        'customer_price',
        'final_price',
        'currency',
        'payment_method',
        'distance_meters',
        'duration_seconds',
        'pickup_latitude',
        'pickup_longitude',
        'pickup_address',
        'dropoff_latitude',
        'dropoff_longitude',
        'dropoff_address',
        'status',
        'is_scheduled',
        'scheduled_at',
        'accepted_at',
        'started_at',
        'completed_at',
        'canceled_at',
        'canceled_by',
        'cancel_reason',
        'passenger_count',
        'notes',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'meta'             => Json::class,
        'is_scheduled'     => 'boolean',
        'estimated_price'  => 'integer',
        'customer_price'   => 'integer',
        'final_price'      => 'integer',
        'distance_meters'  => 'integer',
        'duration_seconds' => 'integer',
        'passenger_count'  => 'integer',
        'pickup_latitude'  => 'decimal:7',
        'pickup_longitude' => 'decimal:7',
        'dropoff_latitude' => 'decimal:7',
        'dropoff_longitude' => 'decimal:7',
        'scheduled_at'     => 'datetime',
        'accepted_at'      => 'datetime',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'canceled_at'      => 'datetime',
    ];

    /**
     * Possible ride statuses.
     */
    const STATUS_PENDING           = 'pending';
    const STATUS_SEARCHING         = 'searching';
    const STATUS_BIDDING           = 'bidding';
    const STATUS_ACCEPTED          = 'accepted';
    const STATUS_DRIVER_EN_ROUTE   = 'driver_en_route';
    const STATUS_ARRIVED_AT_PICKUP = 'arrived_at_pickup';
    const STATUS_PASSENGER_ONBOARD = 'passenger_onboard';
    const STATUS_IN_TRANSIT        = 'in_transit';
    const STATUS_DROPPED_OFF       = 'dropped_off';
    const STATUS_COMPLETED         = 'completed';
    const STATUS_CANCELED          = 'canceled';
    const STATUS_EXPIRED           = 'expired';

    /**
     * Dynamic attributes appended to JSON.
     */
    protected $appends = [
        'driver_name',
        'customer_name',
        'vehicle_category_name',
        'distance_km',
        'duration_min',
    ];

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function store()
    {
        return $this->belongsTo(\Fleetbase\Storefront\Models\Store::class, 'store_uuid', 'uuid');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid');
    }

    public function customer()
    {
        return $this->belongsTo(Contact::class, 'customer_uuid', 'uuid');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function pickupPlace()
    {
        return $this->belongsTo(Place::class, 'pickup_place_uuid', 'uuid');
    }

    public function dropoffPlace()
    {
        return $this->belongsTo(Place::class, 'dropoff_place_uuid', 'uuid');
    }

    public function vehicleCategory()
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_uuid', 'uuid');
    }

    public function vehicleSubCategory()
    {
        return $this->belongsTo(VehicleSubCategory::class, 'vehicle_sub_category_uuid', 'uuid');
    }

    public function bids()
    {
        return $this->hasMany(RideBid::class, 'ride_uuid', 'uuid');
    }

    public function activeBids()
    {
        return $this->hasMany(RideBid::class, 'ride_uuid', 'uuid')
            ->where('status', 'pending')
            ->orderBy('amount', 'asc');
    }

    public function acceptedBid()
    {
        return $this->hasOne(RideBid::class, 'ride_uuid', 'uuid')
            ->where('status', 'accepted');
    }

    public function reviews()
    {
        return $this->hasMany(RideReview::class, 'ride_uuid', 'uuid');
    }

    // ─────────────────────────────────────────────
    // Accessors
    // ─────────────────────────────────────────────

    public function getDriverNameAttribute(): ?string
    {
        $this->loadMissing('driver.user');
        return $this->driver?->user?->name;
    }

    public function getCustomerNameAttribute(): ?string
    {
        $this->loadMissing('customer');
        return $this->customer?->name;
    }

    public function getVehicleCategoryNameAttribute(): ?string
    {
        $this->loadMissing('vehicleCategory');
        return $this->vehicleCategory?->name;
    }

    public function getDistanceKmAttribute(): ?float
    {
        return $this->distance_meters ? round($this->distance_meters / 1000, 1) : null;
    }

    public function getDurationMinAttribute(): ?int
    {
        return $this->duration_seconds ? (int) ceil($this->duration_seconds / 60) : null;
    }

    // ─────────────────────────────────────────────
    // Query Scopes
    // ─────────────────────────────────────────────

    /**
     * Scope rides belonging to a specific store.
     */
    public function scopeForStore($query, string $storeUuid)
    {
        return $query->where('store_uuid', $storeUuid);
    }

    /**
     * Scope rides with active statuses (not completed or canceled).
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELED,
            self::STATUS_EXPIRED,
        ]);
    }

    /**
     * Scope rides that are awaiting bids.
     */
    public function scopeAwaitingBids($query)
    {
        return $query->where('status', self::STATUS_BIDDING);
    }

    /**
     * Scope rides near a given location.
     *
     * @param float $latitude
     * @param float $longitude
     * @param int   $radiusMeters
     */
    public function scopeNearPickup($query, float $latitude, float $longitude, int $radiusMeters = 10000)
    {
        // Haversine formula for MySQL
        return $query->whereRaw(
            '(6371000 * acos(cos(radians(?)) * cos(radians(pickup_latitude)) * cos(radians(pickup_longitude) - radians(?)) + sin(radians(?)) * sin(radians(pickup_latitude)))) <= ?',
            [$latitude, $longitude, $latitude, $radiusMeters]
        );
    }

    // ─────────────────────────────────────────────
    // Business Logic
    // ─────────────────────────────────────────────

    /**
     * Check if ride is in a state that allows cancellation.
     */
    public function isCancelable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_SEARCHING,
            self::STATUS_BIDDING,
            self::STATUS_ACCEPTED,
            self::STATUS_DRIVER_EN_ROUTE,
        ]);
    }

    /**
     * Check if ride is currently active (in progress).
     */
    public function isActive(): bool
    {
        return !in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELED,
            self::STATUS_EXPIRED,
        ]);
    }

    /**
     * Check if ride uses bidding pricing.
     */
    public function isBidding(): bool
    {
        return $this->pricing_method === 'bidding';
    }

    /**
     * Find the ride by its public_id.
     */
    public static function findByPublicId(string $publicId): ?self
    {
        return static::where('public_id', $publicId)->first();
    }
}
