<?php

namespace Hopper\Rides\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class RideReview extends Model
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
    protected $table = 'ride_reviews';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'rrev';

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
        'reviewer_uuid',
        'reviewer_type',
        'reviewee_uuid',
        'reviewee_type',
        'rating',
        'comment',
        'tags',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'rating' => 'integer',
        'tags'   => Json::class,
    ];

    // ─────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────

    public function ride()
    {
        return $this->belongsTo(Ride::class, 'ride_uuid', 'uuid');
    }

    public function company()
    {
        return $this->belongsTo(\Fleetbase\Models\Company::class, 'company_uuid', 'uuid');
    }

    public function reviewer()
    {
        return $this->morphTo(__FUNCTION__, 'reviewer_type', 'reviewer_uuid');
    }

    public function reviewee()
    {
        return $this->morphTo(__FUNCTION__, 'reviewee_type', 'reviewee_uuid');
    }

    // ─────────────────────────────────────────────
    // Static Helpers
    // ─────────────────────────────────────────────

    /**
     * Get the average rating for a given entity.
     *
     * @param string $entityUuid  The UUID of the person being reviewed
     * @param string $entityType  'driver' or 'customer'
     *
     * @return float|null
     */
    public static function averageRatingFor(string $entityUuid, string $entityType): ?float
    {
        $avg = static::where('reviewee_uuid', $entityUuid)
            ->where('reviewee_type', $entityType)
            ->avg('rating');

        return $avg ? round($avg, 1) : null;
    }

    /**
     * Get the total review count for a given entity.
     */
    public static function reviewCountFor(string $entityUuid, string $entityType): int
    {
        return static::where('reviewee_uuid', $entityUuid)
            ->where('reviewee_type', $entityType)
            ->count();
    }

    /**
     * Sync the calculated stats to the reviewee's meta column.
     */
    public static function syncStats(string $revieweeUuid, string $revieweeType): void
    {
        $avg = static::averageRatingFor($revieweeUuid, $revieweeType);
        $count = static::reviewCountFor($revieweeUuid, $revieweeType);

        $model = ($revieweeType === 'driver')
            ? \Fleetbase\FleetOps\Models\Driver::where('uuid', $revieweeUuid)->first()
            : \Fleetbase\FleetOps\Models\Contact::where('uuid', $revieweeUuid)->first();

        if ($model) {
            $meta = $model->meta ?? [];
            $meta['rating'] = $avg;
            $meta['reviews_count'] = $count;

            // If the reviewee is a driver, also sync their total completed rides
            if ($revieweeType === 'driver') {
                $completedRidesCount = \Hopper\Rides\Models\Ride::where('driver_uuid', $revieweeUuid)
                    ->where('status', \Hopper\Rides\Models\Ride::STATUS_COMPLETED)
                    ->count();
                $meta['completed_rides_count'] = $completedRidesCount;
            }

            $model->update(['meta' => $meta]);
        }
    }
}
