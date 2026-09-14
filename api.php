<?php
date_default_timezone_set('Asia/Kolkata');
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Base URL where your bot files are hosted
if (!defined('BOT_BASE_URL')) {
    define('BOT_BASE_URL', 'https://rtmcreator.com/bots/prompt-bot/');
}

// Direct Version Check endpoint for Mobile App Auto-Updater
if (isset($_GET['action']) && $_GET['action'] === 'version_check') {
    echo json_encode([
        'success' => true,
        'latest_version' => '1.0.3',
        'min_supported_version' => '1.0.0',
        'force_update' => false,
        'update_url' => BOT_BASE_URL . 'api.php?action=download_apk',
        'release_notes' => 'Guaranteed Google AdMob Test Ads, 5-Sec Ad Simulator, & Ad-Free PRO Updates!'
    ]);
    exit;
}

// Direct Download Endpoint for APK with proper Android Package Installer headers
if (isset($_GET['action']) && $_GET['action'] === 'download_apk') {
    $possible_paths = [
        dirname(__FILE__) . '/PromptLibrary_v1.0.3_Release.apk',
        dirname(__FILE__) . '/PromptLibrary_v1.0.2_Release.apk',
        dirname(__FILE__) . '/PromptLibrary_v1.0.1_Universal.apk',
        dirname(__FILE__) . '/app-release.apk',
        dirname(__FILE__) . '/app-debug.apk',
    ];
    $apk_path = null;
    foreach ($possible_paths as $p) {
        if (file_exists($p)) {
            $apk_path = $p;
            break;
        }
    }
    if ($apk_path && file_exists($apk_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="AI_Prompt_Hub_v1.0.3.apk"');
        header('Content-Length: ' . filesize($apk_path));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        readfile($apk_path);
        exit;
    } else {
        http_response_code(404);
        echo "APK file not found on server.";
        exit;
    }
}

// Ensure app_users and pro_payments tables exist
try {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_pro TINYINT(1) DEFAULT 0,
        pro_expires_at TIMESTAMP NULL,
        reset_otp VARCHAR(10) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pro_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        payment_id VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(50) DEFAULT 'success',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    // Auto-migrate likes & copies columns in submissions table
    try {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN likes INT DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN copies INT DEFAULT 0");
    } catch (Exception $e) {}
} catch (Exception $e) {}

// POST ?action=like_prompt
if (isset($_GET['action']) && $_GET['action'] === 'like_prompt') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $prompt_id = (int)($input['prompt_id'] ?? 0);
    $is_like = isset($input['is_like']) ? (bool)$input['is_like'] : true;

    if ($prompt_id > 0) {
        global $pdo;
        if ($is_like) {
            $stmt = $pdo->prepare("UPDATE submissions SET likes = COALESCE(likes, 0) + 1 WHERE id = :id");
        } else {
            $stmt = $pdo->prepare("UPDATE submissions SET likes = GREATEST(0, COALESCE(likes, 0) - 1) WHERE id = :id");
        }
        $stmt->execute([':id' => $prompt_id]);

        $stmt_get = $pdo->prepare("SELECT COALESCE(likes, 0) FROM submissions WHERE id = :id");
        $stmt_get->execute([':id' => $prompt_id]);
        $new_likes = (int)$stmt_get->fetchColumn();

        echo json_encode(['success' => true, 'likes' => $new_likes, 'is_liked' => $is_like]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid prompt ID']);
    exit;
}

// POST ?action=copy_prompt
if (isset($_GET['action']) && $_GET['action'] === 'copy_prompt') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $prompt_id = (int)($input['prompt_id'] ?? 0);

    if ($prompt_id > 0) {
        global $pdo;
        $stmt = $pdo->prepare("UPDATE submissions SET copies = COALESCE(copies, 0) + 1 WHERE id = :id");
        $stmt->execute([':id' => $prompt_id]);

        $stmt_get = $pdo->prepare("SELECT COALESCE(copies, 0) FROM submissions WHERE id = :id");
        $stmt_get->execute([':id' => $prompt_id]);
        $new_copies = (int)$stmt_get->fetchColumn();

        echo json_encode(['success' => true, 'copies' => $new_copies]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid prompt ID']);
    exit;
}

// GET ?action=creator_info
if (isset($_GET['action']) && $_GET['action'] === 'creator_info') {
    $creator = trim($_GET['creator'] ?? '');
    if (strpos($creator, '@') === 0) {
        $creator = substr($creator, 1);
    }

    if (!empty($creator)) {
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_prompts,
                COALESCE(SUM(s.likes), 0) AS total_likes,
                COALESCE(SUM(s.copies), 0) AS total_copies,
                MIN(s.created_at) AS member_since
            FROM submissions s
            JOIN users u ON s.telegram_id = u.telegram_id
            WHERE (u.username = :uname1 OR u.username = :uname2) AND s.status = 'approved'
        ");
        $stmt->execute([':uname1' => $creator, ':uname2' => '@' . $creator]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_p = (int)($info['total_prompts'] ?? 0);
        $total_l = (int)($info['total_likes'] ?? 0);
        $total_c = (int)($info['total_copies'] ?? 0);

        // Dynamic Badge Assignment
        $badge = '🚀 Rising Creator';
        if ($total_p >= 15 || $total_l >= 100) {
            $badge = '⭐ Master Prompt Creator';
        } else if ($total_p >= 5 || $total_l >= 30) {
            $badge = '🎨 Pro Creator';
        }

        echo json_encode([
            'success' => true,
            'username' => '@' . ltrim($creator, '@'),
            'total_prompts' => $total_p,
            'total_likes' => $total_l,
            'total_copies' => $total_c,
            'badge' => $badge,
            'member_since' => $info['member_since'] ?? date('Y-m-d H:i:s')
        ]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Creator username is required']);
    exit;
}

// POST ?action=user_register
if (isset($_GET['action']) && $_GET['action'] === 'user_register') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $name = trim($input['name'] ?? 'User');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = trim($input['password'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid email address is required.']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
        exit;
    }

    global $pdo;
    $chk = $pdo->prepare("SELECT id FROM app_users WHERE email = :email");
    $chk->execute([':email' => $email]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'An account with this email already exists.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO app_users (name, email, password_hash) VALUES (:name, :email, :hash)");
    $stmt->execute([':name' => $name, ':email' => $email, ':hash' => $hash]);
    $userId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully!',
        'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'is_pro' => false]
    ]);
    exit;
}

// POST ?action=user_login
if (isset($_GET['action']) && $_GET['action'] === 'user_login') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $email = strtolower(trim($input['email'] ?? ''));
    $password = trim($input['password'] ?? '');

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash, is_pro FROM app_users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Login successful!',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'is_pro' => (bool)$user['is_pro']
            ]
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid email or password.']);
        exit;
    }
}

// POST ?action=forgot_password
if (isset($_GET['action']) && $_GET['action'] === 'forgot_password') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid registered email address is required.']);
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT id, name FROM app_users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $otp = sprintf('%06d', mt_rand(100000, 999999));
        $up = $pdo->prepare("UPDATE app_users SET reset_otp = :otp WHERE email = :email");
        $up->execute([':otp' => $otp, ':email' => $email]);

        // Send Real HTML Email via PHP mail()
        $to = $email;
        $subject = "Your Password Reset OTP Code - AI Prompt Hub";
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: AI Prompt Hub <noreply@rtmcreator.com>\r\n";
        $headers .= "Reply-To: support@rtmcreator.com\r\n";

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; background-color: #0f172a; color: #ffffff; padding: 20px;'>
            <div style='max-width: 500px; margin: 0 auto; background-color: #1e293b; padding: 30px; border-radius: 16px; border: 1px solid #6366f1;'>
                <h2 style='color: #6366f1; text-align: center; margin-top: 0;'>AI Prompt Hub</h2>
                <h3 style='text-align: center; color: #ffffff;'>Password Reset Verification Code</h3>
                <p>Hello <strong>" . htmlspecialchars($user['name'] ?? 'User') . "</strong>,</p>
                <p>You requested to reset your password. Use the 6-digit OTP code below to verify your account:</p>
                <div style='background-color: #0f172a; border: 2px dashed #6366f1; padding: 15px; text-align: center; border-radius: 12px; margin: 20px 0;'>
                    <span style='font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #22c55e;'>" . $otp . "</span>
                </div>
                <p style='color: #94a3b8; font-size: 12px;'>If you did not request a password reset, please ignore this email.</p>
                <hr style='border: none; border-top: 1px solid #334155; margin: 20px 0;' />
                <p style='color: #64748b; font-size: 11px; text-align: center;'>AI Prompt Hub Team - rtmcreator.com</p>
            </div>
        </body>
        </html>
        ";

        @mail($to, $subject, $message, $headers);

        echo json_encode([
            'success' => true,
            'message' => 'OTP code sent to your email address! Please check your inbox and spam folder.'
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'No account found with this email address.']);
        exit;
    }
}

