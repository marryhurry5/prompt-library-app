# BRAIN.md - Prompt Marketplace Bot (Complete System Reference)

PURPOSE: Single source of truth for any AI or developer on this project.
Read this file to get full context without needing to read the codebase.

Project Owner: Nihal
Last Updated: August 27, 2026
Server: https://rtmcreator.com/bots/prompt-bot/
Local Dev Path: C:\Users\nihal\Downloads\prompt-bot-10\

==============================================================================
1. PROJECT OVERVIEW
==============================================================================

Telegram Bot-powered Prompt Marketplace:
- Users submit AI prompts (image/video/text) via bot
- Admins review and approve / reject (with feedback) / schedule posts
- Approved posts auto-publish to @ai_prompt_store channel
- Users earn INR balance: approvals, challenges, referrals
- Users withdraw earnings via UPI
- Admin Wallet Management: Admins can credit (+), deduct (-), and inspect user wallet profiles
- Support Ticket Hub: Users open support tickets, admins reply 1-tap from /admin
- Maintenance Mode: Admin controlled 1-click system maintenance mode with custom notice
- Auto-Reconnecting PDO: Resilient MySQL connection handling with auto-retry on 2006/2013 errors
- Public website (library.php) = searchable prompt gallery with top maintenance banner
- Admin calendar (calendar.php) = view all upcoming scheduled posts

==============================================================================
2. TECHNOLOGY STACK
==============================================================================

Bot Backend:  PHP procedural (no framework) with AutoReconnectPDO wrapper
Database:     MySQL via PDO (Auto-Reconnecting PDO Wrapper)
Bot API:      Telegram Bot API, webhook mode with 200 OK top-level try/catch loop shield
Hosting:      Hostinger / cPanel
Media:        Local /uploads/ folder on server
Cron:         cPanel cron every minute -> cron.php
Timezone:     Asia/Kolkata (IST) set in both PHP and MySQL session
Frontend:     Vanilla HTML/CSS/JS (library.php, calendar.php)
Font:         Google Fonts - Outfit
Integrations: Google Sheets Live Logging (02_MONEY_TRANSACTIONS, 10_SUPPORT_TICKETS)

==============================================================================
3. CONFIGURATION AND CONSTANTS (config.php)
==============================================================================

BOT_TOKEN         = 8576079546:AAH7cS1kjW6T0Szj0TflDHiTuJx9e8Z55yM
API_URL           = https://api.telegram.org/bot{TOKEN}/
ADMIN_ID          = 1655174950  (primary super-admin Telegram ID)
CHANNEL_ID        = -1003798481031 (numeric ID for API calls)
CHANNEL_USERNAME  = ai_prompt_store (for t.me links)
DB_HOST           = localhost
DB_USER           = u414504879_botuser
DB_PASS           = Nihal@2711
DB_NAME           = u414504879_promptbot

NOT in config.php (defined manually per file):
  BOT_BASE_URL = https://rtmcreator.com/bots/prompt-bot/
  Cron secret  = rtmcreator_cron_2711 (in cron.php)
  Google Sheets Web App = https://script.google.com/macros/s/AKfycbw-CeHGfPAL0bFppWxOu7c9exne_HfCN7ZjIurqw-xcRv86-x_TnyjqF8h6JTW6lO047w/exec

==============================================================================
4. FILE STRUCTURE
==============================================================================

  config.php          All constants (token, DB creds, channel, admin ID)
  db.php              AutoReconnectPDO + ALL DB helper functions + auto-migration
  webhook.php         Main Telegram webhook handler (~5100+ lines, loop shield & admin panels)
  cron.php            Scheduled post publisher (cPanel cron every minute)
  library.php         Public gallery website (SSR + client-side JS + maintenance banner)
  calendar.php        Admin calendar for scheduled posts (dark-mode web UI)
  api.php             JSON API: approved prompts (used by library.php)
  api_calendar.php    JSON API: scheduled posts (used by calendar.php)
  pay.php             Payment/withdrawal processing page
  sitemap.php         Auto-generated XML sitemap
  BRAIN.md            THIS FILE - full project reference
  last_weekly_post.txt Tracks when weekly top-creators post last ran
  uploads/            Downloaded media files from Telegram

==============================================================================
5. DATABASE SCHEMA (8 tables, auto-created/migrated in db.php on each load)
==============================================================================

