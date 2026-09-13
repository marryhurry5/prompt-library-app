# Prompt Marketplace - Telegram Bot

A complete Telegram bot MVP for users to submit AI prompts, and for admins to review and publish them to a Telegram channel.

## Requirements
- PHP 7.4+
- MySQL Server
- Shared Hosting or VPS with HTTPS (for Telegram Webhook)

## Setup Instructions

1. **Database Setup**
   - Create a MySQL database and user.
   - Run the SQL code found in `database.sql` to create `users` and `submissions` tables.

2. **Configuration**
   - Open `config.php`.
   - Update `BOT_TOKEN` with your bot token from BotFather.
   - Update `ADMIN_ID` with your personal Telegram ID.
   - Update `CHANNEL_ID` with your channel username (e.g. `@rtm_prompt_store`).
   - Update DB credentials (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`).

3. **Deploy**
   - Upload `config.php`, `db.php`, and `webhook.php` to your web hosting directory (ensure it is accessible via HTTPS).

4. **Set Webhook**
   - To link your bot to your webhook URL, visit the following link in your browser:  
     `https://api.telegram.org/bot<YOUR_BOT_TOKEN_HERE>/setWebhook?url=https://yourdomain.com/path/to/webhook.php`
   - Make sure to replace `<YOUR_BOT_TOKEN_HERE>` and the `url` parameter correctly.

## Permissions Required
- Add the bot as an Admin in the channel `CHANNEL_ID` with the permission to "Post Messages".
