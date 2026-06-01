<?php

/**
 * Sample configuration file. Copy to config.php and edit for your environment.
 */

return [
    // Target WordPress site URL
    'wp_site' => 'https://example.com',

    // Address to connect to. When set, the host is pinned to this address instead of being resolved
    // over DNS, and SSL verification is skipped (the address need not match the certificate).
    // E.g. '127.0.0.1' for an instance on the same server. Leave empty to resolve the host over DNS.
    'wp_addr' => '',

    // Listing fetch limit per post type, to keep large sites manageable: up to max_pages_per_type pages
    // of posts_per_page items each (newest first). When a type has more, the listing notes it is truncated.
    'posts_per_page'     => 100, // items per page (1-100; WordPress caps per_page at 100)
    'max_pages_per_type' => 2,   // pages fetched per type

    // REST API connection timeout, in seconds (default 15)
    'timeout' => 15,

    // Max parallel REST API requests (default 4, clamped to 1-8)
    'concurrency' => 4,

    // Timezone for displaying dates: IANA name, abbreviation, or offset ('Asia/Tokyo', 'JST', '+0900')
    // Omit to use the server's timezone
    // 'timezone' => 'Asia/Tokyo',

    // Listing font (CSS font-family); multi-word names are quoted automatically (default sans-serif)
    // 'font_family' => ['Helvetica', 'Arial', 'sans-serif'],

    // Items to hide from the listing. Each rule is a delimited regex (e.g. '/^wp_/') or an exact string.
    'exclude_type_slugs' => ['/^wp_/', '/^nav_/', 'attachment'], // post type slugs
    'exclude_type_names' => [],                                  // post type names
    'exclude_titles'     => [],                                  // post titles
    'exclude_ids'        => [],                                  // post IDs (exact integers, e.g. [12, 34])

    // Date shown after each title in the listing: 'full', 'date-only', or 'none' (default)
    'show_date' => 'none',

    // Output the REST API endpoint URL in the front matter (default false)
    'show_api_endpoint' => false,

    // <form> handling: 'keep' = leave as HTML block (default), 'remove' = emit <form /> only
    'form_handling' => 'keep',
];