TABLE: users
  telegram_id             BIGINT PK
  username                VARCHAR
  step                    VARCHAR      <- current state machine step
  credits                 INT          <- legacy, mostly unused
  balance                 DECIMAL(10,2)
  referred_by             BIGINT NULL  <- telegram_id of whoever referred them
  referral_reward_given   TINYINT(1)   <- 0=not yet, 1=done (prevents double payout)
  is_blocked              TINYINT(1)   <- unblocked auto on any re-interaction
  is_banned               TINYINT(1)   <- banned user flag
  ban_reason              TEXT NULL
  streak_count            INT          <- current active daily checkin streak
  last_checkin_date       DATE NULL    <- last checkin date in YYYY-MM-DD (IST)
  streak_freeze_count     INT          <- active streak freeze shields (max 1)
  freezes_awarded_at      INT          <- approved posts count when freeze was last awarded
  created_at              TIMESTAMP
  last_active             TIMESTAMP    <- auto-updated on every incoming message

TABLE: submissions
  id                      INT PK AUTO_INCREMENT
  telegram_id             BIGINT
  category                VARCHAR      e.g. AI Art, ChatGPT Prompt
  output_type             VARCHAR      Image / Video / Text
  file_id                 TEXT         Telegram file_id (for re-sends via API)
  local_media             TEXT         Relative path e.g. uploads/abc.jpg
  text_output             TEXT         Prompt title/caption shown publicly
  prompt                  TEXT         The actual AI prompt text
  tags                    TEXT         Comma-separated tags
  status                  VARCHAR      draft -> pending -> approved OR rejected
  feedback                TEXT NULL    Admin rejection reason text
  scheduled_at            TIMESTAMP NULL  NULL=post immediately; set=wait for cron
  posted_to_channel       TINYINT(1)   0=waiting to be posted by cron
  channel_message_id      BIGINT NULL  Telegram message ID after posting
  is_challenge            TINYINT(1)   1=matched challenge keyword=earns double
  created_at              TIMESTAMP

TABLE: support_tickets
  id                      INT PK AUTO_INCREMENT
  telegram_id             BIGINT
  username                VARCHAR
  category                VARCHAR
  message                 TEXT
  status                  VARCHAR(20)   open / closed / resolved
  created_at              TIMESTAMP
  resolved_at             TIMESTAMP NULL

TABLE: daily_checkins
  id                      INT PK AUTO_INCREMENT
  telegram_id             BIGINT
  checkin_date            DATE
  streak                  INT          <- consecutive day streak
  reward_amount           DECIMAL(10,2)<- INR credited
  created_at              TIMESTAMP
  UNIQUE KEY: (telegram_id, checkin_date)

TABLE: settings  (flexible key-value config store)
  setting_key             VARCHAR(100) PK
  setting_value           TEXT
  CURRENT KEYS IN USE:
    referral_reward_amount   default "1.00"   (referral payout per user)
    prompt_reward_amount     default "0.50"   (per approved prompt)
    maintenance_mode         default "0"      (0=off, 1=on)
    maintenance_reason       default "Upgrading system servers for better performance."

==============================================================================
6. SUBMISSION STATE MACHINE (users.step column)
==============================================================================

USER SUBMISSION FLOW:
  none
    -> /submit pressed
  awaiting_category     (category selection)
  awaiting_title        (text title input - auto-sanitizes double spaces to single space)
  awaiting_output_type  (callback: type_Image / type_Video / type_Text or direct media upload)
  awaiting_output       (send photo, video)
  awaiting_prompt       (text - the AI prompt used, auto-collapses double spaces into single while keeping newlines)
  awaiting_tags         (text - comma-separated tags)
  preview               (callback: submit_confirm OR submit_cancel)
    ON CONFIRM: status=pending, auto-run checkChallengeMatch(), notify ALL admins
  none

  * Space Sanitization (`sanitizeSpaces()` in db.php): Automatically compresses accidental double/multiple spaces (`  ` -> ` `) in titles and prompts (both during submission and admin editing), while preserving all intentional prompt line breaks and formatting.

WITHDRAWAL FLOW:
  none -> /withdraw -> awaiting_upi -> user enters UPI ID -> none

ADMIN WALLET STEPS:
  admin_awaiting_w_credit_target   user input (@username or telegram_id)
  admin_awaiting_w_credit_amount   numeric amount to add
  admin_awaiting_w_debit_target    user input (@username or telegram_id)
  admin_awaiting_w_debit_amount    numeric amount to deduct
  admin_awaiting_w_check_target    user input (@username or telegram_id)

ADMIN MAINTENANCE & TICKET STEPS:
  admin_awaiting_maint_notice      custom maintenance notice text
  admin_awaiting_ticket_reply_ID   admin reply text to support ticket ID

==============================================================================
7. ALL COMMANDS REFERENCE
==============================================================================

USER COMMANDS:
  /start        Register/welcome, handle referral via ?start=ref_XXXXX
  /daily        Open Daily Check-In & Streak Reward card
  /checkin      Same as /daily
  /help         How it works + earning rates (dynamic from settings)
  /submit       Begin prompt submission flow
  /profile      User stats: approved/rejected count, balance, rank
  /balance      Show balance, total withdrawn, lifetime earnings, rank + Withdraw button
  /credits      Same as /balance
  /withdraw     UPI withdrawal request (minimum INR 20)
  /refer        Show referral link + total count + current reward amount
  /support      Open Customer Support Ticket menu
  /leaderboard  Top 10 earners by lifetime balance
  /cancel       Cancel any in-progress multi-step flow

