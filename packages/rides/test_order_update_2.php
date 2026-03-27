<?php
use Fleetbase\FleetOps\Models\Order;

$orderPublicId = 'order_rvllQQk';
$order = Order::where('public_id', $orderPublicId)->first();

if (!$order) {
    echo "ORDER NOT FOUND\n";
    exit;
}

echo "Current status: " . $order->status . "\n";
echo "Original status: " . $order->getOriginal('status') . "\n";
echo "Is Dirty Status? " . ($order->isDirty('status') ? 'YES' : 'NO') . "\n";

$order->status = 'started';

echo "After manual set:\n";
echo "New status: " . $order->status . "\n";
echo "Original status: " . $order->getOriginal('status') . "\n";
echo "Is Dirty Status? " . ($order->isDirty('status') ? 'YES' : 'NO') . "\n";

$order->save();

$order->refresh();
echo "After save and refresh:\n";
echo "Final status: " . $order->status . "\n";
echo "Final started: " . ($order->started ? 'YES' : 'NO') . "\n";
