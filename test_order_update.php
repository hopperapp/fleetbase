<?php
use Fleetbase\FleetOps\Models\Order;

$orderPublicId = 'order_rvllQQk';
$order = Order::where('public_id', $orderPublicId)->first();

if (!$order) {
    echo "ORDER NOT FOUND\n";
    exit;
}

echo "Current status: " . $order->status . "\n";
echo "Current started: " . ($order->started ? 'YES' : 'NO') . "\n";

$result = $order->updateStatus('started');

echo "Update status result: " . ($result ? 'SUCCESS' : 'FAILURE') . "\n";

$order->refresh();
echo "New status: " . $order->status . "\n";
echo "New started: " . ($order->started ? 'YES' : 'NO') . "\n";
echo "New started_at: " . $order->started_at . "\n";