ADMIN-ONLY COMMANDS:
  /admin        Open Admin Control Hub (Wallet, Tickets, Maintenance, Pending, Stats, Broadcast, Challenges)
  /stats        Quick text stats dump
  /pending      List all pending submissions
  /admins       List all admin telegram IDs
  /referrals    Detailed referral stats leaderboard

==============================================================================
8. ADMIN CONTROL HUB & PANELS (/admin)
==============================================================================

  💳 User Wallet Management:
    - Credit Wallet (+ money) with instant user notification & Google Sheets logging
    - Debit Wallet (- money) with zero-floor cap, user notification & Google Sheets logging
    - User Wallet Profile Check (Balance, Total Withdrawn, Lifetime Earnings, Rank, Banned status)

  📩 Support Tickets Hub:
    - Filter Open vs All Tickets with live open badge counter
    - Detailed ticket inspection view
    - 1-Tap User Reply button
    - Resolve & Close Ticket action buttons

  🛠️ Maintenance Mode Control:
    - 1-Click Toggle ON / OFF
    - Edit Custom Maintenance Notice
    - Security Guards blocking non-admin user actions during maintenance
    - Admin bypass for full maintenance-time testing
    - Top notification banner on library.php

==============================================================================
9. WITHDRAWAL VS. LIFETIME EARNINGS RULE
==============================================================================

  - Available Wallet Balance (users.balance): Deducted upon withdrawal request (min ₹20.00).
  - Lifetime Earnings: Calculated as (users.balance + COALESCE(total_withdrawn, 0)).
  - Creator Rank: Preserved 100% forever based on Lifetime Earnings.
  - /balance Dashboard displays: Available Balance, Total Withdrawn, Lifetime Earnings, and Creator Rank.

==============================================================================
10. SESSION-BY-SESSION CHANGELOG
==============================================================================

### August 26-27, 2026 (Session 8 Complete)
  - AutoReconnectPDO: Built AutoReconnectPDO class in db.php to automatically ping, reconnect, and retry database operations when Hostinger MySQL connection drops (error 2006/2013 Server has gone away).
  - Admin Wallet Management: Added Credit, Debit, and User Wallet Profile inspection in /admin with live Telegram alerts and Google Sheets (02_MONEY_TRANSACTIONS) logging.
  - Support Tickets Hub: Added 📩 Support Tickets panel with live badge count, Open/History filter, 1-tap user reply, resolve/close actions, and Google Sheets (10_SUPPORT_TICKETS) logging.
  - Admin Maintenance Mode: Added 🛠️ Maintenance hub to /admin with 1-click ON/OFF toggle, custom notice editor, non-admin security guards, admin bypass, and website notification banner on library.php.
  - Webhook Loop Shield: Added top-level http_response_code(200) and try/catch block to guarantee Telegram never retries webhook requests in loops.
  - Submission Flow Fix: Added missing awaiting_output_type handler in processMessage() with smart direct photo/video upload detection and draft auto-creation safety net.
  - Lifetime Earnings Rule: Confirmed withdrawals only deduct spendable balance while preserving Lifetime Earnings and Creator Rank forever. Updated /balance dashboard.
  admin_panel_stats        Full stats (users, submissions, breakdown)
  admin_panel_broadcast    Start broadcast flow
  admin_panel_withdrawals  List pending withdrawal requests
  admin_panel_categories   Manage prompt categories
  admin_panel_admins       Add/remove admin users
  admin_panel_referrals    Referral leaderboard
  admin_panel_recent       Last 5 approved posts
  admin_panel_challenge    Manage challenge keywords (add/remove/clear)
  admin_panel_ref_reward   Set referral payout amount dynamically
  admin_panel_calendar     View scheduled posts in Telegram + link to web calendar
  admin_panel_back         Return to main admin panel

PER-SUBMISSION REVIEW CALLBACKS:
  approve_POSTID   Post to channel immediately, pay INR 0.50 (1.00 if trending)
  schedule_POSTID  Admin enters DD/MM/YYYY HH:MM, post later via cron
  reject_POSTID    Admin types feedback text, user notified with reason
  skip_POSTID      Show next pending submission without action

==============================================================================
8. CHALLENGE / TRENDING SYSTEM
==============================================================================

