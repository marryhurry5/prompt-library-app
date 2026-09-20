<?php
date_default_timezone_set('Asia/Kolkata'); // All times in IST
require_once 'config.php';
require_once 'db.php';

if (function_exists('http_response_code')) {
    http_response_code(200);
}

$update_json = file_get_contents("php://input");
$update = json_decode($update_json, true);

if (!$update) {
    exit;
}

// Telegram Update ID Deduplication Shield (Prevents double notifications on retries)
$update_id = (int)($update['update_id'] ?? 0);
if ($update_id > 0) {
    try {
        global $pdo;
        $pdo->exec("CREATE TABLE IF NOT EXISTS processed_updates (
            update_id BIGINT PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );");
        $chk = $pdo->prepare("INSERT INTO processed_updates (update_id) VALUES (?)");
        $chk->execute([$update_id]);
    } catch (Exception $e) {
        // Duplicate update_id received (Telegram retry) - Exit cleanly to prevent double notification!
        exit;
    }
}

function apiRequest($method, $parameters, $silent = false)
{
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }
    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    $parameters["method"] = $method;
    $ch = curl_init(API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($curl_error || (isset($decoded['ok']) && !$decoded['ok'])) {
        $error_msg = $curl_error ? "cURL Error: $curl_error" : "API Error: " . ($decoded['description'] ?? 'Unknown Error');
        error_log("Method: $method | Error: $error_msg");
        logTechnicalIssue('WARNING', 'webhook.php', 40, "Method: $method | Error: $error_msg", $parameters['chat_id'] ?? '');

        // Prevent infinite loops: dont alert if the error was from attempting to alert the admin
        $is_admin_error_loop = ($method === 'sendMessage' && isset($parameters['chat_id']) && $parameters['chat_id'] == ADMIN_ID);

        if (!$is_admin_error_loop && defined('ADMIN_ID') && !$silent) {
            $admin_params = [
                "method" => "sendMessage",
                "chat_id" => ADMIN_ID,
                "text" => "⚠️ <b>Bot API Error</b>\nFailed Method: <code>$method</code>\nError: <code>" . htmlspecialchars($error_msg) . "</code>",
                "parse_mode" => "HTML"
            ];
            $admin_ch = curl_init(API_URL);
            curl_setopt($admin_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($admin_ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($admin_ch, CURLOPT_POST, true);
            curl_setopt($admin_ch, CURLOPT_POSTFIELDS, json_encode($admin_params));
            curl_setopt($admin_ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
            curl_exec($admin_ch);
            curl_close($admin_ch);
        }
    }

    return $decoded;
}

function sendOrEditStepMessage($chat_id, $message_id, $text, $keyboard)
{
    $res = apiRequest("editMessageText", [
        'chat_id'      => $chat_id,
        'message_id'   => $message_id,
        'text'         => $text,
        'parse_mode'   => 'HTML',
        'reply_markup' => $keyboard
    ]);
    if (!$res || !isset($res['ok']) || $res['ok'] !== true) {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard
        ]);
    }
}

function checkAndRunWeeklyTopPost()
{
    $file = __DIR__ . '/last_weekly_post.txt';
    $last_time = file_exists($file) ? (int) file_get_contents($file) : 0;
    $now = time();
    $seven_days = 7 * 24 * 60 * 60;

    if (($now - $last_time) >= $seven_days) {
        file_put_contents($file, $now);
        $top_weekly = getWeeklyTopCreators(3);
        if (count($top_weekly) > 0) {
            $msg = "🏆 <b>TOP PROMPT CREATORS OF THE WEEK</b> 🏆\n\n";
            $msg .= "Here are our most active creators making the best prompts this week:\n\n";
            $rank = 1;
            $medals = ['🥇', '🥈', '🥉'];
            foreach ($top_weekly as $u) {
                $medal = $medals[$rank - 1] ?? '🎖';
                $uname = htmlspecialchars($u['username'] ?? 'Unknown');
                $count = $u['count'];
                $msg .= "{$medal} {$uname} — <b>{$count}</b> approved prompts\n";
                $rank++;
            }
            $msg .= "\nDo you want to feature in this list?\n👉 Submit your best prompt now!";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🚀 Submit Your Prompt', 'url' => 'https://t.me/Prompts_library_bot']]
                ]
            ];

            apiRequest("sendMessage", [
                'chat_id' => CHANNEL_ID,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        }
    }
}
checkAndRunWeeklyTopPost();

function postDailyLeaderboardToChannel($force = false)
{
    $today = date('Y-m-d');
    if (!$force) {
        $last_date = getSetting('last_daily_leaderboard_date', '');
        if ($last_date === $today) {
            return false; // Already posted today
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
checkAndRunDailyLeaderboardPost();

function checkAndRunScheduledPosts()
{
    $scheduled = getScheduledPosts();
    foreach ($scheduled as $post) {
        // FALLBACK: If local_media is still null (e.g. initial download failed), try downloading again
        if (($post['output_type'] === 'Image' || $post['output_type'] === 'Video') && $post['file_id'] && empty($post['local_media'])) {
            $to_download = $post['file_id'];
            if ($post['output_type'] === 'Video') {
                $decoded = json_decode($post['file_id'], true);
                if (isset($decoded['video'])) {
                    $local_v = downloadAndSaveMedia($decoded['video'], true);
                    $local_t = isset($decoded['thumb']) ? downloadAndSaveMedia($decoded['thumb']) : null;
                    $local_media = json_encode(['video' => $local_v, 'thumb' => $local_t]);
                } else {
                    $local_media = null;
                }
            } else {
                $local_media = downloadAndSaveMedia($post['file_id']);
            }
            
            if ($local_media) {
                updateDraftSubmission($post['id'], 'local_media', $local_media);
            }
        }

        $res = postToChannel($post['id']);
        if ($res) {
            markAsPosted($post['id']);
            $msg_id = $res['result']['message_id'] ?? null;
            if ($msg_id) {
                updateDraftSubmission($post['id'], 'channel_message_id', $msg_id);
                apiRequest("setMessageReaction", [
                    'chat_id' => CHANNEL_ID,
                    'message_id' => $msg_id,
                    'reaction' => [['type' => 'emoji', 'emoji' => '❤️']]
                ]);
            }
            handleReferralReward($post['telegram_id']);
        }
    }
}
try {
    checkAndRunScheduledPosts();

    if (isset($update["message"])) {
        processMessage($update["message"]);
    } elseif (isset($update["callback_query"])) {
        processCallbackQuery($update["callback_query"]);
    }
} catch (Throwable $e) {
    error_log("Webhook Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    logTechnicalIssue('CRITICAL', 'webhook.php', $e->getLine(), $e->getMessage(), $update['message']['chat']['id'] ?? ($update['callback_query']['message']['chat']['id'] ?? ''));
}

function processMessage($message)
{
    if (!isset($message['chat']['id']))
        return;

    $chat_id = $message['chat']['id'];
    $telegram_id = $message['from']['id'];
    $from = $message['from'] ?? [];
    $username = isset($message['from']['username']) ? '@' . $message['from']['username'] : $message['from']['first_name'];
    $text = isset($message['text']) ? $message['text'] : (isset($message['caption']) ? $message['caption'] : '');

    $user = getUser($telegram_id, $username);
    $step = $user['step'] ?? 'none';

    // MAINTENANCE MODE SECURITY GUARD
    if (!isAdmin($telegram_id) && isMaintenanceModeEnabled()) {
        $reason = htmlspecialchars(getMaintenanceReason());
        $msg = "🚧 <b>SYSTEM UNDER MAINTENANCE</b> 🚧\n\n" .
               "Our system is currently undergoing scheduled maintenance to upgrade servers and improve prompt features.\n\n" .
               "📌 <b>Details:</b> <i>{$reason}</i>\n\n" .
               "<i>Please check back in a few minutes! We will be back online shortly. Thank you for your patience!</i> 🚀";
        
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // BANNED USER SECURITY GUARD
    if (!isAdmin($telegram_id) && isUserBanned($telegram_id)) {
        if ($text === '/support' || strpos($step, 'awaiting_support_msg_') === 0) {
            // Allow support ticket appeal
        } else {
            $ban_info = getUserBanInfo($telegram_id);
            $reason = htmlspecialchars($ban_info['ban_reason'] ?? 'Violation of community guidelines');
            
            $msg = "🚫 <b>Access Restricted (Account Banned)</b>\n\n" .
                   "Your account has been suspended by administrators.\n" .
                   "📌 <b>Reason:</b> $reason\n\n" .
                   "<i>If you believe this is an error, tap below to open a support appeal ticket:</i>";
            
            $kb = [
                'inline_keyboard' => [
                    [['text' => '🎧 Contact Support Ticket', 'callback_data' => 'cmd_support']]
                ]
            ];
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $kb
            ]);
            return;
        }
    }

    // MANDATORY CHANNEL JOIN SECURITY GUARD (FOR ALL USERS INCLUDING ADMINS)
    if (!isUserChannelMember($telegram_id)) {
        if ($text === '/support' || strpos($step, 'awaiting_support_msg_') === 0) {
            // Allow support ticket appeal
        } else {
            sendForceJoinCard($chat_id, $telegram_id);
            return;
        }
    }

    // ADMIN: HANDLE BROADCAST CONTENT (SINGLE CLEAN PREVIEW)
    if ($step === 'admin_broadcast_awaiting_content' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Broadcast cancelled."]);
            return;
        }

        $msg_id = $message['message_id'];

        $all_count = count(getAllUsers('all'));
        $active_count = count(getAllUsers('active'));
        $inactive_count = count(getAllUsers('inactive'));
        $top_count = count(getAllUsers('top'));

        $nav_kb = [
            'inline_keyboard' => [
                [['text' => "🚀 All Users ($all_count)", 'callback_data' => "b_launch_all_{$msg_id}"]],
                [['text' => "🟢 Active ($active_count)", 'callback_data' => "b_launch_active_{$msg_id}"],
                 ['text' => "🔴 Inactive ($inactive_count)", 'callback_data' => "b_launch_inactive_{$msg_id}"]],
                [['text' => "💎 Top Creators ($top_count)", 'callback_data' => "b_launch_top_{$msg_id}"]],
                [['text' => "➕ Add Action Button", 'callback_data' => "b_addbtn_{$msg_id}"]],
                [['text' => '❌ Cancel Broadcast', 'callback_data' => 'cmd_cancel']]
            ]
        ];

        // Send EXACTLY 1 single preview message with controls attached directly!
        apiRequest("copyMessage", [
            'chat_id' => $chat_id,
            'from_chat_id' => $chat_id,
            'message_id' => $msg_id,
            'reply_markup' => $nav_kb
        ]);
        
        updateUserStep($telegram_id, 'none');
        return;
    }

    if (strpos($text, '/start') === 0) {
        updateUserStep($telegram_id, 'none');
        
        // Handle Referral
        if (strpos($text, '/start ref_') === 0) {
            $referrer_id = (int)substr($text, 11);
            if ($referrer_id != $telegram_id) {
                $success = setReferrer($telegram_id, $referrer_id);
                if ($success) {
                    $referee_name = $username;
                    $ref_reward_amt = getSetting('referral_reward_amount', '1.00');
                    $ref_user = getUserByTelegramId($referrer_id);
                    logReferralEvent($referrer_id, $ref_user['username'] ?? '', $telegram_id, $username, 'LINKED_PENDING', '-');
                    apiRequest("sendMessage", [
                        'chat_id' => $referrer_id,
                        'text' => "👤 <b>New Referral!</b>\n\n{$referee_name} has joined using your invite link.\n\nYou will both receive <b>₹{$ref_reward_amt}</b> once they join our channel and get their first prompt approved! 🚀",
                        'parse_mode' => 'HTML'
                    ]);
                }
            }
        }
        logUserActivity($telegram_id, $username, '/start', $step, 'User started bot');

        $channel_username = CHANNEL_USERNAME;
        $user_approved_count = getUserApprovedPromptCount($telegram_id);
        $first_bounty = getSetting('first_prompt_reward_amount', '5.00');

        $welcome = "🌟 <b>Welcome to the AI Prompt Marketplace!</b> 🌟\n\n";
        if ($user_approved_count === 0) {
            $welcome .= "🎁 <b>SPECIAL WELCOME OFFER:</b>\n";
            $welcome .= "Submit your first prompt today and earn an instant <b>₹{$first_bounty} WELCOME BONUS</b> upon approval! 💰\n\n";
        }
        $welcome .= "Submit your best AI Art, ChatGPT, or creative prompts to get featured in our official channel: <a href='https://t.me/{$channel_username}'>@" . CHANNEL_USERNAME . "</a>\n\n";
        $welcome .= "<b>How to earn with this bot:</b>\n";
        $welcome .= "1️⃣ Use /submit to choose a category & upload your prompt.\n";
        $welcome .= "2️⃣ Provide the prompt text & AI tool used.\n";
        $welcome .= "3️⃣ Admins review & feature your post on the channel.\n";
        if ($user_approved_count === 0) {
            $welcome .= "4️⃣ <b>Instantly receive ₹{$first_bounty}</b> on your first approved prompt! 🚀\n\n";
        } else {
            $welcome .= "4️⃣ Earn cash for every approved prompt straight to your wallet!\n\n";
        }
        $welcome .= "<b>Quick Menu:</b>\n<i>Choose an option below to get started!</i>";

        // Update command menu for the user
        apiRequest("setMyCommands", [
            'commands' => json_encode([
                ['command' => 'start', 'description' => 'Start the bot'],
                ['command' => 'daily', 'description' => '📅 Daily Check-in & Streak Reward'],
                ['command' => 'submit', 'description' => 'Submit a Prompt'],
                ['command' => 'library', 'description' => 'Browse Web Prompt Library'],
                ['command' => 'challenge', 'description' => 'View Daily Challenge'],
                ['command' => 'profile', 'description' => 'My Profile & Rank'],
                ['command' => 'refer', 'description' => 'Invite Friends & Earn'],
                ['command' => 'leaderboard', 'description' => 'Top Creators'],
                ['command' => 'balance', 'description' => 'Check Balance'],
                ['command' => 'withdraw', 'description' => 'Withdraw Funds'],
                ['command' => 'support', 'description' => 'Help & Customer Support'],
                ['command' => 'promote', 'description' => 'Advertise & Promotions'],
                ['command' => 'help', 'description' => 'User Guide & FAQ'],
                ['command' => 'cancel', 'description' => 'Cancel active process']
            ])
        ]);

        $keyboard_rows = [
            [['text' => '🚀 Submit Prompt', 'callback_data' => 'cmd_submit'], ['text' => '📅 Daily Check-in', 'callback_data' => 'cmd_checkin']],
            [['text' => '🌍 Daily Challenge', 'callback_data' => 'cmd_challenge'], ['text' => '🌐 Web Library', 'url' => 'https://rtmcreator.com/prompt-library/']],
            [['text' => '🤝 Refer & Earn', 'callback_data' => 'cmd_refer'], ['text' => '💰 My Balance', 'callback_data' => 'cmd_balance']],
            [['text' => '🏆 Leaderboard', 'callback_data' => 'cmd_leaderboard'], ['text' => '💸 Withdraw', 'callback_data' => 'cmd_withdraw']],
            [['text' => '👤 My Profile', 'callback_data' => 'cmd_profile'], ['text' => '🎧 Help & Support', 'callback_data' => 'cmd_support']],
            [['text' => '📢 Advertise / Promote', 'callback_data' => 'cmd_promote'], ['text' => '📢 Official Channel', 'url' => 'https://t.me/' . $channel_username]]
        ];
        if (isAdmin($telegram_id)) {
            $keyboard_rows[] = [['text' => '🛡️ Admin Panel', 'callback_data' => 'cmd_admin']];
        }
        $keyboard = ['inline_keyboard' => $keyboard_rows];

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $welcome,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard
        ]);
        return;
    }

    if ($text === '/help') {
        updateUserStep($telegram_id, 'none');
        $help = "📖 <b>Prompt Marketplace - User Guide</b>\n\n";
        $help .= "Welcome! This bot helps you become a prompt creator and showcase your AI skills. Here is how it works:\n\n";
        
        $help .= "🚀 <b>1. How to Submit</b>\n";
        $help .= "Use /submit and follow the steps. You can upload <b>Images, Videos, or Text</b>. All submissions are reviewed by admins before being posted to @ai_prompt_store.\n\n";
        
        $first_reward_amt = getSetting('first_prompt_reward_amount', '5.00');
        $prompt_reward_amt = getSetting('prompt_reward_amount', '0.50');
        $help .= "• <b>1st Approved Prompt:</b> +₹{$first_reward_amt} (🎁 Welcome Bounty!)\n";
        $help .= "• <b>Regular Approved Post:</b> +₹{$prompt_reward_amt}\n";
        $ref_reward_amt = getSetting('referral_reward_amount', '1.00');
        $help .= "• <b>Daily Challenge:</b> 2x Double Reward (if approved)\n";
        $help .= "• <b>Daily Check-in:</b> +₹0.10 to ₹0.40/day (Streak rewards)\n";
        $help .= "• <b>Referrals:</b> +₹{$ref_reward_amt} for both you and your friend!\n\n";
        
        $help .= "🤝 <b>3. Referrals</b>\n";
        $help .= "Use /refer to get your unique link. Share it with friends. You both get rewarded when they join the channel and get their first prompt approved.\n\n";
        
        $help .= "🖼️ <b>4. Your Portfolio</b>\n";
        $help .= "Use /profile and click 'View My Gallery' to see all your high-quality approved prompts in one place.\n\n";
        
        $help .= "⚠️ <b>Need Admin Support?</b>\n";
        $help .= "Contact @nihal2711 for any issues or partnership inquiries.\n\n";
        $help .= "<i>Type /submit to start your journey now!</i>";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 Submit Now', 'callback_data' => 'cmd_submit']],
                [['text' => '🎧 Open Support Ticket', 'callback_data' => 'cmd_support'], ['text' => '📢 Advertise / Promote', 'callback_data' => 'cmd_promote']],
                [['text' => '🌐 Open Web Library', 'url' => 'https://rtmcreator.com/prompt-library/']],
                [['text' => '📢 Official Channel', 'url' => 'https://t.me/' . CHANNEL_USERNAME]]
            ]
        ];

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $help,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard
        ]);
        return;
    }

    if ($text === '/library' || $text === '/website' || $text === '🌐 Web Library') {
        updateUserStep($telegram_id, 'none');
        $msg = "🌐 <b>Prompt Library Marketplace</b> 🌐\n\n";
        $msg .= "Explore our full catalog of 140+ expert AI prompts on the web! Search by category, view prompt results, and check out creator portfolios.\n\n";
        $msg .= "🔗 <b>Website:</b> https://rtmcreator.com/prompt-library/\n\n";
        $msg .= "<i>Click below to open the website directly:</i>";

        $kb = [
            'inline_keyboard' => [
                [['text' => '🌐 Open Prompt Library', 'url' => 'https://rtmcreator.com/prompt-library/']],
                [['text' => '🚀 Submit a Prompt', 'callback_data' => 'cmd_submit']]
            ]
        ];

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    if ($text === '/profile') {
        $stats = getUserStats($telegram_id);
        $rank = getUserRank($telegram_id);
        $approved = $stats['approved'] ?: 0;
        $rejected = $stats['rejected'] ?: 0;

        $bal = getBalance($telegram_id);
        $p_text = "👤 <b>User Profile: {$username}</b>\n\n";
        $p_text .= "💰 <b>Balance:</b> ₹" . number_format($bal, 2) . "\n";
        $p_text .= "🏆 <b>Global Rank:</b> #{$rank}\n\n";
        $p_text .= "<b>Submission Summary:</b>\n";
        $p_text .= "✅ Approved: {$approved}\n";
        $p_text .= "❌ Rejected: {$rejected}\n";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🖼️ View My Gallery', 'callback_data' => 'view_my_prompts']]
            ]
        ];

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $p_text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard
        ]);
        return;
    }

    if ($text === '/refer' || $text === '/referral') {
        $bot_username = "Prompts_library_bot"; 
        $ref_link = "https://t.me/$bot_username?start=ref_$telegram_id";
        $count = getReferralCount($telegram_id);
        $ref_reward_amt = getSetting('referral_reward_amount', '1.00');

        $msg = "🤝 <b>Referral Program</b> 🤝\n\n";
        $msg .= "Invite your friends and earn <b>₹{$ref_reward_amt}</b> for both of you when they join our channel and get their first prompt approved!\n\n";
        $msg .= "📊 <b>Total Referrals:</b> $count\n";
        $msg .= "🔗 <b>Your Link:</b> <code>$ref_link</code>\n\n";
        $msg .= "<i>Tap the button below to share your link directly with friends in 1 click!</i>";

        $share_text = "Hey! 🚀 I'm earning money by submitting AI prompts on Prompt Marketplace!\n\nYou can also submit prompts or browse the best AI Art & ChatGPT prompts and earn ₹{$ref_reward_amt} per referral! 💰\n\nJoin using my invite link:";
        $share_url = "https://t.me/share/url?url=" . urlencode($ref_link) . "&text=" . urlencode($share_text);

        $kb = [
            'inline_keyboard' => [
                [['text' => '📲 Share Link with Friends', 'url' => $share_url]],
                [['text' => '📢 Official Channel', 'url' => 'https://t.me/' . CHANNEL_USERNAME]]
            ]
        ];

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    // ADMIN PANEL COMMAND
    if ($text === '/admin' && isAdmin($telegram_id)) {
        updateUserStep($telegram_id, 'none');
        sendAdminPanel($chat_id);
        return;
    }

    // ADMIN: SET CHALLENGE
    if (strpos($text, '/setchallenge ') === 0 && isAdmin($telegram_id)) {
        $theme = trim(substr($text, 14));
        if ($theme === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Usage: /setchallenge Cyberpunk, Neon, Logo Design"]);
            return;
        }
        $added = addChallengeKeywords($theme);
        $added_str = implode(', ', $added);
        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Daily Challenge keywords added: <b>" . htmlspecialchars($added_str) . "</b>\n📢 Broadcasting notification to all users...", 'parse_mode' => 'HTML']);
        broadcastNewChallengeNotification($added);
        return;
    }

    if ($text === '/cancel') {
        updateUserStep($telegram_id, 'none');
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "❌ Active process cancelled! You can start over with /submit."
        ]);
        return;
    }

    // LEADERBOARD COMMAND
    if ($text === '/leaderboard') {
        updateUserStep($telegram_id, 'none');
        $top_users = getTopUsers(10);
        $top_refs = getTopReferrers(5);
        $lb_text = "🏆 <b>Top Creators Leaderboard</b> 🏆\n\n";

        if (empty($top_users)) {
            $lb_text .= "<i>No users yet!</i>\n\n";
        } else {
            $rank = 1;
            foreach ($top_users as $u) {
                $uname = htmlspecialchars($u['username'] ?? 'Unknown');
                $earnings = number_format($u['lifetime_earnings'], 2);
                $prompts = (int)$u['approved_count'];
                $lb_text .= "<b>{$rank}.</b> {$uname} — <b>₹{$earnings}</b> lifetime | <b>{$prompts}</b> prompts\n";
                $rank++;
            }
            $lb_text .= "\n";
        }
        
        $lb_text .= "🤝 <b>Top Referrers</b> 🤝\n\n";
        if (empty($top_refs)) {
            $lb_text .= "<i>No referrers yet!</i>";
        } else {
            $rank = 1;
            foreach ($top_refs as $r) {
                $uname = htmlspecialchars($r['username'] ?? 'Unknown');
                $lb_text .= "<b>{$rank}.</b> {$uname} — <b>{$r['count']}</b> successful invites\n";
                $rank++;
            }
        }

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $lb_text,
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // DAILY CHECK-IN COMMAND
    if ($text === '/daily' || $text === '/checkin') {
        updateUserStep($telegram_id, 'none');
        sendDailyCheckinCard($chat_id, $telegram_id, null, $username);
        return;
    }

    // CHALLENGE COMMAND
    if ($text === '/challenge') {
        updateUserStep($telegram_id, 'none');
        $challenges = getActiveChallenges();
        if (!empty($challenges)) {
            $msg = "🌍 <b>TRENDING CHALLENGE KEYWORDS</b> 🌍\n\n";
            $msg .= "Currently active challenge keywords:\n";
            foreach ($challenges as $c) {
                $msg .= "🔥 <b>" . htmlspecialchars($c['theme']) . "</b>\n";
            }
            $prompt_reward = (float)getSetting('prompt_reward_amount', '0.25');
            $challenge_reward = $prompt_reward * 2;
            $c_fmt = number_format($challenge_reward, 2);
            $msg .= "💰 <b>Reward:</b> Matched prompts earn <b>DOUBLE REWARDS (2x = ₹{$c_fmt})</b> upon approval + get a 🔥 <b>Trending Badge</b>!\n\n";
            $msg .= "<i>Type /submit to participate now!</i>";
        } else {
            $msg = "🌍 <b>Daily Challenge</b>\n\n";
            $msg .= "There are no active challenge keywords right now.\n";
            $msg .= "Stay tuned — admins add new trending keywords regularly! 🔔";
        }
        $kb = [
            'inline_keyboard' => [
                [['text' => '🚀 Submit Now', 'callback_data' => 'cmd_submit']],
                [['text' => '📢 Official Channel', 'url' => 'https://t.me/' . CHANNEL_USERNAME]]
            ]
        ];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    // SUPPORT COMMAND
    if ($text === '/support') {
        updateUserStep($telegram_id, 'none');
        $msg = "🎧 <b>CUSTOMER SUPPORT & HELP CENTER</b> 🎧\n\n" .
               "Have questions about your submissions, earnings, or withdrawals?\n" .
               "Select a category below to open a support ticket with our team:\n\n" .
               "<i>Our admin team usually responds within a few hours!</i>";
        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 Earnings & Withdrawals', 'callback_data' => 'suppcat_Earnings'],
                    ['text' => '📝 Submissions', 'callback_data' => 'suppcat_Submissions']
                ],
                [
                    ['text' => '❓ General Inquiry', 'callback_data' => 'suppcat_General']
                ],
                [
                    ['text' => '📢 Advertise & Promote', 'callback_data' => 'cmd_promote'],
                    ['text' => '🏠 Main Menu', 'callback_data' => 'cmd_start']
                ]
            ]
        ];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    // PROMOTIONS & ADS COMMAND
    if ($text === '/promote') {
        updateUserStep($telegram_id, 'none');
        $msg = "📢 <b>ADVERTISING & PROMOTIONS CENTER</b> 📢\n\n" .
               "Want to promote your AI Tool, Telegram Channel, App, or Product to thousands of active AI creators and prompt engineers?\n\n" .
               "🌟 <b>Advertising Packages Available:</b>\n" .
               "📌 <b>Package 1: Pinned Channel Post</b> (24h/48h Pinned Post in @" . CHANNEL_USERNAME . ")\n" .
               "🌐 <b>Package 2: Web Library Top Banner</b> (Featured banner on rtmcreator.com/prompt-library/)\n" .
               "🚀 <b>Package 3: Mass Bot Broadcast</b> (Direct ad message delivered to all bot users)\n\n" .
               "Tap below to book your ad directly with our official promotion bot:";
        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 Book Ads on @rtmcreator_bot', 'url' => 'https://t.me/rtmcreator_bot']
                ],
                [
                    ['text' => '🎧 Contact Support', 'callback_data' => 'cmd_support'],
                    ['text' => '🏠 Main Menu', 'callback_data' => 'cmd_start']
                ]
            ]
        ];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    // ADMIN BAN USER COMMAND
    if (strpos($text, '/ban ') === 0 && isAdmin($telegram_id)) {
        $args = trim(substr($text, 5));
        $parts = explode(' ', $args, 2);
        $target = $parts[0] ?? '';
        $reason = $parts[1] ?? 'Violation of community guidelines';

        if (empty($target)) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Usage: /ban [ID_or_@username] [reason]"]);
            return;
        }

        if ($target == ADMIN_ID || $target == (string)$telegram_id) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cannot ban an admin account."]);
            return;
        }

        $success = banUser($target, $reason);
        if ($success) {
            logBanUnban($username, $target, $target, 'BAN', $reason);
            logAdminActivity($telegram_id, $username, 'BAN_USER', $target, "Banned user $target: $reason");
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "✅ User <b>" . htmlspecialchars($target) . "</b> has been <b>BANNED</b>.\n📌 <b>Reason:</b> " . htmlspecialchars($reason),
                'parse_mode' => 'HTML'
            ]);
            $admin_label = getAdminDisplayName($telegram_id, $from);
            notifyOtherAdmins($telegram_id, "🚫 <b>Admin Activity Alert</b>\n\nUser <b>" . htmlspecialchars($target) . "</b> was <b>BANNED</b> (Reason: <i>" . htmlspecialchars($reason) . "</i>) by {$admin_label}.");
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ User not found or already banned."]);
        }
        return;
    }

    // ADMIN UNBAN USER COMMAND
    if (strpos($text, '/unban ') === 0 && isAdmin($telegram_id)) {
        $target = trim(substr($text, 7));
        if (empty($target)) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Usage: /unban [ID_or_@username]"]);
            return;
        }

        $success = unbanUser($target);
        if ($success) {
            logBanUnban($username, $target, $target, 'UNBAN', 'Unbanned by admin');
            logAdminActivity($telegram_id, $username, 'UNBAN_USER', $target, "Unbanned user $target");
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "✅ User <b>" . htmlspecialchars($target) . "</b> has been <b>UNBANNED</b>.",
                'parse_mode' => 'HTML'
            ]);
            $admin_label = getAdminDisplayName($telegram_id, $from);
            notifyOtherAdmins($telegram_id, "🟢 <b>Admin Activity Alert</b>\n\nUser <b>" . htmlspecialchars($target) . "</b> was <b>UNBANNED</b> by {$admin_label}.");
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ User not found or not currently banned."]);
        }
        return;
    }

    // ADMIN STATS COMMAND
    if ($text === '/stats' && isAdmin($telegram_id)) {
        $stats = getStats();

        $msg = "📊 <b>Advanced Bot Statistics</b> 📊\n\n";
        $msg .= "👥 <b>Total Users:</b> " . number_format($stats['total_users']) . "\n";
        $msg .= "📈 <b>Joined Today:</b> " . number_format($stats['new_today']) . "\n";
        $msg .= "📅 <b>Joined This Week:</b> " . number_format($stats['new_week']) . "\n";
        $msg .= "📝 <b>Total Submissions:</b> " . number_format($stats['total_submissions']) . "\n\n";

        $msg .= "<b>Status Breakdown:</b>\n";
        $approved = $stats['status_breakdown']['approved'] ?? 0;
        $pending = $stats['status_breakdown']['pending'] ?? 0;
        $rejected = $stats['status_breakdown']['rejected'] ?? 0;
        
        $msg .= "✅ Approved: " . number_format($approved) . "\n";
        $msg .= "⏳ Pending: " . number_format($pending) . "\n";
        $msg .= "❌ Rejected: " . number_format($rejected) . "\n\n";

        $msg .= "🔥 <b>Top Category:</b> " . ($stats['top_category'] ?? 'N/A') . "\n";
        $msg .= "👤 <b>Active Users (7d):</b> " . number_format($stats['active_7d']) . "\n";

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // ADMIN BROADCAST COMMAND
    if ($text === '/broadcast' && isAdmin($telegram_id)) {
        updateUserStep($telegram_id, 'admin_broadcast_awaiting_content');
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "📢 <b>Broadcast Mode Enabled</b>\n\nPlease send me the content you want to broadcast (Text, Photo, Video, or Document).\n\nThe bot will show you a preview before sending it to all users.\n\nType /cancel to abort.",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // Handle old-style broadcast for backward compatibility or quick use
    if (strpos($text, '/broadcast ') === 0 && isAdmin($telegram_id)) {
        if (!hasAdminPermission($telegram_id, 'can_broadcast')) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "🚫 <b>Access Denied!</b> You do not have permission to send broadcast messages. Contact Super Admin.", 'parse_mode' => 'HTML']);
            return;
        }
        $broadcast_msg = substr($text, 11);
        if (trim($broadcast_msg) !== '') {
            $users = getAllUsers();
            $count = 0;
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⏳ Starting quick broadcast to " . count($users) . " users..."]);
            ignore_user_abort(true);
            set_time_limit(0);
            foreach ($users as $uid) {
                $res = apiRequest("sendMessage", [
                    'chat_id' => $uid,
                    'text' => $broadcast_msg,
                    'parse_mode' => 'HTML'
                ], true);
                
                if ($res && isset($res['ok']) && $res['ok']) {
                    $count++;
                } elseif ($res && isset($res['description']) && strpos($res['description'], 'blocked by the user') !== false) {
                    markUserBlocked($uid);
                }
                
                usleep(35000); 
            }
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Quick broadcast finished. Sent to $count users."]);
            return;
        }
    }

    // ADMIN RECENT COMMAND (List last 10 prompts with IDs)
    if ($text === '/recent' && isAdmin($telegram_id)) {
        $stmt = $pdo->query("SELECT id, category, text_output FROM submissions WHERE status = 'approved' ORDER BY created_at DESC LIMIT 10");
        $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($prompts)) {
            $msg = "🏜️ No prompts found in the library.";
        } else {
            $msg = "📦 <b>Recent Prompt IDs:</b>\n\n";
            foreach ($prompts as $p) {
                $title = $p['text_output'] ?: $p['category'];
                $msg .= "🆔 <b>#{$p['id']}</b> - " . htmlspecialchars($title) . "\n";
            }
            $msg .= "\nTo delete any of these, use: <code>/delete [ID]</code>";
        }
        
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // ADMIN DELETE COMMAND (Consolidated with Options)
    if (strpos($text, '/delete ') === 0 && isAdmin($telegram_id)) {
        if (!hasAdminPermission($telegram_id, 'can_delete')) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "🚫 <b>Access Denied!</b> You do not have permission to delete posts. Contact Super Admin.", 'parse_mode' => 'HTML']);
            return;
        }
        $id = (int)substr($text, 8);
        if ($id > 0) {
            $submission = getSubmissionById($id);
            if ($submission) {
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🗑️ Both (Web + Channel)', 'callback_data' => "admin_del_both_$id"]],
                        [['text' => '🌐 Website Only', 'callback_data' => "admin_del_web_$id"]],
                        [['text' => '📢 Channel Only', 'callback_data' => "admin_del_chan_$id"]],
                        [['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                    ]
                ];
                apiRequest("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "❓ <b>Delete Prompt #$id</b>\nWhere do you want to remove it from?",
                    'parse_mode' => 'HTML',
                    'reply_markup' => $keyboard
                ]);
            } else {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Prompt #$id not found."]);
            }
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Usage: /delete <id>"]);
        }
        return;
    }

    // ADMIN EDIT COMMAND
    if (strpos($text, '/edit ') === 0 && isAdmin($telegram_id)) {
        $id = (int)substr($text, 6);
        if ($id > 0) {
            $submission = getSubmissionById($id);
            if ($submission) {
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '📝 Open Edit Menu', 'callback_data' => "admin_edit_$id"]],
                        [['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                    ]
                ];
                apiRequest("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "📝 <b>Edit Prompt #$id</b>\nClick below to open the editor.",
                    'parse_mode' => 'HTML',
                    'reply_markup' => $keyboard
                ]);
            } else {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Prompt #$id not found."]);
            }
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Usage: /edit <id>"]);
        }
        return;
    }

    if ($text === '/submit') {
        if (getDailySubmissionCount($telegram_id) >= 5) {
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "🚫 You have reached your daily limit of 5 submissions. Please try again tomorrow!"
            ]);
            return;
        }

        $member_res = apiRequest("getChatMember", ['chat_id' => CHANNEL_ID, 'user_id' => $telegram_id]);
        $status = $member_res['result']['status'] ?? 'left';

        if (in_array($status, ['left', 'kicked', 'restricted'])) {
            $channel_username = CHANNEL_USERNAME;
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📢 Join Channel', 'url' => 'https://t.me/' . $channel_username]]
                ]
            ];
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "⚠️ You must join our official channel to submit prompts or become a creator! Please join and then try /submit again.",
                'reply_markup' => $keyboard
            ]);
            return;
        }

        updateUserStep($telegram_id, 'awaiting_category');
        createNewDraft($telegram_id);

        $keyboard = buildCategoryKeyboard();
        $user_approved = getUserApprovedPromptCount($telegram_id);
        $first_bounty_banner = "";
        if ($user_approved === 0) {
            $first_bounty_amt = getSetting('first_prompt_reward_amount', '5.00');
            $first_bounty_banner = "🎁 <b>FIRST PROMPT BOUNTY ACTIVE:</b>\nEarn <b>₹{$first_bounty_amt}</b> when this first prompt is approved!\n\n";
        }

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "{$first_bounty_banner}[🟩⬜⬜⬜] <b>Step 1 of 4: Select Category</b>\n\nPlease select a category for your prompt:\n<i>Example: Photo Editing, AI Art</i>",
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard
        ]);
        return;
    }

    if ($text === '/balance' || $text === '/credits') {
        updateUserStep($telegram_id, 'none');
        $bal = getBalance($telegram_id);
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "💰 You currently have <b>₹" . number_format($bal, 2) . "</b>.",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    if ($text === '/withdraw' || $text === '/balance') {
        updateUserStep($telegram_id, 'none');
        sendBalanceDashboard($chat_id, $telegram_id, $username);
        return;
    }

    if ($text === '/pending' && isAdmin($telegram_id)) {
        global $pdo;
        $stmt = $pdo->query("SELECT id, text_output FROM submissions WHERE status = 'pending' ORDER BY id ASC LIMIT 20");
        $pendings = $stmt->fetchAll();
        if (!$pendings) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ No pending submissions!"]);
            return;
        }
        $kb = [];
        foreach ($pendings as $p) {
            $kb[] = [['text' => "#{$p['id']} - {$p['text_output']}", 'callback_data' => "admin_vp_{$p['id']}"]];
        }
        apiRequest("sendMessage", [
            'chat_id' => $chat_id, 
            'text' => "⏳ <b>Pending Submissions:</b>", 
            'parse_mode' => 'HTML', 
            'reply_markup' => ['inline_keyboard' => $kb]
        ]);
        return;
    }

    if (strpos($text, '/addadmin ') === 0 && defined('ADMIN_ID') && $telegram_id == ADMIN_ID) {
        $new_admin_id = (int)substr($text, 10);
        addAdmin($new_admin_id, $telegram_id);
        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Added $new_admin_id as an admin."]);
        return;
    }

    if (strpos($text, '/removeadmin ') === 0 && defined('ADMIN_ID') && $telegram_id == ADMIN_ID) {
        $rem_admin_id = (int)substr($text, 13);
        if (removeAdmin($rem_admin_id)) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Removed $rem_admin_id from admins."]);
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cannot remove the primary admin."]);
        }
        return;
    }

    if ($text === '/admins' && isAdmin($telegram_id)) {
        $admins = getAllAdmins();
        $msg = "👥 <b>Admin List:</b>\n\n";
        foreach ($admins as $a) {
            $msg .= "• <code>$a</code>" . (defined('ADMIN_ID') && $a == ADMIN_ID ? " (Primary)" : "") . "\n";
        }
        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']);
        return;
    }

    if ($text === '/referrals' && isAdmin($telegram_id)) {
        $stats = getDetailedReferralStats(10);
        
        $msg = "🏆 <b>Top Referrers Detailed Stats</b> 🏆\n\n";
        if (empty($stats)) {
            $msg .= "<i>No referral stats found yet.</i>";
        } else {
            $rank = 1;
            foreach ($stats as $s) {
                $uname = htmlspecialchars($s['username'] ?? 'Unknown');
                $total = (int)$s['total_invites'];
                $success = (int)$s['successful_invites'];
                $rate = $total > 0 ? round(($success / $total) * 100) : 0;
                $earnings = number_format($success * 1.00, 2);
                
                $msg .= "<b>{$rank}. {$uname}</b> (<code>{$s['referrer_id']}</code>)\n";
                $msg .= "• Total Invites: <b>{$total}</b>\n";
                $msg .= "• Successful: <b>{$success}</b> ({$rate}% conversion)\n";
                $msg .= "• Earnings: <b>₹{$earnings}</b>\n\n";
                $rank++;
            }
        }
        
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // ==========================================
    // NON-DRAFT STEP HANDLING
    // ==========================================

    // ADMIN: AWAITING NEW CATEGORY NAME
    if ($step === 'admin_awaiting_category_name' && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a valid category name. Type /cancel to abort."]);
            return;
        }
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled."]);
            return;
        }
        $added = addCategory($text);
        updateUserStep($telegram_id, 'none');
        if ($added) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Category <b>" . htmlspecialchars($text) . "</b> added successfully!", 'parse_mode' => 'HTML']);
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Category already exists or could not be added."]);
        }
        sendCategoryManagePanel($chat_id);
        return;
    }

    // ADMIN: AWAITING CATEGORY RENAME
    if (strpos($step, 'admin_awaiting_cat_rename_') === 0 && isAdmin($telegram_id)) {
        $cat_id = (int)substr($step, 26);
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a valid new category name. Type /cancel to abort."]);
            return;
        }
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled rename."]);
            return;
        }
        $new_name = trim($text);
        $updated = updateCategoryName($cat_id, $new_name);
        updateUserStep($telegram_id, 'none');
        if ($updated) {
            logAdminActivity($telegram_id, $username, 'RENAME_CATEGORY', (string)$cat_id, "Renamed category #{$cat_id} to {$new_name}");
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Category #<b>{$cat_id}</b> successfully renamed to <b>" . htmlspecialchars($new_name) . "</b>!\n<i>All existing prompt posts in this category were automatically updated.</i>", 'parse_mode' => 'HTML']);
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Failed to rename category #{$cat_id}."]);
        }
        sendCategoryManagePanel($chat_id);
        return;
    }

    // ADMIN: AWAITING NEW ADMIN ID
    if ($step === 'admin_awaiting_new_admin_id' && isAdmin($telegram_id) && $telegram_id == ADMIN_ID) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send the Telegram User ID of the new admin. Type /cancel to abort."]);
            return;
        }
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled."]);
            return;
        }
        $new_id = (int)trim($text);
        if ($new_id <= 0) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Invalid User ID. Please send a numeric Telegram User ID."]);
            return;
        }
        addAdmin($new_id, $telegram_id);
        updateUserStep($telegram_id, 'none');
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ User <code>$new_id</code> has been added as an admin!",
            'parse_mode' => 'HTML'
        ]);
        // Notify new admin
        apiRequest("sendMessage", [
            'chat_id' => $new_id,
            'text' => "🎉 You have been granted admin access to the Prompt Bot! Send /admin to open the admin panel."
        ], true);
        sendAdminManagePanel($chat_id);
        return;
    }

    // ADMIN: AWAITING SET CHALLENGE (from admin panel button)
    if ($step === 'admin_awaiting_challenge_theme' && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send challenge keyword(s). Separate multiple keywords with commas. Type /cancel to abort."]);
            return;
        }
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled."]);
            return;
        }
        $added = addChallengeKeywords($text);
        updateUserStep($telegram_id, 'none');
        $added_str = implode(', ', $added);
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ Challenge keywords added: <b>" . htmlspecialchars($added_str) . "</b>\n📢 Broadcasting notification to all users...",
            'parse_mode' => 'HTML'
        ]);
        broadcastNewChallengeNotification($added);
        return;
    }

    if ($step === 'admin_awaiting_ref_reward' && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a numeric amount (e.g. 1.50). Type /cancel to abort."]);
            return;
        }
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled."]);
            return;
        }
        $amount = round((float)preg_replace('/[^0-9.]/', '', $text), 2);
        if ($amount <= 0 || $amount > 100) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Invalid amount. Please enter a value between 0.01 and 100.00."]);
            return;
        }
        setSetting('referral_reward_amount', number_format($amount, 2, '.', ''));
        updateUserStep($telegram_id, 'none');
        apiRequest("sendMessage", [
            'chat_id'    => $chat_id,
            'text'       => "✅ <b>Referral Reward Updated!</b>\n\nNew referral reward: <b>₹" . number_format($amount, 2) . "</b> per successful referral.\n\n<i>Both referrer and referee will now earn this amount.</i>",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    if ($step === 'admin_awaiting_prompt_reward' && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a numeric amount (e.g. 0.50). Type /cancel to abort."]);
            return;
        }
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled."]);
            return;
        }
        $amount = round((float)preg_replace('/[^0-9.]/', '', $text), 2);
        if ($amount <= 0 || $amount > 100) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Invalid amount. Please enter a value between 0.01 and 100.00."]);
            return;
        }
        setSetting('prompt_reward_amount', number_format($amount, 2, '.', ''));
        updateUserStep($telegram_id, 'none');
        apiRequest("sendMessage", [
            'chat_id'    => $chat_id,
            'text'       => "✅ <b>Per Prompt Submission Reward Updated!</b>\n\nNew reward: <b>₹" . number_format($amount, 2) . "</b> per approved prompt submission.",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    if ($step === 'admin_awaiting_first_prompt_reward' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled setting first prompt reward."]);
            return;
        }
        $amount = round((float)preg_replace('/[^0-9.]/', '', $text), 2);
        if ($amount <= 0 || $amount > 500) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Invalid amount. Please enter a value between 0.01 and 500.00."]);
            return;
        }
        setSetting('first_prompt_reward_amount', number_format($amount, 2, '.', ''));
        updateUserStep($telegram_id, 'none');
        apiRequest("sendMessage", [
            'chat_id'    => $chat_id,
            'text'       => "✅ <b>First Prompt Welcome Reward Updated!</b>\n\nNew 1st prompt bounty: <b>₹" . number_format($amount, 2) . "</b> upon first approved prompt submission.",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    if ($step === 'admin_awaiting_maint_reason' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled maintenance notice update."]);
            return;
        }
        $new_reason = trim($text);
        if (empty($new_reason)) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Please send a valid text note for maintenance message."]);
            return;
        }
        setSetting('maintenance_reason', $new_reason);
        updateUserStep($telegram_id, 'none');
        logAdminActivity($telegram_id, $username, 'CHANGE_MAINT_MSG', '0', "Changed maintenance message to: {$new_reason}");
        
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ <b>Maintenance Notice Updated!</b>\n\nNew Notice: <i>" . htmlspecialchars($new_reason) . "</i>",
            'parse_mode' => 'HTML'
        ]);
        sendAdminMaintenancePanel($chat_id);
        return;
    }

    // --- ADMIN WALLET MANAGEMENT STEPS ---
    if ($step === 'admin_w_credit_target' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled wallet action."]);
            return;
        }
        $target_user = findUserByInput($text);
        if (!$target_user) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ <b>User Not Found</b>\n\nNo user matches <code>" . htmlspecialchars($text) . "</code>. Please check and try again, or send /cancel:", 'parse_mode' => 'HTML']);
            return;
        }
        $target_id = $target_user['telegram_id'];
        $uname = $target_user['username'] ? '@' . ltrim($target_user['username'], '@') : 'User ' . $target_id;
        setSetting('admin_w_target_' . $telegram_id, (string)$target_id);
        updateUserStep($telegram_id, 'admin_w_credit_amount');
        
        $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_wallet_menu']]]];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ <b>Target User Found</b>\n\nUser: <b>{$uname}</b> (ID: <code>{$target_id}</code>)\nCurrent Wallet Balance: <b>₹" . number_format($target_user['balance'], 2) . "</b>\n\nPlease enter the amount to <b>CREDIT</b> (e.g. <code>50</code> or <code>100.50</code>):",
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    if ($step === 'admin_w_credit_amount' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled wallet action."]);
            return;
        }
        $amount = round((float)preg_replace('/[^0-9.]/', '', $text), 2);
        if ($amount <= 0) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Invalid amount. Please enter a valid number (e.g. 50 or 100.50). Send /cancel to abort."]);
            return;
        }
        setSetting('admin_w_amt_' . $telegram_id, (string)$amount);
        updateUserStep($telegram_id, 'admin_w_credit_reason');
        $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_wallet_menu']]]];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "💰 <b>Amount to Credit:</b> +₹" . number_format($amount, 2) . "\n\nPlease enter a <b>reason/note</b> for this credit (e.g. <code>Contest Winner</code>, <code>Bonus</code>, <code>Refund</code>):",
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    if ($step === 'admin_w_credit_reason' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled wallet action."]);
            return;
        }
        $target_id = (int)getSetting('admin_w_target_' . $telegram_id, '0');
        $amount = (float)getSetting('admin_w_amt_' . $telegram_id, '0');
        $reason = trim($text);
        if (!$target_id || $amount <= 0) {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Session expired or invalid data. Please try again from /admin."]);
            return;
        }

        addBalance($target_id, $amount, 'CREDIT', 'ADMIN_CREDIT', $reason);
        $new_bal = getBalance($target_id);
        $target_user = getUserByTelegramId($target_id);
        $uname = $target_user['username'] ? '@' . ltrim($target_user['username'], '@') : 'User ' . $target_id;
        $admin_label = $username ? '@' . ltrim($username, '@') : 'Admin ' . $telegram_id;

        updateUserStep($telegram_id, 'none');
        logAdminActivity($telegram_id, $username, 'WALLET_CREDIT', (string)$target_id, "Credited ₹" . number_format($amount, 2) . " to {$uname} (Reason: {$reason})");

        // Notify Admin
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ <b>WALLET CREDIT COMPLETE</b>\n\nTarget User: <b>{$uname}</b> (ID: <code>{$target_id}</code>)\nAmount Credited: <b>+₹" . number_format($amount, 2) . "</b>\nReason: <i>" . htmlspecialchars($reason) . "</i>\nNew Balance: <b>₹" . number_format($new_bal, 2) . "</b>\n\n<i>Logged to Google Sheets & User notified on Telegram!</i>",
            'parse_mode' => 'HTML'
        ]);

        // Notify Target User via Telegram
        apiRequest("sendMessage", [
            'chat_id' => $target_id,
            'text' => "🎉 <b>WALLET CREDIT ALERT</b>\n\nAn admin has credited <b>+₹" . number_format($amount, 2) . "</b> to your wallet balance!\n\nReason: <i>" . htmlspecialchars($reason) . "</i>\nNew Balance: <b>₹" . number_format($new_bal, 2) . "</b>",
            'parse_mode' => 'HTML'
        ]);

        // Notify Other Admins
        notifyOtherAdmins($telegram_id, "💳 <b>Admin Wallet Action</b>\n\n{$admin_label} credited <b>+₹" . number_format($amount, 2) . "</b> to {$uname} (Reason: <i>" . htmlspecialchars($reason) . "</i>). New Balance: <b>₹" . number_format($new_bal, 2) . "</b>");
        return;
    }

    if ($step === 'admin_w_debit_target' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled wallet action."]);
            return;
        }
        $target_user = findUserByInput($text);
        if (!$target_user) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ <b>User Not Found</b>\n\nNo user matches <code>" . htmlspecialchars($text) . "</code>. Please check and try again, or send /cancel:", 'parse_mode' => 'HTML']);
            return;
        }
        $target_id = $target_user['telegram_id'];
        $uname = $target_user['username'] ? '@' . ltrim($target_user['username'], '@') : 'User ' . $target_id;
        setSetting('admin_w_target_' . $telegram_id, (string)$target_id);
        updateUserStep($telegram_id, 'admin_w_debit_amount');
        
        $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_wallet_menu']]]];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ <b>Target User Found</b>\n\nUser: <b>{$uname}</b> (ID: <code>{$target_id}</code>)\nCurrent Wallet Balance: <b>₹" . number_format($target_user['balance'], 2) . "</b>\n\nPlease enter the amount to <b>DEDUCT</b> (e.g. <code>20</code> or <code>50.00</code>):",
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    if ($step === 'admin_w_debit_amount' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled wallet action."]);
            return;
        }
        $amount = round((float)preg_replace('/[^0-9.]/', '', $text), 2);
        if ($amount <= 0) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Invalid amount. Please enter a valid number (e.g. 20 or 50.00). Send /cancel to abort."]);
            return;
        }
        setSetting('admin_w_amt_' . $telegram_id, (string)$amount);
        updateUserStep($telegram_id, 'admin_w_debit_reason');
        $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_wallet_menu']]]];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "🔻 <b>Amount to Deduct:</b> -₹" . number_format($amount, 2) . "\n\nPlease enter a <b>reason/note</b> for this deduction (e.g. <code>Penalty</code>, <code>Overpayment Adjustment</code>):",
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);
        return;
    }

    if ($step === 'admin_w_debit_reason' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled wallet action."]);
            return;
        }
        $target_id = (int)getSetting('admin_w_target_' . $telegram_id, '0');
        $amount = (float)getSetting('admin_w_amt_' . $telegram_id, '0');
        $reason = trim($text);
        if (!$target_id || $amount <= 0) {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Session expired or invalid data. Please try again from /admin."]);
            return;
        }

        deductBalanceAdmin($target_id, $amount, 'DEBIT', 'ADMIN_DEBIT', $reason);
        $new_bal = getBalance($target_id);
        $target_user = getUserByTelegramId($target_id);
        $uname = $target_user['username'] ? '@' . ltrim($target_user['username'], '@') : 'User ' . $target_id;
        $admin_label = $username ? '@' . ltrim($username, '@') : 'Admin ' . $telegram_id;

        updateUserStep($telegram_id, 'none');
        logAdminActivity($telegram_id, $username, 'WALLET_DEBIT', (string)$target_id, "Deducted ₹" . number_format($amount, 2) . " from {$uname} (Reason: {$reason})");

        // Notify Admin
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ <b>WALLET DEDUCTION COMPLETE</b>\n\nTarget User: <b>{$uname}</b> (ID: <code>{$target_id}</code>)\nAmount Deducted: <b>-₹" . number_format($amount, 2) . "</b>\nReason: <i>" . htmlspecialchars($reason) . "</i>\nNew Balance: <b>₹" . number_format($new_bal, 2) . "</b>\n\n<i>Logged to Google Sheets & User notified on Telegram!</i>",
            'parse_mode' => 'HTML'
        ]);

        // Notify Target User via Telegram
        apiRequest("sendMessage", [
            'chat_id' => $target_id,
            'text' => "⚠️ <b>WALLET DEBIT ALERT</b>\n\nAn admin has deducted <b>-₹" . number_format($amount, 2) . "</b> from your wallet balance.\n\nReason: <i>" . htmlspecialchars($reason) . "</i>\nNew Balance: <b>₹" . number_format($new_bal, 2) . "</b>",
            'parse_mode' => 'HTML'
        ]);

        // Notify Other Admins
        notifyOtherAdmins($telegram_id, "💳 <b>Admin Wallet Action</b>\n\n{$admin_label} deducted <b>-₹" . number_format($amount, 2) . "</b> from {$uname} (Reason: <i>" . htmlspecialchars($reason) . "</i>). New Balance: <b>₹" . number_format($new_bal, 2) . "</b>");
        return;
    }

    if ($step === 'admin_w_check_target' && isAdmin($telegram_id)) {
        if ($text === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cancelled wallet action."]);
            return;
        }
        $target_user = findUserByInput($text);
        if (!$target_user) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ <b>User Not Found</b>\n\nNo user matches <code>" . htmlspecialchars($text) . "</code>. Please check and try again, or send /cancel:", 'parse_mode' => 'HTML']);
            return;
        }
        $target_id = $target_user['telegram_id'];
        $uname = $target_user['username'] ? '@' . ltrim($target_user['username'], '@') : 'User ' . $target_id;
        $rank = getUserRank($target_id);
        $user_stats = getUserStats($target_id);

        global $pdo;
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM withdrawals WHERE telegram_id = ? AND status != 'rejected'");
        $stmt->execute([$target_id]);
        $total_withdrawn = (float)$stmt->fetchColumn();
        $lifetime = (float)$target_user['balance'] + $total_withdrawn;

        $banned_info = getUserBanInfo($target_id);
        $status_label = ($banned_info && $banned_info['is_banned']) ? "🔴 Banned (Reason: " . htmlspecialchars($banned_info['ban_reason'] ?? 'None') . ")" : "🟢 Active";

        updateUserStep($telegram_id, 'none');
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "👤 <b>USER WALLET & ACTIVITY PROFILE</b>\n\n" .
                      "User: <b>{$uname}</b> (ID: <code>{$target_id}</code>)\n" .
                      "Current Balance: <b>₹" . number_format($target_user['balance'], 2) . "</b>\n" .
                      "Total Withdrawn: <b>₹" . number_format($total_withdrawn, 2) . "</b>\n" .
                      "Lifetime Earnings: <b>₹" . number_format($lifetime, 2) . "</b>\n" .
                      "Approved Prompts: <b>" . (int)($user_stats['approved'] ?? 0) . "</b>\n" .
                      "Creator Rank: <b>#{$rank}</b>\n" .
                      "Account Status: {$status_label}",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    if ($step === 'awaiting_upi') {
        if (!$text) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a valid UPI ID."]);
            return;
        }
        $bal = getBalance($telegram_id);
        if ($bal < 20) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "🚫 Minimum withdrawal is ₹20."]);
            updateUserStep($telegram_id, 'none');
            return;
        }
        
        // Atomic deduction check (prevents double-spending & negative balance race condition)
        $deducted = deductBalance($telegram_id, $bal);
        if (!$deducted) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Withdrawal failed: Insufficient balance or concurrent request detected."]);
            updateUserStep($telegram_id, 'none');
            return;
        }
        
        $withdrawal_id = createWithdrawal($telegram_id, $bal, $text);
        updateUserStep($telegram_id, 'none');
        
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ <b>Withdrawal Request Submitted!</b>\n\nAmount: ₹" . number_format($bal, 2) . "\nUPI: <code>$text</code>\n\nAdmins will process this shortly.",
            'parse_mode' => 'HTML'
        ]);
        
        // Notify admins
        $pay_url = "https://rtmcreator.com/bots/prompt-bot/pay.php?pa=" . urlencode($text) . "&am=" . urlencode($bal) . "&pn=" . urlencode($username) . "&tn=WD_Ref_" . urlencode($withdrawal_id);
        $admin_kb = [
            'inline_keyboard' => [
                [['text' => '📱 Pay with UPI', 'url' => $pay_url]],
                [['text' => '✅ Approve (Mark Paid)', 'callback_data' => "wd_approve_{$withdrawal_id}"], ['text' => '❌ Reject', 'callback_data' => "wd_reject_{$withdrawal_id}"]]
            ]
        ];
        $admins = getAllAdmins();
        foreach ($admins as $admin) {
            apiRequest("sendMessage", [
                'chat_id' => $admin,
                'text' => "💸 <b>NEW WITHDRAWAL REQUEST</b>\n\nID: #{$withdrawal_id}\nUser: {$username} ({$telegram_id})\nAmount: ₹" . number_format($bal, 2) . "\nUPI: <code>$text</code>",
                'parse_mode' => 'HTML',
                'reply_markup' => $admin_kb
            ]);
        }
        return;
    }

    // ADMIN CUSTOM REJECT REASON
    if (strpos($step, 'reject_') === 0 && isAdmin($telegram_id)) {
        if (!$text) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the reason."]);
            return;
        }
        $draft_id = (int) substr($step, 7);
        $reason = $text;

        $target_draft = getSubmissionById($draft_id);
        if ($target_draft && $target_draft['status'] === 'pending') {
            updateSubmissionStatus($draft_id, 'rejected');

            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Post #$draft_id rejected for custom reason: $reason"]);

            apiRequest("sendMessage", [
                'chat_id' => $target_draft['telegram_id'],
                'text' => "😞 Your recent prompt submission was rejected.\nReason: <b>" . htmlspecialchars($reason) . "</b>",
                'parse_mode' => 'HTML'
            ]);

            $admin_label = getAdminDisplayName($telegram_id, $from);
            $notify_text = "❌ <b>Admin Activity Alert</b>\n\nPost <b>#{$draft_id}</b> was <b>REJECTED</b> (Custom Reason: <i>" . htmlspecialchars($reason) . "</i>) by {$admin_label}.";
            notifyOtherAdmins($telegram_id, $notify_text);
        }
        updateUserStep($telegram_id, 'none');
        return;
    }

    // ADMIN TIP
    if (strpos($step, 'tip_') === 0 && isAdmin($telegram_id)) {
        if (!$text) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the tip."]);
            return;
        }
        $draft_id = (int) substr($step, 4);
        $tip = $text;

        $target_draft = getSubmissionById($draft_id);
        if ($target_draft && $target_draft['status'] === 'pending') {
            updateSubmissionStatus($draft_id, 'rejected');
            updateDraftSubmission($draft_id, 'feedback', $tip);

            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Post #$draft_id rejected with tip: $tip"]);

            apiRequest("sendMessage", [
                'chat_id' => $target_draft['telegram_id'],
                'text' => "😞 Your prompt submission was rejected, but the admin left a tip for improvement:\n\n<b>Tip:</b> " . htmlspecialchars($tip) . "\n\nYou can refine your prompt and try again!",
                'parse_mode' => 'HTML'
            ]);
        }
        updateUserStep($telegram_id, 'none');
        return;
    }

    // USER SUPPORT TICKET INPUT
    if (strpos($step, 'awaiting_support_msg_') === 0) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a text message for your support ticket."]);
            return;
        }
        if (trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Support ticket cancelled."]);
            return;
        }

        // Rate limit: Max 3 open tickets per user
        global $pdo;
        $t_stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE telegram_id = ? AND status = 'open'");
        $t_stmt->execute([(int)$telegram_id]);
        if ((int)$t_stmt->fetchColumn() >= 3) {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ <b>Ticket Limit Reached</b>\n\nYou currently have 3 open support tickets. Please wait for our admin team to reply before creating new tickets.", 'parse_mode' => 'HTML']);
            return;
        }

        $category = substr($step, 21);
        $ticket_id = createSupportTicket($telegram_id, $category, trim($text));
        updateUserStep($telegram_id, 'none');
        
        logSupportTicket($ticket_id, $telegram_id, $username, $category, trim($text), '', 'OPEN');
        logUserActivity($telegram_id, $username, 'SUBMIT_SUPPORT_TICKET', $step, "Support Ticket #T-$ticket_id ($category)");
        
        $confirm_msg = "✅ <b>Support Ticket #T-{$ticket_id} Submitted!</b>\n\n" .
                       "Category: <b>" . htmlspecialchars($category) . "</b>\n" .
                       "Our admin team will review your ticket and reply to you directly in this chat shortly.";
        
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $confirm_msg,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '🏠 Main Menu', 'callback_data' => 'cmd_start']]]]
        ]);

        // Send Support Ticket Card to all admins
        $ticket_card = "📩 <b>NEW SUPPORT TICKET #T-{$ticket_id}</b>\n\n" .
                       "👤 <b>From:</b> " . htmlspecialchars($username) . " (<code>{$telegram_id}</code>)\n" .
                       "🏷 <b>Category:</b> " . htmlspecialchars($category) . "\n" .
                       "💬 <b>Message:</b>\n" . htmlspecialchars(trim($text)) . "\n";
        
        $admin_kb = [
            'inline_keyboard' => [
                [
                    ['text' => '💬 Reply to User', 'callback_data' => "admin_suppreply_{$ticket_id}"],
                    ['text' => '✅ Resolve & Close', 'callback_data' => "admin_suppclose_{$ticket_id}"]
                ]
            ]
        ];

        $admins = getAllAdmins();
        foreach ($admins as $aid) {
            try {
                apiRequest("sendMessage", [
                    'chat_id' => $aid,
                    'text' => $ticket_card,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $admin_kb
                ]);
                usleep(30000);
            } catch (Exception $e) {
                error_log("Failed to send support ticket to admin {$aid}: " . $e->getMessage());
            }
        }
        return;
    }

    // ADMIN SUPPORT REPLY INPUT
    if (strpos($step, 'admin_replying_ticket_') === 0 && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for your support reply."]);
            return;
        }
        if (trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Support reply cancelled."]);
            return;
        }
        $ticket_id = (int)substr($step, 22);
        $ticket = getSupportTicketById($ticket_id);
        if (!$ticket) {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Ticket not found."]);
            return;
        }

        replySupportTicket($ticket_id, $telegram_id, trim($text));
        updateUserStep($telegram_id, 'none');

        logSupportTicket($ticket_id, $ticket['telegram_id'], $ticket['username'] ?? '', $ticket['category'], $ticket['message'], trim($text), 'CLOSED');
        logAdminActivity($telegram_id, $username, 'REPLY_SUPPORT_TICKET', $ticket_id, "Replied & resolved Ticket #T-$ticket_id");

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✅ <b>Support reply delivered to user!</b>",
            'parse_mode' => 'HTML'
        ]);

        // Send reply to user
        $user_reply_msg = "🎧 <b>Support Team Reply (Ticket #T-{$ticket_id})</b>\n\n" .
                          "<i>\"" . htmlspecialchars(trim($text)) . "\"</i>\n\n" .
                          "Need further assistance? Tap below to open a new support ticket.";
        
        $user_kb = [
            'inline_keyboard' => [
                [
                    ['text' => '💬 Send Follow-up Ticket', 'callback_data' => 'cmd_support'],
                    ['text' => '🏠 Main Menu', 'callback_data' => 'cmd_start']
                ]
            ]
        ];

        apiRequest("sendMessage", [
            'chat_id' => $ticket['telegram_id'],
            'text' => $user_reply_msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $user_kb
        ]);
        return;
    }

    // ADMIN BAN WIZARD INPUT
    if (strpos($step, 'admin_awaiting_ban_target') === 0 && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send User ID or @username."]);
            return;
        }
        if (trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Ban process cancelled."]);
            return;
        }
        $target = trim($text);
        if ($target == ADMIN_ID || $target == (string)$telegram_id) {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cannot ban an admin account."]);
            return;
        }
        updateUserStep($telegram_id, "admin_awaiting_ban_reason_{$target}");
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "📝 <b>Target: " . htmlspecialchars($target) . "</b>\n\nPlease type the reason for banning this account (or type 'default' for standard reason):\n\n<i>Type /cancel to abort.</i>",
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
        ]);
        return;
    }

    if (strpos($step, 'admin_awaiting_ban_reason_') === 0 && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a ban reason."]);
            return;
        }
        if (trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Ban process cancelled."]);
            return;
        }
        $target = substr($step, 25);
        $reason = (trim($text) === 'default') ? 'Violation of community guidelines' : trim($text);
        
        $success = banUser($target, $reason);
        updateUserStep($telegram_id, 'none');
        if ($success) {
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "✅ User <b>" . htmlspecialchars($target) . "</b> has been <b>BANNED</b>.\n📌 <b>Reason:</b> " . htmlspecialchars($reason),
                'parse_mode' => 'HTML'
            ]);
            $admin_label = getAdminDisplayName($telegram_id, $from);
            notifyOtherAdmins($telegram_id, "🚫 <b>Admin Activity Alert</b>\n\nUser <b>" . htmlspecialchars($target) . "</b> was <b>BANNED</b> (Reason: <i>" . htmlspecialchars($reason) . "</i>) by {$admin_label}.");
        } else {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ User not found or already banned."]);
        }
        return;
    }

    // BROADCAST BUTTON WIZARD INPUT
    if (strpos($step, 'admin_b_btn_text_') === 0 && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the button."]);
            return;
        }
        if (trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Button creation cancelled."]);
            return;
        }
        $msg_id = (int)substr($step, 17);
        $btn_text = trim($text);
        updateUserStep($telegram_id, "admin_b_btn_url_{$msg_id}_" . urlencode($btn_text));
        
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "🔗 <b>Button Text: " . htmlspecialchars($btn_text) . "</b>\n\nNow please send the <b>URL / Link</b> for this button:\n<i>Example: https://t.me/Prompts_library_bot or https://rtmcreator.com/prompt-library/</i>\n\nType /cancel to abort.",
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
        ]);
        return;
    }

    if (strpos($step, 'admin_b_btn_url_') === 0 && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a valid URL."]);
            return;
        }
        if (trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Button creation cancelled."]);
            return;
        }
        $parts = explode('_', $step, 5);
        $msg_id = (int)$parts[3];
        $btn_text = urldecode($parts[4]);
        $btn_url = trim($text);
        
        if (!filter_var($btn_url, FILTER_VALIDATE_URL)) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Invalid URL format. Please send a valid link starting with http:// or https://"]);
            return;
        }

        updateUserStep($telegram_id, 'none');

        $all_count = count(getAllUsers('all'));
        $active_count = count(getAllUsers('active'));
        $inactive_count = count(getAllUsers('inactive'));
        $top_count = count(getAllUsers('top'));

        $nav_kb = [
            'inline_keyboard' => [
                [['text' => "🔘 Action Button: " . $btn_text, 'url' => $btn_url]],
                [['text' => "🚀 All Users ($all_count)", 'callback_data' => "b_launch_all_{$msg_id}_" . urlencode($btn_text) . "_" . urlencode($btn_url)]],
                [['text' => "🟢 Active ($active_count)", 'callback_data' => "b_launch_active_{$msg_id}_" . urlencode($btn_text) . "_" . urlencode($btn_url)],
                 ['text' => "🔴 Inactive ($inactive_count)", 'callback_data' => "b_launch_inactive_{$msg_id}_" . urlencode($btn_text) . "_" . urlencode($btn_url)]],
                [['text' => "💎 Top Creators ($top_count)", 'callback_data' => "b_launch_top_{$msg_id}_" . urlencode($btn_text) . "_" . urlencode($btn_url)]],
                [['text' => '❌ Cancel Broadcast', 'callback_data' => 'cmd_cancel']]
            ]
        ];

        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ <b>Action Button Added! Updated Single Preview below:</b>", 'parse_mode' => 'HTML']);
        apiRequest("copyMessage", [
            'chat_id' => $chat_id,
            'from_chat_id' => $chat_id,
            'message_id' => $msg_id,
            'reply_markup' => $nav_kb
        ]);
        return;
    }

    // ADMIN EDIT INPUT
    if (strpos($step, 'admin_editing_') === 0 && isAdmin($telegram_id)) {
        if ($text && trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Field editing cancelled."]);
            return;
        }
        
        $rest = substr($step, 14); // Strip 'admin_editing_'
        $parts = explode('_', $rest, 2);
        $draft_id = (int)$parts[0];
        $field = $parts[1] ?? '';
        
        if ($field === 'image') {
            // Handle Media Replacement (Photo or Video)
            $new_file_id = null;
            if (isset($message['photo'])) {
                $photo_arr = $message['photo'];
                $last_photo = end($photo_arr);
                $new_file_id = $last_photo['file_id'] ?? null;
            } elseif (isset($message['video'])) {
                $new_file_id = $message['video']['file_id'] ?? null;
            } elseif (isset($message['document'])) {
                $new_file_id = $message['document']['file_id'] ?? null;
            }

            if (!$new_file_id) {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ Please send a valid Photo or Video to replace the image."]);
                return;
            }

            $target = getSubmissionById($draft_id);
            if ($target) {
                // Delete old image file from server if exists
                if (!empty($target['local_media'])) {
                    $decoded = json_decode($target['local_media'], true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $fpath) {
                            if ($fpath && is_string($fpath)) {
                                $abs_p = __DIR__ . '/' . ltrim($fpath, '/');
                                if (file_exists($abs_p)) {
                                    @unlink($abs_p);
                                }
                            }
                        }
                    } else {
                        $abs_p = __DIR__ . '/' . ltrim($target['local_media'], '/');
                        if (file_exists($abs_p)) {
                            @unlink($abs_p);
                        }
                    }
                }

                // Download and save new media
                $new_local_media = downloadAndSaveMedia($new_file_id);
                updateDraftSubmission($draft_id, 'file_id', $new_file_id);
                if ($new_local_media) {
                    updateDraftSubmission($draft_id, 'local_media', $new_local_media);
                }

                updateUserStep($telegram_id, 'none');
                logContentEdit($draft_id, $username, 'image', $target['file_id'] ?? '', $new_file_id);
                logAdminActivity($telegram_id, $username, 'EDIT_MEDIA', $draft_id, "Replaced image/media for Post #$draft_id (Old file deleted from server)");

                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ <b>Image / Media updated successfully for Post #{$draft_id}!</b>\n<i>Old server file was deleted cleanly.</i>", 'parse_mode' => 'HTML']);

                global $pdo;
                $stmt = $pdo->prepare("SELECT username FROM users WHERE telegram_id = ?");
                $stmt->execute([$target['telegram_id']]);
                $creator_name = $stmt->fetchColumn() ?: 'Unknown';
                sendToAdminDirectly($chat_id, $draft_id, $creator_name);
            } else {
                updateUserStep($telegram_id, 'none');
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Post #$draft_id not found."]);
            }
            return;
        }

        // Standard Text Field Editing (Title, Prompt, Tags, Category)
        if (!$text) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the update."]);
            return;
        }

        if (!empty($field) && $draft_id > 0) {
            $old_target = getSubmissionById($draft_id);
            $old_val = $old_target[$field] ?? '';
            $clean_text = trim($text);
            if ($field === 'text_output') {
                $clean_text = sanitizeSpaces($clean_text, false);
            } elseif ($field === 'prompt') {
                $clean_text = sanitizeSpaces($clean_text, true);
            }
            updateDraftSubmission($draft_id, $field, $clean_text);
            updateUserStep($telegram_id, 'none');

            logContentEdit($draft_id, $username, $field, $old_val, $clean_text);
            logAdminActivity($telegram_id, $username, 'EDIT_CONTENT', $draft_id, "Edited $field for Post #$draft_id");

            $field_label = [
                'text_output' => 'Title',
                'prompt' => 'Prompt',
                'tags' => 'Tags',
                'category' => 'Category'
            ][$field] ?? $field;

            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ <b>{$field_label} updated successfully for Post #{$draft_id}!</b>", 'parse_mode' => 'HTML']);
            
            // Send updated admin review card to editing admin
            $target = getSubmissionById($draft_id);
            if ($target) {
                global $pdo;
                $stmt = $pdo->prepare("SELECT username FROM users WHERE telegram_id = ?");
                $stmt->execute([$target['telegram_id']]);
                $creator_name = $stmt->fetchColumn() ?: 'Unknown';
                sendToAdminDirectly($chat_id, $draft_id, $creator_name);
            }
        } else {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Invalid edit parameters."]);
        }
        return;
    }

    // ADMIN REVOCATION CUSTOM REASON INPUT
    if (strpos($step, 'admin_revokecustom_') === 0 && isAdmin($telegram_id)) {
        if (!$text || trim($text) === '') {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the revocation reason."]);
            return;
        }
        if (trim($text) === '/cancel') {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Revocation cancelled."]);
            return;
        }
        $parts = explode('_', $step, 4);
        $type = $parts[2];
        $id = (int)$parts[3];
        $reason = trim($text);
        
        updateUserStep($telegram_id, 'none');
        executePostRevocation($telegram_id, $chat_id, null, $id, $type, $reason);
        return;
    }

    // ADMIN SCHEDULING INPUT
    if (strpos($step, 'admin_scheduling_') === 0 && isAdmin($telegram_id)) {
        if (!$text) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the time."]);
            return;
        }
        
        $draft_id = (int)substr($step, 17);
        $target = getSubmissionById($draft_id);
        
        if (!$target || in_array($target['status'], ['rejected', 'draft'])) {
            updateUserStep($telegram_id, 'none');
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ This post cannot be scheduled (invalid status)."]);
            return;
        }

        date_default_timezone_set('Asia/Kolkata');
        $parsed_time = strtotime($text);
        
        if (!$parsed_time || $parsed_time <= time()) {
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Invalid time format or time is in the past. Please try again (e.g., 'tomorrow 2 PM', '+5 hours', '2024-05-18 15:30'):"]);
            return;
        }

        // Check if another post is ALREADY scheduled for this exact hour slot
        $target_hour = date('Y-m-d H:00:00', $parsed_time);
        global $pdo;
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE status = 'approved' AND posted_to_channel = 0 AND DATE_FORMAT(scheduled_at, '%Y-%m-%d %H:00:00') = ? AND id != ?");
        $stmt_check->execute([$target_hour, $draft_id]);
        if ($stmt_check->fetchColumn() > 0) {
            $formatted_hour = date('d M Y \a\t h:00 A', $parsed_time);
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⚠️ <b>Schedule Conflict!</b>\n\nAnother post is ALREADY scheduled for <b>$formatted_hour (IST)</b>. Please type a different time or click one of the available quick slot buttons!", 'parse_mode' => 'HTML']);
            return;
        }

        $scheduled_time = date('Y-m-d H:i:s', $parsed_time);
        $was_pending = ($target['status'] === 'pending');

        updateDraftSubmission($draft_id, 'scheduled_at', $scheduled_time);
        updateDraftSubmission($draft_id, 'posted_to_channel', 0);
        updateSubmissionStatus($draft_id, 'approved');
        
        // Download + compress + convert images for the website
        if (($target['output_type'] === 'Image' || $target['output_type'] === 'Video') && $target['file_id']) {
            if ($target['output_type'] === 'Video') {
                $decoded = json_decode($target['file_id'], true);
                if (isset($decoded['video'])) {
                    $local_v = downloadAndSaveMedia($decoded['video'], true);
                    $local_t = isset($decoded['thumb']) ? downloadAndSaveMedia($decoded['thumb']) : null;
                    $local_media = json_encode(['video' => $local_v, 'thumb' => $local_t]);
                    updateDraftSubmission($draft_id, 'local_media', $local_media);
                }
            } else {
                $local_media = downloadAndSaveMedia($target['file_id']);
                if ($local_media) {
                    updateDraftSubmission($draft_id, 'local_media', $local_media);
                }
            }
        }

        $reward_str = "";
        $is_first_prompt = false;
        if ($was_pending) {
            $user_approved_total = getUserApprovedPromptCount($target['telegram_id']);
            $is_first_prompt = ($user_approved_total <= 1);
            $first_prompt_reward = (float)getSetting('first_prompt_reward_amount', '5.00');
            $prompt_reward = (float)getSetting('prompt_reward_amount', '0.50');
            $challenge_reward = $prompt_reward * 2;

            if ($is_first_prompt) {
                addBalance($target['telegram_id'], $first_prompt_reward, 'CREDIT', 'FIRST_PROMPT_BONUS', "First Prompt Welcome Bounty #$draft_id");
                $reward_str = "₹" . number_format($first_prompt_reward, 2) . " (🎁 1st Prompt Welcome Bounty!)";
            } elseif (!empty($target['is_challenge'])) {
                addBalance($target['telegram_id'], $challenge_reward, 'CREDIT', 'PROMPT_APPROVAL', "Prompt #$draft_id approved (2x Trending Bonus)");
                $reward_str = "₹" . number_format($challenge_reward, 2) . " (🔥 2x Trending Bonus!)";
            } else {
                addBalance($target['telegram_id'], $prompt_reward, 'CREDIT', 'PROMPT_APPROVAL', "Prompt #$draft_id approved");
                $reward_str = "₹" . number_format($prompt_reward, 2);
            }
            handleReferralReward($target['telegram_id']);
        }

        updateUserStep($telegram_id, 'none');
        $slot_formatted = date('d M Y \a\t h:i A', $parsed_time);

        logApprovalRejection($draft_id, $target['telegram_id'], $target['username'] ?? '', $target['category'], $target['output_type'], $username, 'SCHEDULED', $slot_formatted);
        logAdminActivity($telegram_id, $username, 'APPROVE_SCHEDULE_PROMPT', $draft_id, "Scheduled post #$draft_id for $slot_formatted");

        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ <b>Post #$draft_id Scheduled!</b>\n\nScheduled time: <b>$slot_formatted (IST)</b>", 'parse_mode' => 'HTML']);
        
        if ($was_pending) {
            if ($is_first_prompt) {
                $bal_now = number_format(getBalance($target['telegram_id']), 2);
                $congrats_sched = "🎉 <b>CONGRATULATIONS! FIRST PROMPT APPROVED!</b> 🎁\n\n" .
                    "Your first prompt was approved and scheduled for <b>$slot_formatted (IST)</b>.\n\n" .
                    "💰 <b>Welcome Bounty Credited:</b> You earned <b>₹" . number_format($first_prompt_reward, 2) . "</b>!\n" .
                    "💵 Current Balance: <b>₹{$bal_now}</b>\n\n" .
                    "🚀 <i>You're on your way to the ₹20.00 minimum UPI withdrawal! Keep submitting more prompts to cash out!</i>";
                apiRequest("sendMessage", [
                    'chat_id' => $target['telegram_id'], 
                    'text' => $congrats_sched,
                    'parse_mode' => 'HTML'
                ]);
            } else {
                apiRequest("sendMessage", [
                    'chat_id' => $target['telegram_id'], 
                    'text' => "🎉 <b>Congratulations!</b>\n\nYour prompt submission was approved and scheduled for <b>$slot_formatted (IST)</b>. You earned <b>{$reward_str}</b>!",
                    'parse_mode' => 'HTML'
                ]);
            }
        } else {
            apiRequest("sendMessage", [
                'chat_id' => $target['telegram_id'], 
                'text' => "📅 <b>Schedule Updated!</b>\n\nYour prompt submission schedule has been updated to <b>$slot_formatted (IST)</b>.",
                'parse_mode' => 'HTML'
            ]);
        }

        $admin_label = getAdminDisplayName($telegram_id, $from);
        $post_title = htmlspecialchars(mb_substr($target['text_output'] ?: $target['category'], 0, 35, 'UTF-8'));
        $notify_text = "⏳ <b>Admin Activity Alert</b>\n\nPost <b>#{$draft_id}</b> (<i>\"{$post_title}\"</i>) was <b>SCHEDULED</b> for <b>{$slot_formatted} (IST)</b> by {$admin_label}.";
        notifyOtherAdmins($telegram_id, $notify_text);
        return;
    }

    $draft = getDraftSubmission($telegram_id);
    if (!$draft && in_array($step, ['awaiting_category', 'awaiting_title', 'awaiting_output_type', 'awaiting_output', 'awaiting_prompt', 'awaiting_tags'])) {
        $draft = createNewDraft($telegram_id);
    }
    if (!$draft) {
        if ($step !== 'none')
            updateUserStep($telegram_id, 'none');
        return;
    }

    if ($step === 'awaiting_title') {
        if ($text && strpos(trim($text), '/') === 0) {
            if (trim($text) === '/cancel' || trim($text) === '/dismiss') {
                updateUserStep($telegram_id, 'none');
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Prompt submission cancelled."]);
                return;
            }
            return; // Ignore slash commands, do not store as Title
        }
        if ($text) {
            $clean_title = sanitizeSpaces($text, false);
            $clean_title = mb_substr($clean_title, 0, 150, 'UTF-8');
            updateDraftSubmission($draft['id'], 'text_output', $clean_title);
            updateUserStep($telegram_id, 'awaiting_output_type');
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🖼️ Image', 'callback_data' => 'type_Image'], ['text' => '🎥 Video', 'callback_data' => 'type_Video']],
                    [['text' => '✍️ Text Only', 'callback_data' => 'type_Text']],
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "[🟩🟩🟩⬜] <b>Step 3 of 4: Select Output Type</b>\nTitle: <b>" . htmlspecialchars($clean_title) . "</b>\n\nWhat type of result media did you generate?\n<i>Select 'Image' for pictures or 'Video' for animations.</i>",
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } else {
            $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send a text title for your prompt.", 'reply_markup' => $cancel_kb]);
        }
        return;
    }

    if ($step === 'awaiting_output_type') {
        if ($text && strpos(trim($text), '/') === 0) {
            if (trim($text) === '/cancel' || trim($text) === '/dismiss') {
                updateUserStep($telegram_id, 'none');
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Prompt submission cancelled."]);
                return;
            }
        }
        
        // Smart fallback: If user uploaded an image or video directly instead of tapping type buttons
        if (isset($message['photo'])) {
            updateDraftSubmission($draft['id'], 'output_type', 'Image');
            $photo = end($message['photo']);
            $new_file_id = $photo['file_id'];
            $current_files = [$new_file_id];
            updateDraftSubmission($draft['id'], 'file_id', json_encode($current_files));

            updateUserStep($telegram_id, 'awaiting_prompt');

            $nav_kb = [
                'inline_keyboard' => [
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            $msg = "✅ <b>Output Image Saved!</b>\n\n[🟩🟩🟩🟩] <b>Step 4 of 4: Enter Full Prompt Text</b>\n\nPlease send the full AI prompt text you used to generate this image:\n<i>Example: masterpiece, 8k, cyberpunk girl, neon lighting --v 6.0</i>";
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $nav_kb
            ]);
            return;
        } elseif (isset($message['video'])) {
            updateDraftSubmission($draft['id'], 'output_type', 'Video');
            $file_id = $message['video']['file_id'];
            $thumb_id = $message['video']['thumbnail']['file_id'] ?? ($message['video']['thumb']['file_id'] ?? null);
            $combined = json_encode(['video' => $file_id, 'thumb' => $thumb_id]);
            updateDraftSubmission($draft['id'], 'file_id', $combined);

            updateUserStep($telegram_id, 'awaiting_prompt');

            $nav_kb = [
                'inline_keyboard' => [
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            $msg = "✅ <b>Output Video Saved!</b>\n\n[🟩🟩🟩🟩] <b>Step 4 of 4: Enter Full Prompt Text</b>\n\nPlease send the full AI prompt text you used to generate this video:";
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $nav_kb
            ]);
            return;
        } else {
            $title_name = !empty($draft['text_output']) ? htmlspecialchars($draft['text_output']) : 'Title';
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🖼️ Image', 'callback_data' => 'type_Image'], ['text' => '🎥 Video', 'callback_data' => 'type_Video']],
                    [['text' => '✍️ Text Only', 'callback_data' => 'type_Text']],
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "[🟩🟩🟩⬜] <b>Step 3 of 4: Select Output Type</b>\nTitle: <b>{$title_name}</b>\n\nPlease select your output type below (or send your result image/video directly):",
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
            return;
        }
    }

    if ($step === 'awaiting_output') {
        if ($draft['output_type'] === 'Image') {
            if (isset($message['photo'])) {
                $photo = end($message['photo']);
                $new_file_id = $photo['file_id'];

                $current_files = json_decode($draft['file_id'] ?: '[]', true);
                if (!is_array($current_files))
                    $current_files = [];
                $current_files[] = $new_file_id;

                updateDraftSubmission($draft['id'], 'file_id', json_encode($current_files));

                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '✅ Done Uploading Images', 'callback_data' => 'done_images']]
                    ]
                ];
                apiRequest("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "✅ Image appended to album (" . count($current_files) . " total).\nSend another image to add to the album, or click 👇 when you're done.",
                    'reply_markup' => $keyboard
                ]);
                return;
            } else {
                $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel Submission', 'callback_data' => 'cmd_cancel']]]];
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please upload a valid image (compressed).", 'reply_markup' => $cancel_kb]);
                return;
            }
        } elseif ($draft['output_type'] === 'Video') {
            if (isset($message['video'])) {
                $file_id = $message['video']['file_id'];
                $thumb_id = $message['video']['thumbnail']['file_id'] ?? ($message['video']['thumb']['file_id'] ?? null);
                
                $combined = json_encode([
                    'video' => $file_id,
                    'thumb' => $thumb_id
                ]);
                updateDraftSubmission($draft['id'], 'file_id', $combined);
            } else {
                $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel Submission', 'callback_data' => 'cmd_cancel']]]];
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please upload a valid video file.", 'reply_markup' => $cancel_kb]);
                return;
            }
        } elseif ($draft['output_type'] === 'Text') {
            if ($text) {
                $clean_title = sanitizeSpaces($text, false);
                updateDraftSubmission($draft['id'], 'text_output', mb_substr($clean_title, 0, 150, 'UTF-8'));
            } else {
                $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel Submission', 'callback_data' => 'cmd_cancel']]]];
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text output.", 'reply_markup' => $cancel_kb]);
                return;
            }
        }
        updateUserStep($telegram_id, 'awaiting_prompt');
        $nav_kb = [
            'inline_keyboard' => [
                [['text' => '💡 Show Example', 'callback_data' => 'show_example_prompt']],
                [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
            ]
        ];
        $msg = "[🟩🟩🟩🟩] <b>Step 4 of 4: Enter Full Prompt Text</b>\n\nGreat! Now please send the exact full prompt you used:\n\n";
        $msg .= "<i>Example: masterpiece, 8k, cyberpunk girl, neon lighting, hyperrealistic, portrait --v 6.0</i>";
        apiRequest("sendMessage", [
            'chat_id' => $chat_id, 
            'text' => $msg, 
            'parse_mode' => 'HTML',
            'reply_markup' => $nav_kb
        ]);
        return;
    }

    if ($step === 'awaiting_prompt') {
        if ($text && strpos(trim($text), '/') === 0) {
            if (trim($text) === '/cancel' || trim($text) === '/dismiss') {
                updateUserStep($telegram_id, 'none');
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Prompt submission cancelled."]);
                return;
            }
            return; // Ignore slash commands, do not store as Prompt
        }
        if ($text) {
            $clean_prompt = sanitizeSpaces($text, true);
            $clean_prompt = mb_substr($clean_prompt, 0, 3500, 'UTF-8');
            updateDraftSubmission($draft['id'], 'prompt', $clean_prompt);
            updateUserStep($telegram_id, 'awaiting_tags');
            $nav_kb = [
                'inline_keyboard' => [
                    [['text' => '💡 Show Example', 'callback_data' => 'show_example_tags']],
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            $msg = "[🟩🟩🟩🟩] <b>Almost Done! Add Relevant Tags</b>\n\nSend hashtags or keywords for this prompt:\n\n";
            $msg .= "<i>Example: #cyberpunk #portrait #neon #realistic</i>";
            apiRequest("sendMessage", [
                'chat_id' => $chat_id, 
                'text' => $msg, 
                'parse_mode' => 'HTML',
                'reply_markup' => $nav_kb
            ]);
        } else {
            $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel Submission', 'callback_data' => 'cmd_cancel']]]];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the prompt.", 'reply_markup' => $cancel_kb]);
        }
        return;
    }

    if ($step === 'awaiting_tags') {
        if ($text && strpos(trim($text), '/') === 0) {
            if (trim($text) === '/cancel' || trim($text) === '/dismiss') {
                updateUserStep($telegram_id, 'none');
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Prompt submission cancelled."]);
                return;
            }
            return; // Ignore slash commands, do not store as Tags
        }
        if ($text) {
            $raw_tags = preg_split('/[\s,]+/', trim($text));
            $formatted_tags = [];
            foreach ($raw_tags as $t) {
                if (empty($t))
                    continue;
                $t = ltrim($t, '#');
                $formatted_tags[] = '#' . $t;
            }
            $final_tags = implode(' ', $formatted_tags);
            $final_tags .= " #id_{$draft['id']}";

            updateDraftSubmission($draft['id'], 'tags', $final_tags);
            updateUserStep($telegram_id, 'preview');
            sendPreview($chat_id, $draft['id'], $username);
        } else {
            $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel Submission', 'callback_data' => 'cmd_cancel']]]];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please send text for the tags.", 'reply_markup' => $cancel_kb]);
        }
        return;
    }

}

function processCallbackQuery($callback)
{
    if (!isset($callback['message']))
        return;

    $callback_id = $callback['id'];
    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id'];
    $telegram_id = $callback['from']['id'];
    $from = $callback['from'] ?? [];
    $data = $callback['data'];
    $username = isset($callback['from']['username']) ? '@' . $callback['from']['username'] : $callback['from']['first_name'];

    // MAINTENANCE MODE SECURITY GUARD
    if (!isAdmin($telegram_id) && isMaintenanceModeEnabled()) {
        $reason = htmlspecialchars(getMaintenanceReason());
        apiRequest("answerCallbackQuery", [
            'callback_query_id' => $callback_id,
            'text' => "🚧 System under maintenance: {$reason}",
            'show_alert' => true
        ]);
        return;
    }

    $user = getUser($telegram_id, $username);
    $draft = getDraftSubmission($telegram_id);

    if ($data === 'cmd_check_joined') {
        if (isUserChannelMember($telegram_id)) {
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => "✅ Thank you for joining! Access granted.",
                'show_alert' => true
            ]);
            apiRequest("deleteMessage", ['chat_id' => $chat_id, 'message_id' => $message_id], true);
            $pseudo_message = $callback['message'];
            $pseudo_message['from'] = $callback['from'];
            $pseudo_message['text'] = '/start';
            processMessage($pseudo_message);
            return;
        } else {
            $channel_name = CHANNEL_USERNAME;
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => "⚠️ You haven't joined @{$channel_name} yet. Please tap 'Join @{$channel_name}' first!",
                'show_alert' => true
            ]);
            return;
        }
    }

    if ($data === 'cmd_checkin') {
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        sendDailyCheckinCard($chat_id, $telegram_id, $message_id, $username);
        return;
    }

    if ($data === 'checkin_already_claimed') {
        $status = getUserCheckinStatus($telegram_id);
        $next_r = number_format($status['next_reward'], 2);
        apiRequest("answerCallbackQuery", [
            'callback_query_id' => $callback_id,
            'text' => "✅ You have already checked in today!\n\nCome back tomorrow after 12:00 AM IST to claim Day {$status['next_streak']} (+₹{$next_r})! 🚀",
            'show_alert' => true
        ]);
        return;
    }

    if ($data === 'claim_checkin') {
        if (!isUserChannelMember($telegram_id)) {
            $ch_name = CHANNEL_USERNAME;
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => "⚠️ Mandatory Channel Join Required!\n\nPlease join @{$ch_name} first before claiming your daily check-in bonus.",
                'show_alert' => true
            ]);
            sendForceJoinCard($chat_id, $telegram_id);
            return;
        }

        $res = claimDailyCheckin($telegram_id);
        if ($res['success']) {
            $streak = $res['streak'];
            $r_fmt = number_format($res['reward'], 2);
            $tier_msg = ($streak >= 7) ? "👑 Day {$streak} Milestone Streak!" : "🔥 Day {$streak} Streak!";
            $alert_text = "🎉 Checked In Successfully!\n\n{$tier_msg}\n+₹{$r_fmt} added to your wallet balance! 💰";
            if (!empty($res['freeze_used'])) {
                $alert_text .= "\n\n🛡️ Your Streak Freeze was used yesterday to save your streak!";
            }
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => $alert_text,
                'show_alert' => true
            ]);
        } else {
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => $res['message'] ?? "You've already claimed today's check-in bonus!",
                'show_alert' => true
            ]);
        }
        sendDailyCheckinCard($chat_id, $telegram_id, $message_id, $username);
        return;
    }

    if ($data === 'cmd_balance' || $data === 'cmd_withdraw') {
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        sendBalanceDashboard($chat_id, $telegram_id, $username);
        return;
    }

    if ($data === 'cmd_start_withdraw') {
        $bal = getBalance($telegram_id);
        if ($bal < 20) {
            $needed = number_format(20 - $bal, 2);
            $bal_fmt = number_format($bal, 2);
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => "⚠️ Insufficient Balance!\n\nMinimum withdrawal limit is ₹20.00.\nYour balance: ₹{$bal_fmt}\nYou need ₹{$needed} more to request payout.",
                'show_alert' => true
            ]);
            return;
        }
        
        updateUserStep($telegram_id, 'awaiting_upi');
        $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "💸 <b>WITHDRAW FUNDS</b>\n\nYour balance: <b>₹" . number_format($bal, 2) . "</b>\nPlease reply with your <b>UPI ID</b> to receive payment:\n<i>(Example: name@upi or 9876543210@paytm)</i>\n\nType /cancel to abort.",
            'parse_mode' => 'HTML',
            'reply_markup' => $cancel_kb
        ]);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        return;
    }

    if (!isUserChannelMember($telegram_id)) {
        if ($data === 'cmd_support' || strpos($data, 'suppcat_') === 0) {
            // Allow support ticket
        } else {
            $channel_name = CHANNEL_USERNAME;
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => "⚠️ Mandatory Channel Join Required! Please join @{$channel_name} first.",
                'show_alert' => true
            ]);
            sendForceJoinCard($chat_id, $telegram_id);
            return;
        }
    }

    if (strpos($data, 'cmd_') === 0) {
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        $pseudo_message = $callback['message'];
        $pseudo_message['from'] = $callback['from'];
        $pseudo_message['text'] = '/' . substr($data, 4);
        processMessage($pseudo_message);
        return;
    }

    if (strpos($data, 'wd_') === 0 && isAdmin($telegram_id)) {
        $parts = explode('_', $data);
        $action = $parts[1];
        $wd_id = (int)$parts[2];
        $wd = getWithdrawalById($wd_id);
        
        if (!$wd || $wd['status'] !== 'pending') {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Already processed.", 'show_alert' => true]);
            return;
        }

        if ($action === 'approve') {
            processWithdrawal($wd_id, 'approved', $telegram_id);
            apiRequest("editMessageText", ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "✅ <b>Withdrawal #$wd_id Approved</b>\nAmount: ₹" . number_format($wd['amount'], 2) . "\nUPI: <code>{$wd['upi_id']}</code>", 'parse_mode' => 'HTML']);
            apiRequest("sendMessage", ['chat_id' => $wd['telegram_id'], 'text' => "🎉 Good news! Your withdrawal of ₹" . number_format($wd['amount'], 2) . " has been approved and sent to your UPI ID: {$wd['upi_id']}."]);
        } elseif ($action === 'reject') {
            processWithdrawal($wd_id, 'rejected', $telegram_id);
            addBalance($wd['telegram_id'], $wd['amount']); // refund
            apiRequest("editMessageText", ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "❌ <b>Withdrawal #$wd_id Rejected</b>\nAmount refunded to user.", 'parse_mode' => 'HTML']);
            apiRequest("sendMessage", ['chat_id' => $wd['telegram_id'], 'text' => "❌ Your withdrawal request of ₹" . number_format($wd['amount'], 2) . " was rejected by admins. The amount has been refunded to your bot balance."]);
        }
        return;
    }

    // ADMIN BROADCAST CONFIRMATION (WITH TARGETING + LIVE COUNTER)
    if (strpos($data, 'admin_broadcast_confirm_') === 0 && isAdmin($telegram_id)) {
        $parts = explode('_', $data);
        $filter = $parts[3]; // all, active, inactive, top
        $msg_id = (int)$parts[4];

        $target_label = [
            'all' => 'ALL Users',
            'active' => 'Active Recently',
            'inactive' => 'Inactive Users',
            'top' => 'Top Creators'
        ][$filter] ?? 'Targeted Users';

        ignore_user_abort(true);
        set_time_limit(0);

        $users = getAllUsers($filter);
        $total = count($users);

        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚀 Starting broadcast to $total users..."]);

        // Send initial progress message
        $progress_res = apiRequest("editMessageText", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "📡 <b>Broadcasting to $target_label...</b>\n\n" .
                      "📤 Sending: <b>0</b> / <b>$total</b>\n" .
                      "✅ Delivered: <b>0</b>\n" .
                      "❌ Failed: <b>0</b>\n\n" .
                      "⏳ Please wait...",
            'parse_mode' => 'HTML'
        ]);

        $count = 0;
        $failed = 0;
        $update_every = 25; // Update progress every 25 users

        foreach ($users as $i => $uid) {
            $res = apiRequest("copyMessage", [
                'chat_id' => $uid,
                'from_chat_id' => $chat_id,
                'message_id' => $msg_id
            ], true);

            if ($res && isset($res['ok']) && $res['ok']) {
                $count++;
            } else {
                $failed++;
                if ($res && isset($res['description']) && strpos($res['description'], 'blocked by the user') !== false) {
                    markUserBlocked($uid);
                }
            }

            $sent = $i + 1;

            // Update progress counter every N users (or on last user)
            if ($sent % $update_every === 0 || $sent === $total) {
                $pct = round(($sent / $total) * 100);
                $bar_filled = (int)($pct / 5);
                $bar_empty = 20 - $bar_filled;
                $progress_bar = str_repeat('▓', $bar_filled) . str_repeat('░', $bar_empty);

                apiRequest("editMessageText", [
                    'chat_id' => $chat_id,
                    'message_id' => $message_id,
                    'text' => "📡 <b>Broadcasting to $target_label...</b>\n\n" .
                              "$progress_bar <b>{$pct}%</b>\n\n" .
                              "📤 Sending: <b>$sent</b> / <b>$total</b>\n" .
                              "✅ Delivered: <b>$count</b>\n" .
                              "❌ Failed: <b>$failed</b>\n\n" .
                              ($sent < $total ? "⏳ Please wait..." : "✅ <b>Completed!</b>"),
                    'parse_mode' => 'HTML'
                ], true); // silent — don't crash broadcast if edit fails
            }

            usleep(34000);
        }

        // Final completion message
        apiRequest("editMessageText", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "✅ <b>Broadcast Completed!</b>\n\n" .
                      "🎯 Target: $target_label\n" .
                      "👥 Total: <b>$total</b>\n" .
                      "✅ Delivered: <b>$count</b>\n" .
                      "❌ Failed/Blocked: <b>$failed</b>",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    if ($data === 'user_tx_history') {
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        showUserTransactionHistory($chat_id, $telegram_id);
        return;
    }

    if ($data === 'cmd_balance') {
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        sendBalanceDashboard($chat_id, $telegram_id, $username);
        return;
    }

    if ($data === 'view_my_prompts') {
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        showGalleryPage($chat_id, null, $telegram_id, 0);
        return;
    }

    if (strpos($data, 'gal_nav_') === 0) {
        $index = (int)substr($data, 8);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        // To keep it smooth, we send a new message and maybe delete the old one or just send a new one
        // Browsing with media often requires new messages if the type changes (Photo -> Video)
        showGalleryPage($chat_id, $message_id, $telegram_id, $index);
        return;
    }

    if (strpos($data, 'show_example_') === 0) {
        $step_name = substr($data, 13);
        $ex_map = [
            'title'  => "💡 TITLE EXAMPLES:\n• Ultra Realistic Cyberpunk Girl in Tokyo\n• 3D Isometric Game Room Banner\n• Master Copywriter Prompt\n• Vintage Cinematic Portrait --v 6.0",
            'prompt' => "💡 PROMPT EXAMPLES:\n• masterpiece, 8k, photorealistic cyberpunk girl, neon rain, 35mm lens --v 6.0\n• Act as senior copywriter. Write 5 headlines for SaaS product.",
            'tags'   => "💡 TAG EXAMPLES:\n• #Cyberpunk #AIArt #Midjourney #Portrait\n• #ChatGPT #Copywriting #Marketing #SEO"
        ];
        $alert_text = $ex_map[$step_name] ?? "💡 Send high quality text or media for fast approval!";
        apiRequest("answerCallbackQuery", [
            'callback_query_id' => $callback_id,
            'text' => $alert_text,
            'show_alert' => true
        ]);
        return;
    }

    if ($data === 'cmd_back') {
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        $current_step = $user['step'];

        if (!$draft) {
            updateUserStep($telegram_id, 'none');
            return;
        }

        if ($current_step === 'awaiting_title') {
            // Back to Step 1: Categories
            updateUserStep($telegram_id, 'awaiting_category');
            $keyboard = buildCategoryKeyboard();
            sendOrEditStepMessage($chat_id, $message_id, "[🟩⬜⬜⬜] <b>Step 1 of 4: Select Category</b>\n\nPlease select a category for your prompt:\n<i>Example: Photo Editing, AI Art</i>", $keyboard);
        } elseif ($current_step === 'awaiting_output_type' || $current_step === 'awaiting_output') {
            // Back to Step 2: Title
            updateUserStep($telegram_id, 'awaiting_title');
            $cat_name = !empty($draft['category']) ? htmlspecialchars($draft['category']) : 'Selected Category';
            $nav_kb = [
                'inline_keyboard' => [
                    [['text' => '💡 Show Example', 'callback_data' => 'show_example_title']],
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            sendOrEditStepMessage($chat_id, $message_id, "[🟩🟩⬜⬜] <b>Step 2 of 4: Enter Prompt Title</b>\nCategory: <b>{$cat_name}</b>\n\nPlease enter a short, catchy Title for this prompt:\n<i>Example: Ultra Realistic Cyberpunk Girl</i>", $nav_kb);
        } elseif ($current_step === 'awaiting_prompt') {
            // Back to Step 3: Output Type
            updateUserStep($telegram_id, 'awaiting_output_type');
            $title_name = !empty($draft['text_output']) ? htmlspecialchars($draft['text_output']) : 'Title';
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🖼️ Image', 'callback_data' => 'type_Image'], ['text' => '🎥 Video', 'callback_data' => 'type_Video']],
                    [['text' => '✍️ Text Only', 'callback_data' => 'type_Text']],
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            sendOrEditStepMessage($chat_id, $message_id, "[🟩🟩🟩⬜] <b>Step 3 of 4: Select Output Type</b>\nTitle: <b>{$title_name}</b>\n\nWhat type of result media did you generate?\n<i>Select 'Image' for pictures or 'Video' for animations.</i>", $keyboard);
        } elseif ($current_step === 'awaiting_tags') {
            // Back to Step 4: Prompt Text
            updateUserStep($telegram_id, 'awaiting_prompt');
            $nav_kb = [
                'inline_keyboard' => [
                    [['text' => '💡 Show Example', 'callback_data' => 'show_example_prompt']],
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            $msg = "[🟩🟩🟩🟩] <b>Step 4 of 4: Enter Full Prompt Text</b>\n\nPlease send the exact full prompt you used:\n\n<i>Example: masterpiece, 8k, cyberpunk girl, neon lighting --v 6.0</i>";
            sendOrEditStepMessage($chat_id, $message_id, $msg, $nav_kb);
        } elseif ($current_step === 'preview') {
            // Back to Step 5: Tags
            updateUserStep($telegram_id, 'awaiting_tags');
            $nav_kb = [
                'inline_keyboard' => [
                    [['text' => '💡 Show Example', 'callback_data' => 'show_example_tags']],
                    [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            $msg = "[🟩🟩🟩🟩] <b>Almost Done! Add Relevant Tags</b>\n\nSend hashtags or keywords for this prompt:\n\n<i>Example: #cyberpunk #portrait #neon #realistic</i>";
            
            // Delete media preview message if present
            apiRequest("deleteMessage", ['chat_id' => $chat_id, 'message_id' => $message_id]);
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $nav_kb
            ]);
        }
        return;
    }

    if ($data === 'done_images') {
        if ($user['step'] !== 'awaiting_output') {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Not at the image upload step."]);
            return;
        }
        updateUserStep($telegram_id, 'awaiting_prompt');
        apiRequest("editMessageReplyMarkup", ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => json_encode(['inline_keyboard' => []])]);
        $nav_kb = [
            'inline_keyboard' => [
                [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
            ]
        ];
        $msg = "Step 4: Great! Now please send the full prompt you used.\n\n";
        $msg .= "<i>Example: masterpiece, 8k, cyberpunk girl, neon lighting, hyperrealistic, portrait --v 6.0</i>";
        apiRequest("sendMessage", [
            'chat_id' => $chat_id, 
            'text' => $msg, 
            'parse_mode' => 'HTML',
            'reply_markup' => $nav_kb
        ]);
        return;
    }

    if (strpos($data, 'cat_') === 0) {
        if ($user['step'] !== 'awaiting_category') {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Action not allowed at this step.", "show_alert" => true]);
            return;
        }
        $category = substr($data, 4);

        // Enforce max 3 submissions per category per day per user
        $cat_count = getDailyCategorySubmissionCount($telegram_id, $category);
        if ($cat_count >= 3) {
            $alert_text = "⚠️ Daily Category Limit Reached!\n\nYou have already submitted 3 prompts in '$category' today. Please choose a different category!";
            apiRequest("answerCallbackQuery", [
                'callback_query_id' => $callback_id,
                'text' => $alert_text,
                'show_alert' => true
            ]);
            return;
        }

        updateDraftSubmission($draft['id'], 'category', $category);
        updateUserStep($telegram_id, 'awaiting_title');

        $nav_kb = [
            'inline_keyboard' => [
                [['text' => '💡 Show Example', 'callback_data' => 'show_example_title']],
                [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
            ]
        ];

        apiRequest("editMessageText", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "[🟩🟩⬜⬜] <b>Step 2 of 4: Enter Prompt Title</b>\nCategory: <b>" . htmlspecialchars($category) . "</b>\n\nPlease enter a short, catchy Title for this prompt:\n<i>Example: Ultra Realistic Cyberpunk Girl</i>",
            'parse_mode' => 'HTML',
            'reply_markup' => $nav_kb
        ]);
        return;
    }

    if (strpos($data, 'type_') === 0) {
        if ($user['step'] !== 'awaiting_output_type') {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Action not allowed at this step."]);
            return;
        }
        $type = substr($data, 5);
        updateDraftSubmission($draft['id'], 'output_type', $type);

        if ($type === 'Text') {
            updateUserStep($telegram_id, 'awaiting_prompt');
            $nav_kb = [
                'inline_keyboard' => [
                    [['text' => '⬅️ Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
                ]
            ];
            apiRequest("editMessageText", [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "📝 <b>Output Type: Text Only</b>\n\n[🟩🟩🟩🟩] <b>Step 4 of 4: Enter Full Prompt Text</b>\n\nPlease send the full AI prompt text you used:\n<i>Example: Write a blog post about artificial intelligence...</i>",
                'parse_mode' => 'HTML',
                'reply_markup' => $nav_kb
            ]);
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
            return;
        }

        updateUserStep($telegram_id, 'awaiting_output');
        $msg = "🖼️ <b>Output Type: {$type}</b>\n\n[🟩🟩🟩⬜] <b>Step 3 of 4: Upload Result Media</b>\n\n";
        if ($type === 'Image') {
            $msg .= "Please upload the <b>FINAL OUTPUT Image</b> (the picture generated by your prompt).";
        } else {
            $msg .= "Please upload the <b>FINAL OUTPUT Video</b>.";
        }

        $nav_kb = [
            'inline_keyboard' => [
                [['text' => '⬅️ Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
            ]
        ];

        apiRequest("editMessageText", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $nav_kb
        ]);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        return;
    }

    if (strpos($data, 'submit_') === 0) {
        $draft_id = (int) substr($data, 7);
        $submission = getSubmissionById($draft_id);
        if (!$submission || $submission['telegram_id'] != $telegram_id || $submission['status'] !== 'draft') {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Already submitted or invalid."]);
            return;
        }

        // Automatic keyword matching for trending challenge
        $matched_kw = checkChallengeMatch($submission['text_output'] ?? '', $submission['prompt'] ?? '', $submission['tags'] ?? '');
        if ($matched_kw) {
            updateDraftSubmission($draft_id, 'is_challenge', 1);
            $submission['is_challenge'] = 1;
        }

        updateSubmissionStatus($draft_id, 'pending');
        updateUserStep($telegram_id, 'none');

        apiRequest("editMessageReplyMarkup", ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => json_encode(['inline_keyboard' => []])]);

        if ($matched_kw) {
            $confirm_msg = "✅ <b>Your prompt has been submitted for review!</b>\n\n" .
                           "🔥 <b>TRENDING MATCH DETECTED!</b>\n" .
                           "Your submission matched the active challenge keyword: <b>" . htmlspecialchars($matched_kw) . "</b>\n" .
                           "If approved, you will earn <b>DOUBLE REWARDS (₹0.50)</b> and get a 🔥 Trending Badge!";
        } else {
            $confirm_msg = "✅ <b>Your prompt has been submitted for review!</b>";
        }

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $confirm_msg,
            'parse_mode' => 'HTML'
        ]);

        sendToAdmin($draft_id, $username);
        return;
    }

    if (strpos($data, 'cancel_') === 0) {
        updateUserStep($telegram_id, 'none');
        apiRequest("editMessageReplyMarkup", ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => json_encode(['inline_keyboard' => []])]);
        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Submission cancelled."]);
        return;
    }

    // ADMIN ACTIONS
    if (strpos($data, 'admin_') === 0) {
        if (!isAdmin($telegram_id)) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Not authorized.", 'show_alert' => true]);
            return;
        }

        // Route admin_panel_*, admin_cat_*, admin_mgmt_*, admin_ch_*, admin_qsched_*, admin_edit_field_*, admin_edit_back_*, admin_revokereason_*, admin_perm_toggle_*, suppcat_*, admin_suppreply_*, admin_suppclose_*, admin_unban_user_*, admin_ban_prompt, b_launch_*, b_addbtn_*, b_stop_* to their dedicated handlers below
        if (strpos($data, 'admin_panel_') === 0 || strpos($data, 'admin_cat_') === 0 || strpos($data, 'admin_mgmt_') === 0 || strpos($data, 'admin_ch_') === 0 || strpos($data, 'admin_qsched_') === 0 || strpos($data, 'admin_edit_field_') === 0 || strpos($data, 'admin_edit_back_') === 0 || strpos($data, 'admin_revokereason_') === 0 || strpos($data, 'admin_perm_toggle_') === 0 || strpos($data, 'suppcat_') === 0 || strpos($data, 'admin_suppreply_') === 0 || strpos($data, 'admin_suppclose_') === 0 || strpos($data, 'admin_unban_user_') === 0 || strpos($data, 'admin_ban_prompt') === 0 || strpos($data, 'b_launch_') === 0 || strpos($data, 'b_addbtn_') === 0 || strpos($data, 'b_stop_') === 0) {
            // Fall through — these are handled by dedicated blocks below
        } else {
            // Original admin action handlers (approve/reject/schedule/edit/etc.)
        if (strpos($data, 'admin_vp_') === 0) {
            $draft_id = (int)substr($data, 9);
            $target = getSubmissionById($draft_id);
            if ($target) {
                global $pdo;
                $stmt = $pdo->prepare("SELECT username FROM users WHERE telegram_id = ?");
                $stmt->execute([$target['telegram_id']]);
                $c_username = $stmt->fetchColumn() ?: 'Unknown';
                sendToAdminDirectly($telegram_id, $draft_id, $c_username);
            } else {
                apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Not found or deleted."]);
            }
            return;
        }

        if (strpos($data, 'admin_del_') === 0) {
            if (!hasAdminPermission($telegram_id, 'can_delete')) {
                apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚫 Permission Denied: Delete access is disabled for your account.", 'show_alert' => true]);
                return;
            }
            $parts = explode('_', $data);
            $type = $parts[2]; // both, web, chan
            $id = (int)$parts[3];
            
            $submission = getSubmissionById($id);
            if (!$submission) {
                apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Post #$id not found."]);
                return;
            }

            // Prompt admin for deletion & revocation reason
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⚠️ Low Quality', 'callback_data' => "admin_revokereason_{$type}_{$id}_Low Quality"],
                        ['text' => '🔄 Duplicate', 'callback_data' => "admin_revokereason_{$type}_{$id}_Duplicate"]
                    ],
                    [
                        ['text' => '🔞 Inappropriate', 'callback_data' => "admin_revokereason_{$type}_{$id}_Inappropriate"],
                        ['text' => '📂 Wrong Category', 'callback_data' => "admin_revokereason_{$type}_{$id}_Wrong Category"]
                    ],
                    [
                        ['text' => '✍️ Custom Reason', 'callback_data' => "admin_revokereason_{$type}_{$id}_Custom"],
                        ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']
                    ]
                ]
            ];

            $scope_text = ($type === 'both') ? "Website + Channel" : (($type === 'web') ? "Website Only" : "Channel Only");

            apiRequest("editMessageText", [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "🗑️ <b>Revoke & Delete Post #$id</b> ($scope_text)\n\nPlease select a revocation reason to notify the creator & adjust balance:",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
            return;
        }

        if (strpos($data, 'admin_reason_') === 0) {
            $parts = explode('_', $data, 4);
            $draft_id = (int) $parts[2];
            $reason_key = $parts[3];

            $target_draft = getSubmissionById($draft_id);
            if (!$target_draft || $target_draft['status'] !== 'pending')
                return;

            apiRequest("editMessageReplyMarkup", ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => json_encode(['inline_keyboard' => []])]);

            if ($reason_key === 'Custom') {
                updateUserStep($telegram_id, "reject_$draft_id");
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "Please type the custom rejection reason for Post #$draft_id:"]);
                return;
            }

            $rejection_tips = [
                'Low Quality'       => 'The prompt output image or result quality is too low or blurry. Please upload high-resolution images.',
                'Duplicate'         => 'This prompt or result has already been submitted or published in our library.',
                'Missing Media'     => 'The output media is missing or invalid. Please ensure you upload the final generated image/video.',
                'Spam Content'      => 'Submission contains invalid, spam, or promotional text.',
                'Wrong Category'    => 'Categorized incorrectly. Please select the appropriate category when submitting.',
                'Incomplete Prompt' => 'The prompt text is incomplete or missing key generation details/parameters.',
                'Inappropriate'     => 'Content violates our community safety guidelines.',
                'Bad Tags'          => 'Tags are missing or inaccurate. Include relevant hashtags like #Midjourney #ChatGPT.',
                'Generic Title'     => 'Title is too generic. Use a descriptive, catchy title like "Ultra Realistic Cyberpunk Girl".'
            ];

            $reason_desc = $rejection_tips[$reason_key] ?? $reason_key;

            updateSubmissionStatus($draft_id, 'rejected');

            logApprovalRejection($draft_id, $target_draft['telegram_id'], $target_draft['username'] ?? '', $target_draft['category'], $target_draft['output_type'], $username, 'REJECTED', $reason_key);
            logAdminActivity($telegram_id, $username, 'REJECT_PROMPT', $draft_id, "Rejected post #$draft_id for $reason_key");

            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Post #$draft_id rejected for: <b>$reason_key</b>", 'parse_mode' => 'HTML']);

            $admin_label = getAdminDisplayName($telegram_id, $from);
            $notify_text = "❌ <b>Admin Activity Alert</b>\n\nPost <b>#{$draft_id}</b> was <b>REJECTED</b> (Reason: <i>" . htmlspecialchars($reason_key) . "</i>) by {$admin_label}.";
            notifyOtherAdmins($telegram_id, $notify_text);

            $user_msg = "😞 <b>Submission Update for Post #$draft_id</b>\n\n";
            $user_msg .= "Your recent prompt submission was rejected.\n";
            $user_msg .= "📌 <b>Reason:</b> $reason_key\n";
            $user_msg .= "💡 <b>Tip:</b> $reason_desc\n\n";
            $user_msg .= "<i>Feel free to fix your prompt and resubmit using /submit!</i>";

            $resubmit_kb = ['inline_keyboard' => [[['text' => '🚀 Resubmit Prompt', 'callback_data' => 'cmd_submit']]]];

            apiRequest("sendMessage", [
                'chat_id' => $target_draft['telegram_id'],
                'text' => $user_msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $resubmit_kb
            ]);
            return;
        }

        $parts = explode('_', $data);
        $action = $parts[1];
        $draft_id = (int) $parts[2];
        $target_draft = getSubmissionById($draft_id);

        if (!$target_draft) return;

        if ($action !== 'edit' && $target_draft['status'] !== 'pending') {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "This post is no longer pending."]);
            return;
        }

        if ($action === 'approve') {
            if (!hasAdminPermission($telegram_id, 'can_approve')) {
                apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚫 Permission Denied: Approval access is disabled for your account.", 'show_alert' => true]);
                return;
            }
            $post_res = postToChannel($draft_id);
            if ($post_res) {
                $msg_id = $post_res['result']['message_id'] ?? null;
                if ($msg_id) {
                    updateDraftSubmission($draft_id, 'channel_message_id', $msg_id);
                    apiRequest("setMessageReaction", [
                        'chat_id' => CHANNEL_ID,
                        'message_id' => $msg_id,
                        'reaction' => [['type' => 'emoji', 'emoji' => '❤️']]
                    ]);
                }
            }

            updateSubmissionStatus($draft_id, 'approved');
            $user_approved_total = getUserApprovedPromptCount($target_draft['telegram_id']);
            $is_first_prompt = ($user_approved_total === 1);
            $first_prompt_reward = (float)getSetting('first_prompt_reward_amount', '5.00');
            $prompt_reward = (float)getSetting('prompt_reward_amount', '0.50');
            $challenge_reward = $prompt_reward * 2;

            if ($is_first_prompt) {
                addBalance($target_draft['telegram_id'], $first_prompt_reward, 'CREDIT', 'FIRST_PROMPT_BONUS', "First Prompt Welcome Bounty #$draft_id");
                $reward_str = "₹" . number_format($first_prompt_reward, 2) . " (🎁 1st Prompt Welcome Bounty!)";
            } elseif (!empty($target_draft['is_challenge'])) {
                addBalance($target_draft['telegram_id'], $challenge_reward, 'CREDIT', 'PROMPT_APPROVAL', "Prompt #$draft_id approved (2x Trending Bonus)");
                $reward_str = "₹" . number_format($challenge_reward, 2) . " (🔥 2x Trending Bonus!)";
            } else {
                addBalance($target_draft['telegram_id'], $prompt_reward, 'CREDIT', 'PROMPT_APPROVAL', "Prompt #$draft_id approved");
                $reward_str = "₹" . number_format($prompt_reward, 2);
            }
            handleReferralReward($target_draft['telegram_id']);
            checkAndAwardDailyMilestone($target_draft['telegram_id']);
            if (checkAndAwardStreakFreeze($target_draft['telegram_id'])) {
                apiRequest("sendMessage", [
                    'chat_id'    => $target_draft['telegram_id'],
                    'text'       => "🛡️ <b>STREAK FREEZE SHIELD EARNED!</b>\n\nCongratulations! Having 3+ approved prompts has earned you <b>1 Streak Freeze Shield</b>.\n\nIf you ever miss a daily check-in, your streak will be protected automatically! 🔥",
                    'parse_mode' => 'HTML'
                ]);
            }

            // Download + compress + convert images for the website
            if (($target_draft['output_type'] === 'Image' || $target_draft['output_type'] === 'Video') && $target_draft['file_id']) {
                if ($target_draft['output_type'] === 'Video') {
                    $decoded = json_decode($target_draft['file_id'], true);
                    if (isset($decoded['video'])) {
                        $local_v = downloadAndSaveMedia($decoded['video'], true);
                        $local_t = isset($decoded['thumb']) ? downloadAndSaveMedia($decoded['thumb']) : null;
                        $local_media = json_encode(['video' => $local_v, 'thumb' => $local_t]);
                        updateDraftSubmission($draft_id, 'local_media', $local_media);
                    }
                } else {
                    $local_media = downloadAndSaveMedia($target_draft['file_id']);
                    if ($local_media) {
                        updateDraftSubmission($draft_id, 'local_media', $local_media);
                    }
                }
            }

            logApprovalRejection($draft_id, $target_draft['telegram_id'], $target_draft['username'] ?? '', $target_draft['category'], $target_draft['output_type'], $username, 'APPROVED', 'Immediate Publish');
            logAdminActivity($telegram_id, $username, 'APPROVE_PROMPT', $draft_id, "Approved & published post #$draft_id");

            apiRequest("editMessageReplyMarkup", ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => json_encode(['inline_keyboard' => []])]);
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Post #$draft_id approved and published."]);
            
            if ($is_first_prompt) {
                $bal_now = number_format(getBalance($target_draft['telegram_id']), 2);
                $channel_name = CHANNEL_USERNAME;
                $user_congrats = "🎉 <b>CONGRATULATIONS! FIRST PROMPT APPROVED!</b> 🎁\n\n" .
                    "Your very first prompt submission has been approved and published to @{$channel_name}!\n\n" .
                    "💰 <b>Welcome Bounty Credited:</b> You earned <b>₹" . number_format($first_prompt_reward, 2) . "</b>!\n" .
                    "💵 Current Wallet Balance: <b>₹{$bal_now}</b>\n\n" .
                    "🚀 <i>You are already on your way to the ₹20.00 minimum UPI withdrawal! Keep submitting quality prompts to cash out!</i>";
                apiRequest("sendMessage", ['chat_id' => $target_draft['telegram_id'], 'text' => $user_congrats, 'parse_mode' => 'HTML']);
            } else {
                apiRequest("sendMessage", ['chat_id' => $target_draft['telegram_id'], 'text' => "🎉 Congratulations! Your prompt submission was approved and posted to the channel. You earned <b>{$reward_str}</b>!", 'parse_mode' => 'HTML']);
            }
            
            $admin_label = getAdminDisplayName($telegram_id, $from);
            $post_title = htmlspecialchars(mb_substr($target_draft['text_output'] ?: $target_draft['category'], 0, 35, 'UTF-8'));
            $notify_text = "📢 <b>Admin Activity Alert</b>\n\nPost <b>#{$draft_id}</b> (<i>\"{$post_title}\"</i>) was <b>APPROVED & PUBLISHED</b> by {$admin_label}.";
            notifyOtherAdmins($telegram_id, $notify_text);
        } elseif ($action === 'reject') {
            if (!hasAdminPermission($telegram_id, 'can_approve')) {
                apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚫 Permission Denied: Rejection access is disabled for your account.", 'show_alert' => true]);
                return;
            }
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⚠️ Low Quality', 'callback_data' => "admin_reason_{$draft_id}_Low Quality"],
                        ['text' => '🔄 Duplicate', 'callback_data' => "admin_reason_{$draft_id}_Duplicate"]
                    ],
                    [
                        ['text' => '🖼️ Missing Media', 'callback_data' => "admin_reason_{$draft_id}_Missing Media"],
                        ['text' => '🚫 Spam Content', 'callback_data' => "admin_reason_{$draft_id}_Spam Content"]
                    ],
                    [
                        ['text' => '📂 Wrong Category', 'callback_data' => "admin_reason_{$draft_id}_Wrong Category"],
                        ['text' => '📝 Incomplete Prompt', 'callback_data' => "admin_reason_{$draft_id}_Incomplete Prompt"]
                    ],
                    [
                        ['text' => '🔞 Inappropriate', 'callback_data' => "admin_reason_{$draft_id}_Inappropriate"],
                        ['text' => '🏷️ Bad / Missing Tags', 'callback_data' => "admin_reason_{$draft_id}_Bad Tags"]
                    ],
                    [
                        ['text' => '💡 Generic Title', 'callback_data' => "admin_reason_{$draft_id}_Generic Title"],
                        ['text' => '✍️ Custom Reason', 'callback_data' => "admin_reason_{$draft_id}_Custom"]
                    ],
                    [
                        ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']
                    ]
                ]
            ];
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "🚫 <b>Reject Submission #{$draft_id}</b>\n\nSelect a 1-tap rejection reason to notify creator with helpful tips, or choose 'Custom Reason':",
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);
        } elseif ($action === 'schedule') {
            if (!hasAdminPermission($telegram_id, 'can_schedule')) {
                apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚫 Permission Denied: Post scheduling access is disabled for your account.", 'show_alert' => true]);
                return;
            }
            updateUserStep($telegram_id, "admin_scheduling_$draft_id");
            $slots = getUpcomingHourlySlots(6);
            $kb = [];
            $row = [];
            foreach ($slots as $slot) {
                $row[] = ['text' => $slot['label'], 'callback_data' => "admin_qsched_{$draft_id}_" . $slot['timestamp']];
                if (count($row) === 2) {
                    $kb[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) $kb[] = $row;
            $kb[] = [['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']];

            $msg = "⏳ <b>Schedule Post #$draft_id</b>\n\n";
            $msg .= "Select an upcoming hourly slot below (IST), or type a custom date/time:\n\n";
            $msg .= "<i>Examples for custom text input:</i>\n";
            $msg .= "• <code>tomorrow 10:00 AM</code>\n";
            $msg .= "• <code>+3 hours</code>\n";
            $msg .= "• <code>2026-08-14 18:00:00</code>";

            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => $kb]
            ]);
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);

        } elseif ($action === 'edit') {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📌 Edit Title', 'callback_data' => "admin_edit_field_{$draft_id}_text_output"]],
                    [['text' => '💬 Edit Prompt', 'callback_data' => "admin_edit_field_{$draft_id}_prompt"]],
                    [['text' => '🏷 Edit Tags', 'callback_data' => "admin_edit_field_{$draft_id}_tags"]],
                    [['text' => '📁 Edit Category', 'callback_data' => "admin_edit_field_{$draft_id}_category"]],
                    [['text' => '🖼 Replace Image / Media', 'callback_data' => "admin_edit_field_{$draft_id}_image"]],
                    [['text' => '🔙 Back', 'callback_data' => "admin_edit_back_{$draft_id}"]]
                ]
            ];
            apiRequest("editMessageReplyMarkup", [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'reply_markup' => json_encode($keyboard)
            ]);
        }
        return;
        } // end else (non-panel admin callbacks)
    }

    if (strpos($data, 'admin_qsched_') === 0) {
        if (!isAdmin($telegram_id)) return;
        if (!hasAdminPermission($telegram_id, 'can_schedule')) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚫 Permission Denied: Post scheduling access is disabled for your account.", 'show_alert' => true]);
            return;
        }
        $parts = explode('_', $data);
        $draft_id = (int)$parts[2];
        $timestamp = (int)$parts[3];

        $target = getSubmissionById($draft_id);
        if (!$target || in_array($target['status'], ['rejected', 'draft'])) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "This post cannot be scheduled.", 'show_alert' => true]);
            return;
        }

        date_default_timezone_set('Asia/Kolkata');
        $scheduled_time = date('Y-m-d H:i:s', $timestamp);
        $was_pending = ($target['status'] === 'pending');

        updateDraftSubmission($draft_id, 'scheduled_at', $scheduled_time);
        updateDraftSubmission($draft_id, 'posted_to_channel', 0);
        updateSubmissionStatus($draft_id, 'approved');

        if (($target['output_type'] === 'Image' || $target['output_type'] === 'Video') && $target['file_id']) {
            if ($target['output_type'] === 'Video') {
                $decoded = json_decode($target['file_id'], true);
                if (isset($decoded['video'])) {
                    $local_v = downloadAndSaveMedia($decoded['video'], true);
                    $local_t = isset($decoded['thumb']) ? downloadAndSaveMedia($decoded['thumb']) : null;
                    $local_media = json_encode(['video' => $local_v, 'thumb' => $local_t]);
                    updateDraftSubmission($draft_id, 'local_media', $local_media);
                }
            } else {
                $local_media = downloadAndSaveMedia($target['file_id']);
                if ($local_media) {
                    updateDraftSubmission($draft_id, 'local_media', $local_media);
                }
            }
        }

        $reward_str = "";
        $is_first_prompt = false;
        if ($was_pending) {
            $user_approved_total = getUserApprovedPromptCount($target['telegram_id']);
            $is_first_prompt = ($user_approved_total <= 1);
            $first_prompt_reward = (float)getSetting('first_prompt_reward_amount', '5.00');
            $prompt_reward = (float)getSetting('prompt_reward_amount', '0.50');
            $challenge_reward = $prompt_reward * 2;

            if ($is_first_prompt) {
                addBalance($target['telegram_id'], $first_prompt_reward, 'CREDIT', 'FIRST_PROMPT_BONUS', "First Prompt Welcome Bounty #$draft_id");
                $reward_str = "₹" . number_format($first_prompt_reward, 2) . " (🎁 1st Prompt Welcome Bounty!)";
            } elseif (!empty($target['is_challenge'])) {
                addBalance($target['telegram_id'], $challenge_reward, 'CREDIT', 'PROMPT_APPROVAL', "Prompt #$draft_id approved (2x Trending Bonus)");
                $reward_str = "₹" . number_format($challenge_reward, 2) . " (🔥 2x Trending Bonus!)";
            } else {
                addBalance($target['telegram_id'], $prompt_reward, 'CREDIT', 'PROMPT_APPROVAL', "Prompt #$draft_id approved");
                $reward_str = "₹" . number_format($prompt_reward, 2);
            }
            handleReferralReward($target['telegram_id']);
        }

        updateUserStep($telegram_id, 'none');
        $slot_formatted = date('d M Y \a\t h:i A', $timestamp);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "✅ Scheduled for $slot_formatted"]);
        apiRequest("editMessageReplyMarkup", ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => json_encode(['inline_keyboard' => []])]);
        logApprovalRejection($draft_id, $target['telegram_id'], $target['username'] ?? '', $target['category'], $target['output_type'], $username, 'SCHEDULED', $slot_formatted);
        logAdminActivity($telegram_id, $username, 'APPROVE_SCHEDULE_PROMPT', $draft_id, "Approved & scheduled post #$draft_id for $slot_formatted");

        apiRequest("sendMessage", [
            'chat_id' => $chat_id, 
            'text' => "✅ <b>Post #$draft_id Approved & Scheduled!</b>\n\nScheduled time: <b>$slot_formatted (IST)</b>", 
            'parse_mode' => 'HTML'
        ]);
        
        if ($was_pending) {
            if ($is_first_prompt) {
                $bal_now = number_format(getBalance($target['telegram_id']), 2);
                $congrats_sched = "🎉 <b>CONGRATULATIONS! FIRST PROMPT APPROVED!</b> 🎁\n\n" .
                    "Your first prompt was approved and scheduled for <b>$slot_formatted (IST)</b>.\n\n" .
                    "💰 <b>Welcome Bounty Credited:</b> You earned <b>₹" . number_format($first_prompt_reward, 2) . "</b>!\n" .
                    "💵 Current Balance: <b>₹{$bal_now}</b>\n\n" .
                    "🚀 <i>You're on your way to the ₹20.00 minimum UPI withdrawal! Keep submitting more prompts to cash out!</i>";
                apiRequest("sendMessage", [
                    'chat_id' => $target['telegram_id'], 
                    'text' => $congrats_sched,
                    'parse_mode' => 'HTML'
                ]);
            } else {
                apiRequest("sendMessage", [
                    'chat_id' => $target['telegram_id'], 
                    'text' => "🎉 <b>Congratulations!</b>\n\nYour prompt submission was approved and scheduled for <b>$slot_formatted (IST)</b>. You earned <b>{$reward_str}</b>!",
                    'parse_mode' => 'HTML'
                ]);
            }
        } else {
            apiRequest("sendMessage", [
                'chat_id' => $target['telegram_id'], 
                'text' => "📅 <b>Schedule Updated!</b>\n\nYour prompt submission schedule has been updated to <b>$slot_formatted (IST)</b>.",
                'parse_mode' => 'HTML'
            ]);
        }

        $admin_label = getAdminDisplayName($telegram_id, $from);
        $post_title = htmlspecialchars(mb_substr($target['text_output'] ?: $target['category'], 0, 35, 'UTF-8'));
        $notify_text = "⏳ <b>Admin Activity Alert</b>\n\nPost <b>#{$draft_id}</b> (<i>\"{$post_title}\"</i>) was <b>SCHEDULED</b> for <b>{$slot_formatted} (IST)</b> by {$admin_label}.";
        notifyOtherAdmins($telegram_id, $notify_text);
        return;
    }

    if (strpos($data, 'admin_edit_field_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $rest = substr($data, 17); // Strip 'admin_edit_field_'
        $parts = explode('_', $rest, 2);
        $draft_id = (int)$parts[0];
        $field = $parts[1] ?? '';
        
        $field_label = [
            'text_output' => 'Title',
            'prompt' => 'Prompt',
            'tags' => 'Tags',
            'category' => 'Category',
            'image' => 'Image / Media'
        ][$field] ?? $field;

        updateUserStep($telegram_id, "admin_editing_{$draft_id}_{$field}");
        
        $prompt_msg = ($field === 'image')
            ? "🖼️ <b>Replacing Media for Post #$draft_id</b>\n\nPlease send the new Photo or Video for this prompt:"
            : "📝 <b>Editing $field_label for Post #$draft_id</b>\n\nPlease send the new value for this field:";

        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $prompt_msg,
            'parse_mode' => 'HTML'
        ]);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        return;
    }

    if (strpos($data, 'admin_edit_back_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $draft_id = (int)substr($data, 16);
        $target = getSubmissionById($draft_id);
        
        if ($target && $target['status'] === 'pending') {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Approve Now', 'callback_data' => 'admin_approve_' . $draft_id], ['text' => '⏳ Schedule', 'callback_data' => 'admin_schedule_' . $draft_id]],
                    [['text' => '📝 Edit', 'callback_data' => 'admin_edit_' . $draft_id], ['text' => '❌ Reject', 'callback_data' => 'admin_reject_' . $draft_id]],
                    [['text' => '💡 Reject with Tip', 'callback_data' => 'admin_tip_' . $draft_id]]
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📝 Edit Again', 'callback_data' => 'admin_edit_' . $draft_id]]
                ]
            ];
        }
        
        apiRequest("editMessageReplyMarkup", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'reply_markup' => json_encode($keyboard)
        ]);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        return;
    }

    if (strpos($data, 'admin_revokereason_') === 0) {
        if (!isAdmin($telegram_id)) return;
        if (!hasAdminPermission($telegram_id, 'can_delete')) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚫 Permission Denied: Delete access is disabled for your account.", 'show_alert' => true]);
            return;
        }
        $parts = explode('_', $data, 5);
        $type = $parts[2];
        $id = (int)$parts[3];
        $reason_key = $parts[4];

        if ($reason_key === 'Custom') {
            updateUserStep($telegram_id, "admin_revokecustom_{$type}_{$id}");
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "📝 <b>Custom Revocation Reason for Post #$id</b>\n\nPlease send the custom reason text to notify the creator:",
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
            ]);
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
            return;
        }

        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        executePostRevocation($telegram_id, $chat_id, $message_id, $id, $type, $reason_key);
        return;
    }

    if (strpos($data, 'suppcat_') === 0) {
        $category = substr($data, 8);
        updateUserStep($telegram_id, "awaiting_support_msg_{$category}");
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        
        $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]];
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "✍️ <b>Support Category: " . htmlspecialchars($category) . "</b>\n\nPlease type your message/question below to submit your support ticket:\n\n<i>Type /cancel to abort.</i>",
            'parse_mode' => 'HTML',
            'reply_markup' => $cancel_kb
        ]);
        return;
    }

    if (strpos($data, 'admin_suppreply_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $ticket_id = (int)substr($data, 16);
        $ticket = getSupportTicketById($ticket_id);
        if (!$ticket) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Ticket not found.", 'show_alert' => true]);
            return;
        }
        updateUserStep($telegram_id, "admin_replying_ticket_{$ticket_id}");
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "💬 <b>Replying to Support Ticket #T-{$ticket_id}</b> (User: <code>{$ticket['telegram_id']}</code>)\n\nPlease type your reply message to send to the user:\n\n<i>Type /cancel to abort.</i>",
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
        ]);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        return;
    }

    if (strpos($data, 'admin_suppclose_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $ticket_id = (int)substr($data, 16);
        closeSupportTicket($ticket_id);
        apiRequest("editMessageText", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "✅ <b>Support Ticket #T-{$ticket_id} Resolved & Closed</b>",
            'parse_mode' => 'HTML'
        ]);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Ticket closed!"]);
        return;
    }

    // ==========================================
    // ADMIN PANEL CALLBACKS (admin_panel_*)
    // ==========================================

    if (strpos($data, 'admin_panel_') === 0) {
        if (!isAdmin($telegram_id)) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Not authorized.", 'show_alert' => true]);
            return;
        }
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        $panel_action = substr($data, 12);

        if ($panel_action === 'stats') {
            $stats = getStats();
            $msg = "📊 <b>Advanced Bot Statistics</b> 📊\n\n";
            $msg .= "👥 <b>Total Users:</b> " . number_format($stats['total_users']) . "\n";
            $msg .= "📈 <b>Joined Today:</b> " . number_format($stats['new_today']) . "\n";
            $msg .= "📅 <b>Joined This Week:</b> " . number_format($stats['new_week']) . "\n";
            $msg .= "📝 <b>Total Submissions:</b> " . number_format($stats['total_submissions']) . "\n\n";
            $approved = $stats['status_breakdown']['approved'] ?? 0;
            $pending  = $stats['status_breakdown']['pending'] ?? 0;
            $rejected = $stats['status_breakdown']['rejected'] ?? 0;
            $msg .= "<b>Status Breakdown:</b>\n";
            $msg .= "✅ Approved: " . number_format($approved) . "\n";
            $msg .= "⏳ Pending:  " . number_format($pending)  . "\n";
            $msg .= "❌ Rejected: " . number_format($rejected) . "\n\n";
            $msg .= "🔥 <b>Top Category:</b> " . ($stats['top_category'] ?? 'N/A') . "\n";
            $msg .= "👤 <b>Active Users (7d):</b> " . number_format($stats['active_7d']) . "\n";
            $kb = ['inline_keyboard' => [[['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']]]];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
            return;
        }

        if ($panel_action === 'pending') {
            global $pdo;
            $stmt = $pdo->query("SELECT id, text_output FROM submissions WHERE status = 'pending' ORDER BY id ASC LIMIT 20");
            $pendings = $stmt->fetchAll();
            if (!$pendings) {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ No pending submissions!"]);
                return;
            }
            $kb = [];
            foreach ($pendings as $p) {
                $kb[] = [['text' => "#{$p['id']} - " . mb_substr($p['text_output'] ?? 'No Title', 0, 30), 'callback_data' => "admin_vp_{$p['id']}"]];
            }
            $kb[] = [['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "⏳ <b>Pending Submissions:</b> " . count($pendings) . " found.", 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $kb]]);
            return;
        }

        if ($panel_action === 'broadcast') {
            updateUserStep($telegram_id, 'admin_broadcast_awaiting_content');
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "📢 <b>Broadcast Mode Enabled</b>\n\nSend the message/media you want to broadcast.\nType /cancel to abort.",
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
            ]);
            return;
        }

        if ($panel_action === 'categories') {
            sendCategoryManagePanel($chat_id, $message_id);
            return;
        }

        if ($panel_action === 'admins') {
            sendAdminManagePanel($chat_id);
            return;
        }

        if ($panel_action === 'banned') {
            sendAdminBannedPanel($chat_id, $message_id);
            return;
        }

        if ($panel_action === 'withdrawals') {
            $wds = getPendingWithdrawals();
            if (empty($wds)) {
                $kb = ['inline_keyboard' => [[['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']]]];
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ No pending withdrawals!", 'reply_markup' => $kb]);
                return;
            }
            $msg = "💸 <b>Pending Withdrawals</b>\n\n";
            foreach ($wds as $wd) {
                $msg .= "<b>#{$wd['id']}</b> — User <code>{$wd['telegram_id']}</code>\n";
                $msg .= "Amount: ₹" . number_format($wd['amount'], 2) . " | UPI: <code>{$wd['upi_id']}</code>\n\n";
            }
            $kb = [];
            foreach ($wds as $wd) {
                $pay_url = "https://rtmcreator.com/bots/prompt-bot/pay.php?pa=" . urlencode($wd['upi_id']) . "&am=" . urlencode($wd['amount']) . "&pn=Creator&tn=WD_Ref_" . urlencode($wd['id']);
                $kb[] = [
                    ['text' => "📱 Pay #{$wd['id']}", 'url' => $pay_url],
                    ['text' => "✅ Approve", 'callback_data' => "wd_approve_{$wd['id']}"],
                    ['text' => "❌ Reject",  'callback_data' => "wd_reject_{$wd['id']}"]
                ];
            }
            $kb[] = [['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => $kb]]);
            return;
        }

        if ($panel_action === 'referrals') {
            $stats = getDetailedReferralStats(10);
            $msg = "🏆 <b>Top Referrers Detailed Stats</b> 🏆\n\n";
            if (empty($stats)) {
                $msg .= "<i>No referral stats found yet.</i>";
            } else {
                $rank = 1;
                foreach ($stats as $s) {
                    $uname = htmlspecialchars($s['username'] ?? 'Unknown');
                    $total = (int)$s['total_invites'];
                    $success = (int)$s['successful_invites'];
                    $rate = $total > 0 ? round(($success / $total) * 100) : 0;
                    $earnings = number_format($success * 1.00, 2);
                    $msg .= "<b>{$rank}. {$uname}</b> (<code>{$s['referrer_id']}</code>)\n";
                    $msg .= "• Invites: <b>{$total}</b> | Successful: <b>{$success}</b> ({$rate}%) | Earned: <b>₹{$earnings}</b>\n\n";
                    $rank++;
                }
            }
            $kb = ['inline_keyboard' => [[['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']]]];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
            return;
        }

        if ($panel_action === 'recent') {
            global $pdo;
            $stmt = $pdo->query("SELECT id, category, text_output FROM submissions WHERE status = 'approved' ORDER BY created_at DESC LIMIT 10");
            $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($prompts)) {
                $msg = "🏜️ No approved prompts found yet.";
            } else {
                $msg = "📦 <b>Recent Approved Prompt IDs:</b>\n\n";
                foreach ($prompts as $p) {
                    $title = $p['text_output'] ?: $p['category'];
                    $msg .= "🆔 <b>#{$p['id']}</b> - " . htmlspecialchars($title) . "\n";
                }
                $msg .= "\nTo delete: <code>/delete [ID]</code>";
            }
            $kb = ['inline_keyboard' => [[['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']]]];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
            return;
        }

        if ($panel_action === 'challenge') {
            sendChallengeManagePanel($chat_id);
            return;
        }

        if ($panel_action === 'calendar') {
            $sched = getAllScheduledPosts();
            if (empty($sched)) {
                $msg = "📅 <b>Schedule Calendar</b>\n\n<i>No posts are currently scheduled.</i>\n\nSchedule posts during approval using the ⏳ Schedule button.";
                $kb  = ['inline_keyboard' => [[['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']]]];
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
                return;
            }
            $msg = "📅 <b>Scheduled Posts (" . count($sched) . ")</b>\n\n";
            // Group by date
            $by_date = [];
            foreach ($sched as $p) {
                $date_key = date('d M Y', strtotime($p['scheduled_at']));
                $by_date[$date_key][] = $p;
            }
            foreach ($by_date as $date => $items) {
                $msg .= "📆 <b>{$date}</b>\n";
                foreach ($items as $p) {
                    $time  = date('h:i A', strtotime($p['scheduled_at']));
                    $title = htmlspecialchars(mb_substr($p['text_output'] ?: $p['category'], 0, 35, 'UTF-8'));
                    $trend = !empty($p['is_challenge']) ? ' 🔥' : '';
                    $msg  .= "  ⏰ {$time} — <b>{$title}</b>{$trend} <i>(@{$p['username']})</i>\n";
                }
                $msg .= "\n";
            }
            $cal_url = defined('BOT_BASE_URL') ? rtrim(BOT_BASE_URL, '/') . '/calendar.php' : 'https://rtmcreator.com/bots/prompt-bot/calendar.php';
            $kb = ['inline_keyboard' => [
                [['text' => '🌐 Open Web Calendar', 'url' => $cal_url]],
                [['text' => '🔙 Back to Panel',     'callback_data' => 'admin_panel_back']]
            ]];
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
            return;
        }

        if ($panel_action === 'ref_reward') {
            $current = getSetting('referral_reward_amount', '1.00');
            updateUserStep($telegram_id, 'admin_awaiting_ref_reward');
            $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]];
            apiRequest("sendMessage", [
                'chat_id'      => $chat_id,
                'text'         => "⚙️ <b>Set Referral Reward Amount</b>\n\n<b>Current:</b> ₹{$current} per referral\n\nSend the new reward amount (e.g. <code>1.50</code>, <code>2.00</code>, <code>0.50</code>):\n<i>Both the referrer and referee receive this amount when a referral is successful.</i>",
                'parse_mode'   => 'HTML',
                'reply_markup' => $kb
            ]);
            return;
        }

        if ($panel_action === 'prompt_reward') {
            $current = getSetting('prompt_reward_amount', '0.50');
            updateUserStep($telegram_id, 'admin_awaiting_prompt_reward');
            $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]];
            apiRequest("sendMessage", [
                'chat_id'      => $chat_id,
                'text'         => "💵 <b>Set Per Prompt Submission Reward</b>\n\n<b>Current:</b> ₹{$current} per approved prompt\n\nSend the new reward amount (e.g. <code>0.50</code>, <code>1.00</code>, <code>0.75</code>):\n<i>Creators will receive this reward whenever their normal prompt submission is approved.</i>",
                'parse_mode'   => 'HTML',
                'reply_markup' => $kb
            ]);
            return;
        }

        if ($panel_action === 'first_prompt_reward') {
            $current = getSetting('first_prompt_reward_amount', '5.00');
            updateUserStep($telegram_id, 'admin_awaiting_first_prompt_reward');
            $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]];
            apiRequest("sendMessage", [
                'chat_id'      => $chat_id,
                'text'         => "🎁 <b>Set First Prompt Welcome Bounty</b>\n\n<b>Current:</b> ₹{$current} for 1st approved prompt\n\nSend the new welcome reward amount (e.g. <code>5.00</code>, <code>3.00</code>, <code>10.00</code>):\n<i>New creators receive this bounty upon having their very first prompt approved!</i>",
                'parse_mode'   => 'HTML',
                'reply_markup' => $kb
            ]);
            return;
        }

        if ($panel_action === 'post_daily_lb') {
            $success = postDailyLeaderboardToChannel(true);
            if ($success) {
                apiRequest("answerCallbackQuery", [
                    'callback_query_id' => $callback_id,
                    'text' => "✅ Daily Leaderboard has been published to the channel!",
                    'show_alert' => true
                ]);
                $channel_name = CHANNEL_USERNAME;
                apiRequest("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "🏆 <b>Success!</b> Daily Leaderboard notification was dispatched to @{$channel_name}!",
                    'parse_mode' => 'HTML'
                ]);
            } else {
                apiRequest("answerCallbackQuery", [
                    'callback_query_id' => $callback_id,
                    'text' => "⚠️ Could not post leaderboard. Please ensure creators exist and the bot is an admin in the channel.",
                    'show_alert' => true
                ]);
            }
            return;
        }

        if ($panel_action === 'sched_gap') {
            $current = getSetting('schedule_hour_gap', '1');
            $kb = [
                'inline_keyboard' => [
                    [['text' => ($current == '1' ? '✅ 1 Hour Gap (Default)' : '1 Hour Gap'), 'callback_data' => 'admin_panel_setgap_1']],
                    [['text' => ($current == '2' ? '✅ 2 Hours Gap' : '2 Hours Gap'), 'callback_data' => 'admin_panel_setgap_2']],
                    [['text' => ($current == '3' ? '✅ 3 Hours Gap' : '3 Hours Gap'), 'callback_data' => 'admin_panel_setgap_3']],
                    [['text' => ($current == '4' ? '✅ 4 Hours Gap' : '4 Hours Gap'), 'callback_data' => 'admin_panel_setgap_4']],
                    [['text' => ($current == '6' ? '✅ 6 Hours Gap' : '6 Hours Gap'), 'callback_data' => 'admin_panel_setgap_6']],
                    [['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']]
                ]
            ];
            if ($message_id) {
                apiRequest("editMessageText", [
                    'chat_id' => $chat_id,
                    'message_id' => $message_id,
                    'text' => "⏰ <b>Configure Post Scheduling Timing Gap</b>\n\nCurrently, quick-schedule time slots are offered with a <b>{$current}-hour gap</b>.\n\nSelect a new timing gap for the quick slot buttons below:",
                    'parse_mode' => 'HTML',
                    'reply_markup' => $kb
                ]);
            } else {
                apiRequest("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "⏰ <b>Configure Post Scheduling Timing Gap</b>\n\nCurrently, quick-schedule time slots are offered with a <b>{$current}-hour gap</b>.\n\nSelect a new timing gap for the quick slot buttons below:",
                    'parse_mode' => 'HTML',
                    'reply_markup' => $kb
                ]);
            }
            return;
        }

        if (strpos($panel_action, 'setgap_') === 0) {
            $gap_val = (int)substr($panel_action, 7);
            if ($gap_val < 1) $gap_val = 1;
            setSetting('schedule_hour_gap', (string)$gap_val);
            logAdminActivity($telegram_id, $username, 'CHANGE_SCHED_GAP', '0', "Changed schedule hour gap to {$gap_val} hour(s)");
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "✅ Schedule timing gap set to {$gap_val} hour(s)!", 'show_alert' => true]);
            sendAdminPanel($chat_id, $message_id);
            return;
        }

        if ($panel_action === 'wallet_menu') {
            sendAdminWalletPanel($chat_id, $message_id);
            return;
        }

        if ($panel_action === 'w_credit') {
            updateUserStep($telegram_id, 'admin_w_credit_target');
            $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_wallet_menu']]]];
            if ($message_id) {
                apiRequest("editMessageText", [
                    'chat_id'      => $chat_id,
                    'message_id'   => $message_id,
                    'text'         => "🟢 <b>ADD MONEY TO USER WALLET (CREDIT)</b>\n\nPlease send the <b>User ID</b> or <b>@username</b> of the target user:\n\n<i>Example: <code>123456789</code> or <code>@username</code></i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            } else {
                apiRequest("sendMessage", [
                    'chat_id'      => $chat_id,
                    'text'         => "🟢 <b>ADD MONEY TO USER WALLET (CREDIT)</b>\n\nPlease send the <b>User ID</b> or <b>@username</b> of the target user:\n\n<i>Example: <code>123456789</code> or <code>@username</code></i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            }
            return;
        }

        if ($panel_action === 'w_debit') {
            updateUserStep($telegram_id, 'admin_w_debit_target');
            $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_wallet_menu']]]];
            if ($message_id) {
                apiRequest("editMessageText", [
                    'chat_id'      => $chat_id,
                    'message_id'   => $message_id,
                    'text'         => "🔴 <b>DEDUCT MONEY FROM USER WALLET (DEBIT)</b>\n\nPlease send the <b>User ID</b> or <b>@username</b> of the target user:\n\n<i>Example: <code>123456789</code> or <code>@username</code></i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            } else {
                apiRequest("sendMessage", [
                    'chat_id'      => $chat_id,
                    'text'         => "🔴 <b>DEDUCT MONEY FROM USER WALLET (DEBIT)</b>\n\nPlease send the <b>User ID</b> or <b>@username</b> of the target user:\n\n<i>Example: <code>123456789</code> or <code>@username</code></i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            }
            return;
        }

        if ($panel_action === 'w_check') {
            updateUserStep($telegram_id, 'admin_w_check_target');
            $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_wallet_menu']]]];
            if ($message_id) {
                apiRequest("editMessageText", [
                    'chat_id'      => $chat_id,
                    'message_id'   => $message_id,
                    'text'         => "🔍 <b>CHECK USER WALLET & STATS</b>\n\nPlease send the <b>User ID</b> or <b>@username</b> to inspect:\n\n<i>Example: <code>123456789</code> or <code>@username</code></i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            } else {
                apiRequest("sendMessage", [
                    'chat_id'      => $chat_id,
                    'text'         => "🔍 <b>CHECK USER WALLET & STATS</b>\n\nPlease send the <b>User ID</b> or <b>@username</b> to inspect:\n\n<i>Example: <code>123456789</code> or <code>@username</code></i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            }
            return;
        }

        if ($panel_action === 'tickets') {
            sendAdminTicketsPanel($chat_id, $message_id, 'open');
            return;
        }

        if ($panel_action === 'tickets_all') {
            sendAdminTicketsPanel($chat_id, $message_id, 'all');
            return;
        }

        if (strpos($panel_action, 'viewticket_') === 0) {
            $ticket_id = (int)substr($panel_action, 11);
            sendAdminViewTicketCard($chat_id, $ticket_id, $message_id);
            return;
        }

        if ($panel_action === 'maint_menu') {
            sendAdminMaintenancePanel($chat_id, $message_id);
            return;
        }

        if ($panel_action === 'maint_on') {
            setSetting('maintenance_mode', '1');
            logAdminActivity($telegram_id, $username, 'ENABLE_MAINT_MODE', '0', "Turned ON Maintenance Mode");
            notifyOtherAdmins($telegram_id, "🛠️ <b>Maintenance Mode ENABLED</b>\n\nAdmin {$username} turned Maintenance Mode ON. Regular users will now see maintenance alerts.");
            sendAdminMaintenancePanel($chat_id, $message_id);
            return;
        }

        if ($panel_action === 'maint_off') {
            setSetting('maintenance_mode', '0');
            logAdminActivity($telegram_id, $username, 'DISABLE_MAINT_MODE', '0', "Turned OFF Maintenance Mode");
            notifyOtherAdmins($telegram_id, "🟢 <b>Maintenance Mode DISABLED</b>\n\nAdmin {$username} turned Maintenance Mode OFF. Bot is now live for all users.");
            sendAdminMaintenancePanel($chat_id, $message_id);
            return;
        }

        if ($panel_action === 'maint_msg') {
            updateUserStep($telegram_id, 'admin_awaiting_maint_reason');
            $current = getMaintenanceReason();
            $kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_maint_menu']]]];
            if ($message_id) {
                apiRequest("editMessageText", [
                    'chat_id'      => $chat_id,
                    'message_id'   => $message_id,
                    'text'         => "✍️ <b>EDIT MAINTENANCE NOTICE MESSAGE</b>\n\n<b>Current Notice:</b>\n<i>" . htmlspecialchars($current) . "</i>\n\nPlease send the new text message to show to users when Maintenance Mode is enabled:\n\n<i>Type /cancel to abort.</i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            } else {
                apiRequest("sendMessage", [
                    'chat_id'      => $chat_id,
                    'text'         => "✍️ <b>EDIT MAINTENANCE NOTICE MESSAGE</b>\n\n<b>Current Notice:</b>\n<i>" . htmlspecialchars($current) . "</i>\n\nPlease send the new text message to show to users when Maintenance Mode is enabled:\n\n<i>Type /cancel to abort.</i>",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $kb
                ]);
            }
            return;
        }

        if ($panel_action === 'back') {
            sendAdminPanel($chat_id, $message_id);
            return;
        }
        return;
    }

    // ==========================================
    // CHALLENGE MANAGEMENT CALLBACKS (admin_ch_*)
    // ==========================================

    if (strpos($data, 'admin_ch_') === 0) {
        if (!isAdmin($telegram_id)) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Not authorized.", 'show_alert' => true]);
            return;
        }
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        $ch_action = substr($data, 9);

        if ($ch_action === 'menu') {
            sendChallengeManagePanel($chat_id);
            return;
        }

        if ($ch_action === 'add') {
            updateUserStep($telegram_id, 'admin_awaiting_challenge_theme');
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "⚡ <b>Add Challenge Keywords</b>\n\nSend keyword(s) to add as active challenges.\n<i>You can send multiple keywords separated by commas! Example: Cyberpunk, Neon, Logo</i>\n\nType /cancel to abort.",
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
            ]);
            return;
        }

        if ($ch_action === 'clear') {
            clearAllChallenges();
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ All active challenge keywords cleared."]);
            sendChallengeManagePanel($chat_id);
            return;
        }

        if (strpos($ch_action, 'del_') === 0) {
            $ch_id = (int)substr($ch_action, 4);
            deleteChallenge($ch_id);
            apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "✅ Challenge keyword #{$ch_id} removed."]);
            sendChallengeManagePanel($chat_id);
            return;
        }
    }

    // ==========================================
    // CATEGORY MANAGEMENT CALLBACKS (admin_cat_*)
    // ==========================================

    if (strpos($data, 'admin_cat_') === 0) {
        if (!isAdmin($telegram_id)) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Not authorized.", 'show_alert' => true]);
            return;
        }
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        $cat_action = substr($data, 10);

        if ($cat_action === 'menu') {
            sendCategoryManagePanel($chat_id, $message_id);
            return;
        }

        if (strpos($cat_action, 'select_') === 0) {
            $cat_id = (int)substr($cat_action, 7);
            sendCategoryDetailCard($chat_id, $cat_id, $message_id);
            return;
        }

        if ($cat_action === 'add') {
            updateUserStep($telegram_id, 'admin_awaiting_category_name');
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "➕ <b>Add New Category</b>\n\nSend the name of the new category:\n<i>Example: Logo Design</i>\n\nType /cancel to abort.",
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
            ]);
            return;
        }

        if (strpos($cat_action, 'rename_') === 0) {
            $cat_id = (int)substr($cat_action, 7);
            $cat = getCategoryById($cat_id);
            if (!$cat) {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Category #{$cat_id} not found."]);
                return;
            }
            updateUserStep($telegram_id, "admin_awaiting_cat_rename_$cat_id");
            $cancel_kb = ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'admin_panel_categories']]]];
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "✏️ <b>RENAME CATEGORY #{$cat_id}</b>\n\nCurrent Name: <b>" . htmlspecialchars($cat['name']) . "</b>\n\nPlease reply with the <b>NEW Name</b> for this category:\n\n<i>Type /cancel to abort.</i>",
                'parse_mode' => 'HTML',
                'reply_markup' => $cancel_kb
            ]);
            return;
        }

        if (strpos($cat_action, 'del_') === 0) {
            $cat_id = (int)substr($cat_action, 4);
            $cat = getCategoryById($cat_id);
            if ($cat) {
                removeCategory($cat_id);
                apiRequest("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "✅ Category <b>" . htmlspecialchars($cat['name']) . "</b> removed!",
                    'parse_mode' => 'HTML'
                ]);
            } else {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Category not found."]);
            }
            sendCategoryManagePanel($chat_id);
            return;
        }
        return;
    }

    // ==========================================
    // ADMIN MANAGEMENT CALLBACKS (admin_mgmt_*)
    // ==========================================

    if (strpos($data, 'admin_mgmt_') === 0) {
        if (!isAdmin($telegram_id)) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Not authorized.", 'show_alert' => true]);
            return;
        }
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        $mgmt_action = substr($data, 11);

        if ($mgmt_action === 'menu') {
            sendAdminManagePanel($chat_id);
            return;
        }

        if ($mgmt_action === 'add') {
            if ($telegram_id != ADMIN_ID) {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "🚫 Only the primary admin can add new admins."]);
                return;
            }
            updateUserStep($telegram_id, 'admin_awaiting_new_admin_id');
            apiRequest("sendMessage", [
                'chat_id' => $chat_id,
                'text' => "➕ <b>Add New Admin</b>\n\nSend the <b>Telegram User ID</b> (numeric) of the person you want to make an admin:\n\n<i>Tip: They can forward any message from themselves to @userinfobot to get their ID.</i>\n\nType /cancel to abort.",
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
            ]);
            return;
        }

        if (strpos($mgmt_action, 'perm_') === 0) {
            $target_aid = (int)substr($mgmt_action, 5);
            if ($telegram_id != ADMIN_ID) {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "🚫 Only Primary Super Admin can edit sub-admin permissions."]);
                return;
            }
            sendAdminPermissionPanel($chat_id, $target_aid, $message_id);
            return;
        }

        if (strpos($mgmt_action, 'del_') === 0) {
            if ($telegram_id != ADMIN_ID) {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "🚫 Only the primary admin can remove admins."]);
                return;
            }
            $rem_id = (int)substr($mgmt_action, 4);
            if ($rem_id == ADMIN_ID) {
                apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Cannot remove the primary admin."]);
            } else {
                removeAdmin($rem_id);
                apiRequest("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "✅ Admin <code>$rem_id</code> removed.",
                    'parse_mode' => 'HTML'
                ]);
                // Notify removed admin
                apiRequest("sendMessage", [
                    'chat_id' => $rem_id,
                    'text' => "⚠️ Your admin access to the Prompt Bot has been revoked."
                ], true);
            }
            sendAdminManagePanel($chat_id);
            return;
        }
        return;
    }

    if (strpos($data, 'admin_perm_toggle_') === 0) {
        if ($telegram_id != ADMIN_ID) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚫 Only Primary Super Admin can toggle permissions.", 'show_alert' => true]);
            return;
        }
        $parts = explode('_', $data, 5);
        $target_aid = (int)$parts[3];
        $perm_key = $parts[4];
        toggleAdminPermission($target_aid, $perm_key);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "Permission updated!"]);
        sendAdminPermissionPanel($chat_id, $target_aid, $message_id);
        return;
    }

    if (strpos($data, 'admin_unban_user_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $target_id = (int)substr($data, 17);
        $success = unbanUser($target_id);
        if ($success) {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "User unbanned!"]);
            $admin_label = getAdminDisplayName($telegram_id, $from);
            notifyOtherAdmins($telegram_id, "🟢 <b>Admin Activity Alert</b>\n\nUser <code>{$target_id}</code> was <b>UNBANNED</b> by {$admin_label}.");
        } else {
            apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "User not found or not banned.", 'show_alert' => true]);
        }
        sendAdminBannedPanel($chat_id, $message_id);
        return;
    }

    if (strpos($data, 'b_addbtn_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $msg_id = (int)substr($data, 9);
        updateUserStep($telegram_id, "admin_b_btn_text_{$msg_id}");
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "➕ <b>Add Custom Action Button</b>\n\nPlease send the <b>Button Text</b> (e.g. <code>🚀 Submit Prompt Now</code>):\n\nType /cancel to abort.",
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]]]
        ]);
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
        return;
    }

    if (strpos($data, 'b_launch_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $parts = explode('_', $data, 5);
        $filter = $parts[2]; // all, active, inactive, top
        $msg_id = (int)$parts[3];
        $btn_text = isset($parts[4]) ? urldecode(explode('_', $parts[4])[0]) : null;
        $btn_url = isset($parts[4]) && count(explode('_', $parts[4])) > 1 ? urldecode(explode('_', $parts[4])[1]) : null;

        $b_id = createBroadcastQueue($telegram_id, $chat_id, $msg_id, $filter, $btn_text, $btn_url, $message_id);
        
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🚀 Broadcast queue #{$b_id} created! Starting execution..."]);

        $total_users = count(getAllUsers($filter));
        $kb = ['inline_keyboard' => [[['text' => '🛑 Stop Broadcast', 'callback_data' => "b_stop_{$b_id}"]]]];

        apiRequest("editMessageText", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "📡 <b>Broadcasting to " . strtoupper($filter) . " Users... (ID: #{$b_id})</b>\n\n" .
                      "░░░░░░░░░░░░░░░░░░░░ <b>0%</b>\n\n" .
                      "📤 Sent: <b>0</b> / <b>$total_users</b>\n" .
                      "✅ Delivered: <b>0</b>\n" .
                      "❌ Failed: <b>0</b>\n\n" .
                      "⏳ <i>Processing background batch...</i>",
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ]);

        // Launch first batch run asynchronously
        processBroadcastBatch($b_id);
        return;
    }

    if (strpos($data, 'b_stop_') === 0) {
        if (!isAdmin($telegram_id)) return;
        $b_id = (int)substr($data, 7);
        updateBroadcastQueueStatus($b_id, 'stopped');
        apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id, 'text' => "🛑 Broadcast #{$b_id} stop requested!", 'show_alert' => true]);
        
        apiRequest("editMessageText", [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "🛑 <b>BROADCAST #{$b_id} HALTED & STOPPED BY ADMIN</b>",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    apiRequest("answerCallbackQuery", ['callback_query_id' => $callback_id]);
}

// ===========================================================
// ADMIN PANEL HELPER FUNCTIONS
// ===========================================================

/**
 * Builds a dynamic inline keyboard from categories in the DB.
 */
function buildCategoryKeyboard() {
    $cats = getCategories(true);
    $rows = [];
    $row = [];
    foreach ($cats as $i => $cat) {
        $btn_label = mb_strlen($cat['name'], 'UTF-8') > 24 ? mb_substr($cat['name'], 0, 21, 'UTF-8') . '...' : $cat['name'];
        $row[] = ['text' => $btn_label, 'callback_data' => 'cat_' . $cat['name']];
        if (count($row) === 2) {
            $rows[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $rows[] = $row;
    }
    $rows[] = [['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']];
    return ['inline_keyboard' => $rows];
}

/**
 * Sends the main admin panel message with all buttons.
 */
function sendAdminPanel($chat_id, $message_id = null) {
    $stats = getStats();
    $pending_count   = $stats['status_breakdown']['pending'] ?? 0;
    $pending_label   = $pending_count > 0 ? "⏳ Pending ($pending_count)" : "⏳ Pending";
    $scheduled_posts = getAllScheduledPosts();
    $sched_count     = count($scheduled_posts);
    $ref_reward      = getSetting('referral_reward_amount', '1.00');
    $sched_gap       = getSetting('schedule_hour_gap', '1');

    $is_maint_on      = isMaintenanceModeEnabled();
    $maint_btn_label  = $is_maint_on ? "🟢 Maintenance: ON" : "🛠️ Maintenance: OFF";

    $msg = "🛡️ <b>ADMIN PANEL</b>\n";
    if ($is_maint_on) {
        $msg .= "⚠️ <b>[MAINTENANCE MODE IS ACTIVE]</b>\n";
    }
    $msg .= str_repeat("―", 20) . "\n\n";
    $msg .= "🏆 <b>Stats:</b> " . number_format($stats['total_users']) . " users | " . number_format($stats['total_submissions']) . " submissions\n";
    $msg .= "🔥 <b>Active 7d:</b> " . number_format($stats['active_7d']) . " users\n";
    $msg .= "📅 <b>Scheduled Posts:</b> {$sched_count} pending\n";
    $msg .= "🤝 <b>Referral Reward:</b> ₹{$ref_reward} per referral\n";
    $msg .= "⏰ <b>Schedule Gap:</b> {$sched_gap}h interval\n";
    if (!empty($stats['top_category'])) {
        $msg .= "🏅 <b>Top Category:</b> " . htmlspecialchars($stats['top_category']) . "\n";
    }
    $msg .= "\n📂 <i>Select an action below:</i>";

    $open_tickets_cnt = getOpenSupportTicketsCount();
    $tickets_label   = $open_tickets_cnt > 0 ? "📩 Support Tickets ({$open_tickets_cnt})" : "📩 Support Tickets";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => $pending_label,         'callback_data' => 'admin_panel_pending'],
                ['text' => "📊 Stats",              'callback_data' => 'admin_panel_stats']
            ],
            [
                ['text' => "📢 Broadcast",          'callback_data' => 'admin_panel_broadcast'],
                ['text' => "💸 Withdrawals",        'callback_data' => 'admin_panel_withdrawals']
            ],
            [
                ['text' => "📁 Manage Categories", 'callback_data' => 'admin_panel_categories'],
                ['text' => "👮 Manage Admins",     'callback_data' => 'admin_panel_admins']
            ],
            [
                ['text' => "🏆 Referral Stats",    'callback_data' => 'admin_panel_referrals'],
                ['text' => "📦 Recent Posts",      'callback_data' => 'admin_panel_recent']
            ],
            [
                ['text' => "⚡ Set Challenge",     'callback_data' => 'admin_panel_challenge'],
                ['text' => "⚙️ Ref Reward",        'callback_data' => 'admin_panel_ref_reward']
            ],
            [
                ['text' => "💵 Regular Reward",    'callback_data' => 'admin_panel_prompt_reward'],
                ['text' => "🎁 1st Prompt Bonus",  'callback_data' => 'admin_panel_first_prompt_reward']
            ],
            [
                ['text' => "🚫 Banned Users",      'callback_data' => 'admin_panel_banned'],
                ['text' => "💳 User Wallet",       'callback_data' => 'admin_panel_wallet_menu']
            ],
            [
                ['text' => "📅 Calendar" . ($sched_count > 0 ? " ($sched_count)" : ""), 'callback_data' => 'admin_panel_calendar'],
                ['text' => "⏰ Schedule Gap ({$sched_gap}h)", 'callback_data' => 'admin_panel_sched_gap']
            ],
            [
                ['text' => $tickets_label,          'callback_data' => 'admin_panel_tickets'],
                ['text' => "🏆 Post Daily LB",     'callback_data' => 'admin_panel_post_daily_lb']
            ],
            [
                ['text' => $maint_btn_label, 'callback_data' => 'admin_panel_maint_menu']
            ]
        ]
    ];

    if ($message_id) {
        apiRequest("editMessageText", [
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard
        ]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard
        ]);
    }
}

