<?php
// Internationalization: detect the client language and translate message keys.

declare(strict_types=1);

// Supported UI languages; the first entry is the default used when nothing else matches.
const SUPPORTED_LANGS = ['en', 'ja'];

// Determine the UI language from the browser's Accept-Language header.
// Returns the highest-priority supported language (by q-value, ties broken by header order),
// falling back to the default (SUPPORTED_LANGS[0], i.e. English) when none is requested.
function client_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $lang  = SUPPORTED_LANGS[0];
    $best_q = -1.0;
    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    // Parse e.g. "ja,en-US;q=0.9,en;q=0.8" and keep the highest-q supported primary subtag.
    foreach (explode(',', $header) as $part) {
        $tokens = explode(';', $part);
        $tag    = strtolower(trim($tokens[0]));
        // Reduce a region tag like "en-US" to its primary subtag "en".
        $primary = explode('-', $tag)[0];
        if (!in_array($primary, SUPPORTED_LANGS, true)) {
            continue;
        }
        // The quality value defaults to 1.0 unless an explicit q= is present.
        $q = 1.0;
        if (isset($tokens[1]) && preg_match('/q=([0-9.]+)/', $tokens[1], $m)) {
            $q = (float) $m[1];
        }
        // Strict > keeps the earlier (higher-priority) tag when q-values tie.
        if ($q > $best_q) {
            $best_q = $q;
            $lang   = $primary;
        }
    }
    return $lang;
}

// Translate a message key into the current client language.
// Falls back to English, then to the key itself, if a translation is missing.
// Extra arguments are interpolated into the template via vsprintf (e.g. "%s").
function t(string $key, mixed ...$args): string
{
    static $messages = [
        'server_error' => [
            'en' => 'A server error occurred.',
            'ja' => 'サーバーエラーが発生しました。',
        ],
        'config_missing' => [
            'en' => 'config.php not found. Copy config.sample.php to create your configuration.',
            'ja' => 'config.php がありません。config.sample.php をコピーして設定を作成してください。',
        ],
        'config_invalid' => [
            'en' => 'config.php is invalid. Copy config.sample.php and recreate your configuration.',
            'ja' => 'config.php が不正です。config.sample.php をコピーして設定を作り直してください。',
        ],
        'config_key_missing' => [
            'en' => 'Required setting "%s" is missing in config.php.',
            'ja' => 'config.php に必須の設定 "%s" がありません。',
        ],
        'config_wp_site_invalid' => [
            'en' => 'The wp_site value in config.php is invalid. Set a URL such as "https://example.com".',
            'ja' => 'config.php の wp_site が不正です。"https://example.com" のような URL を設定してください。',
        ],
        'post_types_fetch_failed' => [
            'en' => 'Failed to retrieve the post type information.',
            'ja' => '投稿タイプの情報を取得できませんでした。',
        ],
        'page_fetch_failed' => [
            'en' => 'Failed to fetch the page at the given url.',
            'ja' => '指定された url のページを取得できませんでした。',
        ],
        'post_fetch_failed' => [
            'en' => 'Failed to retrieve the post information.',
            'ja' => '投稿の情報を取得できませんでした。',
        ],
        'post_parse_failed' => [
            'en' => 'Failed to parse the post information.',
            'ja' => '投稿の情報を解析できませんでした。',
        ],
        'url_must_be_internal' => [
            'en' => 'The url must point to a page on this site.',
            'ja' => 'url にはサイト内の URL を指定してください。',
        ],
        'host_resolve_failed' => [
            'en' => 'Could not resolve the host "%s".',
            'ja' => 'ホスト "%s" の名前解決に失敗しました。',
        ],
        'showing_latest' => [
            'en' => 'Showing the latest %2$d of %1$d items.',
            'ja' => '全 %1$d 件中、最新 %2$d 件を表示しています。',
        ],
        'unreadable_types' => [
            'en' => 'Post types that could not be read',
            'ja' => '読み出せなかった投稿タイプ',
        ],
    ];
    $lang     = client_lang();
    $template = $messages[$key][$lang] ?? $messages[$key]['en'] ?? $key;
    return $args ? vsprintf($template, $args) : $template;
}
