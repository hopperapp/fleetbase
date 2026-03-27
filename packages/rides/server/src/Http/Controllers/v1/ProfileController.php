<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\RideReview;
use Hopper\Rides\Http\Resources\v1\ReviewResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Get review history for a specific profile (driver or customer).
     */
    public function reviews(Request $request, string $type, string $id)
    {
        if (!in_array($type, ['driver', 'customer'])) {
            return response()->error('Invalid profile type.', 400);
        }

        // We use public_id to find the internal UUID
        $model = ($type === 'driver')
            ? \Fleetbase\FleetOps\Models\Driver::where(function ($q) use ($id) { $q->where('public_id', $id)->orWhere('uuid', $id); })->firstOrFail()
            : \Fleetbase\FleetOps\Models\Contact::where(function ($q) use ($id) { $q->where('public_id', $id)->orWhere('uuid', $id); })->firstOrFail();

        $reviews = RideReview::where('reviewee_uuid', $model->uuid)
            ->where('reviewee_type', $type)
            ->with(['reviewer']) // Eager load the reviewer for name/photo
            ->latest()
            ->paginate($request->input('limit', 15));

        return ReviewResource::collection($reviews);
    }
}