function sendAdminMaintenancePanel($chat_id, $message_id = null) {
    $is_on = isMaintenanceModeEnabled();
    $reason = getMaintenanceReason();
    
    $status_str = $is_on ? "🟢 <b>ACTIVE (BOT IS IN MAINTENANCE)</b>" : "🔴 <b>DISABLED (BOT IS LIVE)</b>";
    
    $msg = "🛠️ <b>MAINTENANCE MODE CONTROL HUB</b>\n\n" .
           "Status: {$status_str}\n\n" .
           "📌 <b>Current Maintenance Notice:</b>\n<i>" . htmlspecialchars($reason) . "</i>\n\n" .
           "<i>When Maintenance Mode is ON, regular users who attempt to use the bot will see your maintenance message and cannot submit prompts or request payouts. Authorized admins retain 100% full access.</i>";
           
    $toggle_btn = $is_on 
        ? ['text' => '🔴 Turn OFF Maintenance Mode', 'callback_data' => 'admin_panel_maint_off']
        : ['text' => '🟢 Turn ON Maintenance Mode', 'callback_data' => 'admin_panel_maint_on'];
        
    $kb = [
        'inline_keyboard' => [
            [$toggle_btn],
            [['text' => '✍️ Edit Maintenance Notice Message', 'callback_data' => 'admin_panel_maint_msg']],
            [['text' => '🔙 Back to Admin Panel', 'callback_data' => 'admin_panel_back']]
        ]
    ];

    if ($message_id) {
        apiRequest("editMessageText", [
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb
        ]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb
        ]);
    }
}

