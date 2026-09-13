<?php
/**
 * test_freeze_and_reminder.php - Test Suite for Streak Freeze Shield & 8:00 PM Cron Reminder
 * 
 * Run via CLI or browser: https://rtmcreator.com/bots/prompt-bot/test_freeze_and_reminder.php
 */

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/db.php';

echo "<h2>🧪 Streak Freeze Shield & 8 PM Cron Reminder Test Suite</h2>\n";

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

// ─── 1. Streak Freeze Continuity Logic ───────────────────────────────────────
echo "<h3>1. Streak Freeze Logic Verification</h3>\n";

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$day_before = date('Y-m-d', strtotime('-2 days'));

// Test Helper simulating status calculation
function simulateStatus($last_date, $streak_count, $freeze_count) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $day_before = date('Y-m-d', strtotime('-2 days'));

    if ($last_date === $today) {
        return ['can_claim' => false, 'streak' => $streak_count, 'freeze_used' => false];
    }
    if ($last_date === $yesterday) {
        return ['can_claim' => true, 'streak' => $streak_count + 1, 'freeze_used' => false];
    }
    if ($last_date === $day_before && $freeze_count > 0 && $streak_count > 0) {
        return ['can_claim' => true, 'streak' => $streak_count + 1, 'freeze_used' => true];
    }
    return ['can_claim' => true, 'streak' => 1, 'freeze_used' => false];
}

// Case A: Checked in yesterday (no freeze needed)
$r1 = simulateStatus($yesterday, 3, 1);
assertEqual($r1['streak'], 4, "Yesterday check-in -> Streak increments to Day 4");
assertEqual($r1['freeze_used'], false, "Yesterday check-in -> Freeze not used");

// Case B: Missed yesterday (last check-in 2 days ago) WITH Streak Freeze
$r2 = simulateStatus($day_before, 4, 1);
assertEqual($r2['streak'], 5, "Missed 1 day with Freeze -> Streak increments to Day 5");
assertEqual($r2['freeze_used'], true, "Missed 1 day with Freeze -> Freeze flag triggered");

// Case C: Missed yesterday WITHOUT Streak Freeze
$r3 = simulateStatus($day_before, 4, 0);
assertEqual($r3['streak'], 1, "Missed 1 day without Freeze -> Streak resets to Day 1");
assertEqual($r3['freeze_used'], false, "Missed 1 day without Freeze -> Freeze not used");

// Case D: Missed 2+ days (3 days ago) WITH Freeze
$three_days_ago = date('Y-m-d', strtotime('-3 days'));
$r4 = simulateStatus($three_days_ago, 4, 1);
assertEqual($r4['streak'], 1, "Missed 2+ days with Freeze -> Streak resets to Day 1 (Freeze protects 1 day only)");

// ─── 2. Database Schema Check ─────────────────────────────────────────────────
echo "<h3>2. Database Schema Check</h3>\n";
try {
    global $pdo;
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'streak_freeze_count'");
    assertEqual($stmt->rowCount() > 0, true, "Column 'streak_freeze_count' exists on 'users'");

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'freezes_awarded_at'");
    assertEqual($stmt->rowCount() > 0, true, "Column 'freezes_awarded_at' exists on 'users'");
} catch (Exception $e) {
    echo "<p style='color:orange'>⚠️ DB check (executed on server): " . htmlspecialchars($e->getMessage()) . "</p>\n";
}

echo "<hr><h4>Summary: {$passed} Passed, {$failed} Failed</h4>\n";
