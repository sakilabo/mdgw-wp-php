<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/SiteRequest.php';

$html = isset($_GET['html']);

$is_agent = is_agent($_SERVER['HTTP_USER_AGENT'] ?? '');

// Load the configuration
$config = load_config();

// Get the site name (fall back to the host name if it cannot be retrieved)
$site_name = '';
$res = SiteRequest::get($config['wp_site'] . '/wp-json/?_fields=name,description');
if ($res->success) {
    $site = json_decode($res->body, true);
    $site_name = $site['name'] ?? '';
}
if ($site_name === '') {
    $site_name = parse_url($config['wp_site'], PHP_URL_HOST) ?? '';
}

// Get the list of post types
$res = SiteRequest::get($config['wp_site'] . '/wp-json/wp/v2/types');
if (!$res->success) {
    $detail = $res->error !== '' ? ' (' . $res->error . ')' : '';
    throw new HttpException(t('post_types_fetch_failed') . $detail, HTTP_BAD_GATEWAY);
}
$types = json_decode($res->body, true);
// Exclude post types configured in config.php
$types = array_filter($types, function ($type) use ($config) {
    // Exclude post types without a slug
    if (!isset($type['slug'])) {
        return false;
    }
    // Exclude post types whose slug matches a regex or is an exact match
    foreach ($config['exclude_type_slugs'] ?? [] as $rule) {
        $matched = is_regex($rule) ? preg_match($rule, $type['slug']) : ($type['slug'] === $rule);
        if ($matched) {
            return false;
        }
    }
    // Exclude post types whose name matches a regex or is an exact match
    foreach ($config['exclude_type_names'] ?? [] as $rule) {
        $name = $type['name'] ?? '';
        $matched = is_regex($rule) ? preg_match($rule, $name) : ($name === $rule);
        if ($matched) {
            return false;
        }
    }
    return true;
});

// Add content information to each post type. SiteRequest fetches in parallel and caps the
// number of in-flight requests itself, so we hand it all the URLs at once and let it pace them.
$raw_contents = []; // slug => accumulated raw post arrays
$queue = [];        // request key "slug|page" => url, for the remaining pages (any order)

// Phase 1: fetch the first page of every post type in parallel
$first_urls = [];
foreach ($types as $slug => $type) {
    if (isset($type['rest_base'])) {
        $first_urls[$slug] = build_collection_endpoint_url($config['wp_site'], $type['rest_base'], 1);
    } else {
        unset($types[$slug]);
    }
}
foreach (SiteRequest::get($first_urls) as $slug => $res) {
    if (!$res->success) {
        $detail = ' (slug: ' . $slug . ($res->error !== '' ? ', ' . $res->error : '') . ')';
        throw new HttpException(t('page_fetch_failed') . $detail, HTTP_BAD_GATEWAY);
    }
    $raw_contents[$slug] = json_decode($res->body, true) ?? [];
    // Inspect the page count and queue up the remaining pages
    $total_pages = isset($res->headers['x-wp-totalpages'])
        ? intval($res->headers['x-wp-totalpages'])
        : 1;
    for ($page = 2; $page <= $total_pages; $page++) {
        $queue[$slug . '|' . $page] = build_collection_endpoint_url($config['wp_site'], $types[$slug]['rest_base'], $page);
    }
}

// Phase 2: fetch every remaining page at once (SiteRequest limits the concurrency)
foreach (SiteRequest::get($queue) as $key => $res) {
    $slug = explode('|', $key, 2)[0];
    if (!$res->success) {
        $detail = ' (slug: ' . $slug . ($res->error !== '' ? ', ' . $res->error : '') . ')';
        throw new HttpException(t('page_fetch_failed') . $detail, HTTP_BAD_GATEWAY);
    }
    $response = json_decode($res->body, true) ?? [];
    $raw_contents[$slug] = array_merge($raw_contents[$slug], $response);
}