function sendAdminWalletPanel($chat_id, $message_id = null) {
    $msg = "💳 <b>ADMIN WALLET MANAGEMENT</b>\n\n" .
           "Select an action to manage user balance or inspect wallet details:\n\n" .
           "🟢 <b>Add Money (Credit)</b> — Add funds to a user's wallet with custom reason.\n" .
           "🔴 <b>Deduct Money (Debit)</b> — Deduct funds from a user's wallet with custom reason.\n" .
           "🔍 <b>Check User Wallet</b> — View balance, withdrawals, and activity stats of any user.";
           
    $kb = [
        'inline_keyboard' => [
            [
                ['text' => "🟢 ➕ Add Money (Credit)", 'callback_data' => 'admin_panel_w_credit'],
                ['text' => "🔴 ➖ Deduct Money (Debit)", 'callback_data' => 'admin_panel_w_debit']
            ],
            [
                ['text' => "🔍 👤 Check User Wallet", 'callback_data' => 'admin_panel_w_check']
            ],
            [
                ['text' => "🔙 Back to Panel", 'callback_data' => 'admin_panel_back']
            ]
        ]
    ];

    if ($message_id) {
        apiRequest("editMessageText", [
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb
        ]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb
        ]);
    }
}

function sendAdminTicketsPanel($chat_id, $message_id = null, $status_filter = 'open') {
    $tickets = getSupportTickets($status_filter, 20);
    $open_cnt = getOpenSupportTicketsCount();
    
    $filter_title = ($status_filter === 'all') ? "All Tickets & History" : "Open Tickets";
    $msg = "📩 <b>SUPPORT TICKETS HUB</b> ({$filter_title})\n\n";
    
    if (empty($tickets)) {
        $msg .= "<i>No " . ($status_filter === 'all' ? '' : 'open ') . "support tickets found!</i>\n\n";
    } else {
        $msg .= "Showing latest " . count($tickets) . " ticket(s):\n<i>Tap any ticket below to view details or reply:</i>\n\n";
    }

    $kb = [];
    if ($status_filter === 'all') {
        $kb[] = [['text' => "🟡 View Open Tickets ({$open_cnt})", 'callback_data' => 'admin_panel_tickets']];
    } else {
        $kb[] = [['text' => "📋 View All / History Tickets", 'callback_data' => 'admin_panel_tickets_all']];
    }

    foreach ($tickets as $t) {
        $tid = $t['id'];
        $uname = !empty($t['username']) ? '@' . ltrim($t['username'], '@') : 'ID ' . $t['telegram_id'];
        $status_icon = ($t['status'] === 'open') ? '🟡' : (($t['status'] === 'replied') ? '🟢' : '🔒');
        $cat = htmlspecialchars($t['category']);
        
        $btn_text = "{$status_icon} #T-{$tid} [{$cat}] — {$uname}";
        if (mb_strlen($btn_text, 'UTF-8') > 38) {
            $btn_text = mb_substr($btn_text, 0, 35, 'UTF-8') . '...';
        }
        $kb[] = [['text' => $btn_text, 'callback_data' => "admin_panel_viewticket_{$tid}"]];
    }

    $kb[] = [['text' => '🔙 Back to Admin Panel', 'callback_data' => 'admin_panel_back']];

    if ($message_id) {
        apiRequest("editMessageText", [
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $kb]
        ]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $kb]
        ]);
    }
}

