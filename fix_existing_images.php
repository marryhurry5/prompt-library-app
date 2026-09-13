<?php
require_once 'config.php';
require_once 'db.php';

// Redefine or include the necessary function
function downloadAndSaveMedia($file_ids_json) {
    $file_ids = json_decode($file_ids_json, true);
    if (!is_array($file_ids)) {
        if ($file_ids_json) $file_ids = [$file_ids_json];
        else return null;
    }

    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $saved_paths = [];
    foreach ($file_ids as $file_id) {
        $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/getFile?file_id=" . $file_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $file_info = json_decode($response, true);
        if (!isset($file_info['result']['file_path'])) {
            echo "Could not get file path for $file_id\n";
            continue;
        }
        
        $tg_file_path = $file_info['result']['file_path'];
        $download_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$tg_file_path";

        $raw_image = file_get_contents($download_url);
        if (!$raw_image) {
            echo "Failed to download image for $file_id\n";
            continue;
        }

        $source = @imagecreatefromstring($raw_image);
        if (!$source) {
            echo "GD could not parse image for $file_id\n";
            continue;
        }

        $orig_w = imagesx($source);
        $orig_h = imagesy($source);
        $max_w = 1200;

        if ($orig_w > $max_w) {
            $ratio = $max_w / $orig_w;
            $new_w = $max_w;
            $new_h = (int)($orig_h * $ratio);
            $resampled = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($resampled, $source, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
            imagedestroy($source);
            $source = $resampled;
        }

        $filename = 'prompt_' . uniqid() . '.webp';
        $filepath = $upload_dir . $filename;

        if (function_exists('imagewebp')) {
            imagewebp($source, $filepath, 82);
        } else {
            $filename = str_replace('.webp', '.jpg', $filename);
            $filepath = $upload_dir . $filename;
            imagejpeg($source, $filepath, 82);
        }
        imagedestroy($source);
        $saved_paths[] = 'uploads/' . $filename;
    }

    if (empty($saved_paths)) return null;
    return json_encode($saved_paths);
}

global $pdo;
// Fix Images
$stmt = $pdo->query("SELECT id, file_id, output_type FROM submissions WHERE status = 'approved' AND (local_media IS NULL OR local_media = '' OR local_media = '[]') AND output_type = 'Image'");
$to_fix_img = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($to_fix_img) . " images to fix.\n";
foreach ($to_fix_img as $post) {
    echo "Fixing Image #{$post['id']}... ";
    $local_media = downloadAndSaveMedia($post['file_id']);
    if ($local_media) {
        $update = $pdo->prepare("UPDATE submissions SET local_media = ? WHERE id = ?");
        $update->execute([$local_media, $post['id']]);
        echo "Done.\n";
    } else {
        echo "Failed.\n";
    }
}

// Fix Videos
$stmt = $pdo->query("SELECT id, file_id, output_type FROM submissions WHERE status = 'approved' AND (local_media IS NULL OR local_media = '' OR local_media = '[]') AND output_type = 'Video'");
$to_fix_vid = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nFound " . count($to_fix_vid) . " videos to fix.\n";
foreach ($to_fix_vid as $post) {
    echo "Fixing Video #{$post['id']}... ";
    
    // For old videos, file_id is just a string (the video id)
    $file_id = $post['file_id'];
    $decoded = json_decode($file_id, true);
    if (is_array($decoded) && isset($decoded['video'])) {
        $file_id = $decoded['video'];
    }

    $local_v = downloadAndSaveMedia($file_id, true);
    if ($local_v) {
        // We don't have the thumb for old videos, so we leave it null
        $local_media = json_encode(['video' => $local_v, 'thumb' => null]);
        $update = $pdo->prepare("UPDATE submissions SET local_media = ? WHERE id = ?");
        $update->execute([$local_media, $post['id']]);
        echo "Done.\n";
    } else {
        echo "Failed.\n";
    }
}

echo "\nFinished.\n";
?>