HOW IT WORKS:
  1. Admin adds keywords in admin_panel_challenge (stored in challenges table)
  2. When user confirms submission (submit_confirm callback):
     checkChallengeMatch(title, prompt, tags) runs automatically
  3. If any active keyword found (case-insensitive, UTF-8, anywhere):
     submissions.is_challenge = 1
  4. Challenge posts earn DOUBLE reward (INR 1.00 vs normal INR 0.50)
  5. Trending badge shown on: channel post, admin review, website card, calendar

CHALLENGE MANAGEMENT CALLBACKS (admin_ch_* prefix):
  admin_ch_add        Prompt admin for keywords (enters admin_awaiting_challenge_theme)
  admin_ch_del_ID     Deactivate challenge keyword by ID
  admin_ch_clear      Deactivate ALL active challenge keywords

NOTIFICATION: When new keywords added, ALL users get a broadcast notification.

IMPORTANT: Challenge bonus currently uses same value as referral_reward_amount setting.
To separate: add key "challenge_bonus_amount" to the settings table.

==============================================================================
9. REFERRAL SYSTEM
==============================================================================

FLOW:
  1. User A: /refer -> link = t.me/Prompts_library_bot?start=ref_{A_ID}
  2. User B clicks link -> /start ref_XXXXX -> setReferrer(B, A) -> referred_by=A
  3. Referrer A notified: "User B joined, you both earn INR X.XX"
  4. When B gets FIRST APPROVED POST AND IS A CHANNEL MEMBER:
     handleReferralReward(B) called (inside approval logic in webhook.php)
  5. Both A and B get addBalance with getSetting("referral_reward_amount", "1.00")
  6. B gets referral_reward_given=1 (prevents double payout)

DYNAMIC AMOUNT:
  Admin Panel -> Ref Reward -> admin types number (0.01 to 100.00)
  Stored in: settings table, key = referral_reward_amount
  ALL messages read this dynamically via getSetting():
    /help earnings section, /refer command, /start referral notification,
    reward notification to both users

==============================================================================
10. WITHDRAWAL SYSTEM
==============================================================================

  Minimum: INR 20
  Flow: /withdraw -> awaiting_upi step -> user enters UPI ID
  createWithdrawal() saves record, ALL admins notified with buttons
  Admin: wd_pay_ID   -> paid, deduct balance, notify user
  Admin: wd_reject_ID -> rejected, notify user

==============================================================================
11. SCHEDULED POSTS AND CALENDAR
==============================================================================

SCHEDULING (admin during submission review):
  Admin clicks schedule_POSTID -> enters "DD/MM/YYYY HH:MM"
  DB: scheduled_at = that datetime, status=approved, posted_to_channel=0
  Post sits in DB until cron picks it up

CRON PUBLISHING (cron.php runs every minute via cPanel):
  getScheduledPosts() = WHERE status=approved AND posted_to_channel=0 AND scheduled_at<=NOW_IST
  For each due post:
    Post media (photo/video) or text to CHANNEL_ID
    Save channel_message_id in DB
    markAsPosted() -> posted_to_channel=1
    Award balance (INR 0.50, or 1.00 if is_challenge=1)
    handleReferralReward() for the creator
    Notify creator that post went live

WEB CALENDAR (calendar.php):
  Data: getAllScheduledPosts() from db.php (approved, not posted, has scheduled_at)
  Month view: 7-column grid, posts appear as colored pills on scheduled day
    Purple pills = normal posts
    Red pills    = trending/challenge posts (is_challenge=1)
  Click day or pill -> modal popup with title, IST time, creator, category, media
  List view: posts grouped by date with thumbnail, time, category badge
  Stats strip: Total / Posting Today / This Week / Trending counts
  Navigation: Prev month, Next month, Today buttons
  ESC key closes modal
  JSON API: api_calendar.php returns { success: true, posts: [...], count: N }
    Fields per post: id, title, category, output_type, scheduled_at, username, is_challenge, media_url

TELEGRAM CALENDAR (admin_panel_calendar callback):
  Text list of upcoming posts grouped by date
  Shows: time, title (max 35 chars), creator @username, fire emoji if trending
  Includes button: "Open Web Calendar" -> links to calendar.php URL

==============================================================================
12. NOTIFICATIONS SYSTEM
==============================================================================

USER NOTIFICATIONS:
  Submission confirmed    -> "Your submission is under review"
  Submission approved     -> "Approved! +INR 0.50 (or 1.00 if trending)"
  Submission rejected     -> "Rejected" + admin feedback text
  Referral joined         -> "User X joined with your link, you earn INR X.XX"
  Referral reward earned  -> "You earned INR X.XX referral bonus"
  Withdrawal paid         -> "Your withdrawal was processed"
  New challenge keyword   -> All users broadcast: "New Challenge: keyword"

ADMIN NOTIFICATIONS:
  New submission          -> ALL admins get message with media + buttons
  Withdrawal request      -> ALL admins notified with Pay/Reject buttons
  New admin added         -> New admin gets welcome message
  API error               -> ADMIN_ID auto-alerted (loop-safe: wont alert if error is IN that alert)

