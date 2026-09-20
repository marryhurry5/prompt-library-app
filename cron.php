<?php
/**
 * cron.php - Scheduled Post Publisher
 * 
 * This script automatically publishes posts that are due.
 * Set up a Cron Job on your hosting panel to call this URL every minute:
 *
 *   https://rtmcreator.com/bots/prompt-bot/cron.php?key=YOUR_SECRET_KEY
 *
 * In cPanel Cron Jobs, set the command to:
 *   curl -s "https://rtmcreator.com/bots/prompt-bot/cron.php?key=YOUR_SECRET_KEY" > /dev/null
 *
 * Replace YOUR_SECRET_KEY with a strong random string and update CRON_SECRET below.
 */

date_default_timezone_set('Asia/Kolkata'); // All times in IST

// ─── SECURITY: Secret key to prevent unauthorized browser access ────────────
define('CRON_SECRET', 'rtmcreator_cron_2711'); // Change this to a strong secret!

$is_cli = (php_sapi_name() === 'cli' || empty($_SERVER['HTTP_HOST']) || !isset($_SERVER['REQUEST_METHOD']));
$provided_key = $_GET['key'] ?? '';

// Allow: CLI execution (server cron) OR correct secret key (browser/curl)
if (!$is_cli && $provided_key !== CRON_SECRET) {
    http_response_code(403);
    die('Forbidden');
}
// ───────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ─── apiRequest (same as in webhook.php, needed here standalone) ────────────
function apiRequest($method, $parameters, $silent = false)
{
    if (!$parameters) $parameters = [];
    $parameters["method"] = $method;
    $ch = curl_init(API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
// ───────────────────────────────────────────────────────────────────────────

// ─── downloadAndSaveMedia (needed for fallback media re-download) ───────────
function downloadAndSaveMedia($file_id, $is_video = false)
{
    $response = apiRequest("getFile", ['file_id' => $file_id]);
    if (!isset($response['result']['file_path'])) return null;

    $file_path = $response['result']['file_path'];
    $token = BOT_TOKEN;
    $url = "https://api.telegram.org/file/bot{$token}/{$file_path}";

    $ext = $is_video ? 'mp4' : 'jpg';
    $local_dir = __DIR__ . '/uploads/';
    if (!is_dir($local_dir)) mkdir($local_dir, 0755, true);

    $filename = uniqid('media_', true) . '.' . $ext;
    $local_path = $local_dir . $filename;

    $ch = curl_init($url);
    $fp = fopen($local_path, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    if (!file_exists($local_path) || filesize($local_path) === 0) return null;
    if (!$is_video) {
        applyWatermarkToImageFile($local_path, '@ai_prompt_store');
    }
    return 'uploads/' . $filename;
}
// ───────────────────────────────────────────────────────────────────────────

// ─── sendSmartMessage (handles text/image/video posting to channel) ─────────
function sendSmartMessage($chat_id, $draft, $header, $prompt_part, $footer, $safe_prompt, $keyboard = null)
{
    $full_caption = $header . $prompt_part . $footer;
    $is_media = ($draft['output_type'] === 'Image' || $draft['output_type'] === 'Video') && $draft['file_id'];

    $files = [];
    if ($draft['output_type'] === 'Image' && $draft['file_id']) {
        $decoded = json_decode($draft['file_id'], true);
        if (is_array($decoded)) {
            $files = $decoded;
        } else {
            $files = [$draft['file_id']];
        }
    }
    $is_album = count($files) > 1;

    $caption_to_send = $full_caption;
    $prompt_to_send_separately = null;

    $video_to_send = null;
    if ($draft['output_type'] === 'Video' && $draft['file_id']) {
        $decoded = json_decode($draft['file_id'], true);
        $video_to_send = is_array($decoded) && isset($decoded['video']) ? $decoded['video'] : $draft['file_id'];
    }

    // Telegram restricts media captions to 1024 *visible* characters.
    // We strip HTML tags before measuring so <b>, <code> etc. don't inflate the count.
    // Also, albums don't support inline keyboards so we MUST send keyboard separately.
    $visible_length = mb_strlen(strip_tags($full_caption), 'UTF-8');
    if (($is_media && $visible_length > 1024) || ($is_album && $keyboard)) {
        $caption_to_send = $header . "<i>(Prompt/Actions below 👇)</i>\n\n" . $footer;
        $prompt_to_send_separately = "🔹 <b>Full Prompt:</b>\n<code>" . htmlspecialchars($safe_prompt) . "</code>";
    }

    $params = [
        'chat_id' => $chat_id,
        'parse_mode' => 'HTML',
    ];

    $res = false;

    if ($prompt_to_send_separately) {
        $media_res = false;
        if ($is_album) {
            $media_array = [];
            foreach ($files as $index => $fid) {
                $item = ['type' => 'photo', 'media' => $fid];
                if ($index === 0) {
                    $item['caption'] = $caption_to_send;
                    $item['parse_mode'] = 'HTML';
                }
                $media_array[] = $item;
            }
            $media_res = apiRequest("sendMediaGroup", ['chat_id' => $chat_id, 'media' => json_encode($media_array)]);
        } elseif ($draft['output_type'] === 'Image') {
            $media_params = $params;
            $media_params['caption'] = $caption_to_send;
            $media_params['photo'] = count($files) > 0 ? $files[0] : $draft['file_id'];
            $media_res = apiRequest("sendPhoto", $media_params);
        } elseif ($draft['output_type'] === 'Video') {
            $media_params = $params;
            $media_params['caption'] = $caption_to_send;
            $media_params['video'] = $video_to_send;
            $media_res = apiRequest("sendVideo", $media_params);
        }

        $text_params = $params;
        $text_params['text'] = $prompt_to_send_separately;
        if ($keyboard)
            $text_params['reply_markup'] = $keyboard;

        $res = apiRequest("sendMessage", $text_params);
    } else {
        if ($keyboard)
            $params['reply_markup'] = $keyboard;

        if ($is_album) {
            // If it's an album and NO keyboard (e.g. channel post), we can send just the media group
            $media_array = [];
            foreach ($files as $index => $fid) {
                $item = ['type' => 'photo', 'media' => $fid];
                if ($index === 0) {
                    $item['caption'] = $caption_to_send;
                    $item['parse_mode'] = 'HTML';
                }
                $media_array[] = $item;
            }
            $res = apiRequest("sendMediaGroup", ['chat_id' => $chat_id, 'media' => json_encode($media_array)]);
            // For sendMediaGroup, result is an array of messages. The reaction can be applied to the first one.
            if (isset($res['ok']) && $res['ok'] === true && isset($res['result'][0])) {
                $res['result'] = $res['result'][0]; // Normalize for reactions code which expects single Message in 'result'
            }
        } elseif ($is_media) {
            $params['caption'] = $caption_to_send;
            if ($draft['output_type'] === 'Image') {
                $params['photo'] = count($files) > 0 ? $files[0] : $draft['file_id'];
                $res = apiRequest("sendPhoto", $params);
            } else {
                $params['video'] = $video_to_send;
                $res = apiRequest("sendVideo", $params);
            }
        } else {
            $params['text'] = $caption_to_send;
            $res = apiRequest("sendMessage", $params);
        }
    }

    if (isset($res['ok']) && $res['ok'] === true)
        return $res;
    error_log(print_r($res, true));
    return false;
}
// ───────────────────────────────────────────────────────────────────────────

// ─── postToChannel (publishes a single submission to the channel) ───────────
function postToChannel($draft_id)
{
    $draft = getSubmissionById($draft_id);
    if (!$draft)
        return false;

    global $pdo;
    $stmt = $pdo->prepare("SELECT username FROM users WHERE telegram_id = ?");
    $stmt->execute([$draft['telegram_id']]);
    $username = $stmt->fetchColumn() ?: 'Unknown';

    $safe_prompt = mb_substr($draft['prompt'], 0, 3800, 'UTF-8');
    if (mb_strlen($draft['prompt'], 'UTF-8') > 3800)
        $safe_prompt .= '...';

    $header = "✨ <b>PROMPT RESULT</b> ✨\n\n";
    $header .= "📌 Title: <b>" . htmlspecialchars($draft['text_output'] ?? '') . "</b>\n";
    $header .= "🏷 Category: <b>" . htmlspecialchars($draft['category']) . "</b>\n\n";

    $prompt_part = "🔹 Prompt:\n<code>" . htmlspecialchars($safe_prompt) . "</code>\n\n";

    $footer = "🏷 " . htmlspecialchars($draft['tags']) . "\n";
    $footer .= "👤 Creator: " . htmlspecialchars($username) . "\n\n";
    $footer .= '<a href="https://rtmcreator.com/prompt-library/">🌐 Browse 140+ Prompts on Web</a>' . "\n";
    $footer .= "👉 Explore more secret Prompts on our channel: @ai_prompt_store\n";

    $web_url = "https://rtmcreator.com/prompt-library/?id=" . $draft_id;
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🌐 Browse Full Library', 'url' => $web_url],
                ['text' => '🚀 Submit Prompt', 'url' => 'https://t.me/Prompts_library_bot']
            ]
        ]
    ];

    return sendSmartMessage(CHANNEL_ID, $draft, $header, $prompt_part, $footer, $safe_prompt, $keyboard);
}
// ───────────────────────────────────────────────────────────────────────────