// Phase 3: post-process each post type once all requests have completed
$author_latest = []; // author id => the most recent post's date_gmt, across every post type
foreach ($types as $slug => $type) {
    $contents = $raw_contents[$slug] ?? [];
    $titles_by_id = [];
    // Prepare title and build a map of ID to title
    foreach ($contents as &$content) {
        // Flatten the title to one line
        $title = trim(preg_replace('/\s+/u', ' ', $content['title']['rendered'] ?? ''));
        $content['title'] = $title;
        $titles_by_id[$content['id']] = $content['title'];
    }
    unset($content);
    // Set the parent title
    foreach ($contents as &$content) {
        if (($content['parent'] ?? 0) > 0) {
            $content['parent'] = $titles_by_id[$content['parent']] ?? '';
        }
    }
    unset($content);
    // Exclude posts whose title matches a regex / is an exact match, or whose ID matches one configured in config.php
    $contents = array_filter($contents, function ($content) use ($config) {
        foreach ($config['exclude_titles'] ?? [] as $rule) {
            $rule = trim($rule);
            $matched = is_regex($rule) ? preg_match($rule, $content['title']) : ($content['title'] === $rule);
            if ($matched) {
                return false;
            }
        }
        $id = $content['id'] ?? null;
        $exclude_ids = array_map('intval', $config['exclude_ids'] ?? []);
        if ($id !== null && in_array((int) $id, $exclude_ids, true)) {
            return false;
        }
        return true;
    });
    // Sort contents by date_gmt in descending order
    usort($contents, function ($a, $b) {
        return strtotime($b['date_gmt'] ?? '') - strtotime($a['date_gmt'] ?? '');
    });
    // Prepare each content for rendering
    foreach ($contents as &$content) {
        // Flatten the embedded terms across every taxonomy into a single list of names
        $content['terms'] = array_merge([], ...array_values(extract_terms_by_taxonomy($content)));
        // Format the date according to the timezone and show_date settings
        $content['date'] = format_datetime($content['date_gmt'] ?? '', $config['timezone'] ?? null);
        $content['date_label'] = match ($config['show_date'] ?? 'none') {
            'full'      => $content['date'],                  // full datetime with offset
            'date-only' => explode(' ', $content['date'])[0], // date part only
            default     => '',                                // 'none' (or anything else)
        };
        $content['link'] = $content['link'] ?? '';
        $content['rest_base'] = $type['rest_base'];
        // Keep the author ID (resolved to a name below); track each author's most recent post
        $content['author_id'] = (int) ($content['author'] ?? 0);
        if ($content['author_id'] > 0) {
            $date_gmt = $content['date_gmt'] ?? '';
            if (!isset($author_latest[$content['author_id']]) || $date_gmt > $author_latest[$content['author_id']]) {
                $author_latest[$content['author_id']] = $date_gmt;
            }
        }
    }
    unset($content);
    // Add content information to the post type
    $types[$slug]['contents'] = $contents;
}

// Resolve the unique authors to names/descriptions via the users endpoint, newest poster first.
$authors = [];
if ($author_latest !== []) {
    // Order author IDs by their most recent post across all post types (descending)
    uasort($author_latest, fn($a, $b) => strtotime($b) <=> strtotime($a));
    $ids = array_keys($author_latest);
    // include= takes a comma-separated ID list; chunk it to the endpoint's 100-per-request cap
    // and fetch the chunks in parallel (SiteRequest paces the concurrency itself).
    $users_urls = [];
    foreach (array_chunk($ids, 100) as $chunk) {
        $users_urls[] = $config['wp_site'] . '/wp-json/wp/v2/users?include='
            . implode(',', $chunk) . '&per_page=100&_fields=id,name,description';
    }
    $users_by_id = [];
    foreach (SiteRequest::get($users_urls) as $res) {
        if ($res->success) {
            foreach (json_decode($res->body, true) ?? [] as $user) {
                $users_by_id[$user['id']] = $user;
            }
        }
    }
    // Keep the latest-post order; skip authors the users endpoint did not return
    foreach ($ids as $id) {
        $name = trim($users_by_id[$id]['name'] ?? '');
        if ($name !== '') {
            $authors[$id] = ['name' => $name, 'description' => $users_by_id[$id]['description'] ?? ''];
        }
    }
}

// The response body differs by User-Agent, so any cache must key on it.
header('Vary: User-Agent');
// Disallow indexing and following links from this page
header('X-Robots-Tag: noindex, nofollow', true);
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>MD Gateway - <?= htmlspecialchars($site_name) ?></title>
    <?php if (!$is_agent): ?>
        <style>
            html {
                margin: 0;
                padding: 0;
            }

            body {
                margin: 20px 30px;
                padding: 0;
                font-family: <?= build_css_font_family($config['font_family'] ?? null) ?>;
                font-size: 14px;
                line-height: 1.5;
            }

            #original-site-url {
                font-size: 16px;
                font-weight: bold;
                margin: 12px 0;
            }

            h1 {
                font-size: 30px;
                margin: 20px 0 12px;
            }

            h2 {
                font-size: 24px;
                margin: 18px 0 10px;
            }

            h3.author {
                font-size: 20px;
                margin: 10px 0 6px;
            }

            p {
                margin: 0.3em 0;
            }

            span.parent {
                font-size: 12px;
                vertical-align: 1px;
                color: #808080;
            }

            ul {
                margin: 0.3em 0;
                padding-left: 2em;
            }

            li small {
                font-size: 11px;
            }

            ul.details {
                display: none;
                margin: 0px 0 8px;
                padding-left: 1.5em;
                font-size: 12px;
            }

            button.details {
                padding: 1px 4px;
                font-size: 11px;
                cursor: pointer;
                color: #404040;
                background-color: #F4F4F4;
                border: 1px solid #C0C0C0;
                border-radius: 4px;
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const detailsButtons = document.querySelectorAll('button.details');
                detailsButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        const details = button.nextElementSibling;
                        if (details.style.display === 'none' || details.style.display === '') {
                            details.style.display = 'block';
                        } else {
                            details.style.display = 'none';
                        }
                    });
                });
            });
        </script>
    <?php endif; ?>
