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
  'data_store_id' => env('PCC_DISCOVERY_DATA_STORE_ID', 'pcc-website_1767107779222'),

  // Regional endpoint for locations/us
  // Using us-discoveryengine.googleapis.com is commonly required for us multi-region resources.
  'api_host'   => env('PCC_DISCOVERY_API_HOST', 'https://us-discoveryengine.googleapis.com'),
  'recrawl_api_host' => env('PCC_DISCOVERY_RECRAWL_API_HOST', 'https://us-discoveryengine.googleapis.com'),

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

  // Protected scheduler-triggered recrawl jobs
  'scheduler' => [
    'secret' => env('PCC_SCHEDULER_SECRET', ''),
    'header_name' => env('PCC_SCHEDULER_SECRET_HEADER', 'X-Scheduler-Secret'),
  ],

  'recrawl' => [
    'jobs' => [
      'daily_crawl' => [
        'https://pasadena.edu/',
        'https://pasadena.edu/current-students/guide-to-spring.php',
        'https://pasadena.edu/future-students/winter.php',
        'https://pasadena.edu/current-students/guide-to-fall.php',
        'https://pasadena.edu/current-students/guide-to-summer.php',
        'https://pasadena.edu/calendars/index.php',
        'https://pasadena.edu/calendars/registration.php',
        'https://pasadena.edu/calendars/exam-calendars.php',
        'https://pasadena.edu/calendars/academic.php',
      ],
    ],
  ],

  // Optional Google Sheets webhook logging (Apps Script web app URL)
  'google_sheets_log' => [
    // File-based defaults are set so this can run even when env vars are unavailable.
    'enabled' => env('PCC_GSHEETS_LOG_ENABLED', '1') === '1',
    'webhook_url' => env(
      'PCC_GSHEETS_WEBHOOK_URL',
      'https://script.google.com/macros/s/AKfycbw4YfZzyO_g6ZeibQVtfI5v4qU6dnHrHjTe_noYJ5oPk-hAvYnOl-VIXA_3RreN0wZucA/exec'
    ),
    'webhook_token' => env(
      'PCC_GSHEETS_WEBHOOK_TOKEN',
      '1287c1d1db625ffe6dcbd46aa264db1d93b6b39524b21c9be925a4e707de4ea6'
    ),
    'timeout_seconds' => (int) env('PCC_GSHEETS_TIMEOUT_SECONDS', '3'),
  ],
];