// ─── handleReferralReward (grant referral bonus on first approval) ──────────
function handleReferralReward($telegram_id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT referred_by, referral_reward_given, username FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['referred_by'] && !$user['referral_reward_given']) {
        $referrer_id = (int)$user['referred_by'];
        $daily_limit = (int)getSetting('daily_referral_limit', '5');
        $lifetime_limit = (int)getSetting('max_lifetime_referral_limit', '30');

        $today_count = getTodayReferralRewardCount($referrer_id);
        $total_count = getTotalReferralRewardCount($referrer_id);

        // Enforce Lifetime Max 30 and Daily Max 5 limits silently
        if ($total_count >= $lifetime_limit || $today_count >= $daily_limit) {
            markReferralRewardGiven($telegram_id);
            return;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE telegram_id = ? AND status = 'approved'");
        $stmt->execute([$telegram_id]);
        $count = (int)$stmt->fetchColumn();
        if ($count >= 1) {
            $member_res = apiRequest("getChatMember", ['chat_id' => CHANNEL_ID, 'user_id' => $telegram_id]);
            $status = $member_res['result']['status'] ?? 'left';
            if (in_array($status, ['creator', 'administrator', 'member'])) {
                // Re-verify quota before insertion to prevent concurrent over-rewarding
                if (getTotalReferralRewardCount($referrer_id) >= $lifetime_limit || getTodayReferralRewardCount($referrer_id) >= $daily_limit) {
                    markReferralRewardGiven($telegram_id);
                    return;
                }

                $reward = (float)getSetting('referral_reward_amount', '1.00');
                $recorded = recordReferralReward($referrer_id, $telegram_id, $reward);
                if ($recorded) {
                    $referee_user = getUserByTelegramId($telegram_id);
                    $referrer_user = getUserByTelegramId($referrer_id);

                    addBalance($telegram_id, $reward, 'CREDIT', 'REFERRAL_BONUS', "Referral bonus for joining via @" . ($referrer_user['username'] ?? 'referrer'));
                    addBalance($user['referred_by'], $reward, 'CREDIT', 'REFERRAL_BONUS', "Referral reward for inviting @" . ($referee_user['username'] ?? 'friend'));
                    markReferralRewardGiven($telegram_id);

                    logReferralEvent($referrer_id, $referrer_user['username'] ?? '', $telegram_id, $referee_user['username'] ?? '', 'QUALIFIED_REWARDED', $reward);
                    $userName = $user['username'] ?: "Your friend";
                    $reward_fmt = number_format($reward, 2);
                    apiRequest("sendMessage", [
                        'chat_id'    => $telegram_id,
                        'text'       => "🎉 <b>Referral Bonus Earned!</b>\n\nYou and your referrer just earned <b>₹{$reward_fmt}</b> because your first prompt was approved and you joined our channel!\nKeep submitting prompts to earn more! 💰",
                        'parse_mode' => 'HTML'
                    ]);
                    apiRequest("sendMessage", [
                        'chat_id'    => $user['referred_by'],
                        'text'       => "🎁 <b>Referral Bonus Alert!</b>\n\n{$userName} just got their first prompt approved. You have been awarded <b>₹{$reward_fmt}</b>! Thank you for inviting quality creators. Keep sharing your link! 🔗",
                        'parse_mode' => 'HTML'
                    ]);
                }
            }
        }
    }
}

