<?php
// config.php

define('BOT_TOKEN', '8576079546:AAH7cS1kjW6T0Szj0TflDHiTuJx9e8Z55yM');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

define('ADMIN_ID', '1655174950'); 
define('CHANNEL_ID', '-1003798481031'); // The numeric ID for backend API
define('CHANNEL_USERNAME', 'ai_prompt_store'); // The public username for t.me links

define('DB_HOST', 'localhost');
define('DB_USER', 'u414504879_botuser');
define('DB_PASS', 'Nihal@2711');
define('DB_NAME', 'u414504879_promptbot');

// Google Sheet Master Webhook URL
if (!defined('GOOGLE_SHEET_WEBHOOK_URL')) {
    define('GOOGLE_SHEET_WEBHOOK_URL', 'https://script.google.com/macros/s/AKfycbw-CeHGfPAL0bFppWxOu7c9exne_HfCN7ZjIurqw-xcRv86-x_TnyjqF8h6JTW6lO047w/exec');
}