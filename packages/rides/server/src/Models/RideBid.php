<?php

namespace Hopper\Rides\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class RideBid extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ride_bids';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'bid';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'ride_uuid',
        'driver_uuid',
        'vehicle_uuid',
        'amount',
        'currency',
        'estimated_arrival_min',
        'note',
        'status',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'amount'                => 'integer',
        'estimated_arrival_min' => 'integer',
        'expires_at'            => 'datetime',
    ];

    /**
     * Possible bid statuses.
     */
    const STATUS_PENDING   = 'pending';
    const STATUS_ACCEPTED  = 'accepted';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_EXPIRED   = 'expired';

    /**
     * Dynamic attributes appended to JSON.
     */
    protected $appends = [
        'driver_name',
        'driver_rating',
        'vehicle_info',
    ];

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function ride()
    {
        return $this->belongsTo(Ride::class, 'ride_uuid', 'uuid');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_uuid', 'uuid');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    // ─────────────────────────────────────────────
    // Accessors
    // ─────────────────────────────────────────────

    public function getDriverNameAttribute(): ?string
    {
        $this->loadMissing('driver.user');
        return $this->driver?->user?->name;
    }

    public function getDriverRatingAttribute(): ?float
    {
        // Calculate average rating from ride_reviews for this driver
        $avgRating = RideReview::where('reviewee_uuid', $this->driver_uuid)
            ->where('reviewee_type', 'driver')
            ->avg('rating');

        return $avgRating ? round($avgRating, 1) : null;
    }

    public function getVehicleInfoAttribute(): ?array
    {
        $this->loadMissing('vehicle');

        if (!$this->vehicle) {
            return null;
        }

        return [
            'make'         => $this->vehicle->make,
            'model'        => $this->vehicle->model,
            'year'         => $this->vehicle->year,
            'plate_number' => $this->vehicle->plate_number,
            'color'        => $this->vehicle->meta['color'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────
    // Business Logic
    // ─────────────────────────────────────────────

    /**
     * Check if bid is still active (pending and not expired).
     */
    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Mark this bid as accepted and reject all other bids for the same ride.
     */
    public function accept(): self
    {
        $this->update(['status' => self::STATUS_ACCEPTED]);

        // Reject all other pending bids for this ride
        static::where('ride_uuid', $this->ride_uuid)
            ->where('uuid', '!=', $this->uuid)
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_REJECTED]);

        return $this;
    }

    /**
     * Find bid by public_id.
     */
    public static function findByPublicId(string $publicId): ?self
    {
        return static::where('public_id', $publicId)->first();
    }
}