BROADCAST SYSTEM:
  Admin -> broadcast -> send any message (text/photo/video)
  Audience: All Users / Active 7d / Inactive / Top Earners (credits>=50)
  50ms delay between sends, sleep(1) on rate limit errors

==============================================================================
13. WEBSITE / LIBRARY (library.php)
==============================================================================

URL: https://rtmcreator.com/bots/prompt-bot/library.php

FEATURES:
  SSR: PHP renders initial HTML with approved prompts (for SEO)
  Search: Client-side real-time filter by title/category/username
  Filters: Category dropdown, sort by Newest/Trending/All
  Cards: thumbnail or video, title, category badge, creator, date
  Trending badge: shown if is_challenge=1 (CSS class: .pl-trending-badge)
  Lightbox: click image to expand full screen
  Load More: JS-based pagination
  Dark mode design with purple accent, Outfit font, glassmorphism

API endpoint: api.php
  JSON: { success: true, prompts: [...] }
  Fields: id, text_output, category, output_type, local_media, username, is_challenge, created_at

==============================================================================
14. CALLBACK ROUTING ARCHITECTURE (CRITICAL)
==============================================================================

All callbacks handled in processCallbackQuery() in webhook.php.

ROUTING BY PREFIX:
  admin_panel_*  Admin panel action handlers
  admin_ch_*     Challenge keyword management
  admin_cat_*    Category management
  admin_mgmt_*   Admin user management
  approve_*      Approve a submission
  reject_*       Reject a submission
  schedule_*     Schedule a submission
  skip_*         Skip pending submission
  wd_pay_*       Pay a withdrawal
  wd_reject_*    Reject a withdrawal
  submit_*       Submission step callbacks
  gallery_*      User gallery pagination
  cmd_cancel     Universal cancel button

CRITICAL BYPASS RULE:
  There is a step-intercept check near the top of processCallbackQuery().
  When a user is in a step (e.g. awaiting_upi), some callbacks get intercepted.
  The following prefixes BYPASS this check and always reach their handlers:
    admin_panel_*, admin_cat_*, admin_mgmt_*, admin_ch_*

  IF YOU ADD A NEW ADMIN CALLBACK PREFIX:
  Find the bypass block by searching for: strpos(data, admin_panel_) === 0
  Add the new prefix to that OR chain.

==============================================================================
15. KEY CODE PATTERNS
==============================================================================

apiRequest(method, params, silent=false)
  Defined in BOTH webhook.php and cron.php (standalone copy needed in cron)
  On any error: auto-alerts ADMIN_ID (loop-protected to avoid infinite alert loop)

updateUserStep(telegram_id, step)
  The state machine driver. ALWAYS reset to "none" after a step completes.

getSetting(key, default)
  Reads settings table. Returns default if key not found or DB error. Safe.

setSetting(key, value)
  Upserts: INSERT ... ON DUPLICATE KEY UPDATE

checkChallengeMatch(title, prompt, tags) returns string or false
  Called automatically on submit_confirm callback.

isAdmin(telegram_id) returns bool
  True if == ADMIN_ID constant OR row exists in admins table.

Media URL construction pattern:
  DB stores:  uploads/filename.jpg  (relative path)
  Full URL:   rtrim(BOT_BASE_URL, "/") . "/" . ltrim(local_media, "/")

WordPress conflict safety in db.php:
  db.php detects if ABSPATH is defined (WordPress loaded) and reads config.php
  manually via regex instead of require, avoiding PHP "constant already defined" errors.

==============================================================================
16. EARNING RATES
==============================================================================

  Normal post approved:    +INR 0.50
  Challenge post approved: +INR 1.00 (double; reads referral_reward_amount from settings)
  Referral reward:         +INR X.XX each side (dynamic; key=referral_reward_amount; default=1.00)
  Daily Check-In Streak:   Day 1: ₹0.10, Day 2: ₹0.15, Day 3: ₹0.20, Day 4: ₹0.25, Day 5: ₹0.30, Day 6: ₹0.35, Day 7+: ₹0.40/day (continuous)
  Minimum withdrawal:      INR 20.00

==============================================================================
17. SESSION-BY-SESSION CHANGELOG
==============================================================================

April 2026 - Initial Build
  /start, /help, /submit, /balance, /withdraw
  users + submissions tables
  Channel posting on approval

May 2026 - Media + Scheduling
  Local media download/storage to /uploads/ (not just file_id)
  Admin scheduling with date/time entry
  cron.php for auto-publishing due posts
  library.php public gallery website with SSR
  Categories system with default seed data