// ─── runDailyStreakSaverReminders (8:00 PM IST Streak Saver Engine) ────────
function runDailyStreakSaverReminders()
{
    global $pdo;

    // Trigger only at or after 8:00 PM IST (20:00)
    $current_hour = (int)date('H');
    if ($current_hour < 20) {
        return 0;
    }

    $today = date('Y-m-d');
    $last_run = getSetting('last_streak_reminder_date', '');
    if ($last_run === $today) {
        return 0; // Already dispatched today
    }

    // Target users with active streak (>0) who haven't checked in today
    $stmt = $pdo->prepare("
        SELECT telegram_id, username, streak_count, streak_freeze_count 
        FROM users 
        WHERE streak_count > 0 
          AND (last_checkin_date IS NULL OR last_checkin_date != ?)
          AND is_banned = 0 
          AND is_blocked = 0
        LIMIT 250
    ");
    $stmt->execute([$today]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    foreach ($users as $u) {
        $tid = (int)$u['telegram_id'];
        $streak = (int)$u['streak_count'];
        $next_streak = $streak + 1;
        $reward = getCheckinRewardForStreak($next_streak);
        $reward_fmt = number_format($reward, 2);
        $has_freeze = ((int)$u['streak_freeze_count'] > 0);

        $freeze_line = $has_freeze 
            ? "🛡️ <b>Streak Shield:</b> Active <i>(Protected for 1 day)</i>" 
            : "⚠️ <b>Streak Shield:</b> None <i>(Missing today without a shield will break your streak!)</i>";

        $text = "🔥 <b>DON'T LOSE YOUR DAILY STREAK!</b>\n\n" .
                "You are currently on an active <b>Day {$streak} Streak</b>!\n\n" .
                "Check in before midnight (12:00 AM IST) to claim:\n" .
                "🎁 <b>Day {$next_streak} Bonus (+₹{$reward_fmt})</b>\n\n" .
                "{$freeze_line}\n\n" .
                "<i>Tap the button below to collect your reward now!</i>";

        $kb = [
            'inline_keyboard' => [
                [['text' => "🎁 Claim Day {$next_streak} (+₹{$reward_fmt})", 'callback_data' => 'cmd_checkin']]
            ]
        ];

        $res = apiRequest("sendMessage", [
            'chat_id'                  => $tid,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => $kb
        ], true);

        if ($res && isset($res['ok']) && $res['ok'] === true) {
            $sent++;
        }

        usleep(50000); // 50ms rate limit throttle
    }

    setSetting('last_streak_reminder_date', $today);
    error_log("[CRON] Dispatched 8:00 PM streak reminders to {$sent} user(s) on {$today}");
    return $sent;
}
// ───────────────────────────────────────────────────────────────────────────

// ─── postDailyLeaderboardToChannel (8:00 PM IST Channel Leaderboard Post) ────
function postDailyLeaderboardToChannel($force = false)
{
    $today = date('Y-m-d');
    if (!$force) {
        $last_date = getSetting('last_daily_leaderboard_date', '');
        if ($last_date === $today) {
            return false;
        }
    }

    $top_creators = getDailyTopCreators(5);
    if (empty($top_creators)) {
        return false;
    }

    $date_formatted = date('d M Y');

    $msg = "🏆 <b>DAILY CREATOR LEADERBOARD ({$date_formatted})</b> 🏆\n\n";
    $msg .= "Here are today's top prompt creators who submitted prompts and earned money:\n\n";

    $medals = ['🥇', '🥈', '🥉', '🎖', '🎖'];
    $rank = 1;

    foreach ($top_creators as $c) {
        $medal = $medals[$rank - 1] ?? '🎖';
        $raw_uname = trim($c['username'] ?? '');
        if (empty($raw_uname)) {
            $raw_uname = "Creator_" . substr((string)$c['telegram_id'], -4);
        }
        if (strpos($raw_uname, '@') !== 0 && !str_starts_with($raw_uname, 'Creator_')) {
            $raw_uname = '@' . $raw_uname;
        }
        $uname_safe = htmlspecialchars($raw_uname);
        $count = (int)$c['prompt_count'];
        $earned = (float)$c['earned_amt'];
        $earned_fmt = number_format($earned, 2);
        $prompt_word = ($count === 1) ? "prompt" : "prompts";

        $msg .= "{$medal} <b>{$uname_safe}</b> — <b>{$count} {$prompt_word}</b> (Earned <b>₹{$earned_fmt}</b> 💰)\n";
        $rank++;
    }

    $first_bounty = getSetting('first_prompt_reward_amount', '5.00');

    $msg .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "💡 <b>Want to earn real cash like them?</b>\n";
    $msg .= "Submit your creative AI prompts (ChatGPT, Midjourney, Bing, Flux) and get paid cash for every approved post!\n\n";
    $msg .= "🎁 <b>New Creator Offer:</b> Earn an instant <b>₹{$first_bounty} Welcome Bonus</b> on your 1st approved prompt!\n";
    $msg .= "💸 Instant withdrawals directly to your UPI / GPay / Paytm.\n\n";
    $msg .= "👇 <i>Tap the button below to submit your prompt now!</i>";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🚀 Submit Prompt & Earn Money 💰', 'url' => 'https://t.me/Prompts_library_bot?start=ref_channel']],
            [['text' => '🌐 View Prompt Library', 'url' => 'https://rtmcreator.com/prompt-library/']]
        ]
    ];

    $res = apiRequest("sendMessage", [
        'chat_id'      => CHANNEL_ID,
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => $keyboard
    ]);

    if ($res && isset($res['ok']) && $res['ok'] === true) {
        setSetting('last_daily_leaderboard_date', $today);
        return true;
    }
    return false;
}

