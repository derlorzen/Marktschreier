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
    // Entwickler-Apps für alle Instanzen gemeinsam. Jeder Kunde verbindet sein eigenes Verkäuferkonto.
    'ebay' => [
        'client_id' => env('EBAY_CLIENT_ID'),
        'client_secret' => env('EBAY_CLIENT_SECRET'),
        // RuName der App, im eBay-Entwicklerkonto mit der Rücksprung-URL dieser Instanz angelegt
        'runame' => env('EBAY_RUNAME'),
        'sandbox' => (bool) env('EBAY_SANDBOX', false),
        'site_id' => 77,                 // eBay.de
        'marketplace_id' => 'EBAY_DE',
        'compatibility_level' => 1349,
    ],
    'amazon' => [
        'app_id' => env('AMAZON_APP_ID'),              // amzn1.sp.solution… bzw. amzn1.sellerapps.app…
        'client_id' => env('AMAZON_LWA_CLIENT_ID'),
        'client_secret' => env('AMAZON_LWA_CLIENT_SECRET'),
        'draft' => (bool) env('AMAZON_APP_DRAFT', true), // Entwurfs-Apps brauchen version=beta
        'endpoint' => env('AMAZON_SPAPI_ENDPOINT', 'https://sellingpartnerapi-eu.amazon.com'),
        'seller_central' => env('AMAZON_SELLER_CENTRAL', 'https://sellercentral-europe.amazon.com'),
        'marketplace_id' => env('AMAZON_MARKETPLACE_ID', 'A1PA6795UKMFR9'), // amazon.de
    ],
    // Amazon Werbung (Advertising API), eigenes LWA-Sicherheitsprofil mit Freigabe für die Ads API
    'amazon_ads' => [
        'client_id' => env('AMAZON_ADS_CLIENT_ID', env('AMAZON_LWA_CLIENT_ID')),
        'client_secret' => env('AMAZON_ADS_CLIENT_SECRET', env('AMAZON_LWA_CLIENT_SECRET')),
        'endpoint' => env('AMAZON_ADS_ENDPOINT', 'https://advertising-api-eu.amazon.com'),
        'authorize_url' => 'https://eu.account.amazon.com/ap/oa',
        'token_url' => 'https://api.amazon.co.uk/auth/o2/token',
    ],
    // Optional: Textvorschläge über die Claude API oder ChatGPT (OpenAI)
    'ai' => [
        // anthropic | openai; leer heißt: der Anbieter, für den ein Schlüssel da ist (Claude zuerst)
        'provider' => env('AI_PROVIDER'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'openai_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('OPENAI_MODEL', 'gpt-6-luna'),
    ],
    'tiktok' => [
        'app_key' => env('TIKTOK_APP_KEY'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        // Service-ID aus dem TikTok Shop Partner Center; ohne sie wird die ältere Autorisierungs-Seite genutzt
        'service_id' => env('TIKTOK_SERVICE_ID'),
        'api' => 'https://open-api.tiktokglobalshop.com',
        'auth' => 'https://auth.tiktok-shops.com',
        'version' => '202309',
    ],
    'sync' => [
        'billbee_interval_minutes' => (int) env('BILLBEE_SYNC_MINUTES', 15),
        'shopify_interval_minutes' => (int) env('SHOPIFY_SYNC_MINUTES', 60),
        'orders_interval_minutes' => (int) env('BILLBEE_ORDERS_MINUTES', 30),
        'listings_interval_minutes' => (int) env('LISTINGS_SYNC_MINUTES', 60),
    ],
];