// POST ?action=reset_password
if (isset($_GET['action']) && $_GET['action'] === 'reset_password') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $email = strtolower(trim($input['email'] ?? ''));
    $otp = trim($input['otp'] ?? '');
    $new_password = trim($input['new_password'] ?? '');

    if (empty($email) || empty($otp) || strlen($new_password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Email, OTP, and 6+ char password required.']);
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM app_users WHERE email = :email AND reset_otp = :otp");
    $stmt->execute([':email' => $email, ':otp' => $otp]);
    $user = $stmt->fetch();

    if ($user) {
        $hash = password_hash($new_password, PASSWORD_BCRYPT);
        $up = $pdo->prepare("UPDATE app_users SET password_hash = :hash, reset_otp = NULL WHERE id = :id");
        $up->execute([':hash' => $hash, ':id' => $user['id']]);

        echo json_encode(['success' => true, 'message' => 'Password updated successfully! You can now login.']);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired OTP.']);
        exit;
    }
}

// POST ?action=verify_pro_payment
if (isset($_GET['action']) && $_GET['action'] === 'verify_pro_payment') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $email = strtolower(trim($input['email'] ?? 'guest@aiprompthub.com'));
    $utr = trim($input['utr'] ?? '');
    $payment_id = trim($input['payment_id'] ?? (!empty($utr) ? "UTR_$utr" : ('PAY_' . uniqid())));
    $amount = (float)($input['amount'] ?? 29.00);

    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pro_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_email VARCHAR(255) NOT NULL,
            payment_id VARCHAR(100) NOT NULL,
            utr VARCHAR(100) NULL,
            amount DECIMAL(10,2) NOT NULL,
            status VARCHAR(50) DEFAULT 'active',
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );");
        $colCheck = $pdo->query("SHOW COLUMNS FROM pro_payments LIKE 'utr'")->fetch();
        if (!$colCheck) {
            $pdo->exec("ALTER TABLE pro_payments ADD COLUMN utr VARCHAR(100) NULL AFTER payment_id");
        }
        $expCheck = $pdo->query("SHOW COLUMNS FROM pro_payments LIKE 'expires_at'")->fetch();
        if (!$expCheck) {
            $pdo->exec("ALTER TABLE pro_payments ADD COLUMN expires_at TIMESTAMP NULL AFTER status");
        }
    } catch (Exception $e) {}

    $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

    $ins = $pdo->prepare("INSERT INTO pro_payments (user_email, payment_id, utr, amount, status, expires_at) VALUES (:email, :pid, :utr, :amt, 'active', :exp)");
    $ins->execute([
        ':email' => $email,
        ':pid' => $payment_id,
        ':utr' => $utr,
        ':amt' => $amount,
        ':exp' => $expires_at
    ]);

    try {
        $up = $pdo->prepare("UPDATE app_users SET is_pro = 1, pro_expires_at = :exp WHERE email = :email");
        $up->execute([':exp' => $expires_at, ':email' => $email]);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'message' => 'PRO subscription activated for 30 days!',
        'payment_id' => $payment_id,
        'utr' => $utr,
        'expires_at' => $expires_at
    ]);
    exit;
}

