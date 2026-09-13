<?php
/**
 * test_checkin.php - Unit Test & Diagnostic for Daily Check-In & Streak System
 * 
 * Run via CLI: php test_checkin.php
 * Or visit in browser: https://rtmcreator.com/bots/prompt-bot/test_checkin.php
 */

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/db.php';

echo "<h2>🧪 Daily Check-In & Streak System Test Suite</h2>\n";

$passed = 0;
$failed = 0;

function assertEqual($actual, $expected, $test_name) {
    global $passed, $failed;
    if ($actual === $expected) {
        echo "<p style='color:green'>✅ <b>PASS:</b> {$test_name} (Got: " . var_export($actual, true) . ")</p>\n";
        $passed++;
    } else {
        echo "<p style='color:red'>❌ <b>FAIL:</b> {$test_name} (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")</p>\n";
        $failed++;
    }
}

// ─── 1. Reward Progression Tests ─────────────────────────────────────────────
echo "<h3>1. Reward Progression Logic</h3>\n";
assertEqual(getCheckinRewardForStreak(0), 0.10, "Streak 0 -> ₹0.10");
assertEqual(getCheckinRewardForStreak(1), 0.10, "Day 1 Streak -> ₹0.10");
assertEqual(getCheckinRewardForStreak(2), 0.15, "Day 2 Streak -> ₹0.15");
assertEqual(getCheckinRewardForStreak(3), 0.20, "Day 3 Streak -> ₹0.20");
assertEqual(getCheckinRewardForStreak(4), 0.25, "Day 4 Streak -> ₹0.25");
assertEqual(getCheckinRewardForStreak(5), 0.30, "Day 5 Streak -> ₹0.30");
assertEqual(getCheckinRewardForStreak(6), 0.35, "Day 6 Streak -> ₹0.35");
assertEqual(getCheckinRewardForStreak(7), 0.40, "Day 7 Streak -> ₹0.40 (Max tier)");
assertEqual(getCheckinRewardForStreak(8), 0.40, "Day 8 Streak -> ₹0.40 (Continuous)");
assertEqual(getCheckinRewardForStreak(100), 0.40, "Day 100 Streak -> ₹0.40 (Continuous)");

// ─── 2. Database Schema Verification ─────────────────────────────────────────
echo "<h3>2. Database Schema Check</h3>\n";
try {
    global $pdo;
    $stmt = $pdo->query("SHOW TABLES LIKE 'daily_checkins'");
    assertEqual($stmt->rowCount() > 0, true, "Table 'daily_checkins' exists in database");

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'streak_count'");
    assertEqual($stmt->rowCount() > 0, true, "Column 'streak_count' exists on 'users'");

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_checkin_date'");
    assertEqual($stmt->rowCount() > 0, true, "Column 'last_checkin_date' exists on 'users'");
} catch (Exception $e) {
    echo "<p style='color:orange'>⚠️ DB connection unavailable locally: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}

echo "<hr><h4>Summary: {$passed} Passed, {$failed} Failed</h4>\n";
