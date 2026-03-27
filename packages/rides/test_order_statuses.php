<?php
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingStatus;

$orderPublicId = 'order_rvllQQk';
$order = Order::where('public_id', $orderPublicId)->first();

if (!$order) {
    echo "ORDER NOT FOUND\n";
    exit;
}

echo "Order UUID: " . $order->uuid . "\n";
echo "Tracking Number UUID: " . $order->tracking_number_uuid . "\n";

$statuses = TrackingStatus::where('tracking_number_uuid', $order->tracking_number_uuid)->get();
echo "Total statuses: " . $statuses->count() . "\n";
foreach ($statuses as $s) {
    echo "- " . $s->code . " (" . $s->status . ")\n";
}