// Ensure shop_admins table exists
try {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");
} catch (Exception $e) {}

// POST ?action=admin_register
if (isset($_GET['action']) && $_GET['action'] === 'admin_register') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $email = strtolower(trim($input['email'] ?? ''));
    $password = trim($input['password'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid email address is required.']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
        exit;
    }

    global $pdo;
    $chk = $pdo->prepare("SELECT id FROM shop_admins WHERE email = :email");
    $chk->execute([':email' => $email]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'An admin account with this email already exists.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO shop_admins (email, password_hash) VALUES (:email, :hash)");
    $stmt->execute([':email' => $email, ':hash' => $hash]);

    echo json_encode([
        'success' => true,
        'message' => 'Admin account created successfully!',
        'admin' => ['id' => $pdo->lastInsertId(), 'email' => $email]
    ]);
    exit;
}

// POST ?action=admin_login
if (isset($_GET['action']) && $_GET['action'] === 'admin_login') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $email = strtolower(trim($input['email'] ?? ''));
    $password = trim($input['password'] ?? '');

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT id, email, password_hash FROM shop_admins WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Login successful!',
            'admin' => ['id' => $user['id'], 'email' => $user['email']]
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid email or password.']);
        exit;
    }
}

// POST ?action=edit_shop_item
if (isset($_GET['action']) && $_GET['action'] === 'edit_shop_item') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $image_url = trim($input['image_url'] ?? '');
    $access_link = trim($input['access_link'] ?? '');
    $item_type = trim($input['item_type'] ?? 'Reel Bundle');

    if ($id > 0 && !empty($title) && !empty($image_url) && !empty($access_link)) {
        global $pdo;
        $stmt = $pdo->prepare("UPDATE shop_items SET title = :title, description = :description, image_url = :image_url, access_link = :access_link, item_type = :item_type WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':title' => $title,
            ':description' => $description,
            ':image_url' => $image_url,
            ':access_link' => $access_link,
            ':item_type' => $item_type,
        ]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid data or missing required fields.']);
    exit;
}