</head>

<body>
    <h1><?php echo htmlspecialchars($site_name); ?> - MD Gateway</h1>
    <div>
        <p>このページは <a href="https://github.com/sakilabo/mdgw-wp-php" target="_blank" rel="noopener noreferrer">MD Gateway for WordPress</a> によって生成されています。</p>
        <ul>
            <li>WordPress のコンテンツ一覧を AI 向けに整形表示しています。</li>
            <li>各投稿のリンクから Markdown 形式でコンテンツを参照できます。</li>
            <li>コンテンツの取得には WP REST API (WordPress の標準機能) を利用しています。</li>
            <li>MD Gateway の設定により、コンテンツの一部はフィルタリングされている場合があります。</li>
        </ul>
    </div>
    <div id="original-site-url">
        View original:
        <a href="<?= htmlspecialchars(normalize_url($config['wp_site'])) ?>" target="_blank" rel="noopener noreferrer">
            <?= htmlspecialchars(prettify_url($config['wp_site'])) ?>
        </a>
    </div>
    <main>
        <?php if (count($authors) > 0): ?>
            <div id="authors">
                <h2>Authors</h2>
                <?php foreach ($authors as $author): ?>
                    <h3 class="author"><?= htmlspecialchars($author['name']) ?></h3>
                    <?php if ($author['description'] !== ''): ?>
                        <div class="author-description">
                            <?= nl2br(htmlspecialchars($author['description'])) ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php foreach ($types as $type) : ?>
            <h2>[<?= $type['slug'] ?>] <?= htmlspecialchars($type['name']) ?></h2>
            <ul>
                <?php foreach ($type['contents'] as $content) : ?>
                    <li>
                        <?php $href = rawurlencode($content['rest_base']) . '/' . $content['id']; ?>
                        <?php
                        if ($html) {
                            $href .= '?html';
                        }
                        $title = htmlspecialchars($content['title']);
                        $show_terms = (count($content['terms']) > 0);
                        $show_date = (!$is_agent && $content['date_label']);
                        if ($show_terms || $show_date) {
                            $title .= ' <small>';
                            if ($show_terms) {
                                foreach ($content['terms'] as $term) {
                                    $title .= '[' . htmlspecialchars(trim($term)) . ']';
                                }
                            }
                            if ($show_date) {
                                if ($show_terms) {
                                    $title .= ' ';
                                }
                                $title .= '(' . htmlspecialchars($content['date_label']) . ')';
                            }
                            $title .= '</small>';
                        }
                        ?>
                        <?php if ($content['parent'] ?? false): ?>
                            <span class="parent"><?= htmlspecialchars($content['parent']) ?> ››</span>
                        <?php endif; ?>
                        <a href="<?= $href ?>"><?= $title ?></a>
                        <?php if (!$is_agent): ?>
                            <button class="details">
                                details
                            </button>
                        <?php endif; ?>
                        <ul class="details">
                            <li>Post ID: <?= $content['id'] ?></li>
                            <li>Date: <?= $content['date'] ?></li>
                            <?php $author = $authors[$content['author_id']] ?? null; ?>
                            <?php if (is_array($author)): ?>
                                <li>Author: <?= htmlspecialchars($author['name']) ?></li>
                            <?php endif; ?>
                            <?php if ($is_agent): ?>
                                <li>Markdown: <?= htmlspecialchars(get_absolute_url(rawurlencode($content['rest_base']) . '/' . $content['id'] . ($html ? '?html' : ''))) ?></li>
                                <li>Permalink: <?= htmlspecialchars($content['link']) ?></li>
                            <?php else: ?>
                                <li>Permalink: <a href="<?= htmlspecialchars($content['link']) ?>"><?= htmlspecialchars(prettify_url($content['link'])) ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </main>
</body>

</html>