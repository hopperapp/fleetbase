<?php

namespace Hopper\Rides\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleCategory extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'vehicle_categories';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'vcat';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'key', 'description'];

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
        'name',
        'key',
        'description',
        'icon',
        'image_uuid',
        'base_fare',
        'per_km_fare',
        'per_min_fare',
        'min_fare',
        'max_passengers',
        'currency',
        'is_active',
        'sort_order',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'meta'       => Json::class,
        'is_active'  => 'boolean',
        'base_fare'  => 'integer',
        'per_km_fare' => 'integer',
        'per_min_fare' => 'integer',
        'min_fare'   => 'integer',
        'max_passengers' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * Relationships to always load.
     *
     * @var array
     */
    protected $with = ['subCategories'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subCategories()
    {
        return $this->hasMany(VehicleSubCategory::class, 'vehicle_category_uuid', 'uuid')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function store()
    {
        return $this->belongsTo(\Fleetbase\Storefront\Models\Store::class, 'store_uuid', 'uuid');
    }

    /**
     * Calculate the estimated fare for a given distance and duration.
     *
     * @param int $distanceMeters   Distance in meters
     * @param int $durationSeconds  Duration in seconds
     * @param int $fareMultiplier   Sub-category fare multiplier (100 = 1.0x)
     *
     * @return int Estimated fare in minor currency units
     */
    public function calculateFare(int $distanceMeters, int $durationSeconds, int $fareMultiplier = 100): int
    {
        $distanceKm = $distanceMeters / 1000;
        $durationMin = $durationSeconds / 60;

        $fare = $this->base_fare
            + ($distanceKm * $this->per_km_fare)
            + ($durationMin * $this->per_min_fare);

        // Apply sub-category multiplier
        $fare = (int) round($fare * ($fareMultiplier / 100));

        // Enforce minimum fare
        return max($fare, $this->min_fare);
    }
}
