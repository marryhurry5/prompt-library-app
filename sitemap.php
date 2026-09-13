<?php
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/db.php';

// Base URL of your library page
$base_page_url = "https://rtmcreator.com/bots/prompt-bot/library.php";

header("Content-Type: application/xml; charset=utf-8");

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

// 1. The Main Library Page
echo '  <url>' . PHP_EOL;
echo '    <loc>' . htmlspecialchars($base_page_url) . '</loc>' . PHP_EOL;
echo '    <changefreq>daily</changefreq>' . PHP_EOL;
echo '    <priority>1.0</priority>' . PHP_EOL;
echo '  </url>' . PHP_EOL;

// 2. All Individual Prompts (as query parameters for now, or dedicated pages)
global $pdo;
$stmt = $pdo->query("SELECT id, created_at FROM submissions WHERE status = 'approved' ORDER BY created_at DESC");
$prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($prompts as $p) {
    $url = $base_page_url . "?id=" . $p['id'];
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . htmlspecialchars($url) . '</loc>' . PHP_EOL;
    echo '    <lastmod>' . date('c', strtotime($p['created_at'])) . '</lastmod>' . PHP_EOL;
    echo '    <changefreq>monthly</changefreq>' . PHP_EOL;
    echo '    <priority>0.8</priority>' . PHP_EOL;
    echo '  </url>' . PHP_EOL;
}

echo '</urlset>' . PHP_EOL;
?>