function sendAdminViewTicketCard($chat_id, $ticket_id, $message_id = null) {
    $ticket = getSupportTicketById($ticket_id);
    if (!$ticket) {
        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Support ticket #T-{$ticket_id} not found."]);
        return;
    }

    $user = getUserByTelegramId($ticket['telegram_id']);
    $uname = ($user && !empty($user['username'])) ? '@' . ltrim($user['username'], '@') : 'User ' . $ticket['telegram_id'];
    
    $status_icon = ($ticket['status'] === 'open') ? '🟡 OPEN' : (($ticket['status'] === 'replied') ? '🟢 REPLIED' : '🔒 CLOSED');

    $msg = "📩 <b>SUPPORT TICKET #T-{$ticket['id']} DETAILS</b>\n\n" .
           "👤 <b>User:</b> {$uname} (<code>{$ticket['telegram_id']}</code>)\n" .
           "🏷 <b>Category:</b> " . htmlspecialchars($ticket['category']) . "\n" .
           "📌 <b>Status:</b> {$status_icon}\n" .
           "📅 <b>Submitted:</b> {$ticket['created_at']}\n\n" .
           "💬 <b>User Message:</b>\n" . htmlspecialchars($ticket['message']) . "\n";

    if (!empty($ticket['admin_reply'])) {
        $msg .= "\n✍️ <b>Admin Reply:</b>\n" . htmlspecialchars($ticket['admin_reply']) . "\n";
    }

    $kb = [
        'inline_keyboard' => [
            [
                ['text' => '💬 Reply to User', 'callback_data' => "admin_suppreply_{$ticket['id']}"],
                ['text' => '🔒 Resolve & Close', 'callback_data' => "admin_suppclose_{$ticket['id']}"]
            ],
            [
                ['text' => '🔙 Back to Support Tickets', 'callback_data' => 'admin_panel_tickets']
            ]
        ]
    ];

    if ($message_id) {
        apiRequest("editMessageText", [
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb
        ]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb
        ]);
    }
}

