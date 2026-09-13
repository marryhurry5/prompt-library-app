<?php
// check_db.php
// Visit this in your browser: https://rtmcreator.com/bots/prompt-bot/check_db.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$config_path = dirname(__FILE__) . '/config.php';

echo "<h3>Database Connection Test (Smart Mode)</h3>";

// Read config file manually to see what's inside
$config_content = file_get_contents($config_path);
preg_match("/define\('DB_HOST',\s*'(.*?)'\)/", $config_content, $m) ? $db_host = $m[1] : $db_host = 'localhost';
preg_match("/define\('DB_USER',\s*'(.*?)'\)/", $config_content, $m) ? $db_user = $m[1] : $db_user = '';
preg_match("/define\('DB_PASS',\s*'(.*?)'\)/", $config_content, $m) ? $db_pass = $m[1] : $db_pass = '';
preg_match("/define\('DB_NAME',\s*'(.*?)'\)/", $config_content, $m) ? $db_name = $m[1] : $db_name = '';

echo "Target Host: " . $db_host . "<br>";
echo "Target User: " . $db_user . "<br>";
echo "Target DB Name: " . $db_name . "<br>";

if (defined('DB_USER')) {
    echo "<p style='color:orange'>⚠️ Warning: DB_USER is already defined by WordPress as: " . DB_USER . ". We will ignore this and use your config values instead.</p>";
}

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<h4 style='color:green'>✅ Connection Successful!</h4>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM submissions");
    echo "Total Submissions in DB: " . $stmt->fetchColumn();
    
} catch (PDOException $e) {
    echo "<h4 style='color:red'>❌ Connection Failed!</h4>";
    echo "Error: " . $e->getMessage();
}
?>
