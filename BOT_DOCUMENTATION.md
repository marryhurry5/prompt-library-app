# 📘 Master System & Workflow Documentation - Telegram Prompt Bot (@Prompts_library_bot)

Welcome to the official, exhaustive technical and operational documentation for **@Prompts_library_bot** and **@ai_prompt_store**.

This document explains **every command, every option, every button, every database column, every security guard, and every automated workflow** in complete detail.

---

## 📑 Table of Contents
1. [System Overview & Execution Model](#1-system-overview--execution-model)
2. [Complete File Structure & Responsibilities](#2-complete-file-structure--responsibilities)
3. [Exhaustive Database Schema Reference](#3-exhaustive-database-schema-reference)
4. [User Commands, Buttons & Interactive Options](#4-user-commands-buttons--interactive-options)
5. [Admin Commands, Buttons & Review Options](#5-admin-commands-buttons--review-options)
6. [Step-by-Step Feature Workflows](#6-step-by-step-feature-workflows)
   - [Workflow A: User Registration & Referral Onboarding](#workflow-a-user-registration--referral-onboarding)
   - [Workflow B: Mandatory Channel Join Guard (@ai_prompt_store)](#workflow-b-mandatory-channel-join-guard-ai_prompt_store)
   - [Workflow C: Interactive Prompt Submission Wizard (/submit)](#workflow-c-interactive-prompt-submission-wizard-submit)
   - [Workflow D: Admin Review, Edit, Approval & Publishing](#workflow-d-admin-review-edit-approval--publishing)
   - [Workflow E: Daily 5-Prompt Milestone Bonus System](#workflow-e-daily-5-prompt-milestone-bonus-system)
   - [Workflow F: Earnings & Withdrawal Request System (/withdraw)](#workflow-f-earnings--withdrawal-request-system-withdraw)
   - [Workflow G: Customer Support Ticketing Center (/support)](#workflow-g-customer-support-ticketing-center-support)
   - [Workflow H: Real-Time Non-Blocking Broadcast Engine (/broadcast)](#workflow-h-real-time-non-blocking-broadcast-engine-broadcast)
   - [Workflow I: User Banning & Security System (/ban & /unban)](#workflow-i-user-banning--security-system-ban--unban)
   - [Workflow J: Daily Check-In & Streak Reward System (/daily & /checkin)](#workflow-j-daily-check-in--streak-reward-system-daily--checkin)
7. [Automated Cron Background Engines (`cron.php`)](#7-automated-cron-background-engines-cronphp)
8. [Web Prompt Store (`library.php`) & Admin Content Calendar (`calendar.php`)](#8-web-prompt-store-libraryphp--admin-content-calendar-calendarphp)
9. [System Maintenance, Webhook Reset & Troubleshooting](#9-system-maintenance-webhook-reset--troubleshooting)

---

## 1. System Overview & Execution Model

The system operates as a **Hybrid PHP Event-Driven Architecture** integrated with the Telegram Bot API and Hostinger MySQL database.

```
                      ┌─────────────────────────────────────────┐
                      │    Telegram API (Servers & Webhook)     │
                      └────────────────────┬────────────────────┘
                                           │
                    ┌──────────────────────┴──────────────────────┐
                    ▼                                             ▼
       ┌─────────────────────────┐                   ┌─────────────────────────┐
       │   webhook.php (Event)   │                   │    cron.php (Cron)     │
       │ Real-time User & Admin  │                   │ Scheduled Dispatches &  │
       │ Handlers (<0.05s response)│                  │ Background Broadcasts   │
       └────────────┬────────────┘                   └────────────┬────────────┘
                    │                                             │
                    └──────────────────────┬──────────────────────┘
                                           ▼
                      ┌─────────────────────────────────────────┐
                      │    MySQL Database (Hostinger Server)     │
                      └────────────────────┬────────────────────┘
                                           │
                    ┌──────────────────────┴──────────────────────┐
                    ▼                                             ▼
       ┌─────────────────────────┐                   ┌─────────────────────────┐
       │   library.php (Web)     │                   │  calendar.php (Admin)   │
       │ Web Prompt Store Frontend│                  │ Content Calendar UI     │
       └─────────────────────────┘                   └─────────────────────────┘
```

* **No Persistent SSH Daemon Needed**: Standard PHP scripts execute on-demand.
* **Webhook Processing (`webhook.php`)**: Returns responses to Telegram in **<0.05 seconds**, ensuring the chat interface is fast and non-blocking.
* **Cron Task Processing (`cron.php`)**: Executes background dispatches every 1–5 minutes.

---

## 2. Complete File Structure & Responsibilities

| File Name | Description & Technical Responsibility |
| :--- | :--- |
| **`config.php`** | System configuration constants: `BOT_TOKEN`, `API_URL`, `ADMIN_ID` (1655174950), `CHANNEL_ID` (-1003798481031), `CHANNEL_USERNAME` (`ai_prompt_store`), and MySQL credentials. |
| **`db.php`** | Database layer (PDO initialization, 9 auto-created tables, database helper functions like `getUser()`, `addBalance()`, `banUser()`, `createBroadcastQueue()`, `recordReferralReward()`). |
| **`webhook.php`** | Main entry point for Telegram webhooks. Receives JSON updates from Telegram API, routes message steps, checks security guards, processes user wizards, and renders admin review panels. |
| **`cron.php`** | Automated background task runner. Triggered by server cron to publish scheduled channel posts, dispatch background broadcast batches, and award weekly top creator prizes. |
| **`library.php`** | Public Web Prompt Store (`https://rtmcreator.com/prompt-library/`). Allows users to search prompts, filter categories, and copy prompts with 1 click. |
| **`calendar.php`** | Admin Visual Content Calendar (`https://rtmcreator.com/prompt-library/calendar.php`) displaying post scheduling slots. |
| **`api.php`** | JSON API for `library.php` search, filtering, and pagination. |
| **`api_calendar.php`**| JSON API for `calendar.php` drag-and-drop / slot updates. |
| **`pay.php`** | Payment & payout integration handler. |

---

## 3. Exhaustive Database Schema Reference

The database comprises 9 tables automatically created by `initDb()` in `db.php`:

### 1. `users` Table
Stores user accounts, state parameters, balance, and security statuses.
* `telegram_id` (BIGINT, Primary Key): User's unique Telegram ID.
* `username` (VARCHAR): User's `@username` or first name.
* `step` (VARCHAR): Current wizard conversation step (e.g. `none`, `awaiting_prompt_text`, `awaiting_upi`).
* `balance` (DECIMAL 10,2): Current withdrawable earnings balance in ₹.
* `referred_by` (BIGINT): Telegram ID of the user who referred them.
* `referral_reward_given` (TINYINT): `1` if the referral bonus (+₹1.00) was paid out.
* `is_banned` (TINYINT): `1` if account is suspended by admin.
* `ban_reason` (TEXT): Stored reason for account suspension.
* `is_blocked` (TINYINT): `1` if the user blocked/bot is deactivated.
* `created_at` (TIMESTAMP): Account registration timestamp.
* `last_active` (TIMESTAMP): Updated automatically on every interaction.

### 2. `submissions` Table
Stores prompt submissions and channel publication states.
* `id` (INT, Auto Increment, Primary Key): Post Submission ID (`#123`).
* `telegram_id` (BIGINT): Creator's Telegram ID.
* `category` (VARCHAR): Category (e.g., *Photo Editing*, *AI Art*, *ChatGPT*).
* `output_type` (VARCHAR): `photo`, `video`, or `text`.
* `file_id` (TEXT): Telegram file ID for image/video media.
* `text_output` (TEXT): Title of the post.
* `prompt` (TEXT): The exact AI prompt.
* `tags` (TEXT): Hashtags (e.g., `#midjourney #portrait`).
* `status` (VARCHAR): `draft`, `pending`, `approved`, `scheduled`, `rejected`.
* `is_challenge` (TINYINT): `1` if prompt matched a trending double-reward challenge keyword.
* `scheduled_at` (DATETIME): Timestamp for scheduled channel publication in IST.
* `posted_to_channel` (TINYINT): `1` if published to `@ai_prompt_store`.
* `channel_message_id` (BIGINT): Telegram message ID in `@ai_prompt_store`.

### 3. `broadcast_queue` Table
Powers non-blocking asynchronous broadcasting.
* `id` (INT, Auto Increment, Primary Key): Broadcast Job ID (`#14`).
* `admin_id` (BIGINT): Admin who launched the broadcast.
* `chat_id` (BIGINT): Admin chat ID.
* `message_id` (BIGINT): Telegram message ID to copy.
* `status_msg_id` (BIGINT): Live status card message ID to edit in real-time.
* `audience` (VARCHAR): Target group (`all`, `active`, `inactive`, `top`).
* `button_text`, `button_url` (VARCHAR): Custom inline button text and link.
* `status` (VARCHAR): `pending`, `processing`, `completed`, `stopped`.
* `total_count` (INT): Total targeted users.
* `delivered_count` (INT): Successfully delivered count.
* `failed_count` (INT): Failed/blocked count.

### 4. `broadcast_deliveries` Table
* `id` (INT), `broadcast_id` (INT), `user_id` (BIGINT)
* `UNIQUE KEY` (`broadcast_id`, `user_id`): Prevents duplicate messages during mass broadcasts.

### 5. `daily_bonuses` Table
* `id` (INT), `telegram_id` (BIGINT), `bonus_date` (DATE), `bonus_amount` (DECIMAL 10,2)
* `UNIQUE KEY` (`telegram_id`, `bonus_date`): Guarantees user can only claim the Daily 5-Prompt Milestone Bonus once per day (IST).

### 6. `referral_rewards` Table
* `id` (INT), `referrer_id` (BIGINT), `referee_id` (BIGINT), `reward_amount` (DECIMAL 10,2), `reward_date` (DATE)
* `UNIQUE KEY` (`referrer_id`, `referee_id`): Silently enforces **Daily Max 5** and **Lifetime Max 30** referral quotas per user.

### 7. `support_tickets` Table
* `id` (INT), `telegram_id` (BIGINT), `category` (VARCHAR), `message` (TEXT), `status` (`open`, `replied`, `closed`), `admin_reply` (TEXT), `replied_by` (BIGINT).

### 8. `withdrawals` Table
* `id` (INT), `telegram_id` (BIGINT), `amount` (DECIMAL 10,2), `upi_id` (VARCHAR), `status` (`pending`, `approved`, `rejected`), `processed_by` (BIGINT).

### 9. `categories`, `challenges`, `admins`, `settings` Tables
Manage prompt categories, trending keyword challenges, co-admin permissions, and key-value system settings.

---

## 4. User Commands, Buttons & Interactive Options

### 1. `/start` Command
* **Description**: Launches the bot, registers the user, checks referral links, and displays the main menu.
* **Option `/start ref_XXXXX`**: If passed with a referral parameter, registers referral if the user account is brand new ($\le$ 5 minutes old).
* **Main Menu Options**:
  * `📝 Submit Prompt`: Triggers `/submit` wizard.
  * `🤝 Refer & Earn`: Triggers `/refer` card.
  * `💰 Balance & Withdraw`: Triggers `/withdraw` menu.
  * `🎧 Customer Support`: Triggers `/support` ticketing center.
  * `🚀 Book Promotions`: Triggers `/promote` sponsor center.

### 2. `/submit` Command (Prompt Submission Wizard)
* **Description**: Guides users step-by-step to submit their prompt for admin approval.
* **Interactive Options**:
  1. **Category Buttons**: Select category from DB (*Photo Editing*, *AI Art*, *ChatGPT*, *Thumbnail*, etc.).
  2. **Output Type Buttons**: Select format (`📷 Photo Output`, `🎥 Video Output`, `📝 Text Only`).
  3. **Media Upload**: Send image/video file.
  4. **Title Input**: Type a descriptive title *(automatically sanitizes accidental double/multiple spaces into a single space)*.
  5. **Prompt Input**: Type the exact AI prompt text *(automatically compresses double/multiple horizontal spaces into a single space while preserving line breaks and paragraph formatting)*.
  6. **Tags Input**: Type hashtags (e.g., `#midjourney #portrait`).
  7. **Submission Card Buttons**:
     * `[ ✅ Confirm & Submit ]`: Submits draft to admins for approval.
     * `[ 🔙 Back ]`: Step back.
     * `[ ❌ Cancel ]`: Cancel submission.
  * **Input Sanitization Guard (`sanitizeSpaces`)**: Both user submissions and admin edits automatically compress multiple spaces/tabs into a single space (`  ` ➔ ` `) and normalize non-breaking spaces. Prompts keep all multi-line structures intact.

### 3. `/refer` Command
* **Description**: Generates unique referral link: `https://t.me/Prompts_library_bot?start=ref_YOURID`.
* **Rules**: ₹1.00 bonus per active referral (awarded when referee gets their first prompt approved and joins channel). Daily limit: 5; Lifetime limit: 30.

### 4. `/withdraw` Command
* **Description**: View balance and request UPI payout.
* **Minimum Withdrawal**: **₹20.00**.
* **Options**:
  * Input UPI ID (e.g. `name@upi`, `number@paytm`).
  * Admin receives withdrawal approval card.

### 5. `/support` Command (Ticket Center)
* **Interactive Categories**:
  * `💰 Earnings & Withdrawals`
  * `📝 Submissions`
  * `❓ General Inquiry`
* User types their issue $\rightarrow$ Ticket created $\rightarrow$ Admins notified $\rightarrow$ Admin reply delivered directly to user chat.

### 6. `/promote` Command
* **Description**: Displays advertising packages and direct booking link:
  * `[ 🚀 Book Ads on @rtmcreator_bot ]` (`https://t.me/rtmcreator_bot`)

### 7. `/cancel` Command
* **Description**: Aborts any active step or wizard and returns user state to `none`.

---

## 5. Admin Commands, Buttons & Review Options

Admins access management features via `/admin` or direct commands:

### 1. `/admin` Control Panel Options
* `📋 Pending Submissions`: View all submissions awaiting review.
* `📂 Manage Categories`: Add or disable prompt categories.
* `🔥 Trending Challenges`: Add keyword challenges (2x Double Reward).
* `💰 Pending Withdrawals`: Review and process user payouts.
* `🚫 Banned Users Panel`: View banned accounts and 1-tap unban users.
* `👥 Manage Co-Admins`: Grant/revoke admin permissions.

### 2. Admin Submission Review Card Buttons
When a prompt is submitted, admins receive a real-time review card:

```
🚨 NEW SUBMISSION: #123 🚨
🔥 TRENDING CHALLENGE MATCH! (2x Double Reward: ₹0.50)

📌 Title: Cyberpunk Street Portrait
🏷 Category: AI Art
Prompt: [code block]
Tags: #midjourney #cyberpunk

[ ✅ Approve Now ]  [ ⏳ Schedule ]
[ 📝 Edit ]        [ ❌ Reject ]
[ 💡 Reject with Tip ]
```

* **`✅ Approve Now`**:
  1. Publishes post to `@ai_prompt_store`.
  2. Credits creator balance (+₹0.25 standard or +₹0.50 challenge match).
  3. Triggers Referral Bonus if this is creator's first approved post.
  4. Triggers Daily 5-Prompt Milestone check (+₹0.10).
  5. Reacts with ❤️ to channel post.
* **`⏳ Schedule`**: Select target time slot (e.g., *Today 6:00 PM*, *Tomorrow 9:00 AM*). Post is saved with status `'scheduled'` to be published automatically by `cron.php`.
* **`📝 Edit`**: Edit Title (`text_output`), Prompt, Tags, or Category before publishing. Updated card re-renders directly in admin chat.
* **`❌ Reject`**: Rejects submission and notifies creator.
* **`💡 Reject with Tip`**: Admin sends custom feedback tip (e.g. *"Please add negative prompts"*) delivered to creator with a `[ 🚀 Resubmit Prompt ]` button.

### 3. `/broadcast` Command & Buttons
* **Audience Selector Buttons**:
  * `🚀 All Users` | `🟢 Active (7d)` | `🔴 Inactive` | `💎 Top Creators`
* **`➕ Add Action Button`**: Attach custom CTA buttons (Button Text + URL link).
* **`🛑 Stop Broadcast`**: Halts running broadcast immediately.

### 4. Admin Management Commands
* **/ban [ID_or_@username] [reason]**: Bans user account while preserving all user data.
* **/unban [ID_or_@username]**: Unbans user account and restores full access.
* **/stopbroadcast**: Emergency halts all active broadcast queues.

---

## 6. Step-by-Step Feature Workflows

### Workflow A: User Registration & Referral Onboarding
```
User Clicks Referral Link (t.me/Prompts_library_bot?start=ref_12345)
                         │
                         ▼
             [ Check Database for User ]
                         │
        ┌────────────────┴────────────────┐
        ▼                                 ▼
 (User Already Exists)            (User Is Brand New)
        │                                 │
  Ignore Referral                 Check Account Age (≤ 5 mins)
  (Prevent Duplicate)                     │
                                  Set referred_by = 12345
                                  Notify Referrer
```

### Workflow B: Mandatory Channel Join Guard (@ai_prompt_store)
```
User Sends Message / Taps Button
                         │
                         ▼
        [ Check Membership via getChatMember ]
                         │
        ┌────────────────┴────────────────┐
        ▼                                 ▼
  (Status = Member)             (Status = Left / None)
        │                                 │
  Grant Access to               Block Access & Show
  Command / Feature             Force-Join Card
                                          │
                                [ User Joins Channel & ]
                                [ Taps "✅ I Have Joined" ]
                                          │
                                Check Membership Real-Time
                                          │
                                ┌─────────┴─────────┐
                                ▼                   ▼
                            (Joined)          (Not Joined)
                                │                   │
                          Unlock Bot          Show Alert Warning
```

### Workflow C: Interactive Prompt Submission Wizard (/submit)
```
/submit ──► Select Category ──► Select Output Type (Photo/Video/Text)
                                         │
 Confirm & Submit ◄── Enter Tags ◄── Enter Prompt ◄── Enter Title ◄── Upload Media
        │
        ▼
 Status = 'pending' ──► Notify Admins Real-Time
```

### Workflow D: Admin Review, Edit, Approval & Publishing
```
Admin Receives Review Card
            │
 ┌──────────┼──────────┬──────────┐
 ▼          ▼          ▼          ▼
[Approve] [Schedule] [Edit]    [Reject]
 │          │          │          │
 │          │          │          └─► Status = 'rejected', Notify Creator
 │          │          └─► Update Column (text_output/prompt/tags), Refresh Card
 │          └─► Status = 'scheduled', Set scheduled_at timestamp
 ▼
Publish to @ai_prompt_store
Credit Balance (+₹0.25 / +₹0.50)
Trigger Referral Bonus Check
Trigger Daily 5-Prompt Milestone Check (+₹0.10)
Add ❤️ Reaction to Channel Post
```

### Workflow E: Daily 5-Prompt Milestone Bonus System
```
Prompt Approved (Instant or Cron)
            │
            ▼
Calculate Today's Approved Count for User (IST)
            │
  Is Today's Approved Count >= 5?
            │
   ┌────────┴────────┐
   YES               NO
   │                 │
Check Daily Bonus   Do Nothing
Claimed Today?
   │
   ├─► NO  ──► Insert into daily_bonuses (UNIQUE KEY user_date)
   │           Credit +₹0.10 to User Balance
   │           Send Celebration Alert: "🎉 DAILY MILESTONE BONUS UNLOCKED!"
   │
   └─► YES ──► Ignore (Prevent Double Claim)
```

### Workflow F: Earnings & Withdrawal Request System (/withdraw)
```
User Issues /withdraw ──► Check Balance >= ₹20.00
                                   │
                           User Inputs UPI ID
                                   │
                   Create Withdrawal Record ('pending')
                                   │
                         Notify Admins Real-Time
                                   │
                        ┌──────────┴──────────┐
                        ▼                     ▼
                  [ Approve & Pay ]       [ Reject ]
                        │                     │
               Mark 'approved',        Mark 'rejected',
               Deduct Balance          Refund Balance
```

### Workflow G: Customer Support Ticketing Center (/support)
```
User Issue /support ──► Select Category ──► Enter Message
                                                │
                                    Ticket Status = 'open'
                                                │
                                       Notify All Admins
                                                │
                                    Admin Replies in Chat
                                                │
                                     Ticket Status = 'replied'
                                                │
                                    Deliver Reply to User Chat
```

### Workflow H: Real-Time Non-Blocking Broadcast Engine (/broadcast)
```
Admin Issues /broadcast ──► Send Content ──► Show Single Preview Card
                                                     │
                                       Admin Selects Target Audience
                                                     │
                                 Create Broadcast Queue Record ('pending')
                                                     │
                                 Return Webhook Response Instantly (<0.05s)
                                                     │
                                 Process Background Batches (30 users/tick)
                                                     │
                             Edit Admin Progress Card Real-Time (Every 10 Users)
                                                     │
                                            ┌────────┴────────┐
                                            ▼                 ▼
                                       Completed        Admin Clicked [Stop]
                                            │                 │
                                      Status = 'completed' Status = 'stopped'
```

### Workflow I: User Banning & Security System (/ban & /unban)
```
Admin Issues /ban [User_ID] [Reason]
                 │
  UPDATE users SET is_banned = 1, ban_reason = '...'
                 │
  User Data Preserved 100% (Balance, Submissions, Referrals Intact)
                 │
  User Intercepted by Security Guard on Commands (Allows /support appeal)
                 │
Admin Issues /unban [User_ID]
                 │
  UPDATE users SET is_banned = 0
                 │
  Full Access Restored Instantly with All Data Intact
```

### Workflow J: Daily Check-In & Streak Reward System (/daily & /checkin)
```
User sends /daily or taps [📅 Daily Check-in]
                  │
                  ▼
Channel Membership Guard Check (@ai_prompt_store)
   ├── NOT Joined: Prompt [📢 Join Channel] ──► Block Claim until Joined
   └── Joined: Fetch User Checkin Status (IST timezone)
                  │
                  ▼
Is user already claimed today (last_checkin_date == TODAY)?
   ├── YES: Show [✅ Claimed Today] card with countdown to midnight IST
   └── NO: Check streak continuity:
         ├── If last_checkin_date == YESTERDAY: Streak Continues! (streak = streak + 1)
         ├── If last_checkin_date == 2 DAYS AGO & streak_freeze_count > 0:
         │      🛡️ STREAK FREEZE CONSUMED! Streak Protected! (streak = streak + 1)
         └── If last_checkin_date < YESTERDAY (no freeze): Streak Broken! (streak = 1)
                  │
                  ▼
Calculate Reward based on Streak:
  Day 1: ₹0.10 | Day 2: ₹0.15 | Day 3: ₹0.20 | Day 4: ₹0.25 | Day 5: ₹0.30 | Day 6: ₹0.35 | Day 7+: ₹0.40/day
                  │
                  ▼
User Taps [ 🎁 Claim Day X Bonus ]
                  │
                  ▼
Atomic Insert into daily_checkins (UNIQUE KEY prevents double-claim)
                  │
UPDATE users SET streak_count = X, last_checkin_date = TODAY (and deduct freeze if used)
                  │
addBalance(telegram_id, reward, 'DAILY_CHECKIN')
  ├── Updates users.balance
  ├── Inserts into money_transactions table
  └── Syncs row to Google Sheets (02_MONEY_TRANSACTIONS)
                  │
Render Instant Popup Alert + Updated Check-In Dashboard Card!
```

#### 🛡️ Streak Freeze Shield Rules:
* **Earning**: Users earn 1 active Streak Freeze Shield every 3 approved prompt submissions. Max capacity is 1 active shield.
* **Consumption**: If a creator misses 1 day, the shield is consumed automatically upon their next check-in to preserve their streak.

#### ⏰ 8:00 PM IST Automated Cron Reminder:
* At or after 8:00 PM IST (20:00), `cron.php` queries creators with active streaks (`streak_count > 0`) who have not yet claimed today and sends a targeted reminder:
  *"🔥 Don't lose your streak! You're on Day X (+₹Y.YY). Check in before midnight to keep your streak alive!"*

---

## 7. Automated Cron Background Engines (`cron.php`)

`cron.php` is configured in Hostinger cPanel to run automatically every **1 to 5 minutes**:

```php
// Cron Execution Loop:
1. checkAndRunScheduledPosts();    // Publishes posts when scheduled_at <= NOW()
2. processBroadcastBatch();        // Processes pending background broadcast batches
3. checkAndRunWeeklyTopPost();     // Awards top creator prizes every Sunday at 9:00 PM IST
```

---

## 8. Web Prompt Store (`library.php`) & Admin Content Calendar (`calendar.php`)

* **Web Prompt Store (`library.php`)**: Frontend at `https://rtmcreator.com/prompt-library/` allowing users to search prompts, filter categories, and copy prompts with 1 click. Powered by `api.php`.
* **Admin Content Calendar (`calendar.php`)**: Visual grid calendar at `https://rtmcreator.com/prompt-library/calendar.php` displaying scheduled posts across time slots with 1-tap reschedule controls. Powered by `api_calendar.php`.

---

## 9. System Maintenance, Webhook Reset & Troubleshooting

### Emergency Webhook Reset URL
To flush stuck Telegram server queues and re-link webhook fresh:
`https://api.telegram.org/bot8576079546:AAH7cS1kjW6T0Szj0TflDHiTuJx9e8Z55yM/setWebhook?url=https://rtmcreator.com/bots/prompt-bot/webhook.php&drop_pending_updates=true`

---
*Master System Documentation generated for @Prompts_library_bot version 10.0.*