function sendAdminBannedPanel($chat_id, $message_id = null) {
    $banned_users = getBannedUsers();
    $msg = "🚫 <b>Banned Users Management</b> 🚫\n\n";
    
    if (empty($banned_users)) {
        $msg .= "<i>No users are currently banned!</i>\n\n";
        $kb = [
            [['text' => '➕ Ban User by ID/Username', 'callback_data' => 'admin_ban_prompt']],
            [['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']]
        ];
    } else {
        $msg .= "Total Banned Users: <b>" . count($banned_users) . "</b>\n\n";
        $kb = [];
        foreach ($banned_users as $u) {
            $uname = htmlspecialchars($u['username'] ?? 'User ' . $u['telegram_id']);
            $reason = htmlspecialchars(mb_substr($u['ban_reason'] ?? 'No reason', 0, 25, 'UTF-8'));
            $msg .= "• <code>{$u['telegram_id']}</code> ({$uname}) — <i>{$reason}</i>\n";
            $kb[] = [['text' => "🟢 Unban {$uname}", 'callback_data' => 'admin_unban_user_' . $u['telegram_id']]];
        }
        $kb[] = [['text' => '➕ Ban User by ID/Username', 'callback_data' => 'admin_ban_prompt']];
        $kb[] = [['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']];
    }

    if ($message_id) {
        sendOrEditStepMessage($chat_id, $message_id, $msg, ['inline_keyboard' => $kb]);
    } else {
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $kb]
        ]);
    }
}

