<?php
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/db.php';

if (!defined('BOT_BASE_URL')) {
    define('BOT_BASE_URL', 'https://rtmcreator.com/bots/prompt-bot/');
}

$posts_raw = getCalendarPosts();

// Normalize media and build JS-safe data
$posts_data = [];
foreach ($posts_raw as $p) {
    $media_url = '';
    $raw_media = trim($p['local_media'] ?? '');
    $base = rtrim(BOT_BASE_URL, '/');

    if (!empty($raw_media)) {
        $local_data = json_decode($raw_media, true);
        if (is_array($local_data)) {
            if (isset($local_data['thumb']) && !empty($local_data['thumb'])) {
                $item = $local_data['thumb'];
                $media_url = (strpos($item, 'http') === 0) ? $item : $base . '/' . ltrim($item, '/');
            } elseif (isset($local_data[0]) && !empty($local_data[0])) {
                $item = $local_data[0];
                $media_url = (strpos($item, 'http') === 0) ? $item : $base . '/' . ltrim($item, '/');
            }
        } else {
            $media_url = (strpos($raw_media, 'http') === 0) ? $raw_media : $base . '/' . ltrim($raw_media, '/');
        }
    }

    $event_time = !empty($p['scheduled_at']) ? $p['scheduled_at'] : $p['created_at'];
    $is_published = !empty($p['posted_to_channel']);

    $posts_data[] = [
        'id'           => (int)$p['id'],
        'title'        => $p['text_output'] ?: ($p['category'] . ' Prompt'),
        'category'     => $p['category'],
        'output_type'  => $p['output_type'],
        'scheduled_at' => $event_time,
        'username'     => $p['username'],
        'is_challenge' => !empty($p['is_challenge']),
        'is_published' => $is_published,
        'status'       => $is_published ? 'published' : 'scheduled',
        'media_url'    => $media_url,
    ];
}

// Global Stats for Server-Side Rendering
global $pdo;
$stmt = $pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'approved' OR posted_to_channel = 1");
$total_approved = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'scheduled' OR (status = 'approved' AND posted_to_channel = 0 AND scheduled_at IS NOT NULL)");
$total_scheduled = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM submissions WHERE (status = 'approved' OR status = 'scheduled' OR posted_to_channel = 1) AND DATE(COALESCE(scheduled_at, created_at)) = CURDATE()");
$today_count = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM submissions WHERE (status = 'approved' OR status = 'scheduled' OR posted_to_channel = 1) AND YEARWEEK(COALESCE(scheduled_at, created_at), 1) = YEARWEEK(CURDATE(), 1)");
$week_count = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM submissions WHERE is_challenge = 1 AND (status = 'approved' OR status = 'scheduled' OR posted_to_channel = 1)");
$trending_count = (int)$stmt->fetchColumn();

$top_creators = getTopUsers(5);

$total_count = count($posts_data);
$json_posts  = json_encode($posts_data, JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Content & Analytics Dashboard — Prompt Bot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg: #0d0f1a;
    --surface: #141728;
    --surface2: #1c2035;
    --border: rgba(255,255,255,0.08);
    --accent: #7c3aed;
    --accent-light: #a78bfa;
    --accent-glow: rgba(124, 58, 237, 0.3);
    --text: #e2e8f0;
    --text-dim: #7b8fa1;
    --success: #22c55e;
    --warning: #f59e0b;
    --danger: #ef4444;
    --blue: #3b82f6;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Outfit', sans-serif;
    min-height: 100vh;
    padding: 24px 16px 60px;
}
.page-wrap { max-width: 1200px; margin: 0 auto; }

/* Clean Emoji-Free Title Header */
.dashboard-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
    gap: 16px;
}
.dashboard-title h1 {
    font-size: 1.8rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.02em;
}
.dashboard-title p {
    font-size: 0.88rem;
    color: var(--text-dim);
    margin-top: 4px;
}
.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.store-link-btn {
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--border);
    color: #fff;
    text-decoration: none;
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.2s;
}
.store-link-btn:hover { background: rgba(255,255,255,0.12); }