June 2026 - Referrals + Economy
  Referral system with deep links (?start=ref_XXXXX)
  balance field on users, UPI withdrawal system
  Leaderboard, detailed referral stats
  Broadcast system (audience: all/active/inactive/top)
  Weekly top creators auto-post to channel

July 2026 - UX Polish
  Rejection feedback (admin types reason, user gets it in notification)
  pay.php payment processing page
  User gallery view (/gallery command)

August 11, 2026 - Challenge / Trending System
  challenges table + keyword-based challenge management
  checkChallengeMatch() auto-detection on submit_confirm callback
  Admin: add multiple keywords, remove individually, clear all
  Double reward (INR 1.00) for challenge-matched posts
  Trending badges everywhere: channel post, admin review, website cards, calendar
  Broadcast to all users when new challenge keywords are added
  admin_ch_* callbacks added to bypass list in processCallbackQuery()

August 13, 2026 - Calendar + Dynamic Settings
  settings table created in db.php
  getSetting() and setSetting() helper functions added to db.php
  getAllScheduledPosts() function added to db.php
  calendar.php: full dark-mode web calendar (month view + list view + modals)
  api_calendar.php: JSON endpoint returning upcoming scheduled posts
  Admin Panel: new Calendar button (Telegram text list + web calendar URL button)
  Admin Panel: new Ref Reward button -> set referral payout dynamically
  admin_awaiting_ref_reward step handler added to webhook.php
  handleReferralReward() now uses getSetting() instead of hardcoded 1.00
  All user-facing referral messages now read amount dynamically:
    /start referral notification, /help earnings, /refer command, reward messages