/**
 * Sends the category management panel.
 */
function sendCategoryManagePanel($chat_id, $message_id = null) {
    $cats = getCategories(false); // show all, including inactive
    $msg = "📁 <b>Category Management Hub</b>\n\nTap a category below to view details, <b>Edit Name</b>, or <b>Delete</b> it:";

    $kb = [];
    $row = [];
    foreach ($cats as $cat) {
        $row[] = ['text' => "📁 " . $cat['name'], 'callback_data' => 'admin_cat_select_' . $cat['id']];
        if (count($row) == 2) {
            $kb[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) $kb[] = $row;

    $kb[] = [['text' => "➕ Add New Category", 'callback_data' => 'admin_cat_add']];
    $kb[] = [['text' => '🔙 Back to Panel',    'callback_data' => 'admin_panel_back']];

    if ($message_id) {
        sendOrEditStepMessage($chat_id, $message_id, $msg, ['inline_keyboard' => $kb]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $kb]
        ]);
    }
}

function sendCategoryDetailCard($chat_id, $cat_id, $message_id = null) {
    $cat = getCategoryById($cat_id);
    if (!$cat) {
        sendCategoryManagePanel($chat_id, $message_id);
        return;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE category = ?");
    $stmt->execute([$cat['name']]);
    $prompt_count = (int)$stmt->fetchColumn();

    $msg = "📁 <b>CATEGORY DETAILS</b>\n\n" .
           "📌 Category Name: <b>" . htmlspecialchars($cat['name']) . "</b>\n" .
           "🆔 Category ID: <code>#{$cat['id']}</code>\n" .
           "📊 Total Prompts: <b>{$prompt_count}</b>\n\n" .
           "<i>Choose an action below to edit or delete this category:</i>";

    $kb = [
        'inline_keyboard' => [
            [
                ['text' => '✏️ Edit Name', 'callback_data' => 'admin_cat_rename_' . $cat['id']],
                ['text' => '🗑️ Delete Category', 'callback_data' => 'admin_cat_del_' . $cat['id']]
            ],
            [
                ['text' => '🔙 Back to Categories', 'callback_data' => 'admin_panel_categories']
            ]
        ]
    ];

    if ($message_id) {
        sendOrEditStepMessage($chat_id, $message_id, $msg, $kb);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => $kb
        ]);
    }
}

