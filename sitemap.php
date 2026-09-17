<?php
/**
 * Dynamic XML Sitemap Generator for Google Search Console & Bing Webmaster Tools
 * Generates valid Sitemaps.org 0.9 XML with Google Image extensions for approved AI prompts.
 */

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/db.php';

// Base URL configuration
if (!defined('BOT_BASE_URL')) {
    define('BOT_BASE_URL', 'https://rtmcreator.com/bots/prompt-bot/');
}

// HTTP Headers
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex'); // Do not index the sitemap XML itself, only its target URLs

global $pdo;

/**
 * Clean URL slug generator
 */
function pl_slugify($text, $fallback = 'prompt') {
    $text = strip_tags($text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    if (function_exists('iconv')) {
        $trans = @iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        if ($trans !== false) {
            $text = $trans;
        }
    }
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return !empty($text) ? substr($text, 0, 60) : $fallback;
}

$base_library = 'https://rtmcreator.com/prompt-library/';
$media_base = rtrim(BOT_BASE_URL, '/');

// Fetch all approved prompts
try {
    $stmt = $pdo->prepare("
        SELECT id, category, text_output, local_media, created_at
        FROM submissions
        WHERE status = 'approved'
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $prompts = [];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

// 1. Root Prompt Library Page
echo "  <url>\n";
echo "    <loc>" . htmlspecialchars($base_library) . "</loc>\n";
echo "    <changefreq>daily</changefreq>\n";
echo "    <priority>1.0</priority>\n";
echo "  </url>\n";

// 2. Individual Prompt Pages with Image Metadata
foreach ($prompts as $p) {
    $title = !empty($p['text_output']) ? $p['text_output'] : ($p['category'] . ' AI Prompt');
    $slug = pl_slugify($title, 'prompt');
    $prompt_url = $base_library . $p['id'] . '/' . $slug . '/';
    $lastmod = !empty($p['created_at']) ? date('Y-m-d', strtotime($p['created_at'])) : date('Y-m-d');

    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($prompt_url) . "</loc>\n";
    echo "    <lastmod>" . $lastmod . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";

    // Add Google Image Sitemap tag if prompt has local media images
    if (!empty($p['local_media'])) {
        $media = json_decode($p['local_media'], true);
        if (is_array($media)) {
            $img_urls = [];
            if (isset($media['thumb']) && $media['thumb']) {
                $img_urls[] = $media_base . '/' . ltrim($media['thumb'], '/');
            } elseif (!isset($media['video'])) {
                foreach ($media as $item) {
                    if (is_string($item) && $item) {
                        $img_urls[] = $media_base . '/' . ltrim($item, '/');
                    }
                }
            }
            foreach (array_slice($img_urls, 0, 3) as $img) {
                echo "    <image:image>\n";
                echo "      <image:loc>" . htmlspecialchars($img) . "</image:loc>\n";
                echo "      <image:title>" . htmlspecialchars($title) . "</image:title>\n";
                echo "    </image:image>\n";
            }
        }
    }

    echo "  </url>\n";
}

echo "</urlset>\n";