// GET ?action=shop_items
if (isset($_GET['action']) && $_GET['action'] === 'shop_items') {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM shop_items WHERE status = 'active' ORDER BY created_at DESC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// POST ?action=upload_shop_media
if (isset($_GET['action']) && $_GET['action'] === 'upload_shop_media') {
    if (!empty($_FILES['file']['name'])) {
        $uploads_dir = dirname(__FILE__) . '/uploads';
        if (!is_dir($uploads_dir)) {
            mkdir($uploads_dir, 0755, true);
        }
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $filename = 'shop_' . uniqid() . '.' . $ext;
        $target = $uploads_dir . '/' . $filename;
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            $url = rtrim(BOT_BASE_URL, '/') . '/uploads/' . $filename;
            echo json_encode(['success' => true, 'url' => $url]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'File upload failed']);
    exit;
}

// POST ?action=add_shop_item
if (isset($_GET['action']) && $_GET['action'] === 'add_shop_item') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $image_url = trim($input['image_url'] ?? '');
    $access_link = trim($input['access_link'] ?? '');
    $item_type = trim($input['item_type'] ?? 'Reel Bundle');

    if (!empty($title) && !empty($image_url) && !empty($access_link)) {
        global $pdo;
        $stmt = $pdo->prepare("INSERT INTO shop_items (title, description, image_url, access_link, item_type) VALUES (:title, :description, :image_url, :access_link, :item_type)");
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':image_url' => $image_url,
            ':access_link' => $access_link,
            ':item_type' => $item_type,
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Title, Image URL, and Access Link are required.']);
    exit;
}

// POST ?action=delete_shop_item
if (isset($_GET['action']) && $_GET['action'] === 'delete_shop_item') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        global $pdo;
        $stmt = $pdo->prepare("UPDATE shop_items SET status = 'inactive' WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

// GET ?action=categories
if (isset($_GET['action']) && $_GET['action'] === 'categories') {
    global $pdo;
    $stmt = $pdo->query("SELECT id, name, is_active FROM categories ORDER BY name ASC");
    $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'categories' => $cats]);
    exit;
}

// POST ?action=edit_category
if (isset($_GET['action']) && $_GET['action'] === 'edit_category') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');

    if ($id > 0 && !empty($name)) {
        global $pdo;
        $stmt_old = $pdo->prepare("SELECT name FROM categories WHERE id = :id");
        $stmt_old->execute([':id' => $id]);
        $old_name = $stmt_old->fetchColumn();

        if ($old_name) {
            $stmt = $pdo->prepare("UPDATE categories SET name = :name WHERE id = :id");
            $stmt->execute([':name' => $name, ':id' => $id]);

            if ($old_name !== $name) {
                $stmt_sub = $pdo->prepare("UPDATE submissions SET category = :new_name WHERE category = :old_name");
                $stmt_sub->execute([':new_name' => $name, ':old_name' => $old_name]);
            }

            echo json_encode(['success' => true, 'message' => 'Category updated successfully!']);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Category ID and Name are required.']);
    exit;
}

// POST ?action=add_category
if (isset($_GET['action']) && $_GET['action'] === 'add_category') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $name = trim($input['name'] ?? '');

    if (!empty($name)) {
        global $pdo;
        $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name, is_active) VALUES (:name, 1)");
        $stmt->execute([':name' => $name]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Category name is required.']);
    exit;
}

// POST ?action=delete_category
if (isset($_GET['action']) && $_GET['action'] === 'delete_category') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);

    if ($id > 0) {
        global $pdo;
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid Category ID']);
    exit;
}

