<?php
// config/config.php

function env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  return ($v === false || $v === '') ? $default : $v;
}

return [
  // Required (your values)
  'project_id' => env('PCC_GCP_PROJECT_ID', 'pcc-success-cent-1530898874821'),
  'location'   => env('PCC_DISCOVERY_LOCATION', 'us'),
  'engine_id'  => env('PCC_DISCOVERY_ENGINE_ID', 'pcc-test-ai-agent-search_1767107861879'),

  // Regional endpoint for locations/us
  // Using us-discoveryengine.googleapis.com is commonly required for us multi-region resources.
  'api_host'   => env('PCC_DISCOVERY_API_HOST', 'https://us-discoveryengine.googleapis.com'),

  // Service account JSON path (server-side only)
  'service_account_json' => env('GOOGLE_APPLICATION_CREDENTIALS', __DIR__ . '/service-account.json'),

  // CORS allowlist for embedding in Modern Campus / other domains
  // Add your production domains here.
  'cors_allow_origins' => array_filter(array_map('trim', explode(',', env('PCC_CORS_ORIGINS', '')))),

  // Rate limiting (simple token bucket by IP)
  'rate' => [
    'enabled' => env('PCC_RATE_ENABLED', '1') === '1',
    'capacity' => (int) env('PCC_RATE_CAPACITY', '20'),           // burst
    'refill_per_min' => (int) env('PCC_RATE_REFILL_PER_MIN', '20') // sustained
  ],

  // Caching (filesystem)
  'cache' => [
    'enabled' => env('PCC_CACHE_ENABLED', '1') === '1',
    'ttl_seconds' => (int) env('PCC_CACHE_TTL_SECONDS', '300'), // 5 minutes
  ],

  // Debug controls
  'debug' => [
    'enabled' => env('PCC_DEBUG', '0') === '1',
    'return_raw' => env('PCC_DEBUG_RETURN_RAW', '0') === '1',
  ],
];
