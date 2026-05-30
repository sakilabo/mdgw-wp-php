<?php

/**
 * Sample configuration file. Copy to config.php and edit for your environment.
 */

return [
    // Target WordPress site URL
    'wp_site' => 'https://example.com',

    // true: WordPress is on the same server (access 127.0.0.1, skip SSL verification)
    'loopback' => false,

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
