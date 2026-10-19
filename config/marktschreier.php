<?php

return [
    'shopify' => [
        // z. B. johtomarket.myshopify.com
        'shop' => env('SHOPIFY_SHOP'),
        // Entweder: App aus dem Dev Dashboard (seit 2026 Standard), Token holt Marktschreier selbst
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        // Oder: festes Admin-API-Token einer älteren, im Shopify-Admin angelegten App (shpat_…)
        'token' => env('SHOPIFY_ADMIN_TOKEN'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),
    ],
    'billbee' => [
        'api_key' => env('BILLBEE_API_KEY'),
        'username' => env('BILLBEE_USERNAME'),
        'password' => env('BILLBEE_API_PASSWORD'),
        'base_url' => env('BILLBEE_BASE_URL', 'https://api.billbee.io/api/v1'),
        // Ob "CostPrice" in Billbee netto oder brutto gepflegt ist: net | gross
        'cost_price_mode' => env('BILLBEE_COST_PRICE_MODE', 'net'),
        'vat_rate' => (float) env('BILLBEE_DEFAULT_VAT', 19),
    ],
    'sync' => [
        'billbee_interval_minutes' => (int) env('BILLBEE_SYNC_MINUTES', 15),
        'shopify_interval_minutes' => (int) env('SHOPIFY_SYNC_MINUTES', 60),
    ],
];
