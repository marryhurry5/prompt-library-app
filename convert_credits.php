<?php
require 'db.php';

global $pdo;

// Conversion rate: 10 credits = 0.50 Rs (1 credit = 0.05 Rs)
try {
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + (credits * 0.05), credits = 0 WHERE credits > 0");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Successfully converted credits to Rs for $affected users.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
