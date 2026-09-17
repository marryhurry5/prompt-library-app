<?php
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/db.php';

// Base URL for media
if (!defined('BOT_BASE_URL')) {
    define('BOT_BASE_URL', 'https://rtmcreator.com/bots/prompt-bot/');
}

// Fetch first 12 prompts for SSR based on query filters (SEO/Search engine friendly)
global $pdo;

$where = ["s.status = 'approved'"];
$params = [];

if (!empty($_GET['category']) && $_GET['category'] !== 'all') {
    $where[] = "s.category = :category";
    $params[':category'] = $_GET['category'];
}

if (!empty($_GET['creator'])) {
    $creator = trim($_GET['creator']);
    if (strpos($creator, '@') === 0) {
        $creator = substr($creator, 1);
    }
    if (is_numeric($creator)) {
        $where[] = "u.telegram_id = :creator";
        $params[':creator'] = (int)$creator;
    } else {
        $where[] = "(u.username = :creator OR u.username = :creator_at)";
        $params[':creator'] = $creator;
        $params[':creator_at'] = '@' . $creator;
    }
}

if (!empty($_GET['search'])) {
    $search = '%' . trim($_GET['search']) . '%';
    $where[] = "(s.prompt LIKE :search OR s.text_output LIKE :search OR s.tags LIKE :search)";
    $params[':search'] = $search;
}

$where_clause = implode(" AND ", $where);

$stmt = $pdo->prepare("
    SELECT s.id, s.category, s.output_type, s.tags, s.prompt, s.text_output, s.local_media, s.is_challenge, s.created_at, u.username
    FROM submissions s
    JOIN users u ON s.telegram_id = u.telegram_id
    WHERE $where_clause
    ORDER BY s.created_at DESC
    LIMIT 12
");
foreach ($params as $key => $val) {
    if ($key === ':creator' && is_numeric($val)) {
        $stmt->bindValue($key, $val, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
}
$stmt->execute();
$ssr_prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($ssr_prompts as &$p) {
    $raw_media = trim($p['local_media'] ?? '');
    $base = rtrim(BOT_BASE_URL, '/');
    $p['image_urls'] = [];
    $p['video_url'] = null;

    if (!empty($raw_media)) {
        $local_data = json_decode($raw_media, true);
        if (is_array($local_data)) {
            if (isset($local_data['video'])) {
                if (!empty($local_data['thumb'])) {
                    $p['image_urls'][] = (strpos($local_data['thumb'], 'http') === 0) ? $local_data['thumb'] : $base . '/' . ltrim($local_data['thumb'], '/');
                }
                if (!empty($local_data['video'])) {
                    $p['video_url'] = (strpos($local_data['video'], 'http') === 0) ? $local_data['video'] : $base . '/' . ltrim($local_data['video'], '/');
                }
            } else {
                foreach ($local_data as $item) {
                    if (!empty($item)) {
                        $p['image_urls'][] = (strpos($item, 'http') === 0) ? $item : $base . '/' . ltrim($item, '/');
                    }
                }
            }
        } else {
            $p['image_urls'][] = (strpos($raw_media, 'http') === 0) ? $raw_media : $base . '/' . ltrim($raw_media, '/');
        }
    }
}
unset($p);

// Slug generator for clean SEO URLs
if (!function_exists('pl_slugify')) {
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
}

// Handle Auto-Open & Direct Search Visits for SEO
$auto_open_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$auto_open_data = null;
$auto_open_slug = '';
$clean_prompt_url = 'https://rtmcreator.com/prompt-library/';
$related_prompts = [];

if ($auto_open_id > 0) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.category, s.output_type, s.tags, s.prompt, s.text_output, s.local_media, s.created_at, u.username
        FROM submissions s
        JOIN users u ON s.telegram_id = u.telegram_id
        WHERE s.id = ? AND s.status = 'approved'
    ");
    $stmt->execute([$auto_open_id]);
    $auto_open_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($auto_open_data) {
        $local_data = json_decode($auto_open_data['local_media'] ?? '', true);
        $base = rtrim(BOT_BASE_URL, '/');
        $auto_open_data['image_urls'] = [];
        $auto_open_data['video_url'] = null;

        if (is_array($local_data)) {
            if (isset($local_data['video'])) {
                if (isset($local_data['thumb']) && $local_data['thumb']) {
                    $auto_open_data['image_urls'][] = $base . '/' . ltrim($local_data['thumb'], '/');
                }
                if ($local_data['video']) {
                    $auto_open_data['video_url'] = $base . '/' . ltrim($local_data['video'], '/');
                }
            } else {
                $auto_open_data['image_urls'] = array_map(fn($path) => $base . '/' . ltrim($path, '/'), $local_data);
            }
        }

        $auto_open_slug = pl_slugify($auto_open_data['text_output'] ?: ($auto_open_data['category'] . ' AI Prompt'));
        $clean_prompt_url = 'https://rtmcreator.com/prompt-library/' . $auto_open_id . '/' . $auto_open_slug . '/';

        // Fetch 4 related prompts in the same category for SEO internal spidering
        try {
            $rel_stmt = $pdo->prepare("
                SELECT s.id, s.category, s.output_type, s.prompt, s.text_output, s.local_media, s.created_at, u.username
                FROM submissions s
                JOIN users u ON s.telegram_id = u.telegram_id
                WHERE s.status = 'approved' AND s.category = :category AND s.id != :id
                ORDER BY s.created_at DESC
                LIMIT 4
            ");
            $rel_stmt->execute([
                ':category' => $auto_open_data['category'],
                ':id' => $auto_open_id
            ]);
            $related_prompts = $rel_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($related_prompts as &$rp) {
                $rp_local = json_decode($rp['local_media'] ?? '', true);
                $rp['thumb'] = null;
                if (is_array($rp_local)) {
                    if (isset($rp_local['thumb']) && $rp_local['thumb']) {
                        $rp['thumb'] = $base . '/' . ltrim($rp_local['thumb'], '/');
                    } elseif (!isset($rp_local['video']) && count($rp_local) > 0) {
                        $first = reset($rp_local);
                        if (is_string($first)) $rp['thumb'] = $base . '/' . ltrim($first, '/');
                    }
                }
                $rp['slug'] = pl_slugify($rp['text_output'] ?: ($rp['category'] . ' AI Prompt'));
                $rp['url'] = 'https://rtmcreator.com/prompt-library/' . $rp['id'] . '/' . $rp['slug'] . '/';
            }
            unset($rp);
        } catch (Exception $e) {
            $related_prompts = [];
        }

        // 301 Permanent Redirect for legacy query parameter URLs (?id=123) to clean SEO slug URL
        if (!empty($clean_prompt_url) && !empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], '?id=') !== false && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header("HTTP/1.1 301 Moved Permanently");
                header("Location: " . $clean_prompt_url);
                exit;
            }
        }
    }
}

$is_wordpress = defined('ABSPATH');
?>
<?php if (!$is_wordpress): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $auto_open_data ? htmlspecialchars($auto_open_data['text_output'] ?: ($auto_open_data['category'] . ' AI Prompt')) . " | Free AI Prompt & Copy Instructions" : "Premium AI Prompt Library | Best ChatGPT & Art Prompts"; ?></title>
    <meta name="description" content="<?php echo $auto_open_data ? htmlspecialchars(mb_strimwidth($auto_open_data['prompt'], 0, 155, "...")) . " - Copy this " . htmlspecialchars($auto_open_data['category']) . " AI prompt for free on AI Prompt Hub." : "Discover the world's best AI Art, ChatGPT, and creative prompts. Browse our curated library of community-submitted AI results and copy them for free."; ?>">
    
    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="<?php echo $auto_open_data ? 'article' : 'website'; ?>">
    <meta property="og:site_name" content="Prompt Library Marketplace">
    <meta property="og:url" content="<?php echo htmlspecialchars($clean_prompt_url); ?>">
    <meta property="og:title" content="<?php echo $auto_open_data ? htmlspecialchars($auto_open_data['text_output'] ?: ($auto_open_data['category'] . ' AI Prompt')) . ' | AI Prompt Hub' : 'Premium AI Prompt Library | Best ChatGPT & Art Prompts'; ?>">
    <meta property="og:description" content="<?php echo $auto_open_data ? htmlspecialchars(mb_strimwidth($auto_open_data['prompt'], 0, 155, "...")) : "Discover the world's best AI Art, ChatGPT, and creative prompts. Browse our curated library of community-submitted AI results and copy them for free."; ?>">
    <?php if ($auto_open_data && count($auto_open_data['image_urls']) > 0): ?>
        <meta property="og:image" content="<?php echo htmlspecialchars($auto_open_data['image_urls'][0]); ?>">
        <meta name="twitter:card" content="summary_large_image">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($clean_prompt_url); ?>">

    <!-- JSON-LD Structured Data for Google Search Engine Optimization -->
    <?php if ($auto_open_data): ?>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {
              "@type": "ListItem",
              "position": 1,
              "name": "Home",
              "item": "https://rtmcreator.com/"
            },
            {
              "@type": "ListItem",
              "position": 2,
              "name": "Prompt Library",
              "item": "https://rtmcreator.com/prompt-library/"
            },
            {
              "@type": "ListItem",
              "position": 3,
              "name": "<?php echo addslashes($auto_open_data['category']); ?>",
              "item": "https://rtmcreator.com/prompt-library/?category=<?php echo urlencode($auto_open_data['category']); ?>"
            },
            {
              "@type": "ListItem",
              "position": 4,
              "name": "<?php echo addslashes($auto_open_data['text_output'] ?: $auto_open_data['category'] . ' Prompt'); ?>",
              "item": "<?php echo addslashes($clean_prompt_url); ?>"
            }
          ]
        },
        {
          "@type": "CreativeWork",
          "headline": "<?php echo addslashes($auto_open_data['text_output'] ?: $auto_open_data['category'] . ' AI Prompt'); ?>",
          "text": "<?php echo addslashes($auto_open_data['prompt']); ?>",
          "url": "<?php echo addslashes($clean_prompt_url); ?>",
          "datePublished": "<?php echo date('c', strtotime($auto_open_data['created_at'])); ?>",
          "author": {
            "@type": "Person",
            "name": "<?php echo addslashes($auto_open_data['username']); ?>"
          }<?php if (!empty($auto_open_data['image_urls'])): ?>,
          "image": "<?php echo addslashes($auto_open_data['image_urls'][0]); ?>"<?php endif; ?>
        }
      ]
    }
    </script>
    <?php else: ?>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "CollectionPage",
      "name": "AI Prompt Library",
      "description": "A curated collection of high-quality AI prompts for art and text generation.",
      "url": "https://rtmcreator.com/prompt-library/",
      "hasPart": [
        <?php 
        $parts = [];
        foreach ($ssr_prompts as $p) {
            $parts[] = '{
              "@type": "CreativeWork",
              "name": "' . addslashes($p['text_output'] ?: $p['category'] . ' Prompt') . '",
              "description": "' . addslashes(mb_strimwidth($p['prompt'], 0, 150, "...")) . '",
              "author": { "@type": "Person", "name": "' . addslashes($p['username']) . '" }
            }';
        }
        echo implode(",", $parts);
        ?>
      ]
    }
    </script>
    <?php endif; ?>