.view-toggle {
    display: flex; background: var(--surface2);
    border-radius: 12px; padding: 3px; border: 1px solid var(--border);
}
.view-toggle button {
    background: none; border: none; color: var(--text-dim);
    padding: 8px 16px; border-radius: 9px; font-weight: 700;
    font-size: 0.85rem; cursor: pointer; transition: all 0.2s;
    display: flex; align-items: center; gap: 6px; font-family: 'Outfit', sans-serif;
}
.view-toggle button.active {
    background: var(--accent); color: #fff;
    box-shadow: 0 4px 12px var(--accent-glow);
}

/* 5 Metrics Cards Grid */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.metric-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s, border-color 0.2s;
}
.metric-card:hover {
    transform: translateY(-2px);
    border-color: rgba(255,255,255,0.15);
}
.metric-icon {
    width: 50px; height: 50px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; flex-shrink: 0;
}
.metric-icon.green  { background: rgba(34,197,94,0.15); color: var(--success); }
.metric-icon.purple { background: rgba(124,58,237,0.15); color: var(--accent-light); }
.metric-icon.orange { background: rgba(245,158,11,0.15); color: var(--warning); }
.metric-icon.blue   { background: rgba(59,130,246,0.15); color: var(--blue); }
.metric-icon.red    { background: rgba(239,68,68,0.15); color: var(--danger); }

.metric-val { font-size: 1.6rem; font-weight: 900; color: #fff; line-height: 1; }
.metric-lbl { font-size: 0.8rem; color: var(--text-dim); font-weight: 600; margin-top: 4px; }

/* Dashboard Layout Grid: Left Leaderboard, Right Calendar */
.dashboard-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 24px;
}

/* Leaderboard Sidebar Widget */
.leaderboard-widget {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 22px;
    height: fit-content;
}
.widget-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}
.widget-badge {
    background: rgba(124,58,237,0.2);
    color: var(--accent-light);
    border-radius: 100px;
    padding: 3px 10px;
    font-size: 0.72rem;
    font-weight: 700;
}
.lb-list { display: flex; flex-direction: column; gap: 12px; }
.lb-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 10px 12px;
    min-width: 0;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
}
.lb-row:hover {
    border-color: rgba(124,58,237,0.4);
    background: rgba(124,58,237,0.08);
}
.lb-user-wrap {
    display: flex; align-items: center; gap: 10px; min-width: 0; overflow: hidden;
}
.lb-rank-num { font-size: 1rem; width: 22px; text-align: center; font-weight: 800; flex-shrink: 0; }
.lb-uname { color: #fff; font-weight: 700; font-size: 0.88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lb-meta { text-align: right; flex-shrink: 0; }
.lb-prompts-tag { font-size: 0.72rem; color: var(--accent-light); font-weight: 700; }
.lb-earnings-tag { font-size: 0.8rem; color: var(--success); font-weight: 800; margin-top: 1px; }

/* Filter Tabs & Navigation */
.cal-controls-strip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}
.filter-pills { display: flex; gap: 8px; flex-wrap: wrap; }
.filter-pill {
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--text-dim);
    border-radius: 100px;
    padding: 6px 14px;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Outfit', sans-serif;
}
.filter-pill.active {
    background: rgba(124,58,237,0.25);
    border-color: rgba(124,58,237,0.5);
    color: var(--accent-light);
}

.cal-nav { display: flex; align-items: center; gap: 8px; }
.cal-nav button {
    background: var(--surface2); border: 1px solid var(--border);
    color: var(--text); width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background 0.15s; font-size: 0.85rem;
}
.cal-nav button:hover { background: rgba(255,255,255,0.1); }
.cal-month-label { font-size: 1rem; font-weight: 700; min-width: 130px; text-align: center; }

/* Compact Month Calendar Grid */
.calendar-grid-wrap { overflow: hidden; border-radius: 18px; width: 100%; }
.calendar-grid {
    display: grid; grid-template-columns: repeat(7, 1fr);
    gap: 2px; background: var(--border);
    border: 1px solid var(--border); border-radius: 18px; overflow: hidden;
    width: 100%; min-width: 0;
}
.day-header {
    background: var(--surface2); padding: 10px 4px;
    font-size: 0.75rem; font-weight: 700; color: var(--text-dim);
    text-transform: uppercase; text-align: center;
}
.day-cell {
    background: var(--surface); min-height: 78px; padding: 8px;
    position: relative; transition: background 0.15s, border-color 0.15s;
    display: flex; flex-direction: column; justify-content: space-between;
}
.day-cell.other-month { background: rgba(13,15,26,0.6); opacity: 0.4; }
.day-cell.today { background: rgba(124,58,237,0.12); }
.day-cell.today .day-num { color: var(--accent-light); font-weight: 900; }
.day-cell.has-posts { cursor: pointer; }
.day-cell.has-posts:hover { background: var(--surface2); }
.day-num { font-size: 0.9rem; font-weight: 800; color: var(--text-dim); }