/**
 * Sends the challenge management panel.
 */
function sendChallengeManagePanel($chat_id) {
    $challenges = getActiveChallenges();
    $msg = "⚡ <b>Challenge Keywords Management</b>\n\n";
    if (empty($challenges)) {
        $msg .= "<i>No active challenge keywords. Add keywords below to auto-match submissions!</i>";
    } else {
        $msg .= "Active challenge keywords:\n";
        foreach ($challenges as $c) {
            $msg .= "🔥 #{$c['id']} — <b>" . htmlspecialchars($c['theme']) . "</b>\n";
        }
    }

    $kb = [];
    foreach ($challenges as $c) {
        $kb[] = [['text' => "🗑️ Remove: " . htmlspecialchars($c['theme']), 'callback_data' => 'admin_ch_del_' . $c['id']]];
    }
    $kb[] = [['text' => "➕ Add Keywords", 'callback_data' => 'admin_ch_add']];
    if (!empty($challenges)) {
        $kb[] = [['text' => "💥 Clear All Keywords", 'callback_data' => 'admin_ch_clear']];
    }
    $kb[] = [['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']];

    apiRequest("sendMessage", [
        'chat_id'      => $chat_id,
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => ['inline_keyboard' => $kb]
    ]);
}

/**
 * Sends the admin management panel.
 */
function sendAdminManagePanel($chat_id) {
    $admins = getAllAdmins();
    $msg = "👮 <b>Admin & Permissions Management</b>\n\n";
    $msg .= "<b>Current Admins:</b>\n";
    foreach ($admins as $aid) {
        $is_primary = (defined('ADMIN_ID') && $aid == ADMIN_ID);
        $label = $is_primary ? " ⭐ Primary (Super Admin)" : " 👤 Sub-Admin";
        $msg .= "• <code>$aid</code>$label\n";
    }

    $kb = [];
    foreach ($admins as $aid) {
        if (!defined('ADMIN_ID') || $aid != ADMIN_ID) {
            $kb[] = [
                ['text' => "⚙️ Perms: $aid", 'callback_data' => 'admin_mgmt_perm_' . $aid],
                ['text' => "❌ Remove: $aid", 'callback_data' => 'admin_mgmt_del_' . $aid]
            ];
        }
    }
    $kb[] = [['text' => "➕ Add New Admin",   'callback_data' => 'admin_mgmt_add']];
    $kb[] = [['text' => '🔙 Back to Panel', 'callback_data' => 'admin_panel_back']];

    apiRequest("sendMessage", [
        'chat_id'      => $chat_id,
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => ['inline_keyboard' => $kb]
    ]);
}

function sendAdminPermissionPanel($chat_id, $target_admin_id, $message_id = null) {
    $perms = getAdminPermissions($target_admin_id);
    
    $labels = [
        'can_approve'   => 'Review & Approve Submissions',
        'can_schedule'  => 'Schedule Future Posts',
        'can_delete'    => 'Delete Posts (Web & Channel)',
        'can_challenge' => 'Manage Challenges & Keywords',
        'can_settings'  => 'Bot Settings & Payout Amounts',
        'can_broadcast' => 'Send Mass Broadcast Messages'
    ];
    
    $msg = "⚙️ <b>Permissions for Sub-Admin</b> <code>$target_admin_id</code>:\n\n";
    $msg .= "Tap any button below to Enable 🟢 or Disable 🔴 access in real time:\n";
    
    $kb = [];
    foreach ($labels as $key => $label) {
        $icon = !empty($perms[$key]) ? "🟢" : "🔴";
        $kb[] = [['text' => "{$icon} {$label}", 'callback_data' => "admin_perm_toggle_{$target_admin_id}_{$key}"]];
    }
    
    $kb[] = [['text' => "❌ Remove Admin {$target_admin_id}", 'callback_data' => "admin_mgmt_del_{$target_admin_id}"]];
    $kb[] = [['text' => '🔙 Back to Admins', 'callback_data' => 'admin_panel_admins']];
    
    if ($message_id) {
        sendOrEditStepMessage($chat_id, $message_id, $msg, ['inline_keyboard' => $kb]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $kb]
        ]);
    }
}

function downloadAndSaveMedia($file_ids_json, $is_video = false) {
    $file_ids = json_decode($file_ids_json, true);
    // Handle legacy single file_id (not JSON)
    if (!is_array($file_ids)) {
        if ($file_ids_json) $file_ids = [$file_ids_json];
        else return null;
    }

    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $saved_paths = [];

    foreach ($file_ids as $file_id) {
        // Step 1: Get file path from Telegram
        $file_info = apiRequest("getFile", ['file_id' => $file_id]);
        if (!isset($file_info['result']['file_path'])) {
            error_log("downloadAndSaveMedia: Could not get file path for $file_id");
            continue;
        }
        $tg_file_path = $file_info['result']['file_path'];
        $download_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$tg_file_path";

        // Step 2: Download the image into memory
        $raw_image = file_get_contents($download_url);
        if (!$raw_image) {
            error_log("downloadAndSaveMedia: Failed to download image from Telegram.");
            continue;
        }

        // Step 3: If video, just save raw file. If image, process with GD.
        if ($is_video) {
            $filename = 'prompt_vid_' . uniqid() . '.mp4';
            $filepath = $upload_dir . $filename;
            file_put_contents($filepath, $raw_image);
            $saved_paths[] = 'uploads/' . $filename;
            continue;
        }

        $source = @imagecreatefromstring($raw_image);
        if (!$source) {
            error_log("downloadAndSaveMedia: GD could not parse image.");
            continue;
        }

        // Step 4: Resize if larger than 1200px wide (for compression)
        $orig_w = imagesx($source);
        $orig_h = imagesy($source);
        $max_w = 1200;

        if ($orig_w > $max_w) {
            $ratio = $max_w / $orig_w;
            $new_w = $max_w;
            $new_h = (int)($orig_h * $ratio);
            $resampled = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($resampled, $source, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
            imagedestroy($source);
            $source = $resampled;
        }

        // Step 4.5: Stamp watermark badge (@ai_prompt_store)
        $w_text = '@ai_prompt_store';
        $curr_w = imagesx($source);
        $curr_h = imagesy($source);

        $padding_x = 10;
        $padding_y = 6;
        $text_len  = strlen($w_text);
        $text_w    = $text_len * 9;
        $text_h    = 15;

        $margin_right = 16;
        $margin_bottom = 16;

        $rect_x1 = $curr_w - $margin_right - $text_w - ($padding_x * 2);
        $rect_y1 = $curr_h - $margin_bottom - $text_h - ($padding_y * 2);
        $rect_x2 = $curr_w - $margin_right;
        $rect_y2 = $curr_h - $margin_bottom;

        if ($rect_x1 > 0 && $rect_y1 > 0) {
            $bg_color = imagecolorallocatealpha($source, 0, 0, 0, 50);
            imagefilledrectangle($source, $rect_x1, $rect_y1, $rect_x2, $rect_y2, $bg_color);

            $text_color = imagecolorallocate($source, 255, 255, 255);
            $text_x = $rect_x1 + $padding_x;
            $text_y = $rect_y1 + $padding_y;
            imagestring($source, 5, $text_x, $text_y, $w_text, $text_color);
        }

        // Step 5: Save as WebP with good quality
        $filename = 'prompt_' . uniqid() . '.webp';
        $filepath = $upload_dir . $filename;

        if (function_exists('imagewebp')) {
            imagewebp($source, $filepath, 82); // 82% quality = great balance
        } else {
            // Fallback to JPEG if WebP not available on this server
            $filename = str_replace('.webp', '.jpg', $filename);
            $filepath = $upload_dir . $filename;
            imagejpeg($source, $filepath, 82);
            error_log("downloadAndSaveMedia: WebP not supported, saved as JPEG instead.");
        }
        imagedestroy($source);

        $saved_paths[] = 'uploads/' . $filename;
    }

    if (empty($saved_paths)) return null;
    return json_encode($saved_paths);
}

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

        // Collect all message IDs created for this submission (Media + Text Prompt)
        $all_msg_ids = [];
        if (isset($media_res['ok']) && $media_res['ok'] === true) {
            if (isset($media_res['result']) && is_array($media_res['result']) && isset($media_res['result'][0]['message_id'])) {
                foreach ($media_res['result'] as $m_item) {
                    if (isset($m_item['message_id'])) $all_msg_ids[] = $m_item['message_id'];
                }
            } elseif (isset($media_res['result']['message_id'])) {
                $all_msg_ids[] = $media_res['result']['message_id'];
            }
        }
        if (isset($res['ok']) && $res['ok'] === true && isset($res['result']['message_id'])) {
            $all_msg_ids[] = $res['result']['message_id'];
        }

        if (!empty($all_msg_ids)) {
            if (!isset($res['result']) || !is_array($res['result'])) $res['result'] = [];
            $res['result']['all_message_ids'] = $all_msg_ids;
        }
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

function sendPreview($chat_id, $draft_id, $username)
{
    $draft = getSubmissionById($draft_id);
    if (!$draft)
        return;

    $safe_prompt = mb_substr($draft['prompt'], 0, 3800, 'UTF-8');
    if (mb_strlen($draft['prompt'], 'UTF-8') > 3800)
        $safe_prompt .= '...';

    $header = "✨ <b>PROMPT RESULT</b> ✨\n\n";
    $header .= "📌 Title: <b>" . htmlspecialchars($draft['text_output'] ?? 'Untitled') . "</b>\n";
    $header .= "🏷 Category: <b>" . htmlspecialchars($draft['category']) . "</b>\n\n";

    $prompt_part = "Prompt:\n<code>" . htmlspecialchars($safe_prompt) . "</code>\n\n";

    $footer = "Tags: " . htmlspecialchars($draft['tags']) . "\n";
    $footer .= "Creator: " . htmlspecialchars($username) . "\n\n";
    $footer .= "👉 Explore more secret Prompts on our channel: @ai_prompt_store\n";
    $footer .= "🤖 If you want to submit your prompt, you can submit on @Prompts_library_bot";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '✅ Confirm & Submit', 'callback_data' => 'submit_' . $draft_id]],
            [['text' => '🔙 Back', 'callback_data' => 'cmd_back'], ['text' => '❌ Cancel', 'callback_data' => 'cmd_cancel']]
        ]
    ];

    sendSmartMessage($chat_id, $draft, $header, $prompt_part, $footer, $safe_prompt, $keyboard);
}

function sendToAdminDirectly($admin_chat_id, $draft_id, $username)
{
    $draft = getSubmissionById($draft_id);
    if (!$draft) return;

    $safe_prompt = mb_substr($draft['prompt'], 0, 3800, 'UTF-8');
    if (mb_strlen($draft['prompt'], 'UTF-8') > 3800) $safe_prompt .= '...';

    $header = "🚨 <b>PENDING SUBMISSION: #{$draft_id}</b> 🚨\n\n";
    $header .= "📌 Title: <b>" . htmlspecialchars($draft['text_output'] ?? 'Untitled') . "</b>\n";
    $header .= "🏷 Category: <b>" . htmlspecialchars($draft['category']) . "</b>\n\n";

    $prompt_part = "Prompt:\n<code>" . htmlspecialchars($safe_prompt) . "</code>\n\n";

    $footer = "Tags: " . htmlspecialchars($draft['tags']) . "\n";
    $footer .= "Creator: " . htmlspecialchars($username) . "\n\n";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '✅ Approve Now', 'callback_data' => 'admin_approve_' . $draft_id], ['text' => '⏳ Schedule', 'callback_data' => 'admin_schedule_' . $draft_id]],
            [['text' => '📝 Edit', 'callback_data' => 'admin_edit_' . $draft_id], ['text' => '❌ Reject', 'callback_data' => 'admin_reject_' . $draft_id]],
            [['text' => '💡 Reject with Tip', 'callback_data' => 'admin_tip_' . $draft_id]]
        ]
    ];

    sendSmartMessage($admin_chat_id, $draft, $header, $prompt_part, $footer, $safe_prompt, $keyboard);
}