<?php endif; ?>
    
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
    /* DESIGN SYSTEM */
    :root {
      --pl-bg: #05070a;
      --pl-surface: rgba(13, 17, 27, 0.95);
      --pl-card-bg: rgba(255, 255, 255, 0.03);
      --pl-accent: #8b5cf6;
      --pl-accent-rgb: 139, 92, 246;
      --pl-secondary: #3b82f6;
      --pl-text: #f8fafc;
      --pl-text-dim: #94a3b8;
      --pl-border: rgba(255, 255, 255, 0.1);
      --pl-gradient: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
      --pl-radius: 32px;
    }

    body { margin: 0; background: var(--pl-bg); color: var(--pl-text); font-family: 'Inter', sans-serif; }

    #pl-wrapper {
      padding: 80px 24px;
      position: relative;
      overflow-x: hidden;
      width: 100%;
    }

    #pl-wrapper * { box-sizing: border-box; transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }

    /* Mesh Background */
    #pl-mesh-bg {
      position: fixed;
      inset: 0;
      z-index: 0;
      background: 
        radial-gradient(at 0% 0%, rgba(var(--pl-accent-rgb), 0.15) 0, transparent 50%),
        radial-gradient(at 100% 0%, rgba(59, 130, 246, 0.1) 0, transparent 50%),
        radial-gradient(at 100% 100%, rgba(var(--pl-accent-rgb), 0.1) 0, transparent 50%),
        radial-gradient(at 0% 100%, rgba(59, 130, 246, 0.08) 0, transparent 50%);
      filter: blur(100px);
      animation: plMeshFlow 25s ease-in-out infinite alternate;
    }
    @keyframes plMeshFlow { from { transform: translate(-5%, -5%) scale(1); } to { transform: translate(5%, 5%) scale(1.15); } }

    /* Layout */
    #pl-header { text-align: center; margin-bottom: 72px; position: relative; z-index: 10; }
    #pl-header h1 { font-family: 'Outfit', sans-serif; font-size: clamp(2.8rem, 9vw, 5rem); font-weight: 800; margin: 0 0 16px; background: linear-gradient(to bottom, #fff 40%, #94a3b8 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    #pl-header p { color: var(--pl-text-dim); font-size: 1.25rem; max-width: 600px; margin: 0 auto; opacity: 0.8; }

    #pl-search-wrap { max-width: 720px; margin: 0 auto 64px; position: relative; z-index: 10; display: flex; align-items: center; }
    #pl-search { width: 100%; padding: 22px 140px 22px 56px; background: var(--pl-surface); backdrop-filter: blur(32px); border: 1px solid var(--pl-border); border-radius: 22px; color: #fff; font-size: 1.1rem; outline: none; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4); transition: all 0.25s ease; }
    #pl-search:focus { border-color: var(--pl-accent); box-shadow: 0 0 30px rgba(124, 58, 237, 0.3); }
    .pl-search-icon { position: absolute; left: 24px; font-size: 1.2rem; color: var(--pl-text-dim); pointer-events: none; }
    .pl-search-btn { position: absolute; right: 8px; background: linear-gradient(135deg, #7c3aed, #6366f1); color: #fff; border: none; padding: 12px 24px; border-radius: 16px; font-size: 0.95rem; font-weight: 700; font-family: 'Outfit', sans-serif; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s ease; box-shadow: 0 4px 15px rgba(124, 58, 237, 0.4); }
    .pl-search-btn:hover { opacity: 0.95; transform: scale(1.02); }

    #pl-filters { 
        display: flex; 
        justify-content: center; 
        flex-wrap: wrap; 
        gap: 12px; 
        margin-bottom: 64px; 
        position: relative; 
        z-index: 10; 
    }
    .pl-filter-btn { 
        padding: 12px 26px; 
        background: var(--pl-card-bg); 
        border: 1px solid var(--pl-border); 
        border-radius: 100px; 
        color: var(--pl-text-dim); 
        font-weight: 700; 
        font-size: 0.95rem; 
        font-family: 'Outfit', sans-serif; 
        cursor: pointer; 
        backdrop-filter: blur(10px); 
        transition: all 0.2s ease;
        white-space: nowrap;
        user-select: none;
    }
    .pl-filter-btn:hover {
        border-color: var(--pl-accent);
        color: #fff;
        transform: translateY(-2px);
    }
    .pl-filter-btn.active { 
        background: var(--pl-accent); 
        color: #fff; 
        border-color: var(--pl-accent); 
        box-shadow: 0 4px 15px rgba(124, 58, 237, 0.4);
    }

    .pl-total-count-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(124, 58, 237, 0.15);
        border: 1px solid rgba(124, 58, 237, 0.4);
        color: #a78bfa;
        font-weight: 800;
        font-size: 0.88rem;
        padding: 8px 20px;
        border-radius: 100px;
        box-shadow: 0 4px 20px rgba(124, 58, 237, 0.2);
    }

    #pl-load-more {
        background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%) !important;
        color: #fff !important;
        border: 1px solid rgba(167, 139, 250, 0.5) !important;
        padding: 20px 60px !important;
        font-size: 1.15rem !important;
        font-weight: 800 !important;
        border-radius: 100px !important;
        box-shadow: 0 12px 35px rgba(124, 58, 237, 0.4), 0 0 20px rgba(124, 58, 237, 0.2) !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        cursor: pointer !important;
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    #pl-load-more:hover {
        transform: translateY(-4px) scale(1.03) !important;
        box-shadow: 0 20px 45px rgba(124, 58, 237, 0.6), 0 0 30px rgba(124, 58, 237, 0.4) !important;
        background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%) !important;
    }

    /* ==========================================================================
       FLOATING TELEGRAM BOT POPUP BANNER (PREMIUM MODERN DESIGN)
       ========================================================================== */
    .pl-tg-banner {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9998;
        background: linear-gradient(135deg, rgba(17, 24, 39, 0.94) 0%, rgba(13, 17, 30, 0.97) 100%);
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 20px;
        padding: 14px 44px 14px 16px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 
            0 16px 40px -8px rgba(0, 0, 0, 0.75), 
            0 0 0 1px rgba(255, 255, 255, 0.05),
            0 8px 24px -4px rgba(124, 58, 237, 0.25);
        animation: plSlideUp 0.45s cubic-bezier(0.16, 1, 0.3, 1);
        max-width: 460px;
        transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        box-sizing: border-box;
    }
    .pl-tg-banner.pl-banner-hiding {
        opacity: 0 !important;
        transform: translateY(18px) scale(0.96) !important;
        pointer-events: none;
    }
    @keyframes plSlideUp {
        from { transform: translateY(50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* Icon badge with Telegram brand styling & live dot */
    .pl-tg-banner-icon-wrap {
        position: relative;
        flex-shrink: 0;
    }
    .pl-tg-banner-icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(34, 158, 217, 0.18) 0%, rgba(124, 58, 237, 0.25) 100%);
        border: 1px solid rgba(34, 158, 217, 0.35);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #29b6f6;
        font-size: 1.45rem;
        box-shadow: 0 4px 14px rgba(34, 158, 217, 0.22);
        transition: transform 0.2s;
    }
    .pl-tg-banner:hover .pl-tg-banner-icon {
        transform: scale(1.05);
    }
    .pl-tg-live-dot {
        position: absolute;
        top: -2px;
        right: -2px;
        width: 10px;
        height: 10px;
        background: #10b981;
        border: 2px solid #0f1423;
        border-radius: 50%;
        box-shadow: 0 0 8px #10b981;
        animation: plPulseDot 2s infinite;
    }
    @keyframes plPulseDot {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.2); opacity: 0.75; }
    }

    /* Text details */
    .pl-tg-banner-content {
        flex: 1;
        min-width: 0;
    }
    .pl-tg-banner-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.65rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #a78bfa;
        margin-bottom: 2px;
        line-height: 1;
    }
    .pl-tg-banner-title {
        color: #ffffff;
        font-family: 'Outfit', sans-serif;
        font-weight: 700;
        font-size: 0.95rem;
        line-height: 1.25;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .pl-tg-banner-desc {
        color: #94a3b8;
        font-size: 0.77rem;
        line-height: 1.35;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .pl-tg-accent {
        color: #4ade80;
        font-weight: 700;
    }

    /* Modern action button */
    .pl-tg-banner-btn {
        background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
        color: #ffffff !important;
        text-decoration: none !important;
        font-family: 'Outfit', sans-serif;
        font-weight: 700;
        font-size: 0.84rem;
        padding: 9px 15px;
        border-radius: 12px;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.15);
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        flex-shrink: 0;
    }
    .pl-tg-banner-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(2, 132, 199, 0.55);
        background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%);
        color: #ffffff !important;
    }
    .pl-tg-banner-btn i {
        font-size: 0.75rem;
        transition: transform 0.2s;
    }
    .pl-tg-banner-btn:hover i {
        transform: translateX(3px);
    }

    /* Close button nestled gracefully in top-right */
    .pl-tg-banner-close {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #94a3b8;
        font-size: 0.72rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        padding: 0;
        line-height: 1;
        outline: none;
    }
    .pl-tg-banner-close:hover {
        background: rgba(239, 68, 68, 0.2);
        border-color: rgba(239, 68, 68, 0.4);
        color: #f87171;
        transform: rotate(90deg) scale(1.08);
    }

    /* Mobile adjustments */
    @media (max-width: 600px) {
        .pl-tg-banner {
            right: 12px;
            bottom: 12px;
            left: 12px;
            max-width: none;
            width: auto;
            padding: 12px 34px 12px 12px;
            gap: 10px;
            border-radius: 16px;
        }
        .pl-tg-banner-icon {
            width: 38px;
            height: 38px;
            font-size: 1.25rem;
            border-radius: 11px;
        }
        .pl-tg-banner-tag {
            font-size: 0.6rem;
        }
        .pl-tg-banner-title {
            font-size: 0.86rem;
        }
        .pl-tg-banner-desc {
            font-size: 0.7rem;
        }
        .pl-tg-banner-btn {
            padding: 8px 12px;
            font-size: 0.76rem;
            border-radius: 10px;
            gap: 5px;
        }
        .pl-tg-banner-close {
            top: 7px;
            right: 7px;
            width: 22px;
            height: 22px;
            font-size: 0.68rem;
        }
    }

    /* Leaderboard Card & Upward Ticker */
    .pl-leaderboard-card {
        max-width: 800px;
        margin: 80px auto 0;
        background: var(--pl-surface);
        border: 1px solid var(--pl-border);
        border-radius: 28px;
        padding: 32px;
        position: relative;
        z-index: 10;
        backdrop-filter: blur(20px);
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
    }
    .pl-lb-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--pl-border);
    }
    .pl-lb-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-family: 'Outfit', sans-serif;
        font-size: 1.25rem;
        font-weight: 800;
        color: #fff;
    }
    .pl-lb-badge {
        background: rgba(124, 58, 237, 0.2);
        border: 1px solid rgba(124, 58, 237, 0.4);
        color: #a78bfa;
        border-radius: 100px;
        padding: 4px 14px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .pl-ticker-container {
        height: 190px;
        overflow: hidden;
        position: relative;
        mask-image: linear-gradient(to bottom, transparent 0%, black 15%, black 85%, transparent 100%);
        -webkit-mask-image: linear-gradient(to bottom, transparent 0%, black 15%, black 85%, transparent 100%);
    }
    .pl-ticker-track {
        display: flex;
        flex-direction: column;
        gap: 10px;
        animation: plTickerScroll 22s linear infinite;
    }
    .pl-ticker-container:hover .pl-ticker-track {
        animation-play-state: paused;
    }
    @keyframes plTickerScroll {
        0%   { transform: translateY(0); }
        100% { transform: translateY(-50%); }
    }
    .pl-lb-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--pl-border);
        border-radius: 16px;
        padding: 12px 20px;
        color: #fff;
        font-size: 0.95rem;
        user-select: none;
        pointer-events: none; /* NON-CLICKABLE */
        gap: 12px;
        min-width: 0;
    }
    .pl-lb-rank {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 700;
        min-width: 0;
        overflow: hidden;
    }
    .pl-lb-username {
        color: #fff;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .pl-lb-stats {
        display: flex;
        align-items: center;
        gap: 14px;
        font-size: 0.88rem;
        color: var(--pl-text-dim);
        flex-shrink: 0;
        white-space: nowrap;
    }
    .pl-lb-earnings {
        color: #22c55e;
        font-weight: 800;
    }
    .pl-lb-prompts {
        background: rgba(124, 58, 237, 0.15);
        border: 1px solid rgba(124, 58, 237, 0.3);
        color: #a78bfa;
        padding: 3px 10px;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
    }

    #pl-grid-container { width: 100%; max-width: 1240px; margin: 0 auto; position: relative; z-index: 10; box-sizing: border-box; }
    #pl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; width: 100%; box-sizing: border-box; }
    @media (max-width: 768px) { #pl-grid { grid-template-columns: 1fr !important; gap: 14px !important; } }

    /* Card Styling (SSR Ready) */
    .pl-card { background: var(--pl-surface); border: 1px solid var(--pl-border); border-radius: var(--pl-radius); padding: 32px; display: flex; flex-direction: column; gap: 24px; cursor: pointer; text-decoration: none; color: inherit; }
    .pl-card:hover { transform: translateY(-12px); border-color: var(--pl-accent); }
    .pl-card-img-wrap { 
        width: 100%; 
        aspect-ratio: 1/1; 
        border-radius: 20px; 
        overflow: hidden; 
        background: #090c14; 
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .pl-card-img { 
        width: 100%; 
        height: 100%; 
        object-fit: contain; 
        object-position: center; 
        position: relative;
        z-index: 2;
    }
    .pl-card-img-bg {
        position: absolute;
        inset: -12px;
        width: calc(100% + 24px);
        height: calc(100% + 24px);
        object-fit: cover;
        object-position: center;
        filter: blur(18px) opacity(0.45) brightness(0.7);
        z-index: 1;
        pointer-events: none;
    }
    .pl-card-text-preview { 
        height: 100%; width: 100%; 
        padding: 30px; 
        background: linear-gradient(135deg, rgba(var(--pl-accent-rgb), 0.1) 0%, rgba(59, 130, 246, 0.1) 100%); 
        display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;
        color: var(--pl-text-dim); font-size: 0.9rem; line-height: 1.5; font-style: italic;
    }
    .pl-card-text-preview i { font-size: 3rem; margin-bottom: 15px; opacity: 0.4; display: block; }
    .pl-card-body { flex: 1; display: flex; flex-direction: column; gap: 20px; }
    .pl-card-title { font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 700; color: #fff; margin: 0; }
    .pl-card-preview { color: var(--pl-text-dim); font-size: 1rem; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .pl-cat-badge { display: inline-block; width: fit-content; background: rgba(var(--pl-accent-rgb), 0.2); color: #a78bfa; font-size: 0.75rem; font-weight: 800; padding: 6px 14px; border-radius: 100px; border: 1px solid rgba(var(--pl-accent-rgb), 0.4); text-transform: uppercase; }
    .pl-trending-badge { display: inline-block; width: fit-content; background: rgba(239, 68, 68, 0.2); color: #ef4444; font-size: 0.75rem; font-weight: 800; padding: 6px 14px; border-radius: 100px; border: 1px solid rgba(239, 68, 68, 0.4); text-transform: uppercase; }
    .pl-card-footer { display: flex; align-items: center; justify-content: flex-end; padding-top: 24px; border-top: 1px solid var(--pl-border); }
    .pl-btn-group {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .pl-copy-btn {
      background: var(--pl-accent) !important;
      color: #fff !important;
      border: none !important;
      padding: 10px 20px !important;
      border-radius: 14px !important;
      font-weight: 800 !important;
      cursor: pointer !important;
      display: flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
    }

    .pl-share-btn {
      width: 44px !important; height: 44px !important;
      background: rgba(255,255,255,0.1) !important;
      border: 1px solid var(--pl-border) !important;
      border-radius: 12px !important;
      display: flex !important; align-items: center !important; justify-content: center !important;
      cursor: pointer !important;
      color: #fff !important;
      font-size: 1.1rem !important;
    }

    .pl-share-btn i { color: #fff !important; display: inline-block !important; }

    .pl-share-btn:hover { 
      background: var(--pl-accent) !important; 
      border-color: var(--pl-accent) !important; 
      transform: scale(1.1); 
    }

    .pl-fav-btn {
      width: 44px !important; height: 44px !important;
      background: rgba(255,255,255,0.05) !important;
      border: 1px solid var(--pl-border) !important;
      border-radius: 12px !important;
      display: flex !important; align-items: center !important; justify-content: center !important;
      cursor: pointer !important;
      color: #fff !important;
      font-size: 1.1rem !important;
    }

    .pl-fav-btn.active {
      background: rgba(239, 68, 68, 0.15) !important;
      border-color: rgba(239, 68, 68, 0.4) !important;
      color: #ef4444 !important;
    }

    .pl-fav-btn:hover { transform: scale(1.1); background: rgba(255,255,255,0.1) !important; }
    .pl-fav-btn.active:hover { background: rgba(239, 68, 68, 0.2) !important; }
    
    /* ==========================================================================
       MODAL & PROMPT DETAILS DIALOG - ULTRA MODERN MOBILE & DESKTOP SYSTEM
       ========================================================================== */
    #pl-modal-overlay { 
        position: fixed; 
        inset: 0; 
        background: rgba(0, 0, 0, 0.85); 
        backdrop-filter: blur(16px); 
        -webkit-backdrop-filter: blur(16px);
        z-index: 999999; 
        display: none; 
        align-items: center; 
        justify-content: center; 
        padding: 24px; 
        box-sizing: border-box;
    }
    #pl-modal-overlay.open { 
        display: flex; 
    }
    #pl-modal { 
        background: #0d1117; 
        border: 1px solid var(--pl-border); 
        border-radius: 28px; 
        width: 100%; 
        max-width: 860px; 
        max-height: 88vh; 
        display: flex;
        flex-direction: column;
        overflow: hidden;
        position: relative; 
        box-shadow: 0 35px 80px rgba(0, 0, 0, 0.8), 0 0 40px rgba(139, 92, 246, 0.15);
        animation: plModalPop 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        box-sizing: border-box;
    }
    @keyframes plModalPop {
        from { transform: scale(0.95); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }

    /* Top Header Bar */
    .pl-modal-header {
        padding: 16px 24px 12px;
        background: #0d1117;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        z-index: 5;
    }
    .pl-modal-drag-pill {
        display: none;
    }
    .pl-modal-header-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        gap: 12px;
    }
    #pl-modal-badges {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        min-width: 0;
    }
    #pl-modal-close { 
        width: 38px; 
        height: 38px; 
        min-width: 38px; 
        min-height: 38px; 
        flex-shrink: 0; 
        border-radius: 50%; 
        background: rgba(255, 255, 255, 0.08); 
        border: 1px solid rgba(255, 255, 255, 0.15); 
        color: #fff; 
        cursor: pointer; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 1rem; 
        line-height: 1; 
        padding: 0; 
        transition: all 0.2s;
        margin-left: auto;
    }
    #pl-modal-close:hover { 
        background: rgba(239, 68, 68, 0.25); 
        border-color: #ef4444; 
        color: #ef4444; 
        transform: rotate(90deg) scale(1.06); 
    }

    /* Scrollable Content Body */
    /* Scrollable Content Body (Strict Vertical Only - Zero Horizontal Scroll) */
    .pl-modal-scroll-body {
        flex: 1;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        padding: 24px;
        -webkit-overflow-scrolling: touch;
        box-sizing: border-box;
        touch-action: pan-y;
        width: 100%;
        max-width: 100%;
    }
    .pl-modal-scroll-body::-webkit-scrollbar { width: 6px; }
    .pl-modal-scroll-body::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); }
    .pl-modal-scroll-body::-webkit-scrollbar-thumb { background: rgba(124, 58, 237, 0.45); border-radius: 10px; }

    .pl-modal-grid {
        display: grid;
        grid-template-columns: 340px 1fr;
        gap: 24px;
        align-items: start;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        box-sizing: border-box;
    }
    #pl-modal-left, #pl-modal-right {
        min-width: 0;
        max-width: 100%;
        overflow-x: hidden;
        box-sizing: border-box;
    }
    #pl-modal-imgs {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        box-sizing: border-box;
    }

    /* Media Frame & Vertical Stack (Zero Horizontal Scroll) */
    .pl-modal-media-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        box-sizing: border-box;
    }
    .pl-modal-media-wrap {
        position: relative;
        width: 100%;
        max-width: 100%;
        height: 350px;
        background: #06080e;
        border: 1px solid var(--pl-border);
        border-radius: 20px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
    }
    .pl-modal-img-bg {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center;
        filter: blur(22px) opacity(0.4) brightness(0.65);
        transform: scale(1.1);
        pointer-events: none;
        z-index: 1;
    }
    .pl-modal-img {
        position: relative;
        z-index: 2;
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        border-radius: 12px;
        box-shadow: 0 12px 35px rgba(0, 0, 0, 0.65);
    }
    .pl-modal-video {
        position: relative;
        z-index: 2;
        width: 100%;
        max-width: 100%;
        height: 100%;
        max-height: 100%;
        object-fit: contain;
        background: #000;
        border-radius: 12px;
    }
    
    .pl-result-box { 
        background: rgba(var(--pl-accent-rgb), 0.08); 
        border: 1px solid rgba(var(--pl-accent-rgb), 0.25); 
        border-radius: 18px; 
        padding: 22px; 
        color: #fff;
        line-height: 1.6;
        box-sizing: border-box;
    }
    
    /* Right Details Column */
    #pl-modal-title { 
        font-family: 'Outfit', sans-serif; 
        font-size: 1.35rem; 
        font-weight: 800; 
        color: #fff; 
        margin: 0 0 14px 0; 
        line-height: 1.32; 
        word-break: break-word; 
    }

    .pl-prompt-box { 
        background: rgba(0, 0, 0, 0.45); 
        border: 1px solid rgba(255, 255, 255, 0.1); 
        border-radius: 18px; 
        padding: 16px 18px; 
        margin: 12px 0 14px 0; 
        position: relative;
        box-sizing: border-box;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }
    .pl-prompt-box-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        width: 100%;
        box-sizing: border-box;
    }
    .pl-prompt-box-tag {
        font-size: 0.72rem;
        color: #a78bfa;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .pl-prompt-quick-copy {
        background: rgba(139, 92, 246, 0.15);
        border: 1px solid rgba(139, 92, 246, 0.35);
        color: #c4b5fd;
        font-size: 0.76rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
        transition: all 0.2s;
    }
    .pl-prompt-quick-copy:hover {
        background: var(--pl-accent);
        color: #fff;
    }
    .pl-prompt-box-text-wrap {
        max-height: 200px;
        overflow-y: auto;
        overflow-x: hidden;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    .pl-prompt-box-text-wrap::-webkit-scrollbar { width: 4px; }
    .pl-prompt-box-text-wrap::-webkit-scrollbar-thumb { background: rgba(124, 58, 237, 0.4); border-radius: 10px; }

    #pl-modal-prompt { 
        font-size: 0.94rem; 
        line-height: 1.65; 
        color: #f1f5f9;
        white-space: pre-wrap; 
        word-break: break-word; 
        overflow-wrap: anywhere;
        overflow-x: hidden;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        user-select: text;
        -webkit-user-select: text;
    }

    #pl-modal-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        color: var(--pl-text-dim);
        font-size: 0.8rem;
        padding: 10px 14px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 12px;
        margin-bottom: 6px;
        box-sizing: border-box;
    }

    /* Pinned Bottom Footer Actions */
    .pl-modal-footer {
        padding: 16px 24px 20px;
        background: #090c14;
        border-top: 1px solid var(--pl-border);
        flex-shrink: 0;
        z-index: 10;
        box-sizing: border-box;
    }
    .pl-modal-actions-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        box-sizing: border-box;
    }
    #pl-modal-fav-btn, #pl-modal-share-btn {
        width: 48px;
        height: 48px;
        min-width: 48px;
        min-height: 48px;
        border-radius: 14px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
    }
    #pl-modal-copy-btn {
        flex: 1;
        height: 48px;
        padding: 0 20px;
        background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);
        color: #fff;
        border: none;
        border-radius: 14px;
        font-weight: 800;
        font-size: 1rem;
        font-family: 'Outfit', sans-serif;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s;
        box-shadow: 0 4px 18px rgba(139, 92, 246, 0.4);
    }
    #pl-modal-copy-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(139, 92, 246, 0.6);
    }
    
    /* Mobile Responsiveness & App Experience */
    @media (max-width: 768px) {
        #pl-wrapper { padding: 24px 12px 90px; }
        #pl-header { margin-bottom: 28px; }
        #pl-header h1 { font-size: clamp(1.8rem, 7.5vw, 2.8rem); letter-spacing: -0.03em; margin-bottom: 8px; }
        #pl-header p { font-size: 0.92rem; line-height: 1.5; padding: 0 6px; opacity: 0.85; }
        .pl-total-count-badge { padding: 5px 14px; font-size: 0.78rem; margin-top: 8px; }

        /* Search Input on Mobile */
        #pl-search-wrap { margin-bottom: 28px; }
        #pl-search { 
            padding: 14px 100px 14px 44px !important; 
            font-size: 0.95rem !important; 
            border-radius: 16px !important; 
        }
        .pl-search-icon { left: 16px !important; font-size: 1rem !important; }
        .pl-search-btn { 
            right: 6px !important; 
            padding: 8px 14px !important; 
            font-size: 0.85rem !important; 
            border-radius: 12px !important; 
        }

        /* Horizontally Swipeable Native Category Pills */
        #pl-filters { 
            display: flex !important; 
            flex-wrap: nowrap !important; 
            overflow-x: auto !important; 
            -webkit-overflow-scrolling: touch; 
            justify-content: flex-start !important; 
            gap: 8px !important; 
            margin-left: -12px !important; 
            margin-right: -12px !important; 
            padding: 4px 12px 10px !important; 
            margin-bottom: 28px !important; 
            scrollbar-width: none; 
        }
        #pl-filters::-webkit-scrollbar { display: none; }
        .pl-filter-btn { 
            flex-shrink: 0 !important; 
            white-space: nowrap !important; 
            padding: 8px 16px !important; 
            font-size: 0.82rem !important; 
            border-radius: 100px !important;
        }

        /* Prompt Cards Grid */
        #pl-grid { gap: 14px !important; }
        .pl-card { padding: 16px !important; border-radius: 18px !important; gap: 12px !important; word-break: break-word; }
        .pl-card:hover { transform: none !important; }
        .pl-card-img-wrap { border-radius: 14px !important; aspect-ratio: 4/3 !important; }
        .pl-card-title { font-size: 1.1rem !important; line-height: 1.35 !important; word-break: break-word; }
        .pl-card-preview { font-size: 0.88rem !important; -webkit-line-clamp: 2 !important; word-break: break-word; }
        .pl-cat-badge, .pl-trending-badge { font-size: 0.68rem !important; padding: 4px 9px !important; }
        .pl-card-footer { padding-top: 12px !important; }
        .pl-btn-group { gap: 6px !important; width: 100% !important; justify-content: flex-end !important; }
        .pl-copy-btn { padding: 8px 14px !important; font-size: 0.82rem !important; border-radius: 10px !important; }
        .pl-share-btn, .pl-fav-btn { width: 38px !important; height: 38px !important; border-radius: 10px !important; font-size: 0.95rem !important; }

        /* Load More Button */
        #pl-load-more-wrap { margin-top: 40px !important; }
        #pl-load-more { padding: 14px 32px !important; font-size: 0.95rem !important; width: calc(100% - 24px); max-width: 300px; justify-content: center; }

        /* Leaderboard Ticker Card on Mobile */
        .pl-leaderboard-card { margin-top: 40px !important; padding: 18px 14px !important; border-radius: 18px !important; }
        .pl-lb-title { font-size: 1rem !important; }
        .pl-lb-badge { font-size: 0.65rem !important; padding: 3px 8px !important; }
        .pl-ticker-container { height: 160px !important; }
        .pl-lb-item { padding: 8px 12px !important; font-size: 0.82rem !important; border-radius: 12px !important; }
        .pl-lb-stats { gap: 8px !important; font-size: 0.78rem !important; }
        .pl-lb-prompts { font-size: 0.7rem !important; padding: 2px 6px !important; }

        /* ==========================================================================
           MOBILE-NATIVE BOTTOM SHEET MODAL (<= 768px)
           ========================================================================== */
        #pl-modal-overlay { 
            position: fixed !important;
            inset: 0 !important;
            width: 100% !important;
            height: 100% !important;
            padding: 0 !important; 
            display: none;
            align-items: flex-end !important;
            justify-content: center !important;
            background: rgba(0, 0, 0, 0.85) !important;
            backdrop-filter: blur(14px) !important;
            -webkit-backdrop-filter: blur(14px) !important;
            z-index: 999999 !important;
        }
        #pl-modal-overlay.open {
            display: flex !important;
        }
        #pl-modal { 
            width: 100% !important;
            max-width: 100% !important;
            max-height: 92vh !important;
            height: auto !important;
            border-radius: 24px 24px 0 0 !important; 
            margin: 0 !important; 
            border-bottom: none !important;
            border-left: none !important;
            border-right: none !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            background: #0d1117 !important;
            border-top: 1px solid rgba(255, 255, 255, 0.16) !important;
            box-shadow: 0 -15px 40px rgba(0, 0, 0, 0.8) !important;
            animation: plSheetSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        @keyframes plSheetSlideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        .pl-modal-header {
            padding: 8px 16px 10px !important;
            background: #0d1117 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
            touch-action: none;
        }
        .pl-modal-drag-pill {
            display: block !important;
            width: 44px !important;
            height: 5px !important;
            border-radius: 10px !important;
            background: rgba(255, 255, 255, 0.3) !important;
            margin: 2px auto 10px auto !important;
        }
        #pl-modal-close { 
            width: 34px !important; 
            height: 34px !important; 
            min-width: 34px !important; 
            min-height: 34px !important; 
            background: rgba(255, 255, 255, 0.12) !important; 
            font-size: 0.9rem !important; 
        }

        .pl-modal-scroll-body {
            padding: 16px 16px 20px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            -webkit-overflow-scrolling: touch !important;
            touch-action: pan-y !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .pl-modal-grid { 
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
            box-sizing: border-box !important;
        }
        #pl-modal-left, #pl-modal-right { 
            width: 100% !important; 
            max-width: 100% !important; 
            min-width: 0 !important; 
            overflow-x: hidden !important;
            box-sizing: border-box !important;
        }

        #pl-modal-imgs {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
            box-sizing: border-box !important;
        }
        .pl-modal-media-list {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
            box-sizing: border-box !important;
        }
        .pl-modal-media-wrap {
            height: 220px !important;
            max-height: 240px !important;
            border-radius: 16px !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
            box-sizing: border-box !important;
        }
        .pl-result-box {
            padding: 16px !important;
            border-radius: 16px !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: hidden !important;
            box-sizing: border-box !important;
        }

        #pl-modal-title { 
            font-size: 1.18rem !important; 
            line-height: 1.35 !important; 
            margin: 2px 0 10px 0 !important; 
            word-break: break-word !important; 
            overflow-wrap: anywhere !important;
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        .pl-prompt-box { 
            padding: 14px !important; 
            border-radius: 14px !important; 
            margin: 10px 0 !important; 
            word-break: break-word !important; 
            overflow-wrap: anywhere !important;
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .pl-prompt-box-text-wrap {
            max-height: none !important;
            overflow: visible !important;
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        #pl-modal-prompt { 
            font-size: 0.9rem !important; 
            line-height: 1.6 !important; 
            word-break: break-word !important; 
            overflow-wrap: anywhere !important;
            overflow-x: hidden !important;
            white-space: pre-wrap !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        #pl-modal-meta { 
            flex-direction: column !important; 
            align-items: flex-start !important;
            gap: 6px !important; 
            padding: 10px 12px !important; 
            border-radius: 12px !important; 
            font-size: 0.78rem !important; 
            margin-bottom: 8px !important; 
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        
        .pl-modal-footer {
            padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px)) !important;
            background: #090c14 !important;
            box-shadow: 0 -8px 25px rgba(0, 0, 0, 0.6) !important;
        }
        .pl-modal-actions-wrap { 
            gap: 8px !important; 
            width: 100% !important; 
        }
        #pl-modal-fav-btn, #pl-modal-share-btn { 
            width: 46px !important; 
            height: 46px !important; 
            min-width: 46px !important; 
            min-height: 46px !important; 
            border-radius: 12px !important; 
            font-size: 1.1rem !important; 
        }
        #pl-modal-copy-btn { 
            height: 46px !important; 
            padding: 0 16px !important;
            font-size: 0.94rem !important; 
            border-radius: 12px !important; 
            flex: 1 !important; 
        }

        /* SEO Content Section */
        #pl-seo-content { padding: 20px 14px !important; margin-top: 48px !important; border-radius: 18px !important; }
        #pl-seo-content h2 { font-size: 1.4rem !important; }
        #pl-seo-content h3 { font-size: 1.15rem !important; }
        #pl-seo-content > div > div { grid-template-columns: 1fr !important; gap: 16px !important; }
    }

    @media (max-width: 480px) {
        #pl-search { padding-right: 50px !important; }
        .pl-search-btn { 
            width: 38px !important; 
            height: 38px !important; 
            padding: 0 !important; 
            justify-content: center !important; 
            border-radius: 10px !important;
        }
        .pl-search-btn-text { display: none !important; }
    /* ==========================================================================
       STANDALONE PROMPT HERO (OPTIMIZED FOR GOOGLEBOT & DIRECT SEARCH VISITORS)
       ========================================================================== */
    .pl-standalone-container {
        max-width: 1040px;
        margin: 0 auto 50px auto;
        width: 100%;
        box-sizing: border-box;
    }

    /* Breadcrumbs */
    .pl-breadcrumbs {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        font-size: 0.85rem;
        color: var(--pl-text-dim);
        margin-bottom: 20px;
        padding: 10px 16px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--pl-border);
        border-radius: 12px;
    }
    .pl-breadcrumbs a {
        color: #c4b5fd;
        text-decoration: none;
        transition: color 0.2s;
    }
    .pl-breadcrumbs a:hover {
        color: #fff;
        text-decoration: underline;
    }
    .pl-bc-sep {
        color: rgba(255, 255, 255, 0.25);
    }
    .pl-bc-current {
        color: #fff;
        font-weight: 600;
    }

    /* Main Standalone Card */
    .pl-standalone-card {
        background: var(--pl-surface);
        border: 1px solid var(--pl-border);
        border-radius: 24px;
        padding: 28px;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6), 0 0 30px rgba(139, 92, 246, 0.12);
        box-sizing: border-box;
        margin-bottom: 36px;
    }
    .pl-standalone-grid {
        display: grid;
        grid-template-columns: 380px 1fr;
        gap: 28px;
        align-items: start;
        box-sizing: border-box;
    }
    .pl-standalone-media-col, .pl-standalone-info-col {
        min-width: 0;
        box-sizing: border-box;
    }

    /* Media presentation in standalone */
    .pl-standalone-media-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
        width: 100%;
    }
    .pl-standalone-media-wrap {
        position: relative;
        width: 100%;
        height: 380px;
        background: #06080e;
        border: 1px solid var(--pl-border);
        border-radius: 20px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
    }
    .pl-standalone-img-bg {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        filter: blur(22px) opacity(0.4) brightness(0.65);
        transform: scale(1.1);
        pointer-events: none;
        z-index: 1;
    }
    .pl-standalone-img {
        position: relative;
        z-index: 2;
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        border-radius: 12px;
        box-shadow: 0 12px 35px rgba(0, 0, 0, 0.65);
    }
    .pl-standalone-video {
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #000;
        border-radius: 12px;
    }
    .pl-standalone-text-result {
        background: rgba(var(--pl-accent-rgb), 0.08);
        border: 1px solid rgba(var(--pl-accent-rgb), 0.25);
        border-radius: 18px;
        padding: 24px;
        color: #fff;
    }
    .pl-standalone-result-badge {
        font-size: 0.78rem;
        color: var(--pl-accent);
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin-bottom: 10px;
    }
    .pl-standalone-result-quote {
        font-size: 1.15rem;
        font-style: italic;
        line-height: 1.6;
    }

    /* Info presentation in standalone */
    .pl-standalone-badges {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 12px;
    }
    .pl-standalone-author {
        font-size: 0.82rem;
        color: #94a3b8;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .pl-standalone-date {
        font-size: 0.82rem;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .pl-standalone-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.6rem;
        font-weight: 800;
        color: #fff;
        line-height: 1.3;
        margin: 0 0 16px 0;
        word-break: break-word;
    }
    .pl-standalone-prompt-text {
        font-size: 0.95rem;
        line-height: 1.65;
        color: #f1f5f9;
        white-space: pre-wrap;
        word-break: break-word;
        max-height: 240px;
        overflow-y: auto;
        padding-right: 6px;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }
    .pl-standalone-prompt-text::-webkit-scrollbar { width: 4px; }
    .pl-standalone-prompt-text::-webkit-scrollbar-thumb { background: rgba(124, 58, 237, 0.4); border-radius: 10px; }

    .pl-standalone-actions-row {
        display: flex;
        gap: 12px;
        margin: 18px 0;
        flex-wrap: wrap;
    }
    .pl-standalone-copy-btn {
        flex: 1;
        min-width: 180px;
        height: 48px;
        background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);
        color: #fff;
        border: none;
        border-radius: 14px;
        font-weight: 800;
        font-size: 0.96rem;
        font-family: 'Outfit', sans-serif;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s;
        box-shadow: 0 4px 18px rgba(139, 92, 246, 0.4);
    }
    .pl-standalone-copy-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(139, 92, 246, 0.6);
    }
    .pl-standalone-share-btn {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid var(--pl-border);
        color: #fff;
        font-size: 1.15rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .pl-standalone-share-btn:hover {
        background: rgba(255, 255, 255, 0.14);
        transform: scale(1.05);
    }

    /* In-card Earn Banner */
    .pl-standalone-earn-banner {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 18px;
        background: rgba(2, 132, 199, 0.12);
        border: 1px solid rgba(56, 189, 248, 0.3);
        border-radius: 16px;
        margin-top: 14px;
        box-sizing: border-box;
    }
    .pl-standalone-earn-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: rgba(34, 158, 217, 0.2);
        color: #38bdf8;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .pl-standalone-earn-content {
        flex: 1;
        min-width: 0;
    }
    .pl-standalone-earn-title {
        color: #fff;
        font-weight: 700;
        font-size: 0.92rem;
        font-family: 'Outfit', sans-serif;
    }
    .pl-standalone-earn-desc {
        color: #94a3b8;
        font-size: 0.76rem;
        line-height: 1.3;
    }
    .pl-standalone-earn-btn {
        background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
        color: #fff !important;
        text-decoration: none !important;
        font-weight: 700;
        font-size: 0.82rem;
        padding: 9px 14px;
        border-radius: 10px;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: transform 0.2s;
    }
    .pl-standalone-earn-btn:hover {
        transform: scale(1.04);
    }

    /* Related Prompts Section (For Internal Linking & Googlebot Spidering) */
    .pl-related-section {
        margin: 30px 0;
    }
    .pl-related-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }
    .pl-related-header h2 {
        font-family: 'Outfit', sans-serif;
        font-size: 1.25rem;
        font-weight: 700;
        color: #fff;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .pl-related-header h2 i {
        color: var(--pl-accent);
    }
    .pl-related-more-link {
        color: #a78bfa;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: color 0.2s;
    }
    .pl-related-more-link:hover {
        color: #fff;
    }
    .pl-related-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
    }
    .pl-related-card {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--pl-border);
        border-radius: 16px;
        overflow: hidden;
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
        transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
    }
    .pl-related-card:hover {
        transform: translateY(-4px);
        border-color: rgba(139, 92, 246, 0.4);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
    }
    .pl-related-img-wrap {
        height: 140px;
        width: 100%;
        background: #090c14;
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .pl-related-img-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .pl-related-fallback-icon {
        font-size: 1.8rem;
        color: rgba(139, 92, 246, 0.5);
    }
    .pl-related-body {
        padding: 12px;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .pl-related-title {
        font-size: 0.88rem;
        font-weight: 700;
        color: #fff;
        line-height: 1.35;
        margin-bottom: 6px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .pl-related-meta {
        font-size: 0.74rem;
        color: #94a3b8;
    }

    /* Explore Full Library Transition Button */
    .pl-explore-divider {
        text-align: center;
        margin: 40px 0 30px 0;
        position: relative;
    }
    .pl-explore-btn {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(139, 92, 246, 0.4);
        color: #f1f5f9;
        font-family: 'Outfit', sans-serif;
        font-size: 0.98rem;
        font-weight: 700;
        padding: 14px 28px;
        border-radius: 100px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        transition: all 0.25s;
    }
    .pl-explore-btn:hover {
        background: rgba(139, 92, 246, 0.2);
        border-color: #8b5cf6;
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(139, 92, 246, 0.35);
    }

    /* Mobile Responsive adjustments for Standalone Hero */
    @media (max-width: 768px) {
        .pl-standalone-container {
            margin-bottom: 30px;
        }
        .pl-standalone-card {
            padding: 18px 14px;
            border-radius: 18px;
        }
        .pl-standalone-grid {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .pl-standalone-media-wrap {
            height: 240px;
        }
        .pl-standalone-title {
            font-size: 1.25rem;
            margin-bottom: 12px;
        }
        .pl-standalone-prompt-text {
            font-size: 0.88rem;
            max-height: 200px;
        }
        .pl-standalone-earn-banner {
            flex-direction: column;
            text-align: center;
            padding: 14px;
            gap: 10px;
        }
        .pl-standalone-earn-btn {
            width: 100%;
            justify-content: center;
        }
        .pl-related-grid {
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .pl-related-img-wrap {
            height: 110px;
        }
    }
    @media (max-width: 480px) {
        .pl-related-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
<body>

<?php if (isMaintenanceModeEnabled()): ?>
<div style="background: linear-gradient(90deg, rgba(239,68,68,0.25), rgba(245,158,11,0.25)); border-bottom: 1px solid rgba(239,68,68,0.4); color: #fca5a5; padding: 12px 16px; text-align: center; font-size: 0.88rem; font-weight: 500; z-index: 99999; position: relative;">
    🚧 <b>System Maintenance Notice:</b> <?= htmlspecialchars(getMaintenanceReason()) ?>
</div>
<?php endif; ?>

<div id="pl-wrapper">
    <div id="pl-mesh-bg"></div>

    <?php
    $total_approved_count = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'approved'")->fetchColumn();
    ?>

    <?php if ($auto_open_data): ?>
    <!-- ==========================================================================
         STANDALONE PROMPT VIEW (OPTIMIZED FOR GOOGLEBOT & SEARCH ENGINE USERS)
         ========================================================================== -->
    <div id="pl-standalone-hero" class="pl-standalone-container">
        <nav class="pl-breadcrumbs" aria-label="Breadcrumb">
            <a href="https://rtmcreator.com/"><i class="fa-solid fa-house"></i> Home</a>
            <span class="pl-bc-sep">/</span>
            <a href="https://rtmcreator.com/prompt-library/">Prompt Library</a>
            <span class="pl-bc-sep">/</span>
            <a href="https://rtmcreator.com/prompt-library/?category=<?php echo urlencode($auto_open_data['category']); ?>"><?php echo htmlspecialchars($auto_open_data['category']); ?></a>
            <span class="pl-bc-sep">/</span>
            <span class="pl-bc-current"><?php echo htmlspecialchars(mb_strimwidth($auto_open_data['text_output'] ?: 'Prompt #' . $auto_open_id, 0, 45, '...')); ?></span>
        </nav>

        <article class="pl-standalone-card">
            <div class="pl-standalone-grid">
                <!-- Media Column -->
                <div class="pl-standalone-media-col">
                    <?php if (!empty($auto_open_data['video_url'])): ?>
                        <div class="pl-standalone-media-wrap">
                            <video class="pl-standalone-video" controls playsinline poster="<?php echo $auto_open_data['image_urls'][0] ?? ''; ?>">
                                <source src="<?php echo htmlspecialchars($auto_open_data['video_url']); ?>" type="video/mp4">
                            </video>
                        </div>
                    <?php elseif (!empty($auto_open_data['image_urls'])): ?>
                        <div class="pl-standalone-media-list">
                            <?php foreach ($auto_open_data['image_urls'] as $img_src): ?>
                                <div class="pl-standalone-media-wrap">
                                    <img class="pl-standalone-img-bg" src="<?php echo htmlspecialchars($img_src); ?>" alt="" aria-hidden="true">
                                    <img class="pl-standalone-img" src="<?php echo htmlspecialchars($img_src); ?>" alt="<?php echo htmlspecialchars($auto_open_data['text_output'] ?: 'AI Prompt Artwork'); ?>" loading="eager">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="pl-standalone-text-result">
                            <div class="pl-standalone-result-badge"><i class="fa-solid fa-sparkles"></i> AI Generated Result</div>
                            <div class="pl-standalone-result-quote">"<?php echo htmlspecialchars($auto_open_data['text_output'] ?: 'AI Prompt Result'); ?>"</div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info Column -->
                <div class="pl-standalone-info-col">
                    <div class="pl-standalone-badges">
                        <span class="pl-cat-badge"><?php echo htmlspecialchars($auto_open_data['category']); ?></span>
                        <span class="pl-standalone-author"><i class="fa-solid fa-user-astronaut"></i> @<?php echo htmlspecialchars($auto_open_data['username']); ?></span>
                        <span class="pl-standalone-date"><i class="fa-regular fa-clock"></i> <?php echo date('M d, Y', strtotime($auto_open_data['created_at'])); ?></span>
                    </div>

                    <h1 class="pl-standalone-title"><?php echo htmlspecialchars($auto_open_data['text_output'] ?: $auto_open_data['category'] . ' AI Prompt'); ?></h1>

                    <div class="pl-prompt-box">
                        <div class="pl-prompt-box-top">
                            <span class="pl-prompt-box-tag"><i class="fa-solid fa-terminal"></i> PROMPT INSTRUCTIONS</span>
                            <button type="button" class="pl-prompt-quick-copy" onclick="plCopyDirect(this, <?php echo htmlspecialchars(json_encode($auto_open_data['prompt'])); ?>)">
                                <i class="fa-regular fa-clone"></i> Quick Copy
                            </button>
                        </div>
                        <div class="pl-standalone-prompt-text"><?php echo htmlspecialchars($auto_open_data['prompt']); ?></div>
                    </div>

                    <div class="pl-standalone-actions-row">
                        <button type="button" class="pl-standalone-copy-btn" onclick="plCopyDirect(this, <?php echo htmlspecialchars(json_encode($auto_open_data['prompt'])); ?>)">
                            <i class="fa-regular fa-clone"></i> Copy Full Prompt
                        </button>
                        <button type="button" class="pl-standalone-share-btn" onclick="plShareCustom('<?php echo htmlspecialchars(addslashes($auto_open_data['text_output'] ?: 'AI Prompt')); ?>', '<?php echo htmlspecialchars($clean_prompt_url); ?>')">
                            <i class="fa-solid fa-share-nodes"></i>
                        </button>
                    </div>

                    <!-- Creator Callout Banner -->
                    <div class="pl-standalone-earn-banner">
                        <div class="pl-standalone-earn-icon"><i class="fa-brands fa-telegram"></i></div>
                        <div class="pl-standalone-earn-content">
                            <div class="pl-standalone-earn-title">Submit Prompts &amp; Earn Cash!</div>
                            <div class="pl-standalone-earn-desc">Earn ₹0.50 – ₹1.00 per approved prompt + referral bonuses.</div>
                        </div>
                        <a href="https://t.me/Prompts_library_bot" target="_blank" rel="noopener" class="pl-standalone-earn-btn">
                            <span>Open Bot</span> <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </article>

        <!-- Related Prompts Section for Googlebot Crawling & Internal Linking -->
        <?php if (!empty($related_prompts)): ?>
        <section class="pl-related-section" aria-label="Related Prompts">
            <div class="pl-related-header">
                <h2><i class="fa-solid fa-sparkles"></i> More <?php echo htmlspecialchars($auto_open_data['category']); ?> Prompts</h2>
                <a href="https://rtmcreator.com/prompt-library/?category=<?php echo urlencode($auto_open_data['category']); ?>" class="pl-related-more-link">View Category <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            <div class="pl-related-grid">
                <?php foreach ($related_prompts as $rp): ?>
                <a href="<?php echo htmlspecialchars($rp['url']); ?>" class="pl-related-card">
                    <div class="pl-related-img-wrap">
                        <?php if ($rp['thumb']): ?>
                            <img src="<?php echo htmlspecialchars($rp['thumb']); ?>" alt="<?php echo htmlspecialchars($rp['text_output'] ?: 'Related Prompt'); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="pl-related-fallback-icon"><i class="fa-solid fa-quote-left"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="pl-related-body">
                        <div class="pl-related-title"><?php echo htmlspecialchars($rp['text_output'] ?: $rp['category'] . ' Prompt'); ?></div>
                        <div class="pl-related-meta">by @<?php echo htmlspecialchars($rp['username']); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Transition to Full Library Exploration -->
        <div class="pl-explore-divider" id="pl-catalog-anchor">
            <button type="button" class="pl-explore-btn" onclick="plScrollToCatalog()">
                <i class="fa-solid fa-compass"></i> Explore All <?php echo number_format($total_approved_count); ?>+ Community Prompts <i class="fa-solid fa-chevron-down"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>
    <div id="pl-header">
        <h1>Prompt Library</h1>
        <p>Unlock the full potential of AI with our curated community library. Expertly crafted prompts for every creative need.</p>
        <div style="margin-top: 18px;">
            <span class="pl-total-count-badge">✨ <?php echo number_format($total_approved_count); ?>+ Total Approved Prompts</span>
        </div>
    </div>

    <div id="pl-search-wrap">
        <i class="fa-solid fa-magnifying-glass pl-search-icon"></i>
        <input type="text" id="pl-search" placeholder="Search prompts by title, keyword, category, or creator..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
        <button type="button" id="pl-search-btn" class="pl-search-btn">
            <i class="fa-solid fa-magnifying-glass"></i> <span class="pl-search-btn-text">Search</span>
        </button>
    </div>

    <div id="pl-filters">
        <?php
        $categories = getCategories(true);
        $active_cat = isset($_GET['category']) ? $_GET['category'] : 'all';
        ?>
        <button class="pl-filter-btn <?php echo ($active_cat === 'all' ? 'active' : ''); ?>" data-cat="all" onclick="selectCategory(event, 'all')">All Collection</button>
        <button class="pl-filter-btn <?php echo ($active_cat === 'favs' ? 'active' : ''); ?>" data-cat="favs" onclick="selectCategory(event, 'favs')">❤️ My Saved</button>
        <?php foreach ($categories as $cat): 
            $cat_name = $cat['name'];
            $icon = ['AI Art'=>'🎨', 'ChatGPT Prompt'=>'🤖', 'Photo Editing'=>'📷', 'Gaming Banner'=>'🎮', 'Thumbnail'=>'📺', 'Content Writing'=>'✍️', 'Marketing'=>'📣'][$cat_name] ?? '📁';
            $active_class = ($active_cat === $cat_name) ? 'active' : '';
        ?>
            <button class="pl-filter-btn <?php echo $active_class; ?>" data-cat="<?php echo htmlspecialchars($cat_name); ?>" onclick="selectCategory(event, '<?php echo htmlspecialchars(addslashes($cat_name)); ?>')"><?php echo $icon . ' ' . htmlspecialchars($cat_name); ?></button>
        <?php endforeach; ?>
    </div>

    <div id="pl-grid-container">
        <div id="pl-grid">
            <?php foreach ($ssr_prompts as $p): 
                $thumb = count($p['image_urls']) > 0 ? $p['image_urls'][0] : '';
                $icon = ['AI Art'=>'🎨', 'ChatGPT Prompt'=>'🤖', 'Photo Editing'=>'📷', 'Gaming Banner'=>'🎮', 'Thumbnail'=>'📺', 'Content Writing'=>'✍️', 'Marketing'=>'📣'][$p['category']] ?? '📁';
            ?>
            <article class="pl-card" data-id="<?php echo $p['id']; ?>" onclick="openPrompt(<?php echo $p['id']; ?>)">
                <div class="pl-card-img-wrap">
                    <?php if ($thumb): ?>
                        <img class="pl-card-img-bg" src="<?php echo $thumb; ?>" alt="" aria-hidden="true">
                        <img class="pl-card-img" src="<?php echo $thumb; ?>" alt="<?php echo htmlspecialchars($p['text_output']); ?>" loading="lazy">
                    <?php else: ?>
                        <div class="pl-card-text-preview">
                            <i class="fa-solid fa-quote-left"></i>
                            <div><?php echo htmlspecialchars(mb_strimwidth($p['text_output'], 0, 100, "...")); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="pl-card-body">
                    <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                        <span class="pl-cat-badge"><?php echo $icon . " " . $p['category']; ?></span>
                        <?php if (!empty($p['is_challenge'])): ?>
                            <span class="pl-trending-badge">🔥 Trending</span>
                        <?php endif; ?>
                    </div>
                    <h2 class="pl-card-title"><?php echo htmlspecialchars($p['text_output'] ?: $p['category'] . ' Prompt'); ?></h2>
                    <p class="pl-card-preview"><?php echo htmlspecialchars($p['prompt']); ?></p>
                    <div class="pl-card-footer" style="justify-content: flex-end;">
                        <div class="pl-btn-group">
                            <button class="pl-fav-btn" data-favid="<?php echo $p['id']; ?>" onclick="toggleFav(event, <?php echo $p['id']; ?>)" title="Save Prompt">
                                <i class="fa-regular fa-heart"></i>
                            </button>
                            <button class="pl-share-btn" onclick="plShare(event, 'Awesome Prompt', <?php echo $p['id']; ?>)" title="Share Prompt">
                                <i class="fa-solid fa-share-nodes"></i>
                            </button>
                            <button class="pl-copy-btn" onclick="plCopy(event, this, <?php echo htmlspecialchars(json_encode($p['prompt'])); ?>)">📋 Copy</button>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="pl-load-more-wrap" style="text-align: center; margin-top: 80px; position: relative; z-index: 10;">
        <button id="pl-load-more" class="pl-filter-btn" style="padding: 20px 56px;">Discover More Prompts</button>
    </div>

    <!-- LEADERBOARD CARD (Upward Ticker, Non-Clickable) -->
    <?php
    $top_creators = getTopUsers(10);
    if (!empty($top_creators)):
    ?>
    <div class="pl-leaderboard-card">
        <div class="pl-lb-header">
            <div class="pl-lb-title">
                <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i>
                <span>Top Creators Leaderboard</span>
            </div>
            <span class="pl-lb-badge">Lifetime Earnings</span>
        </div>
        <div class="pl-ticker-container">
            <div class="pl-ticker-track">
                <?php 
                $loop_creators = array_merge($top_creators, $top_creators);
                $medals = ['🥇', '🥈', '🥉'];
                $rank_counter = 1;
                foreach ($loop_creators as $tc):
                    $actual_rank = (($rank_counter - 1) % count($top_creators)) + 1;
                    $uname = trim($tc['username'], '@');
                    $earnings = number_format($tc['lifetime_earnings'], 2);
                    $prompts = (int)$tc['approved_count'];
                    $medal = $medals[$actual_rank - 1] ?? ('#' . $actual_rank);
                    $rank_counter++;
                ?>
                    <div class="pl-lb-item">
                        <div class="pl-lb-rank">
                            <span style="font-size:1.1rem; width: 28px; text-align: center; flex-shrink: 0;"><?php echo $medal; ?></span>
                            <span class="pl-lb-username">@<?php echo htmlspecialchars($uname); ?></span>
                        </div>
                        <div class="pl-lb-stats">
                            <span class="pl-lb-prompts"><?php echo $prompts; ?> Prompts</span>
                            <span class="pl-lb-earnings">₹<?php echo $earnings; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- SEO CONTENT SECTION -->
    <div id="pl-seo-content" style="max-width: 1000px; margin: 120px auto 0; padding: 60px; background: rgba(255,255,255,0.02); border-radius: 40px; border: 1px solid var(--pl-border); position: relative; z-index: 10;">
        <h2 style="font-family: 'Outfit', sans-serif; font-size: 2.5rem; color: #fff; margin-bottom: 30px;">Mastering AI with the Ultimate Prompt Library</h2>
        <div style="color: var(--pl-text-dim); line-height: 1.8; font-size: 1.1rem;">
            <p>Welcome to <strong>RTM Creator's AI Prompt Library</strong>, your number one destination for high-performance AI prompts. Whether you are using <strong>ChatGPT</strong> for content writing, <strong>Midjourney</strong> for AI Art, or <strong>Stable Diffusion</strong> for professional design, our community-driven database has everything you need.</p>
            
            <h3 style="color: #fff; margin-top: 40px;">What is a Prompt Library?</h3>
            <p>A prompt library is a curated collection of instructions (prompts) designed to get the best results from Artificial Intelligence models. Instead of guessing what to type, you can browse thousands of proven examples for marketing, coding, gaming banners, and photo editing.</p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px;">
                <div>
                    <h4 style="color: var(--pl-accent);">🎨 For AI Artists</h4>
                    <p>Get pixel-perfect results for portraits, landscapes, and logo designs. Our library includes specific lighting, camera, and style parameters.</p>
                </div>
                <div>
                    <h4 style="color: var(--pl-accent);">✍️ For Writers & SEOs</h4>
                    <p>Generate blog posts, product descriptions, and email templates that sound human and rank high on search engines.</p>
                </div>
            </div>

            <h3 style="color: #fff; margin-top: 40px;">Frequently Asked Questions (FAQ)</h3>
            <div style="border-top: 1px solid var(--pl-border); margin-top: 20px; padding-top: 20px;">
                <p><strong>Q: How do I use these prompts?</strong><br>A: Simply click "Copy" and paste the text into your favorite AI tool like ChatGPT, Claude, or Midjourney.</p>
                <p><strong>Q: Are these prompts free to use?</strong><br>A: Yes! Our library is community-supported and completely free for personal and professional use.</p>
            </div>
        </div>
    </div>
</div>

<div id="pl-modal-overlay">
  <div id="pl-modal">
    <!-- Top Drag Handle & Mobile Close Bar -->
    <div class="pl-modal-header">
      <div class="pl-modal-drag-pill"></div>
      <div class="pl-modal-header-row">
        <div id="pl-modal-badges"></div>
        <button id="pl-modal-close" type="button" aria-label="Close modal">✕</button>
      </div>
    </div>

    <!-- Scrollable Content Body -->
    <div class="pl-modal-scroll-body">
      <div class="pl-modal-grid">
        <div id="pl-modal-left">
          <div id="pl-modal-imgs"></div>
        </div>
        <div id="pl-modal-right">
          <h2 id="pl-modal-title"></h2>
          <div class="pl-prompt-box">
            <div class="pl-prompt-box-top">
              <span class="pl-prompt-box-tag"><i class="fa-solid fa-terminal"></i> The Prompt</span>
              <button type="button" id="pl-modal-quick-copy" class="pl-prompt-quick-copy">
                <i class="fa-regular fa-clone"></i> Quick Copy
              </button>
            </div>
            <div class="pl-prompt-box-text-wrap">
              <div id="pl-modal-prompt"></div>
            </div>
          </div>
          <div id="pl-modal-meta"></div>
        </div>
      </div>
    </div>

    <!-- Pinned Bottom Action Bar -->
    <div class="pl-modal-footer">
      <div class="pl-modal-actions-wrap">
        <button id="pl-modal-fav-btn" class="pl-fav-btn" title="Save Prompt"></button>
        <button id="pl-modal-share-btn" class="pl-share-btn" title="Share Prompt">
           <i class="fa-solid fa-share-nodes"></i>
        </button>
        <button id="pl-modal-copy-btn" class="pl-btn-large">
          <i class="fa-regular fa-clone"></i> Copy Full Prompt
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const API_URL = '<?php echo rtrim(BOT_BASE_URL, "/"); ?>/api.php';
let allPrompts = <?php echo json_encode($ssr_prompts); ?>;
let currentPage = 1;

// Read query params on load
const urlParams = new URLSearchParams(window.location.search);
let activeFilter = urlParams.get('category') || 'all';
let searchTerm = urlParams.get('search') || '';
let activeCreator = urlParams.get('creator') || '';

const catIcons = { 'AI Art': '🎨', 'ChatGPT Prompt': '🤖', 'Photo Editing': '📷', 'Gaming Banner': '🎮', 'Thumbnail': '📺', 'Content Writing': '✍️', 'Marketing': '📣' };

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escJs(str) {
    if (!str) return '';
    return str.replace(/`/g, '\\`').replace(/\$/g, '\\$');
}

function isFav(id) {
    const favs = JSON.parse(localStorage.getItem('pl_favs') || '[]');
    return favs.includes(id.toString());
}

function toggleFav(e, id) {
    if (e) e.stopPropagation();
    let favs = JSON.parse(localStorage.getItem('pl_favs') || '[]');
    const idStr = id.toString();
    if (favs.includes(idStr)) {
      favs = favs.filter(f => f !== idStr);
    } else {
      favs.push(idStr);
    }
    localStorage.setItem('pl_favs', JSON.stringify(favs));
    
    // Update all hearts on page
    document.querySelectorAll(`[data-favid="${id}"]`).forEach(btn => {
      const active = favs.includes(idStr);
      btn.classList.toggle('active', active);
      btn.innerHTML = active ? '<i class="fa-solid fa-heart"></i>' : '<i class="fa-regular fa-heart"></i>';
    });

    // Update modal heart
    const modalHeart = document.getElementById('pl-modal-fav-btn');
    if (modalHeart && modalHeart.dataset.currentid == idStr) {
        const active = favs.includes(idStr);
        modalHeart.classList.toggle('active', active);
        modalHeart.innerHTML = active ? '<i class="fa-solid fa-heart"></i>' : '<i class="fa-regular fa-heart"></i>';
    }
}

function copyToClipboard(text, onDone, onFail) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(onDone).catch(() => fallbackClipboardCopy(text, onDone, onFail));
    } else {
        fallbackClipboardCopy(text, onDone, onFail);
    }
}

function fallbackClipboardCopy(text, onDone, onFail) {
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.left = '0';
        ta.style.opacity = '0';
        ta.setAttribute('readonly', '');
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const success = document.execCommand('copy');
        document.body.removeChild(ta);
        if (success) {
            if (onDone) onDone();
        } else {
            if (onFail) onFail();
        }
    } catch (err) {
        if (onFail) onFail();
    }
}

function plCopy(e, btn, text, originalHtml = '📋 Copy') {
    if (e) e.stopPropagation();
    copyToClipboard(text, () => {
        const oldHtml = btn.innerHTML;
        const oldBg = btn.style.background;
        const oldBorder = btn.style.borderColor;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        btn.style.background = '#10b981';
        btn.style.borderColor = '#10b981';
        setTimeout(() => {
            btn.innerHTML = oldHtml;
            btn.style.background = oldBg;
            btn.style.borderColor = oldBorder;
        }, 2000);
    }, () => {
        alert('Prompt copied! (Please paste manually)');
    });
}

function plSlugify(text) {
    if (!text) return 'prompt';
    return text.toString().toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .trim()
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .substring(0, 60) || 'prompt';
}

function getCleanPromptUrl(p) {
    const slug = plSlugify(p.text_output || (p.category + ' Prompt'));
    return window.location.origin + '/prompt-library/' + p.id + '/' + slug + '/';
}

function updateURL(id = null) {
    try {
        if (id) {
            const p = allPrompts.find(x => x.id == id);
            const slug = p ? plSlugify(p.text_output || (p.category + ' Prompt')) : 'prompt';
            const cleanPath = '/prompt-library/' + id + '/' + slug + '/';
            window.history.pushState({ promptId: id }, '', cleanPath);
        } else {
            const params = [];
            if (activeFilter && activeFilter !== 'all' && activeFilter !== 'favs') {
                params.push('category=' + encodeURIComponent(activeFilter));
            }
            if (searchTerm) {
                params.push('search=' + encodeURIComponent(searchTerm));
            }
            if (activeCreator) {
                params.push('creator=' + encodeURIComponent(activeCreator));
            }
            const query = params.length > 0 ? ('?' + params.join('&')) : '';
            window.history.pushState(null, '', '/prompt-library/' + query);
        }
    } catch(e) {}
}

function plCopyDirect(btn, text) {
    copyToClipboard(text, () => {
        const oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        btn.style.background = '#10b981';
        btn.style.borderColor = '#10b981';
        setTimeout(() => {
            btn.innerHTML = oldHtml;
            btn.style.background = '';
            btn.style.borderColor = '';
        }, 2000);
    }, () => {
        alert('Prompt copied to clipboard!');
    });
}

function plCopySnippet(btn, text) {
    plCopyDirect(btn, text);
}

function plShareCustom(title, url) {
    const shareData = {
        title: title || 'Awesome AI Prompt',
        text: 'Check out this awesome AI prompt on AI Prompt Hub:',
        url: url
    };
    if (navigator.share) {
        navigator.share(shareData).catch(() => {});
    } else {
        copyToClipboard(url, () => {
            alert('Clean prompt link copied to clipboard!');
        });
    }
}

function plScrollToCatalog() {
    const el = document.getElementById('pl-header') || document.getElementById('pl-search-wrap');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth' });
    }
}

function plShare(e, text, id) {
    if (e) e.stopPropagation();
    const p = allPrompts.find(x => x.id == id);
    const cleanUrl = p ? getCleanPromptUrl(p) : (window.location.origin + '/prompt-library/' + id + '/');
    const shareData = {
        title: text || 'Awesome AI Prompt',
        text: 'Check out this awesome AI prompt on AI Prompt Hub:',
        url: cleanUrl
    };
    if (navigator.share) {
        navigator.share(shareData).catch(() => {});
    } else {
        copyToClipboard(cleanUrl, () => {
            alert('Clean prompt link copied to clipboard!');
        });
    }
}

function closeModal() {
    const overlay = document.getElementById('pl-modal-overlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    
    // Pause any playing videos
    const videos = overlay.querySelectorAll('video');
    videos.forEach(v => { try { v.pause(); } catch(e){} });
    
    updateURL();
}

function openPrompt(id) {
    const p = allPrompts.find(x => x.id == id);
    if (!p) return;
    
    const overlay = document.getElementById('pl-modal-overlay');
    const imgsEl = document.getElementById('pl-modal-imgs');
    const icon = catIcons[p.category] || '📁';
    
    // Handle Media with Responsive Wrapper & Aspect Ratio Fit
    if (p.video_url) {
        imgsEl.innerHTML = `
            <div class="pl-modal-media-wrap">
                <video class="pl-modal-video" controls playsinline poster="${p.image_urls[0] || ''}">
                    <source src="${p.video_url}" type="video/mp4">
                </video>
            </div>
        `;
    } else if (p.image_urls && p.image_urls.length > 0) {
        if (p.image_urls.length === 1) {
            imgsEl.innerHTML = `
                <div class="pl-modal-media-wrap">
                    <img class="pl-modal-img-bg" src="${p.image_urls[0]}" alt="" aria-hidden="true">
                    <img class="pl-modal-img" src="${p.image_urls[0]}" alt="${esc(p.text_output || 'AI Prompt')}" loading="eager">
                </div>
            `;
        } else {
            imgsEl.innerHTML = `
                <div class="pl-modal-media-list">
                    ${p.image_urls.map(src => `
                        <div class="pl-modal-media-wrap">
                            <img class="pl-modal-img-bg" src="${src}" alt="" aria-hidden="true">
                            <img class="pl-modal-img" src="${src}" alt="${esc(p.text_output || 'AI Prompt')}" loading="eager">
                        </div>
                    `).join('')}
                </div>
            `;
        }
    } else {
        // Text-only prompt: Show stylized output result box
        imgsEl.innerHTML = `
            <div class="pl-result-box">
                <div style="font-size: 0.76rem; color: var(--pl-accent); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 10px;">
                    <i class="fa-solid fa-sparkles"></i> AI Generated Result
                </div>
                <div style="font-size: 1.12rem; font-style: italic; opacity: 0.95; line-height: 1.55;">"${esc(p.text_output || 'Text Prompt Result')}"</div>
            </div>
        `;
    }

    // Header Badges
    const badgeHtml = `<span class="pl-cat-badge">${icon} ${esc(p.category)}</span>` + 
        (p.is_challenge == 1 ? ` <span class="pl-trending-badge">🔥 Trending</span>` : '');
    document.getElementById('pl-modal-badges').innerHTML = badgeHtml;

    // Title & Prompt
    document.getElementById('pl-modal-title').textContent = p.text_output || (p.category + ' Prompt');
    document.getElementById('pl-modal-prompt').textContent = p.prompt;

    // Metadata: Creator & Date
    const cleanUname = (p.username || 'Anonymous').replace('@', '');
    const dateStr = p.created_at ? new Date(p.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
    document.getElementById('pl-modal-meta').innerHTML = `
        <span><i class="fa-solid fa-hashtag" style="opacity:0.6;"></i> ID: <b>#${p.id}</b></span>
        <span><i class="fa-solid fa-user-astronaut" style="opacity:0.6;"></i> Creator: <a href="?creator=${encodeURIComponent(cleanUname)}" onclick="filterByCreator(event, '${escJs(cleanUname)}')" style="color:var(--pl-accent); font-weight:700; text-decoration:none;">@${esc(cleanUname)}</a></span>
        ${dateStr ? `<span><i class="fa-regular fa-calendar" style="opacity:0.6;"></i> ${dateStr}</span>` : ''}
    `;
    
    // Quick Copy button inside prompt box
    const quickCopyBtn = document.getElementById('pl-modal-quick-copy');
    if (quickCopyBtn) {
        quickCopyBtn.onclick = (e) => plCopy(e, quickCopyBtn, p.prompt, '<i class="fa-regular fa-clone"></i> Quick Copy');
    }

    // Favorite Button
    const favBtn = document.getElementById('pl-modal-fav-btn');
    favBtn.dataset.currentid = p.id;
    const active = isFav(p.id);
    favBtn.classList.toggle('active', active);
    favBtn.innerHTML = active ? '<i class="fa-solid fa-heart"></i>' : '<i class="fa-regular fa-heart"></i>';
    favBtn.onclick = (e) => toggleFav(e, p.id);

    // Share Button
    const shareBtn = document.getElementById('pl-modal-share-btn');
    shareBtn.onclick = (e) => plShare(e, p.text_output || 'AI Prompt', p.id);

    // Main Bottom Copy Button
    const copyBtn = document.getElementById('pl-modal-copy-btn');
    copyBtn.innerHTML = '<i class="fa-regular fa-clone"></i> Copy Full Prompt';
    copyBtn.style.background = '';
    copyBtn.style.borderColor = '';
    copyBtn.onclick = (e) => plCopy(e, copyBtn, p.prompt, '<i class="fa-regular fa-clone"></i> Copy Full Prompt');

    // Reset scroll position to top
    const scrollBody = overlay.querySelector('.pl-modal-scroll-body');
    if (scrollBody) scrollBody.scrollTop = 0;

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    
    updateURL(id);
}

// Close Button Listener
const modalCloseBtn = document.getElementById('pl-modal-close');
if (modalCloseBtn) {
    modalCloseBtn.onclick = closeModal;
}

// Backdrop Click to Close
const modalOverlayEl = document.getElementById('pl-modal-overlay');
if (modalOverlayEl) {
    modalOverlayEl.addEventListener('click', (e) => {
        if (e.target.id === 'pl-modal-overlay') {
            closeModal();
        }
    });
}

// Keyboard Escape Key to Close
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const overlay = document.getElementById('pl-modal-overlay');
        if (overlay && overlay.classList.contains('open')) {
            closeModal();
        }
    }
});

// Mobile Swipe-Down to Dismiss on Header
(function initModalSwipeDismiss() {
    const modalEl = document.getElementById('pl-modal');
    const headerEl = document.querySelector('.pl-modal-header');
    if (!modalEl || !headerEl) return;
    
    let startY = 0;
    let currentY = 0;
    let isDragging = false;

    headerEl.addEventListener('touchstart', (e) => {
        if (window.innerWidth > 768) return;
        startY = e.touches[0].clientY;
        isDragging = true;
    }, { passive: true });

    headerEl.addEventListener('touchmove', (e) => {
        if (!isDragging) return;
        currentY = e.touches[0].clientY;
        const diffY = currentY - startY;
        if (diffY > 0) {
            modalEl.style.transform = `translateY(${diffY}px)`;
            modalEl.style.transition = 'none';
        }
    }, { passive: true });

    headerEl.addEventListener('touchend', () => {
        if (!isDragging) return;
        isDragging = false;
        const diffY = currentY - startY;
        modalEl.style.transition = 'transform 0.25s ease';
        if (diffY > 75) {
            modalEl.style.transform = 'translateY(100%)';
            setTimeout(() => {
                modalEl.style.transform = '';
                closeModal();
            }, 180);
        } else {
            modalEl.style.transform = '';
        }
    });
})();

// Popstate event for seamless Browser Back/Forward navigation with clean URLs
window.addEventListener('popstate', (e) => {
    if (e.state && e.state.promptId) {
        openPrompt(e.state.promptId);
    } else {
        const overlay = document.getElementById('pl-modal-overlay');
        if (overlay && overlay.classList.contains('open')) {
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        }
    }
});

// Filter grid by a specific creator (Portfolio mode)
function filterByCreator(e, creator) {
    if (e) e.preventDefault();
    // Close modal
    document.getElementById('pl-modal-overlay').classList.remove('open');
    document.body.style.overflow = '';

    // Reset filters
    activeFilter = 'all';
    document.querySelectorAll('.pl-filter-btn').forEach(b => b.classList.remove('active'));
    
    // Find the 'All' button and make it active
    const allBtn = document.querySelector('.pl-filter-btn[data-cat="all"]');
    if (allBtn) allBtn.classList.add('active');
    
    // Set creator & query
    activeCreator = creator;
    refreshGrid();
}

// Fetch More (Pagination)
async function fetchMore() {
    currentPage++;
    const params = new URLSearchParams({
        page: currentPage,
        category: activeFilter,
        search: searchTerm,
        creator: activeCreator
    });
    try {
        const resp = await fetch(`${API_URL}?${params.toString()}`);
        const data = await resp.json();
        if (data.success && data.prompts.length > 0) {
            const existingIds = allPrompts.map(p => p.id);
            const newPrompts = data.prompts.filter(p => !existingIds.includes(p.id));
            allPrompts = [...allPrompts, ...newPrompts];
            renderNewItems(newPrompts, false);
            if (data.prompts.length < 12 || (data.total_pages && currentPage >= data.total_pages)) {
                document.getElementById('pl-load-more').style.display = 'none';
            } else {
                document.getElementById('pl-load-more').style.display = 'inline-block';
            }
        } else {
            document.getElementById('pl-load-more').style.display = 'none';
        }
    } catch (err) {
        console.error('Fetch error:', err);
        document.getElementById('pl-load-more').style.display = 'none';
    }
}

// Server-Side search and category filter refresh
async function refreshGrid() {
    currentPage = 1;
    updateURL();

    if (activeFilter === 'favs') {
        const grid = document.getElementById('pl-grid');
        grid.innerHTML = '';
        
        let filtered = allPrompts.filter(p => {
            const matchesSearch = p.prompt.toLowerCase().includes(searchTerm.toLowerCase()) || 
                                 (p.text_output && p.text_output.toLowerCase().includes(searchTerm.toLowerCase()));
            return isFav(p.id) && matchesSearch;
        });

        if (filtered.length === 0) {
            grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 100px; color: var(--pl-text-dim); font-size: 1.2rem; opacity: 0.5;">No saved prompts match this search...</div>';
            document.getElementById('pl-load-more').style.display = 'none';
            return;
        }

        renderNewItems(filtered, true);
        document.getElementById('pl-load-more').style.display = 'none';
        return;
    }

    const params = new URLSearchParams({
        page: 1,
        category: activeFilter,
        search: searchTerm,
        creator: activeCreator
    });

    const grid = document.getElementById('pl-grid');
    grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 100px; color: var(--pl-text-dim); font-size: 1.2rem; opacity: 0.8;"><i class="fa-solid fa-spinner fa-spin"></i> Searching database...</div>';

    try {
        const resp = await fetch(`${API_URL}?${params.toString()}`);
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('API Non-JSON response:', text);
            data = null;
        }

        if (data && data.success && data.prompts && data.prompts.length > 0) {
            allPrompts = data.prompts;
            renderNewItems(data.prompts, true);
            if (data.prompts.length < 12 || (data.total_pages && currentPage >= data.total_pages)) {
                document.getElementById('pl-load-more').style.display = 'none';
            } else {
                document.getElementById('pl-load-more').style.display = 'inline-block';
            }
        } else {
            grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 100px; color: var(--pl-text-dim); font-size: 1.2rem; opacity: 0.5;">No prompts found matching your criteria.</div>';
            document.getElementById('pl-load-more').style.display = 'none';
        }
    } catch (err) {
        console.error('Search error:', err);
        grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 100px; color: var(--pl-text-dim); font-size: 1.2rem; opacity: 0.5;">Error searching catalog. Please try again.</div>';
        document.getElementById('pl-load-more').style.display = 'none';
    }
}

function renderNewItems(items, clear = false) {
    const grid = document.getElementById('pl-grid');
    if (clear) grid.innerHTML = '';
    
    items.forEach(p => {
        const article = document.createElement('article');
        article.className = 'pl-card';
        const icon = catIcons[p.category] || '📁';
        
        let thumb = (p.image_urls && p.image_urls.length > 0) ? p.image_urls[0] : '';

        article.innerHTML = `
            <div class="pl-card-img-wrap">
                ${thumb ? `<img class="pl-card-img-bg" src="${thumb}" alt="" aria-hidden="true"><img class="pl-card-img" src="${thumb}" alt="${esc(p.text_output)}" loading="lazy">` : 
                `<div class="pl-card-text-preview"><i class="fa-solid fa-quote-left"></i><div>${esc(p.text_output ? p.text_output.substring(0, 80) + '...' : 'Text Prompt Result')}</div></div>`}
            </div>
            <div class="pl-card-body">
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                    <span class="pl-cat-badge">${icon} ${p.category}</span>
                    ${p.is_challenge == 1 ? '<span class="pl-trending-badge">🔥 Trending</span>' : ''}
                </div>
                <h2 class="pl-card-title">${esc(p.text_output || p.category + ' Prompt')}</h2>
                <p class="pl-card-preview">${esc(p.prompt)}</p>
                <div class="pl-card-footer" style="justify-content: flex-end;">
                    <div class="pl-btn-group">
                        <button class="pl-fav-btn ${isFav(p.id) ? 'active' : ''}" data-favid="${p.id}" onclick="toggleFav(event, ${p.id})" title="Save Prompt">
                            <i class="${isFav(p.id) ? 'fa-solid' : 'fa-regular'} fa-heart"></i>
                        </button>
                        <button class="pl-share-btn" onclick="plShare(event, 'Awesome Prompt', ${p.id})" title="Share Prompt">
                            <i class="fa-solid fa-share-nodes"></i>
                        </button>
                        <button class="pl-copy-btn" onclick="plCopy(event, this, \`${escJs(p.prompt)}\`)">📋 Copy</button>
                    </div>
                </div>
            </div>
        `;
        article.onclick = () => openPrompt(p.id);
        grid.appendChild(article);
    });
}

document.getElementById('pl-load-more').onclick = fetchMore;

// Search Listeners
let searchTimeout;
const searchInput = document.getElementById('pl-search');
const searchBtn   = document.getElementById('pl-search-btn');

function triggerSearch() {
    clearTimeout(searchTimeout);
    if (searchInput) searchTerm = searchInput.value;
    refreshGrid();
}

if (searchInput) {
    searchInput.addEventListener('input', (e) => {
        searchTerm = e.target.value;
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            refreshGrid();
        }, 350);
    });

    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            triggerSearch();
        }
    });
}