.day-dots-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 6px;
}
.day-dot {
    font-size: 0.68rem;
    font-weight: 800;
    padding: 2px 6px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 2px;
}
.day-dot.published {
    background: rgba(34,197,94,0.2);
    color: #22c55e;
    border: 1px solid rgba(34,197,94,0.3);
}
.day-dot.scheduled {
    background: rgba(124,58,237,0.2);
    color: #a78bfa;
    border: 1px solid rgba(124,58,237,0.3);
}

/* Timeline List View */
.list-view { display: flex; flex-direction: column; gap: 10px; }
.list-group-header {
    font-size: 0.85rem;
    font-weight: 800;
    color: var(--accent-light);
    margin-top: 14px;
    margin-bottom: 6px;
    padding: 6px 12px;
    background: rgba(124,58,237,0.1);
    border-radius: 10px;
    border-left: 3px solid var(--accent);
    display: flex;
    align-items: center;
    gap: 8px;
}
.list-item {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 12px 16px;
    display: flex; align-items: center; gap: 14px; cursor: pointer; transition: transform 0.15s, border-color 0.15s;
}
.list-item:hover { transform: translateX(3px); border-color: rgba(124,58,237,0.4); }
.list-thumb {
    width: 48px; height: 48px; border-radius: 10px; object-fit: contain;
    background: var(--surface2); flex-shrink: 0; display: flex; align-items: center; justify-content: center;
}
.list-thumb img { width: 48px; height: 48px; border-radius: 10px; object-fit: contain; object-position: center; }
.list-info { flex: 1; min-width: 0; }
.list-title { font-size: 0.95rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.list-meta { font-size: 0.78rem; color: var(--text-dim); margin-top: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.status-badge-published { background: rgba(34,197,94,0.15); color: var(--success); border: 1px solid rgba(34,197,94,0.3); border-radius: 6px; padding: 2px 8px; font-size: 0.72rem; font-weight: 700; }
.status-badge-scheduled { background: rgba(124,58,237,0.15); color: var(--accent-light); border: 1px solid rgba(124,58,237,0.3); border-radius: 6px; padding: 2px 8px; font-size: 0.72rem; font-weight: 700; }

/* Interactive Modal Styling */
.modal-overlay {
    position: fixed; inset: 0; z-index: 1000;
    background: rgba(0,0,0,0.75); backdrop-filter: blur(12px);
    display: flex; align-items: center; justify-content: center;
    padding: 16px; opacity: 0; pointer-events: none; transition: opacity 0.25s ease;
}
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 24px; width: 100%; max-width: 500px;
    box-shadow: 0 40px 80px rgba(0,0,0,0.6);
    transform: scale(0.92) translateY(20px); transition: transform 0.25s ease;
    overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;
}
.modal-overlay.open .modal-card { transform: scale(1) translateY(0); }
.modal-img-wrap {
    width: 100%; height: 220px; background: var(--surface2);
    display: flex; align-items: center; justify-content: center;
    position: relative; overflow: hidden;
}
.modal-img-wrap img { width: 100%; height: 220px; object-fit: contain; object-position: center; }
.modal-content-body { padding: 22px; overflow-y: auto; }
.modal-badges-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.modal-title-text { font-size: 1.15rem; font-weight: 800; color: #fff; margin-bottom: 12px; line-height: 1.4; }
.modal-meta-box {
    background: rgba(255,255,255,0.03); border: 1px solid var(--border);
    border-radius: 14px; padding: 14px; margin-bottom: 16px;
    display: flex; flex-direction: column; gap: 8px; font-size: 0.85rem; color: var(--text-dim);
}
.modal-actions-row { display: flex; gap: 10px; }
.modal-btn-store {
    flex: 1; background: var(--accent); color: #fff; text-decoration: none;
    padding: 12px; border-radius: 12px; font-weight: 700; font-size: 0.9rem;
    text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    transition: background 0.2s;
}
.modal-btn-store:hover { background: #6d28d9; }
.modal-btn-close {
    background: var(--surface2); color: var(--text); border: 1px solid var(--border);
    padding: 12px 20px; border-radius: 12px; font-weight: 700; font-size: 0.9rem;
    cursor: pointer; transition: background 0.2s; font-family: 'Outfit', sans-serif;
}
.modal-btn-close:hover { background: rgba(255,255,255,0.1); }

/* Responsive media queries — Zero Horizontal Scroll Enforcement */
@media (max-width: 900px) {
    .dashboard-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    .leaderboard-widget {
        order: 2;
    }
}

@media (max-width: 640px) {
    html, body {
        overflow-x: hidden;
        width: 100%;
        max-width: 100vw;
    }
    body {
        padding: 12px 8px 40px;
    }
    .page-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }

    .dashboard-header {
        margin-bottom: 16px;
        padding-bottom: 12px;
        gap: 10px;
    }
    .dashboard-title h1 {
        font-size: 1.25rem;
    }
    .dashboard-title p {
        font-size: 0.78rem;
    }
    .view-toggle button {
        padding: 6px 10px;
        font-size: 0.75rem;
    }

    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        margin-bottom: 16px;
    }
    .metric-card {
        padding: 10px 12px;
        gap: 8px;
        border-radius: 12px;
        min-width: 0;
        overflow: hidden;
    }
    .metric-icon {
        width: 34px; height: 34px;
        font-size: 0.95rem;
        border-radius: 8px;
    }
    .metric-val {
        font-size: 1.15rem;
    }
    .metric-lbl {
        font-size: 0.68rem;
    }

    .cal-controls-strip {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    .filter-pills {
        justify-content: flex-start;
        width: 100%;
        flex-wrap: wrap;
        overflow-x: hidden;
        gap: 6px;
    }
    .filter-pill {
        padding: 5px 10px;
        font-size: 0.72rem;
        flex-shrink: 0;
    }
    .cal-nav {
        width: 100%;
        justify-content: space-between;
    }
    .cal-month-label {
        font-size: 0.88rem;
        min-width: auto;
    }

    .calendar-grid-wrap {
        width: 100%;
        overflow-x: hidden;
        border-radius: 12px;
    }
    .calendar-grid {
        min-width: 0 !important;
        width: 100% !important;
        border-radius: 12px;
    }
    .day-header {
        padding: 4px 1px;
        font-size: 0.62rem;
    }
    .day-cell {
        min-height: 48px;
        padding: 4px 2px;
    }
    .day-num {
        font-size: 0.75rem;
        margin-bottom: 1px;
    }
    .day-dot {
        font-size: 0.6rem;
        padding: 1px 4px;
    }

    .list-item {
        padding: 10px 10px;
        gap: 8px;
        min-width: 0;
        overflow: hidden;
    }
    .list-thumb {
        width: 38px; height: 38px;
        border-radius: 8px;
    }
    .list-thumb img {
        width: 38px; height: 38px;
        border-radius: 8px;
    }
    .list-title {
        font-size: 0.82rem;
    }
    .list-meta {
        font-size: 0.68rem;
        gap: 4px;
    }

    .leaderboard-widget {
        padding: 14px;
        border-radius: 14px;
        width: 100%;
        overflow: hidden;
    }
    .widget-title {
        font-size: 0.9rem;
        margin-bottom: 10px;
        padding-bottom: 8px;
    }
    .lb-row {
        padding: 7px 8px;
    }
    .lb-uname {
        font-size: 0.78rem;
    }
    .lb-prompts-tag {
        font-size: 0.65rem;
    }
    .lb-earnings-tag {
        font-size: 0.72rem;
    }

    .modal-card {
        border-radius: 18px !important;
        max-height: 92vh !important;
    }
    .modal-img-wrap {
        height: 180px !important;
    }
    .modal-img-wrap img {
        height: 180px !important;
    }
    .modal-content-body {
        padding: 16px !important;
    }
    .modal-title-text {
        font-size: 1rem !important;
        line-height: 1.35 !important;
        word-break: break-word;
    }
    .modal-meta-box {
        padding: 10px !important;
        font-size: 0.78rem !important;
        border-radius: 10px !important;
    }
}
@media (max-width: 380px) {
    .metrics-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>
</head>
<body>
<div class="page-wrap">

    <!-- Emoji-Free Clean Header -->
    <div class="dashboard-header">
        <div class="dashboard-title">
            <h1>Admin Content & Analytics Dashboard</h1>
            <p>Real-time prompt publishing, scheduling metrics, and creator leaderboards</p>
        </div>
        <div class="header-actions">
            <a href="library.php" class="store-link-btn"><i class="fa-solid fa-store"></i> Web Prompt Store</a>
            <div class="view-toggle">
                <button class="active" id="btn-month" onclick="setView('month')"><i class="fa-solid fa-calendar-days"></i> Month</button>
                <button id="btn-list" onclick="setView('list')"><i class="fa-solid fa-list"></i> List</button>
            </div>
        </div>
    </div>

    <!-- 5 Real-time Metric Cards -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-icon green"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="metric-val" id="stat-approved"><?php echo $total_approved; ?></div>
                <div class="metric-lbl">Total Approved</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon purple"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="metric-val" id="stat-scheduled"><?php echo $total_scheduled; ?></div>
                <div class="metric-lbl">Total Scheduled</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon orange"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="metric-val" id="stat-today"><?php echo $today_count; ?></div>
                <div class="metric-lbl">Prompts Today</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon blue"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <div class="metric-val" id="stat-week"><?php echo $week_count; ?></div>
                <div class="metric-lbl">This Week</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon red"><i class="fa-solid fa-fire"></i></div>
            <div>
                <div class="metric-val" id="stat-trending"><?php echo $trending_count; ?></div>
                <div class="metric-lbl">Trending Challenges</div>
            </div>
        </div>
    </div>

    <!-- Dashboard Layout: Left Leaderboard, Right Calendar -->
    <div class="dashboard-layout">
        
        <!-- Left: Top Creators Leaderboard Widget -->
        <div class="leaderboard-widget">
            <div class="widget-title">
                <span>Top Creators Leaderboard</span>
                <span class="widget-badge">Top 5</span>
            </div>
            <div class="lb-list">
                <?php 
                $medals = ['🥇', '🥈', '🥉', '#4', '#5'];
                $rank_i = 0;
                foreach ($top_creators as $tc):
                    $uname = trim($tc['username'], '@');
                    $earnings = number_format($tc['lifetime_earnings'], 2);
                    $prompts = (int)$tc['approved_count'];
                    $medal = $medals[$rank_i] ?? ('#' . ($rank_i + 1));
                    $rank_i++;
                ?>
                <div class="lb-row" onclick="filterByCreator('@<?php echo htmlspecialchars($uname); ?>')">
                    <div class="lb-user-wrap">
                        <div class="lb-rank-num"><?php echo $medal; ?></div>
                        <div class="lb-uname">@<?php echo htmlspecialchars($uname); ?></div>
                    </div>
                    <div class="lb-meta">
                        <div class="lb-prompts-tag"><?php echo $prompts; ?> Prompts</div>
                        <div class="lb-earnings-tag">₹<?php echo $earnings; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right: Content Calendar & Controls -->
        <div>
            <div class="cal-controls-strip">
                <div class="filter-pills">
                    <button class="filter-pill active" onclick="setFilter('all', this)">All Posts (<?php echo $total_count; ?>)</button>
                    <button class="filter-pill" onclick="setFilter('published', this)">Published (<?php echo $total_approved; ?>)</button>
                    <button class="filter-pill" onclick="setFilter('scheduled', this)">Scheduled (<?php echo $total_scheduled; ?>)</button>
                </div>
                <div class="cal-nav">
                    <button onclick="changeMonth(-1)"><i class="fa-solid fa-chevron-left"></i></button>
                    <div class="cal-month-label" id="month-label"></div>
                    <button onclick="changeMonth(1)"><i class="fa-solid fa-chevron-right"></i></button>
                    <button onclick="goToday()" style="background:rgba(124,58,237,0.2);color:var(--accent-light);width:auto;padding:0 12px;font-weight:700;">Today</button>
                </div>
            </div>

            <div id="main-content"></div>
        </div>

    </div>

</div>

<!-- Day Schedule Modal -->
<div class="modal-overlay" id="day-modal-overlay" onclick="closeDayModal(event)">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div style="padding:18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3 id="day-modal-title" style="font-size:1.1rem;font-weight:800;color:#fff;"></h3>
                <div style="font-size:0.78rem;color:var(--text-dim);margin-top:2px;" id="day-modal-subtitle"></div>
            </div>
            <button class="modal-btn-close" onclick="closeDayModalDirect()" style="padding:6px 14px;font-size:0.8rem;">Close</button>
        </div>
        <div class="modal-content-body" id="day-modal-body" style="padding:16px;"></div>
    </div>
</div>

<!-- Interactive Detail Modal -->
<div class="modal-overlay" id="post-modal-overlay" onclick="closePostModal(event)">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-img-wrap" id="modal-media-box">
            <i class="fa-solid fa-quote-left" style="font-size:3rem;color:var(--text-dim)"></i>
        </div>
        <div class="modal-content-body">
            <div class="modal-badges-row" id="modal-badges-box"></div>
            <div class="modal-title-text" id="modal-title-text"></div>
            <div class="modal-meta-box" id="modal-meta-box"></div>
            <div class="modal-actions-row">
                <a href="#" id="modal-store-link" target="_blank" class="modal-btn-store"><i class="fa-solid fa-store"></i> View in Web Store</a>
                <button class="modal-btn-close" onclick="closePostModalDirect()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const POSTS = <?php echo $json_posts; ?>;
const DAY_NAMES   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const CAT_ICONS   = {'AI Art':'🎨', 'ChatGPT Prompt':'🤖', 'Photo Editing':'📷', 'Gaming Banner':'🎮', 'Thumbnail':'📺', 'Content Writing':'✍️', 'Marketing':'📣'};

let currentView = 'month';
let currentFilter = 'all';
let viewYear, viewMonth;

function init() {
    const now = new Date();
    viewYear  = now.getFullYear();
    viewMonth = now.getMonth();
    
    if (window.innerWidth <= 768) {
        currentView = 'list';
        document.getElementById('btn-month').classList.remove('active');
        document.getElementById('btn-list').classList.add('active');
    }
    render();
}

function setView(v) {
    currentView = v;
    document.getElementById('btn-month').classList.toggle('active', v==='month');
    document.getElementById('btn-list').classList.toggle('active',  v==='list');
    render();
}

function setFilter(f, btn) {
    currentFilter = f;
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    render();
}

function filterByCreator(uname) {
    const cleanName = uname.replace('@','').toLowerCase();
    currentFilter = 'creator_' + cleanName;
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
    render();
}

function changeMonth(delta) {
    viewMonth += delta;
    if (viewMonth > 11) { viewMonth = 0; viewYear++; }
    if (viewMonth < 0)  { viewMonth = 11; viewYear--; }
    render();
}

function goToday() {
    const now = new Date();
    viewYear  = now.getFullYear();
    viewMonth = now.getMonth();
    render();
}

function getFilteredPosts() {
    if (currentFilter === 'published') return POSTS.filter(p => p.is_published);
    if (currentFilter === 'scheduled') return POSTS.filter(p => !p.is_published);
    if (currentFilter.startsWith('creator_')) {
        const target = currentFilter.substring(8);
        return POSTS.filter(p => (p.username || '').replace('@','').toLowerCase() === target);
    }
    return POSTS;
}

function render() {
    document.getElementById('month-label').textContent = MONTH_NAMES[viewMonth] + ' ' + viewYear;
    if (currentView === 'month') renderMonth();
    else renderList();
}

function renderMonth() {
    const content = document.getElementById('main-content');
    const filtered = getFilteredPosts();

    const firstDay = new Date(viewYear, viewMonth, 1).getDay();
    const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
    const daysInPrev  = new Date(viewYear, viewMonth, 0).getDate();

    const today = new Date();
    const todayY = today.getFullYear(), todayM = today.getMonth(), todayD = today.getDate();

    const byDate = {};
    filtered.forEach(p => {
        const d = new Date(p.scheduled_at);
        if (d.getFullYear() === viewYear && d.getMonth() === viewMonth) {
            const key = d.getDate();
            if (!byDate[key]) byDate[key] = [];
            byDate[key].push(p);
        }
    });

    let html = '<div class="calendar-grid-wrap"><div class="calendar-grid">';
    DAY_NAMES.forEach(d => { html += `<div class="day-header">${d}</div>`; });

    for (let i = 0; i < firstDay; i++) {
        const d = daysInPrev - firstDay + i + 1;
        html += `<div class="day-cell other-month"><div class="day-num">${d}</div></div>`;
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const isToday = (viewYear === todayY && viewMonth === todayM && d === todayD);
        const posts   = byDate[d] || [];
        const hasPosts = posts.length > 0;
        const cls = ['day-cell', isToday ? 'today' : '', hasPosts ? 'has-posts' : ''].filter(Boolean).join(' ');

        const publishedCount = posts.filter(p => p.is_published).length;
        const scheduledCount = posts.length - publishedCount;

        html += `<div class="${cls}" ${hasPosts ? `onclick="openDayModal(${d})"` : ''}>`;
        html += `<div class="day-num">${d}</div>`;
        
        if (hasPosts) {
            html += `<div class="day-dots-wrap">`;
            if (publishedCount > 0) html += `<span class="day-dot published" title="${publishedCount} Published">● ${publishedCount}</span>`;
            if (scheduledCount > 0) html += `<span class="day-dot scheduled" title="${scheduledCount} Scheduled">● ${scheduledCount}</span>`;
            html += `</div>`;
        }
        
        html += `</div>`;
    }

    const cells = firstDay + daysInMonth;
    const remaining = (7 - (cells % 7)) % 7;
    for (let i = 1; i <= remaining; i++) {
        html += `<div class="day-cell other-month"><div class="day-num">${i}</div></div>`;
    }

    html += '</div></div>';
    content.innerHTML = html;
}

function renderList() {
    const content = document.getElementById('main-content');
    const filtered = getFilteredPosts();

    // Strictly filter List View to the selected Month & Year
    const monthPosts = filtered.filter(p => {
        const d = new Date(p.scheduled_at);
        return d.getFullYear() === viewYear && d.getMonth() === viewMonth;
    });

    if (monthPosts.length === 0) {
        content.innerHTML = `<div style="text-align:center;padding:60px;color:var(--text-dim);">No posts for <b>${MONTH_NAMES[viewMonth]} ${viewYear}</b> matching current filter.</div>`;
        return;
    }

    const groups = {};
    monthPosts.forEach(p => {
        const d = new Date(p.scheduled_at);
        const key = d.toLocaleDateString('en-IN', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
        if (!groups[key]) groups[key] = [];
        groups[key].push(p);
    });

    let html = '<div class="list-view">';
    Object.keys(groups).forEach(dateKey => {
        html += `<div class="list-group-header"><i class="fa-solid fa-calendar-day"></i> ${dateKey}</div>`;
        groups[dateKey].forEach(p => {
            const icon = CAT_ICONS[p.category] || '📁';
            const time = new Date(p.scheduled_at).toLocaleTimeString('en-IN', {hour:'2-digit',minute:'2-digit',hour12:true});
            const thumb = p.media_url ? `<img src="${p.media_url}" onerror="this.parentNode.innerHTML='${icon}'">` : icon;
            const statusBadge = p.is_published
                ? '<span class="status-badge-published">Published</span>'
                : '<span class="status-badge-scheduled">Scheduled</span>';

            html += `<div class="list-item" onclick="openPostModal(${p.id})">
                <div class="list-thumb">${thumb}</div>
                <div class="list-info">
                    <div class="list-title">${escHtml(p.title)}</div>
                    <div class="list-meta">
                        ${statusBadge}
                        <span>${icon} ${p.category}</span>
                        <span>@${escHtml(p.username)}</span>
                    </div>
                </div>
                <div style="font-size:0.8rem;font-weight:700;color:var(--text-dim);">${time}</div>
            </div>`;
        });
    });
    html += '</div>';
    content.innerHTML = html;
}

function openDayModal(day) {
    const filtered = getFilteredPosts();
    const dayPosts = filtered.filter(p => {
        const d = new Date(p.scheduled_at);
        return d.getFullYear() === viewYear && d.getMonth() === viewMonth && d.getDate() === day;
    });

    if (dayPosts.length === 0) return;

    const dateObj = new Date(viewYear, viewMonth, day);
    const dateStr = dateObj.toLocaleDateString('en-IN', {weekday:'long', month:'long', day:'numeric', year:'numeric'});

    document.getElementById('day-modal-title').textContent = dateStr;
    document.getElementById('day-modal-subtitle').textContent = `${dayPosts.length} Prompts Submitted / Scheduled`;

    let html = '<div class="list-view">';
    dayPosts.forEach(p => {
        const icon = CAT_ICONS[p.category] || '📁';
        const time = new Date(p.scheduled_at).toLocaleTimeString('en-IN', {hour:'2-digit',minute:'2-digit',hour12:true});
        const thumb = p.media_url ? `<img src="${p.media_url}" onerror="this.parentNode.innerHTML='${icon}'">` : icon;
        const statusBadge = p.is_published
            ? '<span class="status-badge-published">Published</span>'
            : '<span class="status-badge-scheduled">Scheduled</span>';

        html += `<div class="list-item" onclick="openPostModal(${p.id})">
            <div class="list-thumb">${thumb}</div>
            <div class="list-info">
                <div class="list-title">${escHtml(p.title)}</div>
                <div class="list-meta">
                    ${statusBadge}
                    <span>${icon} ${p.category}</span>
                    <span>@${escHtml(p.username)}</span>
                </div>
            </div>
            <div style="font-size:0.8rem;font-weight:700;color:var(--text-dim);">${time}</div>
        </div>`;
    });
    html += '</div>';

    document.getElementById('day-modal-body').innerHTML = html;
    document.getElementById('day-modal-overlay').classList.add('open');
}

function closeDayModalDirect() {
    document.getElementById('day-modal-overlay').classList.remove('open');
}

function closeDayModal(e) {
    if (e.target.id === 'day-modal-overlay') {
        closeDayModalDirect();
    }
}

function openPostModal(id) {
    const p = POSTS.find(x => x.id === id);
    if (!p) return;

    const icon = CAT_ICONS[p.category] || '📁';
    const mediaBox = document.getElementById('modal-media-box');
    if (p.media_url) {
        mediaBox.innerHTML = `<img src="${p.media_url}" alt="Prompt Media">`;
    } else {
        mediaBox.innerHTML = `<i class="fa-solid fa-quote-left" style="font-size:3rem;color:var(--text-dim)"></i>`;
    }

    const statusBadge = p.is_published
        ? '<span class="status-badge-published">✅ Published to Channel</span>'
        : '<span class="status-badge-scheduled">⏳ Scheduled for Publishing</span>';
    
    document.getElementById('modal-badges-box').innerHTML = `
        ${statusBadge}
        <span style="background:rgba(255,255,255,0.06);border-radius:6px;padding:2px 8px;font-size:0.72rem;font-weight:600;">${icon} ${escHtml(p.category)}</span>
        ${p.is_challenge ? '<span style="background:rgba(239,68,68,0.15);color:#ef4444;border-radius:6px;padding:2px 8px;font-size:0.72rem;font-weight:700;">🔥 Trending</span>' : ''}
    `;

    document.getElementById('modal-title-text').textContent = p.title;

    const d = new Date(p.scheduled_at);
    const dateStr = d.toLocaleDateString('en-IN', {weekday:'long', year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit', hour12:true});

    document.getElementById('modal-meta-box').innerHTML = `
        <div>👤 Creator: <b>@${escHtml(p.username)}</b></div>
        <div>📅 Time: <b>${dateStr}</b></div>
        <div>🎯 Format: <b>${escHtml(p.output_type.toUpperCase())}</b></div>
    `;

    document.getElementById('modal-store-link').href = `library.php?id=${p.id}`;
    document.getElementById('post-modal-overlay').classList.add('open');
}

function closePostModalDirect() {
    document.getElementById('post-modal-overlay').classList.remove('open');
}

function closePostModal(e) {
    if (e.target.id === 'post-modal-overlay') {
        closePostModalDirect();
    }
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('DOMContentLoaded', init);
</script>
</body>
</html>
