<?php
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS local_media TEXT NULL AFTER file_id;");
    echo "✅ Successfully added local_media column.";
} catch (PDOException $e) {
    echo "DB Connection failed: " . $e->getMessage();
}