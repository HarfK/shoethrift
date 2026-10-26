<?php

return [

    /*
    | Flat shipping fee in RM, added to every order at checkout (per the proposal's cart wireframe).
    | The fee charged is stored on each order, so changing this never affects past orders.
    */
    'shipping_fee' => 10.00,

    /*
    | Cash on Delivery limits (see App\Services\Checkout\CashOnDeliveryPolicy).
    | - max_failed_deliveries: customers with this many failed COD deliveries must pay online.
    | - max_total: largest order total (RM) allowed for COD; null/empty = no limit.
    */
    'cod' => [
        'max_failed_deliveries' => (int) env('SHOP_COD_MAX_FAILED_DELIVERIES', 2),
        'max_total' => is_numeric(env('SHOP_COD_MAX_TOTAL')) ? (float) env('SHOP_COD_MAX_TOTAL') : null,
    ],

    /*
    | Upper materials an admin can choose for a product (validated with Rule::in; shop filter).
    */
    'materials' => ['Leather', 'Suede', 'Canvas', 'Mesh / Knit', 'Synthetic', 'Rubber', 'Other'],

    /*
    | Malaysian states and federal territories for addresses (validated with Rule::in).
    */
    'states' => [
        'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis',
        'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu',
        'W.P. Kuala Lumpur', 'W.P. Labuan', 'W.P. Putrajaya',
    ],

    // Address book size limit per customer.
    'max_addresses' => 10,

    /*
    | Low stock (admin dashboard + notification): a product that started with more pairs than
    | this and is now down to this many or fewer. Single one-of-a-kind pairs are never "low".
    */
    'low_stock_threshold' => (int) env('SHOP_LOW_STOCK_THRESHOLD', 2),

    /*
    | Shown on the Customer Service, Shipping and Return pages. Placeholder details for the FYP:
    | replace with the real business contact before going live.
    */
    'support' => [
        'email' => env('SHOP_SUPPORT_EMAIL', 'support@shoethrift.test'),
        'phone' => env('SHOP_SUPPORT_PHONE', '03-1234 5678'),
        'hours' => 'Monday to Friday, 9 am to 6 pm (except public holidays)',
        'reply_within' => '1 to 2 working days',
    ],

    'delivery' => [
        'dispatch' => '1 to 2 working days',
        'west_malaysia' => '2 to 4 working days',
        'east_malaysia' => '4 to 7 working days',
    ],

    /*
    | Returns (App\Models\Order::returnUnavailableReason()): requests are accepted for paid,
    | delivered orders within window_days of delivery (the wireframe's "7 Days Return").
    */
    'returns' => [
        'window_days' => (int) env('SHOP_RETURN_WINDOW_DAYS', 7),
        'max_photos' => 3,
        'address' => "ShoeThrift Returns\nNo. 8, Jalan Kasut 3\n40150 Shah Alam, Selangor",
    ],

];
