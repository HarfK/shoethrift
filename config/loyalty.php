<?php

/*
|--------------------------------------------------------------------------
| Loyalty points (App\Services\Loyalty\PointsService)
|--------------------------------------------------------------------------
| Every rule lives here. Points are whole numbers; RM values are worked out in sen.
*/

return [
    // Earn: points per RM1 of item subtotal (shipping excluded), rounded down.
    'earn_per_ringgit' => 1,

    // Points are credited when the order becomes Completed (online: delivered; COD: cash collected).
    'credit_on_status' => 'completed',

    // Redeem in blocks: every 100 points = RM5 off.
    'redeem_block_points' => 100,
    'redeem_block_value' => 5.00,

    // At least this many points per redemption.
    'redeem_min_points' => 100,

    // The discount can't be more than this share of the item subtotal.
    'redeem_max_share' => 0.5,

    // Points expire this many months after they were earned (oldest points are used / expire first).
    'expiry_months' => 12,

    // "Points expiring soon" on My Points.
    'expiring_soon_days' => 30,
];