try {
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = 12;
    $offset = ($page - 1) * $limit;

    global $pdo;

    $where = ["s.status = 'approved'", "(s.scheduled_at IS NULL OR s.scheduled_at <= NOW())"];
    $params = [];

    // Filter by Category (ignore 'all' and client-side 'favs')
    if (!empty($_GET['category']) && $_GET['category'] !== 'all' && $_GET['category'] !== 'favs') {
        $where[] = "s.category = :category";
        $params[':category'] = trim($_GET['category']);
    }

    // Filter by Creator
    if (!empty($_GET['creator'])) {
        $creator = trim($_GET['creator']);
        if (strpos($creator, '@') === 0) {
            $creator = substr($creator, 1);
        }
        if (is_numeric($creator)) {
            $where[] = "u.telegram_id = :creator_id";
            $params[':creator_id'] = (int)$creator;
        } else {
            $where[] = "(u.username = :creator_uname OR u.username = :creator_uname_at)";
            $params[':creator_uname'] = $creator;
            $params[':creator_uname_at'] = '@' . $creator;
        }
    }

    // Search Query (title, prompt, tags, category, username)
    // Uses unique PDO parameter tokens (:s1 .. :s5) to prevent invalid parameter count error
    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $where[] = "(s.prompt LIKE :s1 OR s.text_output LIKE :s2 OR s.tags LIKE :s3 OR s.category LIKE :s4 OR u.username LIKE :s5)";
        $params[':s1'] = $search;
        $params[':s2'] = $search;
        $params[':s3'] = $search;
        $params[':s4'] = $search;
        $params[':s5'] = $search;
    }

    $where_clause = implode(" AND ", $where);

    // Get total count matching criteria
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM submissions s
        JOIN users u ON s.telegram_id = u.telegram_id
        WHERE $where_clause
    ");
    foreach ($params as $key => $val) {
        if ($key === ':creator_id') {
            $count_stmt->bindValue($key, $val, PDO::PARAM_INT);
        } else {
            $count_stmt->bindValue($key, $val, PDO::PARAM_STR);
        }
    }
    $count_stmt->execute();
    $total = (int)$count_stmt->fetchColumn();

    // Fetch results
    $sql = "
        SELECT s.id, s.category, s.output_type, s.tags, s.prompt, s.text_output, s.local_media, s.is_challenge, s.created_at, COALESCE(s.likes, 0) AS likes, COALESCE(s.copies, 0) AS copies, u.username
        FROM submissions s
        JOIN users u ON s.telegram_id = u.telegram_id
        WHERE $where_clause
        ORDER BY s.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $val) {
        if ($key === ':creator_id') {
            $stmt->bindValue($key, $val, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $prompts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($prompts as &$p) {
        $raw_media = trim($p['local_media'] ?? '');
        $base = rtrim(BOT_BASE_URL, '/');
        $p['image_urls'] = [];
        $p['video_url'] = null;

        if (!empty($raw_media)) {
            $local_data = json_decode($raw_media, true);
            if (is_array($local_data)) {
                if (isset($local_data['video'])) {
                    if (!empty($local_data['thumb'])) {
                        $p['image_urls'][] = (strpos($local_data['thumb'], 'http') === 0) ? $local_data['thumb'] : $base . '/' . ltrim($local_data['thumb'], '/');
                    }
                    if (!empty($local_data['video'])) {
                        $p['video_url'] = (strpos($local_data['video'], 'http') === 0) ? $local_data['video'] : $base . '/' . ltrim($local_data['video'], '/');
                    }
                } else {
                    foreach ($local_data as $item) {
                        if (!empty($item)) {
                            $p['image_urls'][] = (strpos($item, 'http') === 0) ? $item : $base . '/' . ltrim($item, '/');
                        }
                    }
                }
            } else {
                $p['image_urls'][] = (strpos($raw_media, 'http') === 0) ? $raw_media : $base . '/' . ltrim($raw_media, '/');
            }
        }
        unset($p['local_media']);
    }
    unset($p);

    echo json_encode([
        'success' => true,
        'total' => $total,
        'page' => $page,
        'per_page' => $limit,
        'total_pages' => (int)ceil($total / $limit),
        'prompts' => $prompts
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}