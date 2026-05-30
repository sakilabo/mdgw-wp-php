<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/SiteRequest.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/converter/LanguagePreConverter.php';
require_once __DIR__ . '/converter/HtmlTableConverter.php';
require_once __DIR__ . '/converter/BlockConverter.php';
require_once __DIR__ . '/converter/FormConverter.php';

use League\HTMLToMarkdown\HtmlConverter;

// Load the configuration
$config = load_config();

// Get the query parameters
$rest_base = $_GET['rest_base'] ?? null;
$page_id = intval($_GET['id'] ?? 0);
$url = $_GET['url'] ?? null;
$raw_mode = isset($_GET['raw']);

// When a url is given, get rest_base and id from that page's link tag
if (!empty($url)) {
    $url = urldecode($url);
    // Fetch the page
    // If a url outside wp_site is given, SiteRequest::get throws HttpException(400)
    $res = SiteRequest::get($url);
    // Follow a redirect (once only)
    if (!$res->success && isset($res->headers['location'])) {
        $res = SiteRequest::get($res->headers['location']);
    }
    // Abort if the page could not be fetched
    if (!$res->success) {
        throw new HttpException(t('page_fetch_failed'), HTTP_BAD_GATEWAY);
    }
    $html = $res->body;
    // Parse the page HTML and get rest_base and id from the link tag
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $link = $dom->getElementsByTagName('link');
    foreach ($link as $element) {
        if ($element->getAttribute('rel') === 'alternate' && $element->getAttribute('title') === 'JSON') {
            $href = $element->getAttribute('href');
            // Confirm href is of the form /wp-json/wp/v2/{rest_base}/{id} and extract the values
            if (preg_match('/\/wp-json\/wp\/v2\/([^\/]+)\/(\d+)/', $href, $m)) {
                $rest_base = $m[1];
                $page_id = $m[2];
                break;
            }
        }
    }
}

// If rest_base or page_id is empty, redirect to the top page (the same directory as this script)
if (empty($rest_base) || empty($page_id) || $page_id <= 0) {
    header('Location: ./', true, HTTP_SEE_OTHER);
    exit;
}

// Fetch the post
$api_url = $config['wp_site'] . '/wp-json/wp/v2/' . $rest_base . '/' . $page_id . '?_embed=author,wp:term';
$res = SiteRequest::get($api_url);
if (!$res->success) {
    throw new HttpException(t('post_fetch_failed'), HTTP_BAD_GATEWAY);
}
$post = json_decode($res->body, true);
if (!$post) {
    throw new HttpException(t('post_parse_failed'), HTTP_BAD_GATEWAY);
}
$content = $post['content']['rendered'] ?? '';
// Normalize line endings to LF
$content = str_replace(["\r\n", "\r"], "\n", $content);
// Put the title on one line
$title = trim(preg_replace('/\s+/u', ' ', $post['title']['rendered'] ?? ''));

// Convert the post HTML to Markdown
if (!$raw_mode) {
    // Replace Font Awesome icons (<i class="fa ..."></i>) with ■
    $content = preg_replace(
        '/<i\b[^>]*\bclass\s*=\s*["\'][^"\']*\bfa[bsrld]?\b[^"\']*["\'][^>]*>\s*<\/i>/i',
        '■',
        $content
    );
    // Convert the HTML to Markdown with html-to-markdown
    $converter = new HtmlConverter([
        'strip_tags'   => true,    // Keep only the contents of tags that cannot be converted
        'remove_nodes' => 'script style',
        'hard_break'   => true,    // Treat line breaks as Markdown line breaks
        'header_style' => 'atx',   // Use the # style for headings
    ]);
    // Keep tables as HTML tags instead of converting them to pipe tables
    $converter->getEnvironment()->addConverter(new HtmlTableConverter());
    // Convert <pre class="mermaid"> and friends into code fences starting with ```mermaid
    $converter->getEnvironment()->addConverter(new LanguagePreConverter());
    // Separate block-level tags the library drops, such as figure/details/summary
    $converter->getEnvironment()->addConverter(new BlockConverter());
    // Handle <form> per config: 'keep' (default) leaves it as an HTML block, 'remove' emits <form />
    $converter->getEnvironment()->addConverter(new FormConverter($config['form_handling'] ?? 'keep'));
    $markdown = $converter->convert($content);
    // Tidy the URLs inside links (in parentheses) into a readable form
    $markdown = preg_replace_callback('/\]\((https?:\/\/.+?)\)/', function ($m) {
        return '](' . prettify_url($m[1]) . ')';
    }, $markdown);
    $content = '# ' . $title . "\n\n" . $markdown;
    // Get the author's name and description from the embedded author data, if available
    $post['author_name'] = $post['_embedded']['author'][0]['name'] ?? '';
    $author_description = $post['_embedded']['author'][0]['description'] ?? '';
    $post['author_description'] = trim(preg_replace('/\s+/u', ' ', $author_description));
}

// Collapse consecutive blank lines (including whitespace-only lines) into a single blank line.
// Only whitespace-only lines are targeted, so the next line's leading indentation is not consumed.
$content = preg_replace('/(?:[ \t]*\n){2,}/', "\n\n", $content);

// Output the headers
header('Content-Type: text/plain; charset=UTF-8');
// Output the YAML Front Matter for Markdown output
if (!$raw_mode) {
    $date = format_datetime($post['date_gmt'] ?? '', $config['timezone'] ?? null);
    echo "---\n";
    echo 'post_id: ' . format_yaml_value($post['id']) . "\n";
    echo 'date: ' . format_yaml_value($date) . "\n";
    echo 'title: ' . format_yaml_value($title) . "\n";
    foreach (extract_terms_by_taxonomy($post) as $tax => $names) {
        echo $tax . ': ' . format_yaml_value($names) . "\n";
    }
    if ($post['author_name'] != '') {
        echo 'author: ' . format_yaml_value($post['author_name']) . "\n";
        if ($post['author_description'] != '') {
            echo 'author_description: ' . format_yaml_value($post['author_description']) . "\n";
        }
    }
    echo 'permalink: ' . format_yaml_value($post['link'] ?? '') . "\n";
    // Output the REST API endpoint only when enabled in config (default off)
    if ($config['show_api_endpoint'] ?? false) {
        echo 'api_endpoint: ' . format_yaml_value($api_url) . "\n";
    }
    echo "---\n\n";
}
// Output the content
echo $content;