function sendToAdmin($draft_id, $username)
{
    $draft = getSubmissionById($draft_id);
    if (!$draft)
        return;

    // Check challenge keyword match if not already tagged
    if (!$draft['is_challenge']) {
        $match = checkChallengeMatch($draft['text_output'] ?? '', $draft['prompt'] ?? '', $draft['tags'] ?? '');
        if ($match) {
            updateDraftSubmission($draft_id, 'is_challenge', 1);
            $draft['is_challenge'] = 1;
        }
    }

    $safe_prompt = mb_substr($draft['prompt'], 0, 3800, 'UTF-8');
    if (mb_strlen($draft['prompt'], 'UTF-8') > 3800)
        $safe_prompt .= '...';

    $header = "🚨 <b>NEW SUBMISSION: #{$draft_id}</b> 🚨\n\n";
    $user_approved_count = getUserApprovedPromptCount($draft['telegram_id']);
    if ($user_approved_count === 0) {
        $first_bounty = getSetting('first_prompt_reward_amount', '5.00');
        $header .= "🎁 <b>FIRST-TIME CREATOR SUBMISSION!</b> (Eligible for ₹{$first_bounty} First Prompt Bonus upon approval)\n\n";
    }
    if (!empty($draft['is_challenge'])) {
        $p_rew = (float)getSetting('prompt_reward_amount', '0.50');
        $c_rew = $p_rew * 2;
        $c_fmt = number_format($c_rew, 2);
        $header .= "🔥 <b>TRENDING CHALLENGE MATCH! (2x Double Reward: ₹{$c_fmt})</b>\n\n";
    }
    $header .= "📌 Title: <b>" . htmlspecialchars($draft['text_output'] ?? 'Untitled') . "</b>\n";
    $header .= "🏷 Category: <b>" . htmlspecialchars($draft['category']) . "</b>\n\n";

    $prompt_part = "Prompt:\n<code>" . htmlspecialchars($safe_prompt) . "</code>\n\n";

    $footer = "Tags: " . htmlspecialchars($draft['tags']) . "\n";
    $footer .= "Creator: " . htmlspecialchars($username) . "\n\n";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '✅ Approve Now', 'callback_data' => 'admin_approve_' . $draft_id], ['text' => '⏳ Schedule', 'callback_data' => 'admin_schedule_' . $draft_id]],
            [['text' => '📝 Edit', 'callback_data' => 'admin_edit_' . $draft_id], ['text' => '❌ Reject', 'callback_data' => 'admin_reject_' . $draft_id]],
            [['text' => '💡 Reject with Tip', 'callback_data' => 'admin_tip_' . $draft_id]]
        ]
    ];

    $admins = getAllAdmins();
    foreach ($admins as $admin) {
        try {
            sendSmartMessage($admin, $draft, $header, $prompt_part, $footer, $safe_prompt, $keyboard);
            usleep(30000); // 30ms rate limit protection
        } catch (Exception $e) {
            error_log("Failed to send review card to admin {$admin}: " . $e->getMessage());
        }
    }
}

// processBroadcastBatch is defined in db.php for shared usage between webhook.php and cron.php

function checkAndAwardDailyMilestone($telegram_id) {
    $target = (int)getSetting('daily_milestone_target', '5');
    $bonus_amount = (float)getSetting('daily_milestone_bonus_amount', '0.10');
    
    $today_count = getTodayApprovedCount($telegram_id);
    
    if ($today_count >= $target && !hasEarnedDailyBonusToday($telegram_id)) {
        $awarded = awardDailyBonus($telegram_id, $bonus_amount);
        if ($awarded) {
            $user = getUserByTelegramId($telegram_id);
            $new_balance = number_format($user['balance'] ?? 0, 2);
            $b_fmt = number_format($bonus_amount, 2);
            
            $msg = "🎉 <b>DAILY MILESTONE BONUS UNLOCKED!</b> 🎉\n\n" .
                   "Congratulations! You have published <b>{$target} approved prompts</b> today!\n\n" .
                   "🎁 <b>Bonus Awarded:</b> +<b>₹{$b_fmt}</b> credited to your balance!\n" .
                   "💰 <b>New Balance:</b> ₹{$new_balance}\n\n" .
                   "<i>Keep submitting prompts daily to maximize your earnings! 🚀</i>";
            
            $kb = [
                'inline_keyboard' => [
                    [['text' => '🚀 Submit More Prompts', 'callback_data' => 'cmd_submit']],
                    [['text' => '💰 View Balance', 'callback_data' => 'cmd_balance']]
                ]
            ];
            
            try {
                apiRequest("sendMessage", [
                    'chat_id' => $telegram_id,
                    'text' => $msg,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $kb
                ]);
            } catch (Exception $e) {
                error_log("Failed to send daily milestone alert to {$telegram_id}: " . $e->getMessage());
            }
            return true;
        }
    }
    return false;
}

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

    $header = "";
    if (!empty($draft['is_challenge'])) {
        $header .= "🔥 <b>TRENDING PROMPT</b> 🔥\n\n";
    } else {
        $header .= "✨ <b>PROMPT RESULT</b> ✨\n\n";
    }
    $header .= "📌 Title: <b>" . htmlspecialchars($draft['text_output'] ?? '') . "</b>\n";
    $header .= "🏷 Category: <b>" . htmlspecialchars($draft['category']) . "</b>\n\n";

    $prompt_part = "🔹 Prompt:\n<code>" . htmlspecialchars($safe_prompt) . "</code>\n\n";

    $footer = "🏷 " . htmlspecialchars($draft['tags']) . "\n";
    $footer .= "👤 Creator: " . htmlspecialchars($username) . "\n\n";
    $footer .= '<a href="https://rtmcreator.com/prompt-library/">🌐 Browse 140+ Prompts on Web</a>' . "\n";
    $footer .= "👉 Explore more secret Prompts on our channel: @" . CHANNEL_USERNAME . "\n";

    $web_url = "https://rtmcreator.com/prompt-library/?id=" . $draft_id;
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🌐 Browse Full Library', 'url' => $web_url],
                ['text' => '🚀 Submit Prompt', 'url' => 'https://t.me/Prompts_library_bot']
            ]
        ]
    ];

    $res = sendSmartMessage(CHANNEL_ID, $draft, $header, $prompt_part, $footer, $safe_prompt, $keyboard);
    if ($res && isset($res['ok']) && $res['ok'] === true) {
        $all_ids = $res['result']['all_message_ids'] ?? [];
        if (empty($all_ids) && isset($res['result']['message_id'])) {
            $all_ids = [$res['result']['message_id']];
        }
        if (!empty($all_ids)) {
            $stored_val = count($all_ids) > 1 ? json_encode(array_values(array_unique($all_ids))) : (string)$all_ids[0];
            updateDraftSubmission($draft_id, 'channel_message_id', $stored_val);
        }
    }
    return $res;
}

function broadcastNewChallengeNotification($added_keywords) {
    if (empty($added_keywords)) return;
    $users = getAllUsers('all');
    $kw_list = implode(', ', array_map(fn($k) => "<b>" . htmlspecialchars($k) . "</b>", $added_keywords));
    $prompt_reward = (float)getSetting('prompt_reward_amount', '0.25');
    $challenge_reward = $prompt_reward * 2;
    $c_fmt = number_format($challenge_reward, 2);

    $msg = "🚀 <b>NEW TRENDING CHALLENGES ANNOUNCED!</b> 🚀\n\n" .
           "Admin has added new trending challenge keywords:\n" .
           "🔥 $kw_list\n\n" .
           "💡 <b>How it works:</b>\n" .
           "Include any of these keywords in your prompt title, description, or tags when submitting!\n\n" .
           "💰 <b>Reward:</b> Matched prompts automatically earn <b>DOUBLE REWARDS (2x = ₹{$c_fmt})</b> upon approval and get a 🔥 <b>Trending Badge</b>!\n\n" .
           "Tap below to submit your prompt now!";
    
    $kb = ['inline_keyboard' => [[['text' => '🚀 Submit Prompt Now', 'callback_data' => 'cmd_submit']]]];

    ignore_user_abort(true);
    set_time_limit(0);
    foreach ($users as $uid) {
        apiRequest("sendMessage", [
            'chat_id' => $uid,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $kb
        ], true);
        usleep(34000);
    }
}

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

        // Double check they have at least one approved post (this one)
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
                    addBalance($telegram_id, $reward);
                    addBalance($referrer_id, $reward);
                    markReferralRewardGiven($telegram_id);

                    $reward_str = '₹' . number_format($reward, 2);
                    $userName = $user['username'] ?: "Your friend";

                    // Notify User
                    apiRequest("sendMessage", [
                        'chat_id' => $telegram_id,
                        'text' => "🎉 <b>Referral Bonus Earned!</b>\n\nYou and your referrer just earned <b>{$reward_str}</b> because your first prompt was approved and you joined our channel!\nKeep submitting prompts to earn more! 💰",
                        'parse_mode' => 'HTML'
                    ]);

                    // Notify Referrer
                    apiRequest("sendMessage", [
                        'chat_id' => $referrer_id,
                        'text' => "🎁 <b>Referral Bonus Alert!</b>\n\n{$userName} just got their first prompt approved. You have been awarded <b>{$reward_str}</b>! Thank you for inviting quality creators. Keep sharing your link! 🔗",
                        'parse_mode' => 'HTML'
                    ]);
                }
            }
        }
    }
}

function showGalleryPage($chat_id, $old_msg_id, $telegram_id, $index) {
    $prompts = getUserApprovedSubmissions($telegram_id);
    $total = count($prompts);

    if ($total == 0) {
        apiRequest("sendMessage", [
            'chat_id' => $chat_id,
            'text' => "🎬 <b>Your Gallery is Empty!</b>\n\nOnce your prompts are approved, they will appear here as your creative portfolio.",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // Wrap around index
    if ($index < 0) $index = $total - 1;
    if ($index >= $total) $index = 0;

    $p = $prompts[$index];
    $display_index = $index + 1;
    
    // Build the parts for sendSmartMessage
    $header = "🖼️ <b>My Gallery ($display_index / $total)</b>\n\n";
    $header .= "🏷 Category: <b>" . htmlspecialchars($p['category']) . "</b>\n";
    
    $prompt_part = "💬 Prompt:\n<code>" . htmlspecialchars($p['prompt']) . "</code>\n\n";
    
    $footer = "✨ Tags: " . htmlspecialchars($p['tags']) . "\n";
    if ($p['is_challenge']) $footer .= "🏆 <b>Channel Challenge entry</b>\n";
    $footer .= "\n<i>Use buttons below to browse your work</i>";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '⬅️ Previous', 'callback_data' => "gal_nav_" . ($index - 1)],
                ['text' => "$display_index / $total", 'callback_data' => "gal_nav_refresh"],
                ['text' => 'Next ➡️', 'callback_data' => "gal_nav_" . ($index + 1)]
            ],
            [['text' => '👤 Back to Profile', 'callback_data' => 'cmd_profile']]
        ]
    ];

    // If we're updating, deleting the old message ensures we don't flood
    // and correctly handle media type changes.
    if ($old_msg_id) {
        apiRequest("deleteMessage", ['chat_id' => $chat_id, 'message_id' => $old_msg_id]);
    }

    sendSmartMessage($chat_id, $p, $header, $prompt_part, $footer, $p['prompt'], $keyboard);
}

function getAdminDisplayName($telegram_id, $from = []) {
    if (!empty($from['username'])) {
        return "<b>" . htmlspecialchars($from['username']) . "</b>";
    }
    if (!empty($from['first_name'])) {
        $name = $from['first_name'];
        if (!empty($from['last_name'])) $name .= " " . $from['last_name'];
        return "<b>" . htmlspecialchars($name) . "</b>";
    }
    $user = getUserByTelegramId($telegram_id);
    if ($user && !empty($user['username'])) {
        return "<b>" . htmlspecialchars($user['username']) . "</b>";
    }
    return "admin <code>{$telegram_id}</code>";
}

function notifyOtherAdmins($acting_admin_id, $message_text) {
    $admins = getAllAdmins();
    foreach ($admins as $admin_id) {
        if ((int)$admin_id !== (int)$acting_admin_id) {
            try {
                apiRequest("sendMessage", [
                    'chat_id'    => $admin_id,
                    'text'       => $message_text,
                    'parse_mode' => 'HTML'
                ], true);
                usleep(30000); // 30ms rate limit protection
            } catch (Exception $e) {
                error_log("Failed to send admin notification to {$admin_id}: " . $e->getMessage());
            }
        }
    }
}

function executePostRevocation($acting_admin_id, $chat_id, $message_id, $id, $type, $reason) {
    $submission = getSubmissionById($id);
    if (!$submission) {
        apiRequest("sendMessage", ['chat_id' => $chat_id, 'text' => "❌ Post #$id not found."]);
        return;
    }

    $was_approved = ($submission['status'] === 'approved');
    $res_msg = "";

    // 1. Remove from Web Library & update DB Status
    if ($type === 'both' || $type === 'web') {
        updateSubmissionStatus($id, 'rejected');
        $res_msg .= "✅ Removed from Website. ";
    } else {
        updateSubmissionStatus($id, 'rejected');
    }

    // 2. Delete Message(s) from Telegram Channel
    if (($type === 'both' || $type === 'chan') && !empty($submission['channel_message_id'])) {
        $raw_msg_val = $submission['channel_message_id'];
        $msg_ids = json_decode($raw_msg_val, true);
        
        if (is_array($msg_ids)) {
            foreach ($msg_ids as $mid) {
                apiRequest("deleteMessage", ['chat_id' => CHANNEL_ID, 'message_id' => (int)$mid]);
                usleep(20000); // 20ms pause
            }
        } else if (is_numeric($raw_msg_val)) {
            $single_id = (int)$raw_msg_val;
            // Delete primary message ID
            apiRequest("deleteMessage", ['chat_id' => CHANNEL_ID, 'message_id' => $single_id]);
            // Fallback: Also attempt to delete preceding message ID (single_id - 1) in case the post was a 2-message split!
            apiRequest("deleteMessage", ['chat_id' => CHANNEL_ID, 'message_id' => $single_id - 1]);
        }
        $res_msg .= "✅ Deleted all post messages from Channel. ";
    }

    // 3. Financial Rollback (Deduct earnings if post was previously approved)
    $deducted_amount = 0.00;
    if ($was_approved) {
        $prompt_reward = (float)getSetting('prompt_reward_amount', '0.25');
        $deducted_amount = !empty($submission['is_challenge']) ? ($prompt_reward * 2) : $prompt_reward;
        addBalance($submission['telegram_id'], -$deducted_amount);
        $deduct_fmt = number_format($deducted_amount, 2);
        $res_msg .= "💰 Deducted ₹{$deduct_fmt} from creator balance.";
    }

    // 4. Send Apology & Reason Notification to Creator
    $creator_id = $submission['telegram_id'];
    $post_title = htmlspecialchars(mb_substr($submission['text_output'] ?: $submission['category'], 0, 35, 'UTF-8'));
    
    $rejection_tips = [
        'Low Quality'       => 'Output image/media quality did not meet channel standards. Please submit higher resolution media.',
        'Duplicate'         => 'This prompt or result is a duplicate of a previously approved post.',
        'Inappropriate'     => 'Content violates our safety and community guidelines.',
        'Wrong Category'    => 'Incorrect category selected. Please submit under the matching category.'
    ];
    $reason_tip = $rejection_tips[$reason] ?? 'Please ensure your prompt meets quality guidelines before resubmitting.';

    $apology_text = "⚠️ <b>Notice: Submission Revoked (Post #{$id})</b>\n\n";
    $apology_text .= "We apologize for the inconvenience! Upon secondary moderation review, your prompt post <b>#{$id}</b> (<i>\"{$post_title}\"</i>) was found to violate guidelines and has been revoked.\n\n";
    $apology_text .= "📌 <b>Revocation Reason:</b> " . htmlspecialchars($reason) . "\n";
    if ($was_approved && $deducted_amount > 0) {
        $apology_text .= "💰 <b>Balance Adjustment:</b> -₹" . number_format($deducted_amount, 2) . " (Deducted)\n";
    }
    $apology_text .= "💡 <b>Tip:</b> {$reason_tip}\n\n";
    $apology_text .= "<i>Feel free to fix your prompt and resubmit using /submit!</i>";

    $resubmit_kb = ['inline_keyboard' => [[['text' => '🚀 Resubmit Prompt', 'callback_data' => 'cmd_submit']]]];
    apiRequest("sendMessage", [
        'chat_id'      => $creator_id,
        'text'         => $apology_text,
        'parse_mode'   => 'HTML',
        'reply_markup' => $resubmit_kb
    ]);

    // 5. Update admin chat message
    if ($message_id) {
        apiRequest("editMessageText", [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => "🗑️ <b>Post #{$id} Revocation Complete</b>\n\n$res_msg",
            'parse_mode' => 'HTML'
        ]);
    } else {
        apiRequest("sendMessage", [
            'chat_id'    => $chat_id,
            'text'       => "🗑️ <b>Post #{$id} Revocation Complete</b>\n\n$res_msg",
            'parse_mode' => 'HTML'
        ]);
    }

    $notify_text = "🗑️ <b>Admin Activity Alert</b>\n\nPost <b>#{$id}</b> (<i>\"{$post_title}\"</i>) was <b>REVOKED & DELETED</b> (Reason: <i>" . htmlspecialchars($reason) . "</i>{$deduct_info}) by {$admin_label}.";
    notifyOtherAdmins($acting_admin_id, $notify_text);
}

function isUserChannelMember($telegram_id) {
    if (isAdmin($telegram_id)) return true;
    $res = apiRequest("getChatMember", [
        'chat_id' => CHANNEL_ID,
        'user_id' => $telegram_id
    ], true);
    
    $status = $res['result']['status'] ?? 'left';
    return in_array($status, ['creator', 'administrator', 'member']);
}

function sendForceJoinCard($chat_id, $telegram_id) {
    $channel_name = CHANNEL_USERNAME;
    $msg = "📢 <b>MANDATORY CHANNEL JOIN REQUIRED</b>\n\n" .
           "To use <b>@Prompts_library_bot</b>, submit prompts, or earn rewards, you must first join our official Telegram channel:\n\n" .
           "👉 <b>@{$channel_name}</b>\n\n" .
           "<i>Please join the channel below and then tap <b>\"✅ I Have Joined\"</b> to unlock the bot!</i>";

    $kb = [
        'inline_keyboard' => [
            [['text' => "📢 Join @{$channel_name}", 'url' => "https://t.me/{$channel_name}"]],
            [['text' => "✅ I Have Joined", 'callback_data' => 'cmd_check_joined']]
        ]
    ];

    apiRequest("sendMessage", [
        'chat_id' => $chat_id,
        'text' => $msg,
        'parse_mode' => 'HTML',
        'reply_markup' => $kb
    ]);
}

function sendBalanceDashboard($chat_id, $telegram_id, $username) {
    global $pdo;
    $bal = getBalance($telegram_id);
    
    // Approved count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE telegram_id = ? AND status = 'approved'");
    $stmt->execute([(int)$telegram_id]);
    $approved_count = (int)$stmt->fetchColumn();

    // Total withdrawn
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE telegram_id = ? AND status != 'rejected'");
    $stmt->execute([(int)$telegram_id]);
    $total_withdrawn = (float)$stmt->fetchColumn();

    // Lifetime earnings (Available Balance + Total Withdrawn)
    $lifetime_earnings = $bal + $total_withdrawn;
    $rank = getUserRank($telegram_id);

    // Referral count & earnings
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral_rewards WHERE referrer_id = ?");
    $stmt->execute([(int)$telegram_id]);
    $ref_count = (int)$stmt->fetchColumn();
    $ref_reward_amt = (float)getSetting('referral_reward_amount', '1.00');
    $ref_earnings = $ref_count * $ref_reward_amt;

    $bal_fmt = number_format($bal, 2);
    $withdrawn_fmt = number_format($total_withdrawn, 2);
    $lifetime_fmt = number_format($lifetime_earnings, 2);
    $ref_fmt = number_format($ref_earnings, 2);

    $min_wd = 20.00;
    $progress_pct = min(100, max(0, round(($bal / $min_wd) * 100)));
    $filled_blocks = min(5, (int)round(($progress_pct / 100) * 5));
    $empty_blocks = 5 - $filled_blocks;
    $progress_bar = str_repeat('🟩', $filled_blocks) . str_repeat('⬜', $empty_blocks);

    $msg = "💰 <b>ACCOUNT BALANCE & EARNINGS DASHBOARD</b>\n\n" .
           "👤 Creator: <b>" . htmlspecialchars($username) . "</b> (Rank <b>#{$rank}</b>)\n\n" .
           "💵 <b>Available Wallet Balance:</b> ₹{$bal_fmt}\n" .
           "📈 <b>Withdrawal Goal:</b> [{$progress_bar}] <b>{$progress_pct}%</b> (₹{$bal_fmt} / ₹20.00)\n" .
           "💸 <b>Total Withdrawn:</b> ₹{$withdrawn_fmt}\n" .
           "🏆 <b>Lifetime Earnings:</b> ₹{$lifetime_fmt}\n\n" .
           "📊 <b>Activity Breakdown:</b>\n" .
           "• Approved Prompts: <b>{$approved_count}</b>\n" .
           "• Referral Rewards: <b>₹{$ref_fmt}</b> ({$ref_count} referrals)\n\n" .
           "📌 <i>Minimum Withdrawal Limit: ₹20.00</i>\n" .
           "<i>Note: Withdrawing funds deducts from your available balance, but your Lifetime Earnings and Creator Rank remain intact forever!</i>";

    $kb = [
        'inline_keyboard' => [
            [['text' => '💸 Withdraw Funds (UPI)', 'callback_data' => 'cmd_start_withdraw']],
            [['text' => '📅 Daily Check-in', 'callback_data' => 'cmd_checkin'], ['text' => '📜 Last 10 Transactions', 'callback_data' => 'user_tx_history']],
            [['text' => '🤝 Refer & Earn', 'callback_data' => 'cmd_refer'], ['text' => '🚀 Submit Prompt', 'callback_data' => 'cmd_submit']]
        ]
    ];

    apiRequest("sendMessage", [
        'chat_id' => $chat_id,
        'text' => $msg,
        'parse_mode' => 'HTML',
        'reply_markup' => $kb
    ]);
}

function showUserTransactionHistory($chat_id, $telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT amount, transaction_type, source, description, created_at FROM money_transactions WHERE telegram_id = ? ORDER BY id DESC LIMIT 10");
    $stmt->execute([(int)$telegram_id]);
    $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($txs)) {
        $msg = "📜 <b>LAST 10 WALLET TRANSACTIONS</b>\n\n<i>No transactions recorded yet! Earn rewards by submitting prompts or inviting friends.</i>";
    } else {
        $msg = "📜 <b>LAST 10 WALLET TRANSACTIONS</b>\n\n";
        foreach ($txs as $tx) {
            $amt = (float)$tx['amount'];
            $is_credit = ($tx['transaction_type'] === 'CREDIT' || $amt > 0);
            $icon = $is_credit ? "🟢 <b>+₹" . number_format(abs($amt), 2) . "</b>" : "🔴 <b>-₹" . number_format(abs($amt), 2) . "</b>";
            $source = htmlspecialchars($tx['source'] ?? 'WALLET');
            $desc = htmlspecialchars($tx['description'] ?? '');
            $date = date('d M Y, h:i A', strtotime($tx['created_at']));

            $msg .= "{$icon} (<b>{$source}</b>)\n";
            if (!empty($desc)) {
                $msg .= "📝 <i>{$desc}</i>\n";
            }
            $msg .= "🕒 <code>{$date}</code>\n";
            $msg .= "───────────────\n";
        }
    }

    $kb = [
        'inline_keyboard' => [
            [['text' => '📅 Daily Check-in', 'callback_data' => 'cmd_checkin'], ['text' => '💰 Back to Balance', 'callback_data' => 'cmd_balance']],
            [['text' => '🚀 Submit Prompt', 'callback_data' => 'cmd_submit']]
        ]
    ];

    apiRequest("sendMessage", [
        'chat_id' => $chat_id,
        'text' => $msg,
        'parse_mode' => 'HTML',
        'reply_markup' => $kb
    ]);
}

function sendDailyCheckinCard($chat_id, $telegram_id, $message_id = null, $user_name = '') {
    global $pdo;
    if (empty($user_name)) {
        $u = getUserByTelegramId($telegram_id);
        $user_name = $u['username'] ?? 'Creator';
    }

    $status = getUserCheckinStatus($telegram_id);
    $bal = getBalance($telegram_id);
    $bal_fmt = number_format($bal, 2);

    $cur_streak = $status['current_streak'];
    $next_streak = $status['next_streak'];
    $today_reward_fmt = number_format($status['today_reward'], 2);
    $next_reward_fmt = number_format($status['next_reward'], 2);
    $can_claim = $status['can_claim'];

    $clean_user = htmlspecialchars(ltrim($user_name, '@'));
    $msg = "📅 <b>DAILY REWARDS & STREAK CHECK-IN</b> 📅\n\n";
    $msg .= "👤 Creator: <b>@{$clean_user}</b>\n";
    $msg .= "💵 Wallet Balance: <b>₹{$bal_fmt}</b>\n";

    if ($cur_streak > 0) {
        $msg .= "🔥 Current Streak: <b>{$cur_streak} Day" . ($cur_streak > 1 ? "s" : "") . "</b>\n";
    } else {
        $msg .= "🔥 Current Streak: <b>0 Days</b> <i>(Start your streak today!)</i>\n";
    }

    $freeze_count = (int)($status['streak_freeze'] ?? 0);
    if ($freeze_count > 0) {
        $msg .= "🛡️ Streak Shield: <b>Active (Protected)</b>\n\n";
    } else {
        $msg .= "🛡️ Streak Shield: <i>0/1 (Earn by having 3 approved prompts)</i>\n\n";
    }

    if (!empty($status['freeze_used'])) {
        $msg .= "⚠️ 🛡️ <b>STREAK SHIELD SAVED YOU!</b> You missed yesterday, but your shield protected your streak from breaking!\n\n";
    }

    $msg .= "<b>7-Day Reward Ladder:</b>\n";
    $day_rewards = [
        1 => '0.10',
        2 => '0.15',
        3 => '0.20',
        4 => '0.25',
        5 => '0.30',
        6 => '0.35',
        7 => '0.40'
    ];

    $highlight_day = $can_claim ? $next_streak : $cur_streak;
    $cycle_day = min(7, max(1, $highlight_day));

    foreach ($day_rewards as $day_num => $day_amt) {
        $icon = '⚪';
        $tag = '';
        if ($can_claim) {
            if ($day_num < $cycle_day) {
                $icon = '✅';
            } elseif ($day_num === $cycle_day) {
                $icon = '👉';
                $tag = ' <b>(Today)</b>';
            }
        } else {
            if ($day_num <= $cycle_day) {
                $icon = '✅';
            }
        }

        if ($day_num === 7) {
            $msg .= "{$icon} <b>Day 7+:</b> ₹{$day_amt} / day 👑 <i>(Max Tier)</i>{$tag}\n";
        } else {
            $msg .= "{$icon} <b>Day {$day_num}:</b> ₹{$day_amt}{$tag}\n";
        }
    }

    $msg .= "\n";
    if ($cur_streak >= 7) {
        $msg .= "👑 <b>MAX TIER UNLOCKED!</b> You earn <b>₹0.40 every day</b> as long as your streak stays active!\n\n";
    }

    if ($can_claim) {
        $msg .= "🎁 <b>Today's Reward:</b> +<b>₹{$today_reward_fmt}</b>\n";
        $msg .= "<i>Tap the claim button below to collect your reward!</i>\n";
    } else {
        $msg .= "✅ <b>You've claimed today's check-in! (+₹{$today_reward_fmt})</b>\n";
        $msg .= "⏳ Come back tomorrow after <b>12:00 AM IST</b> to claim <b>Day {$next_streak} (+₹{$next_reward_fmt})</b>!\n";
        $msg .= "⚠️ <i>Missing a day resets your streak back to Day 1.</i>\n";
    }

    $buttons = [];
    if ($can_claim) {
        $buttons[] = [
            ['text' => "🎁 Claim Day {$next_streak} Bonus (+₹{$today_reward_fmt})", 'callback_data' => 'claim_checkin']
        ];
    } else {
        $buttons[] = [
            ['text' => "✅ Claimed Today (Next: +₹{$next_reward_fmt})", 'callback_data' => 'checkin_already_claimed']
        ];
    }

    $buttons[] = [
        ['text' => '💰 My Balance', 'callback_data' => 'cmd_balance'],
        ['text' => '🚀 Submit Prompt', 'callback_data' => 'cmd_submit']
    ];
    $buttons[] = [
        ['text' => '🏠 Main Menu', 'callback_data' => 'cmd_start']
    ];

    $keyboard = ['inline_keyboard' => $buttons];

    if ($message_id) {
        sendOrEditStepMessage($chat_id, $message_id, $msg, $keyboard);
    } else {
        apiRequest("sendMessage", [
            'chat_id'                  => $chat_id,
            'text'                     => $msg,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => $keyboard
        ]);
    }
}