if (searchBtn) {
    searchBtn.addEventListener('click', (e) => {
        e.preventDefault();
        triggerSearch();
    });
}

// Category Selection Function
function selectCategory(e, catName) {
    if (e) e.preventDefault();
    document.querySelectorAll('#pl-filters .pl-filter-btn').forEach(b => b.classList.remove('active'));
    
    let targetBtn = (e && e.currentTarget) ? e.currentTarget : document.querySelector(`.pl-filter-btn[data-cat="${catName}"]`);
    if (targetBtn) targetBtn.classList.add('active');
    
    activeFilter = catName;
    activeCreator = ''; // Clear creator filter when switching categories
    refreshGrid();
}

// Initialize Hearts and States on Page Load
document.addEventListener('DOMContentLoaded', () => {
    allPrompts.forEach(p => {
        if (isFav(p.id)) {
            const btns = document.querySelectorAll(`[data-favid="${p.id}"]`);
            btns.forEach(btn => {
                btn.classList.add('active');
                btn.innerHTML = '<i class="fa-solid fa-heart"></i>';
            });
        }
    });

    // Check if initial list is less than page limit
    if (allPrompts.length < 12) {
        document.getElementById('pl-load-more').style.display = 'none';
    }
});

// Register current prompt in allPrompts list for modal interactions
<?php if ($auto_open_data): ?>
    if (!allPrompts.find(x => x.id == <?php echo $auto_open_id; ?>)) {
        allPrompts.push(<?php echo json_encode($auto_open_data); ?>);
    }
