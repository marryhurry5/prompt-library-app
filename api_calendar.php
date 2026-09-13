<?php
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (!defined('BOT_BASE_URL')) {
    define('BOT_BASE_URL', 'https://rtmcreator.com/bots/prompt-bot/');
}

$posts = getCalendarPosts();

$result = [];
foreach ($posts as $p) {
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

    $result[] = [
        'id'           => (int)$p['id'],
        'title'        => $p['text_output'] ?: ($p['category'] . ' Prompt'),
        'category'     => $p['category'],
        'output_type'  => $p['output_type'],
        'scheduled_at' => $event_time,
        'username'     => $p['username'],
        'is_challenge' => (bool)$p['is_challenge'],
        'is_published' => $is_published,
        'status'       => $is_published ? 'published' : 'scheduled',
        'media_url'    => $media_url,
    ];
}

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

echo json_encode([
    'success' => true,
    'posts' => $result,
    'count' => count($result),
    'stats' => [
        'total_approved'  => $total_approved,
        'total_scheduled' => $total_scheduled,
        'today_count'     => $today_count,
        'week_count'      => $week_count,
        'trending_count'  => $trending_count
    ],
    'top_creators' => $top_creators
]);
