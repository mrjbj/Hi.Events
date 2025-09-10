<?php
// Temporary script to recalculate event statistics
// Usage: Run this inside your Docker container with PHP available

require_once __DIR__ . '/bootstrap/app.php';

use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\EventStatisticRepositoryInterface;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;

$app = app();

echo "=== Event Statistics Recalculator ===\n";

if ($argc < 2) {
    echo "Usage: php temp_recalculate_stats.php <event_id>\n";
    echo "Example: php temp_recalculate_stats.php 123\n";
    exit(1);
}

$eventId = (int)$argv[1];

try {
    $orderRepo = $app->make(OrderRepositoryInterface::class);
    $statsRepo = $app->make(EventStatisticRepositoryInterface::class);
    
    // Get all COMPLETED orders for this event
    $completedOrders = $orderRepo->findWhere([
        'event_id' => $eventId,
        'status' => OrderStatus::COMPLETED->name
    ]);
    
    echo "Found " . $completedOrders->count() . " completed orders for event $eventId\n";
    
    if ($completedOrders->isEmpty()) {
        echo "No completed orders found. Nothing to recalculate.\n";
        exit(0);
    }
    
    // Calculate totals from actual orders
    $totalGross = 0;
    $totalBeforeAdditions = 0;
    $totalTax = 0;
    $totalFee = 0;
    $totalProductsSold = 0;
    $totalAttendeesRegistered = 0;
    $totalOrdersCreated = $completedOrders->count();
    
    foreach ($completedOrders as $order) {
        // Load order items
        $orderWithItems = $orderRepo->loadRelation(OrderItemDomainObject::class)->findById($order->getId());
        
        $totalGross += $orderWithItems->getTotalGross();
        $totalBeforeAdditions += $orderWithItems->getTotalBeforeAdditions();
        $totalTax += $orderWithItems->getTotalTax();
        $totalFee += $orderWithItems->getTotalFee();
        
        if ($orderWithItems->getOrderItems()) {
            $totalProductsSold += $orderWithItems->getOrderItems()
                ->sum(fn($orderItem) => $orderItem->getQuantity());
            
            $totalAttendeesRegistered += $orderWithItems->getTicketOrderItems()
                ->sum(fn($orderItem) => $orderItem->getQuantity());
        }
    }
    
    echo "\nCalculated totals:\n";
    echo "- Gross Sales: $totalGross\n";
    echo "- Before Additions: $totalBeforeAdditions\n";
    echo "- Total Tax: $totalTax\n";
    echo "- Total Fee: $totalFee\n";
    echo "- Products Sold: $totalProductsSold\n";
    echo "- Attendees Registered: $totalAttendeesRegistered\n";
    echo "- Orders Created: $totalOrdersCreated\n";
    
    // Get current statistics
    $currentStats = $statsRepo->findFirstWhere(['event_id' => $eventId]);
    
    if ($currentStats) {
        echo "\nCurrent statistics in database:\n";
        echo "- Gross Sales: " . $currentStats->getSalesTotalGross() . "\n";
        echo "- Before Additions: " . $currentStats->getSalesTotalBeforeAdditions() . "\n";
        echo "- Total Tax: " . $currentStats->getTotalTax() . "\n";
        echo "- Total Fee: " . $currentStats->getTotalFee() . "\n";
        echo "- Products Sold: " . $currentStats->getProductsSold() . "\n";
        echo "- Attendees Registered: " . $currentStats->getAttendeesRegistered() . "\n";
        echo "- Orders Created: " . $currentStats->getOrdersCreated() . "\n";
        echo "- Total Refunded: " . $currentStats->getTotalRefunded() . "\n";
        
        echo "\nDifferences:\n";
        echo "- Gross Sales diff: " . ($totalGross - $currentStats->getSalesTotalGross()) . "\n";
        echo "- Orders Created diff: " . ($totalOrdersCreated - $currentStats->getOrdersCreated()) . "\n";
        
        // Update the statistics
        echo "\nUpdating statistics...\n";
        $statsRepo->updateWhere(
            attributes: [
                'sales_total_gross' => $totalGross,
                'sales_total_before_additions' => $totalBeforeAdditions,
                'total_tax' => $totalTax,
                'total_fee' => $totalFee,
                'products_sold' => $totalProductsSold,
                'attendees_registered' => $totalAttendeesRegistered,
                'orders_created' => $totalOrdersCreated,
                'version' => $currentStats->getVersion() + 1,
            ],
            where: ['event_id' => $eventId]
        );
        
        echo "Statistics updated successfully!\n";
    } else {
        echo "\nNo existing statistics found. Creating new record...\n";
        $statsRepo->create([
            'event_id' => $eventId,
            'sales_total_gross' => $totalGross,
            'sales_total_before_additions' => $totalBeforeAdditions,
            'total_tax' => $totalTax,
            'total_fee' => $totalFee,
            'products_sold' => $totalProductsSold,
            'attendees_registered' => $totalAttendeesRegistered,
            'orders_created' => $totalOrdersCreated,
            'total_refunded' => 0,
        ]);
        
        echo "New statistics record created successfully!\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}