August 26-31, 2026 - Session 8 Complete System Upgrades & Flutter Mobile App
  - 1-Tap Test Push Notification Button: Added 🔔 Notification Bell icon to HomeScreen AppBar. Tapping it calls NotificationService.showNewPromptNotification() to immediately send a real system push notification to the user's notification bar!
  - Replace Image / Media in Telegram Bot: Added 🖼️ Replace Image / Media option to Telegram Bot Admin Edit Menu in webhook.php. Admin can send a new photo or video for any post; the bot automatically deletes the old image file from the server disk using unlink(), downloads the new media, updates file_id & local_media in MySQL, and syncs instantly with the mobile app!
  - Production Signed Release APK: Generated release keystore upload-keystore.jks (CN=NihalYadav, OU=AIPromptHub), configured key.properties and android/app/build.gradle with package name com.example.prompt_library_app matching Amazon Appstore listing lock. Resolves Amazon Appstore Debug Certificate rejection!
  - Creator Profile Screens: Added creator_profile_screen.dart displaying Creator Rank Badges (⭐ Master Creator, 🎨 Pro Creator, 🚀 Rising Creator), Avatar, Total Prompts, Total Likes, Total Copies, and a dedicated grid of prompts by that creator. Tapping any @username opens their profile screen!
  - Live Prompt Copy Counter: Auto-migrated copies column in submissions table, added POST ?action=copy_prompt endpoint in api.php, and added live copy counter button + 🔥 Popular badge for 50+ copy prompts.
  - Daily Push Notification Digest: Added scheduleDailyEveningDigest in NotificationService triggering evening notifications "🔥 Top Prompt of the Day is Live!" at 7:00 PM IST.
  - 1-Person 1-Like Enforcement: Enforced 1-like-per-person toggle rule in PromptProvider using SharedPreferences. Tapping like toggles between liked/unliked and syncs +1/-1 count directly with MySQL database.
  - Social Media Active Highlighting: Updated prompt_card.dart and prompt_details_screen.dart to display vibrant pink/red highlighted active container background, filled heart icon, and glowing border when liked.
  - Flutter Mobile App: Built app-debug.apk & app-release.apk (v1.0.2+3) successfully!
  - User Wallet 10-Transaction History: Added money_transactions MySQL table and showUserTransactionHistory function in webhook.php & db.php. Users can view their last 10 credit/debit transactions (+₹0.25 prompt reward, +₹1.00 referral bonus, -₹20.00 withdrawal, admin credit/debit) directly via the 📜 Last 10 Transactions button in /balance or by sending /transactions.
  - Scheduled Prompts Filter: Updated api.php SQL query to enforce (s.scheduled_at IS NULL OR s.scheduled_at <= NOW()). Scheduled prompts now show ONLY after their scheduled time.
  - New Prompt System Push Notifications: Built NotificationService with flutter_local_notifications triggering system notification "Hey! A new prompt has just been uploaded. Do you wanna check? 🚀" whenever new prompts go live.
  - 16:9 Widescreen Category Cards: Configured category cards to render in 16:9 aspect ratio while keeping prompt cards in 1:1 square ratio.
  - Submit & Earn Money FAB: Updated Floating Action Button label to Submit Prompt & Earn Money 💰.
  - Real Email OTP Delivery: Integrated PHP mail() in action=forgot_password sending HTML verification email from noreply@rtmcreator.com. No pre-filled OTP.
  - Auto-Updater Download Fix: Added action=download_apk in api.php with Content-Type: application/vnd.android.package-archive headers. Eliminates 100% download stuck issues.
  - Live UPI VPA (nihalyadav9860@oksbi): Configured automated ₹29 PRO payment gateway to pay directly to nihalyadav9860@oksbi.
  - Automated ₹29 PRO Payments: Integrated verify_pro_payment endpoint & pro_payments table. Automatically unlocks 30-day PRO status upon successful payment!
  - Working Forgot Password OTP: Added forgot_password & reset_password endpoints with 6-digit OTP verification and BCRYPT password hash updating.
  - User Login, Signup & Guest: Created UserAuthScreen & UserProvider (Login, Signup, and 1-tap Continue as Guest).
  - Single Universal Compact APK (25.0 MB): Built universal optimized release APK with standard filenames app-release.apk and app-debug.apk (25.0 MB down from 50MB).
  - 78% Split APK Size Reduction (11.0 MB): Enabled ProGuard R8 code shrinking (minifyEnabled true, shrinkResources true), tree-shook fonts (MaterialIcons reduced by 99.3%), and compiled architecture-split release APKs (app-arm64-v8a-release.apk @ 11.0 MB).
  - 4-Tab Bottom Navigation & Profile: Built MainNavigationScreen (Home, Shop, Favorites, Profile). ProfileScreen contains Privacy Policy, Terms & Conditions, About Us, Contact Us, Admin Portal link, and App Version (v1.0.1+2).
  - Upgrade to PRO (₹29/mo): Created ProProvider & ProUpgradeScreen (₹29/month). Enables 100% Ad-Free experience (hides banner ads) & Priority Support via direct UPI payment launcher.
  - Digital Shop & Admin Auth: Added AdminAuthScreen (Email & BCRYPT password encryption login/register), AdminShopScreen, and Product Editing (_showEditProductDialog + edit_shop_item).
  - Version 1.0.1+2 Alignment: Recompiled APK with internal version 1.0.1+2 matching Hostinger api.php latest_version 1.0.1 to eliminate repeating update popup loops.
  - 4 Major Features: Added FavoritesProvider & FavoritesScreen (❤️), Open in AI Tools (ChatGPT, Bing AI, Claude), Share via share_plus (📤), and NotificationService (🔔).
  - Live In-App Auto-Updater: Integrated UpdateService, package_info_plus, VersionModel, and api.php version_check endpoint. Built app-debug.apk with live updater listener.
  - Production AdMob Credentials: Bound official production AdMob App ID (ca-app-pub-5860757655925932~8256186504) and Rewarded Ad Unit ID (ca-app-pub-5860757655925932/6312549441).
  - AdMob Banner & Rewarded Ads: Added sticky bottom BannerAdWidget to home_screen.dart and 3-attempt progressive retry loader in AdMobService for Rewarded Ads.
  - Temurin JDK 17 & Gradle 8.4: Resolved Java 25 compatibility by binding org.gradle.java.home to Eclipse Adoptium JDK 17 with Gradle 8.4 & AGP 8.1.0.
  - Submit Prompt FAB: Direct URL launcher redirect to t.me/Prompts_library_bot.
  - AutoReconnectPDO: Built AutoReconnectPDO class in db.php to automatically ping, reconnect, and retry database operations when Hostinger MySQL connection drops (error 2006/2013 Server has gone away).
  - Admin Wallet Management: Added Credit (+), Debit (-), and User Wallet Profile inspection in /admin with live Telegram alerts and Google Sheets (02_MONEY_TRANSACTIONS) logging.
  - Support Tickets Hub: Added 📩 Support Tickets panel with live badge count, Open/History filter, 1-tap user reply, resolve/close actions, and Google Sheets (10_SUPPORT_TICKETS) logging.
  - Admin Maintenance Mode: Added 🛠️ Maintenance hub to /admin with 1-click ON/OFF toggle, custom notice editor, non-admin security guards, admin bypass, and website notification banner on library.php.
  - Webhook Loop Shield: Added top-level http_response_code(200) and try/catch block to guarantee Telegram never retries webhook requests in loops.
  - Submission Flow Fix: Added missing awaiting_output_type handler in processMessage() with smart direct photo/video upload detection and draft auto-creation safety net.
  - Lifetime Earnings Rule: Confirmed withdrawals only deduct spendable balance while preserving Lifetime Earnings and Creator Rank forever. Updated /balance dashboard.

