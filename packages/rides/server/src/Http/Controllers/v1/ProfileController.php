<?php

namespace Hopper\Rides\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Hopper\Rides\Models\RideReview;
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
            ? \Fleetbase\FleetOps\Models\Driver::where('public_id', $id)->firstOrFail()
            : \Fleetbase\FleetOps\Models\Contact::where('public_id', $id)->firstOrFail();

        $reviews = RideReview::where('reviewee_uuid', $model->uuid)
            ->where('reviewee_type', $type)
            ->with(['ride'])
            ->latest()
            ->paginate($request->input('limit', 15));

        return response()->json([
            'reviews' => $reviews->items(),
            'meta'    => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'total'        => $reviews->total(),
            ]
        ]);
    }
}