<?php endif; ?>

</script>
<!-- FLOATING TELEGRAM BOT POPUP BANNER (PREMIUM REDESIGN) -->
<div id="pl-tg-banner" class="pl-tg-banner" role="complementary" aria-label="Telegram Bot Announcement">
    <button class="pl-tg-banner-close" onclick="dismissTgBanner()" title="Close notification" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
    </button>
    
    <div class="pl-tg-banner-icon-wrap">
        <div class="pl-tg-banner-icon">
            <i class="fa-brands fa-telegram"></i>
        </div>
        <span class="pl-tg-live-dot" title="Active creator rewards"></span>
    </div>
    
    <div class="pl-tg-banner-content">
        <div class="pl-tg-banner-tag">
            <i class="fa-solid fa-bolt"></i> CREATOR PROGRAM
        </div>
        <div class="pl-tg-banner-title">Submit Prompts &amp; Earn Cash</div>
        <div class="pl-tg-banner-desc">Get <strong class="pl-tg-accent">₹0.50 – ₹1.00</strong> per prompt + daily rewards</div>
    </div>
    
    <a href="https://t.me/Prompts_library_bot" target="_blank" rel="noopener" class="pl-tg-banner-btn">
        <span>Open Bot</span>
        <i class="fa-solid fa-arrow-right"></i>
    </a>
</div>

<script>
function dismissTgBanner() {
    const banner = document.getElementById('pl-tg-banner');
    if (!banner) return;
    banner.classList.add('pl-banner-hiding');
    setTimeout(() => { banner.style.display = 'none'; }, 320);
    try { sessionStorage.setItem('pl_tg_dismissed', '1'); } catch(e){}
}
(function() {
    try {
        if (sessionStorage.getItem('pl_tg_dismissed') === '1') {
            const b = document.getElementById('pl-tg-banner');
            if (b) b.style.display = 'none';
        }
    } catch(e){}
})();
</script>

<?php if (!$is_wordpress): ?>
</body>
</html>
<?php endif; ?>