### September 8-9, 2026 - Session 9 (Daily Check-In, Streak Freeze Shield & 8 PM Cron Reminder)
  - Daily Check-In & Streak Engine: Added /daily and /checkin commands and interactive Telegram card with real-time 7-day visual progression ladder.
  - Progressive & Continuous Reward Model: Day 1 (₹0.10), Day 2 (₹0.15), Day 3 (₹0.20), Day 4 (₹0.25), Day 5 (₹0.30), Day 6 (₹0.35), Day 7+ (₹0.40). Users who maintain streaks beyond Day 7 continually earn ₹0.40/day.
  - Streak Break Protection: Missing a calendar day (IST) resets streak back to Day 1 unless protected by a Streak Freeze shield.
  - 🛡️ Streak Freeze Shield: Earned automatically every 3 approved prompts (holding max 1 active shield). If a creator misses 1 day, the shield is consumed automatically to save their streak and rewards from breaking!
  - ⏰ 8:00 PM IST Cron Reminder: Automated background reminder engine in cron.php dispatching daily at 8:00 PM IST targeting active streak creators who haven't claimed today.
  - Channel Join Guard: Mandatory @ai_prompt_store channel membership check before claiming.
  - Database Schema & Deduplication: Added daily_checkins table with UNIQUE KEY (telegram_id, checkin_date) preventing race condition double-claims, and added streak_count, last_checkin_date, streak_freeze_count, freezes_awarded_at to users table.
### September 9, 2026 - Session 10 (Double Space Auto-Sanitizer for Title & Prompt)
  - sanitizeSpaces() Engine: Created smart regex whitespace compression helper in db.php.
  - Title Auto-Fit: Single-line titles automatically collapse multiple spaces, tabs, and accidental newlines into a single clean space (`  ` ➔ ` `), trimmed cleanly.
  - Prompt Multiline Formatting Preservation: Prompts automatically collapse horizontal multiple spaces and tabs into a single space, strips trailing spaces per line, while strictly preserving user line breaks, paragraphs, and multi-line AI parameters (e.g. `--ar 16:9 --v 6.0`).
### September 12, 2026 - Session 11 (Google Play Store & Amazon Appstore Compliance Upgrade)
  - Production Package Identifier: Replaced placeholder com.example.prompt_library_app with official com.rtmcreator.promptlibrary across android/app/build.gradle and Kotlin source.
  - Android 14 & 15 Compliance: Enforced targetSdk = 35 and compileSdk = 35.
  - Runtime Permissions: Added android.permission.POST_NOTIFICATIONS and RECEIVE_BOOT_COMPLETED in AndroidManifest.xml for Android 13+ support.
  - Network Security: Enforced android:usesCleartextTraffic="false" for encrypted HTTPS communications.
  - R8 Code Shrinking: Enabled minifyEnabled true, shrinkResources true, and custom proguard-rules.pro for smaller, protected release builds.
  - Official Hosted Privacy Policy: Created privacy.php hosted at https://rtmcreator.com/bots/prompt-bot/privacy.php complying with Google Play Console and Amazon Appstore listing guidelines. Linked in-app via "View Online" button.
  - Automated Release Script: Created build_store_release.bat and build_store_release.ps1 for 1-click generation of Google Play (.aab) and Amazon Appstore (.apk) release artifacts.

==============================================================================
18. OPEN TASKS AND FUTURE IDEAS
==============================================================================

  [ ] Separate challenge bonus from referral reward (add key: challenge_bonus_amount)
  [ ] Alert admin 1 hour before a scheduled post goes live
  [ ] Edit / delete scheduled posts from web calendar UI
  [ ] User profile page on public library website
  [ ] Admin analytics dashboard with submission/revenue charts
  [ ] Hindi language support
  [ ] Payment gateway webhook in pay.php for auto-confirming UPI transfers
  [ ] Submission editing flow before admin approval
  [ ] User badge/tier system (Bronze / Silver / Gold based on approved posts)
  [ ] Cron health monitoring (alert admin if cron hasnt run in X minutes)

==============================================================================
QUICK REFERENCE CARD
==============================================================================

  Bot Username:    Prompts_library_bot
  Channel:         @ai_prompt_store (numeric ID: -1003798481031)
  Super Admin ID:  1655174950
  Server URL:      https://rtmcreator.com/bots/prompt-bot/
  Cron URL:        /cron.php?key=rtmcreator_cron_2711
  Webhook URL:     /webhook.php
  DB Name:         u414504879_promptbot
  Timezone:        Asia/Kolkata (IST, UTC+5:30)

  EARNING RATES:
    Normal approval:    +INR 0.50
    Challenge approval: +INR 1.00 (dynamic from settings)
    Referral reward:    +INR X.XX each (dynamic from settings, default 1.00)
    Min withdrawal:     INR 20.00

  FILES TO UPLOAD AFTER ANY CHANGE:
    db.php, webhook.php, library.php, cron.php, calendar.php, api_calendar.php, BRAIN.md

==============================================================================
END OF BRAIN.md
==============================================================================




