<?php
/**
 * Test script for sanitizeSpaces() functionality
 */

// Define function exactly as in db.php for standalone execution
function test_sanitizeSpaces($text, $multiline = false) {
    if (!is_string($text)) {
        return $text;
    }
    // Normalize UTF-8 non-breaking spaces (\u00A0) to standard spaces
    $text = preg_replace('/\x{00A0}/u', ' ', $text);

    if ($multiline) {
        // Collapse multiple horizontal spaces and tabs into a single space, preserving newlines
        $text = preg_replace('/[^\S\r\n]{2,}/u', ' ', $text);
        // Remove trailing horizontal whitespace from each line
        $text = preg_replace('/[^\S\r\n]+$/m', '', $text);
        return trim($text);
    } else {
        // For single-line inputs (like titles), collapse any consecutive whitespace into one space
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}

$tests = [
    // --- Title Tests (single line) ---
    [
        'name' => 'Title: Simple double spaces',
        'input' => 'Cyberpunk  Neon   Warrior',
        'multiline' => false,
        'expected' => 'Cyberpunk Neon Warrior'
    ],
    [
        'name' => 'Title: Leading, trailing, and multi-spaces',
        'input' => '   Realistic   Portrait  of   a   Woman   ',
        'multiline' => false,
        'expected' => 'Realistic Portrait of a Woman'
    ],
    [
        'name' => 'Title: Accidental newlines and tabs',
        'input' => "Midjourney\t\tAnime\n\nCharacter",
        'multiline' => false,
        'expected' => 'Midjourney Anime Character'
    ],
    [
        'name' => 'Title: Non-breaking spaces (mobile paste)',
        'input' => "Beautiful\xc2\xa0\xc2\xa0Sunset\xc2\xa0Landscape",
        'multiline' => false,
        'expected' => 'Beautiful Sunset Landscape'
    ],

    // --- Prompt Tests (multiline) ---
    [
        'name' => 'Prompt: Horizontal multi-spaces on single line',
        'input' => 'masterpiece,   8k  resolution,    cinematic lighting',
        'multiline' => true,
        'expected' => 'masterpiece, 8k resolution, cinematic lighting'
    ],
    [
        'name' => 'Prompt: Multiline with paragraph preservation and multi-space collapsing',
        'input' => "masterpiece,   high quality,  8k\n\ncinematic   lighting,  octane  render\n--ar  16:9   --v  6.0",
        'multiline' => true,
        'expected' => "masterpiece, high quality, 8k\n\ncinematic lighting, octane render\n--ar 16:9 --v 6.0"
    ],
    [
        'name' => 'Prompt: Trailing spaces per line',
        'input' => "Prompt line 1   \nPrompt line 2  \nPrompt line 3",
        'multiline' => true,
        'expected' => "Prompt line 1\nPrompt line 2\nPrompt line 3"
    ],
    [
        'name' => 'Prompt: Non-breaking spaces in prompt',
        'input' => "hyperrealistic\xc2\xa0\xc2\xa0digital  art\n\n8k\xc2\xa0resolution",
        'multiline' => true,
        'expected' => "hyperrealistic digital art\n\n8k resolution"
    ]
];

$passed = 0;
$failed = 0;

echo "=== Running Space Sanitization Tests ===\n\n";

foreach ($tests as $t) {
    $result = test_sanitizeSpaces($t['input'], $t['multiline']);
    if ($result === $t['expected']) {
        echo "✅ PASS: {$t['name']}\n";
        $passed++;
    } else {
        echo "❌ FAIL: {$t['name']}\n";
        echo "   Input:    " . json_encode($t['input']) . "\n";
        echo "   Expected: " . json_encode($t['expected']) . "\n";
        echo "   Actual:   " . json_encode($result) . "\n";
        $failed++;
    }
}

echo "\nResult: {$passed} passed, {$failed} failed.\n";