function checkAndRunDailyLeaderboardPost()
{
    $current_hour = (int)date('H'); // IST 0-23
    // Send at or after 8:00 PM IST (20:00)
    if ($current_hour < 20) {
        return false;
    }

    $today = date('Y-m-d');
    $last_date = getSetting('last_daily_leaderboard_date', '');
    if ($last_date === $today) {
        return false; // Already posted today
    }

    return postDailyLeaderboardToChannel(false);
}
// ───────────────────────────────────────────────────────────────────────────

// ─── MAIN: Run the scheduler ────────────────────────────────────────────────
$scheduled = getScheduledPosts();
$published = 0;
$now_ist   = date('Y-m-d H:i:s');

foreach ($scheduled as $post) {
    // Fallback: re-download media if missing
    if (($post['output_type'] === 'Image' || $post['output_type'] === 'Video') && $post['file_id'] && empty($post['local_media'])) {
        if ($post['output_type'] === 'Video') {
            $decoded = json_decode($post['file_id'], true);
            if (isset($decoded['video'])) {
                $local_v     = downloadAndSaveMedia($decoded['video'], true);
                $local_t     = isset($decoded['thumb']) ? downloadAndSaveMedia($decoded['thumb']) : null;
                $local_media = json_encode(['video' => $local_v, 'thumb' => $local_t]);
                updateDraftSubmission($post['id'], 'local_media', $local_media);
            }
        } else {
            $local_media = downloadAndSaveMedia($post['file_id']);
            if ($local_media) updateDraftSubmission($post['id'], 'local_media', $local_media);
        }
    }

    $res = postToChannel($post['id']);
    if ($res && isset($res['result'])) {
        markAsPosted($post['id']);
        $msg_id = $res['result']['message_id'] ?? null;
        if ($msg_id) {
            updateDraftSubmission($post['id'], 'channel_message_id', $msg_id);
            apiRequest("setMessageReaction", [
                'chat_id'   => CHANNEL_ID,
                'message_id' => $msg_id,
                'reaction'  => [['type' => 'emoji', 'emoji' => '❤️']]
            ]);
        }
        handleReferralReward($post['telegram_id']);
        checkAndAwardDailyMilestone($post['telegram_id']);
        $published++;
        error_log("[CRON] Published post #{$post['id']} at $now_ist");
    } else {
        error_log("[CRON] FAILED to publish post #{$post['id']} at $now_ist");
    }
}

// PROCESS PENDING BROADCAST QUEUE BATCHES
$active_broadcast = getActiveBroadcastQueue();
if ($active_broadcast) {
    processBroadcastBatch($active_broadcast['id']);
}

// ─── 8:00 PM IST STREAK SAVER REMINDER ENGINE ──────────────────────────────
$reminders_sent = runDailyStreakSaverReminders();

// ─── 8:00 PM IST DAILY CHANNEL LEADERBOARD ENGINE ──────────────────────────
$daily_lb_sent = checkAndRunDailyLeaderboardPost();

echo "✅ Cron ran at $now_ist (IST). Published: $published post(s). Reminders sent: $reminders_sent. Daily LB: " . ($daily_lb_sent ? 'Sent' : 'Skipped') . ".";
