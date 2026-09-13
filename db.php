<?php
// db.php
date_default_timezone_set('Asia/Kolkata'); // All times in IST
$config_path = dirname(__FILE__) . '/config.php';

// Detect if we are in WordPress to avoid constant conflicts
if (defined('ABSPATH')) {
    // Read config file manually to avoid "Constant already defined" errors from WordPress
    $config_content = file_get_contents($config_path);
    
    preg_match("/define\('DB_HOST',\s*'(.*?)'\)/", $config_content, $m) ? $db_host = $m[1] : $db_host = 'localhost';
    preg_match("/define\('DB_USER',\s*'(.*?)'\)/", $config_content, $m) ? $db_user = $m[1] : $db_user = '';
    preg_match("/define\('DB_PASS',\s*'(.*?)'\)/", $config_content, $m) ? $db_pass = $m[1] : $db_pass = '';
    preg_match("/define\('DB_NAME',\s*'(.*?)'\)/", $config_content, $m) ? $db_name = $m[1] : $db_name = '';
} else {
    require_once $config_path;
    $db_host = DB_HOST;
    $db_user = DB_USER;
    $db_pass = DB_PASS;
    $db_name = DB_NAME;
}

class AutoReconnectPDO {
    private $host;
    private $dbname;
    private $user;
    private $pass;
    private $pdo;

    public function __construct($host, $dbname, $user, $pass) {
        $this->host = $host;
        $this->dbname = $dbname;
        $this->user = $user;
        $this->pass = $pass;
        $this->connect();
    }

    private function connect() {
        try {
            $this->pdo = new PDO("mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4", $this->user, $this->pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
            $this->pdo->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            error_log("[AutoReconnectPDO] Connection error: " . $e->getMessage());
            throw $e;
        }
    }

    private function ensureConnection() {
        if (!$this->pdo) {
            $this->connect();
            return;
        }
        try {
            @$this->pdo->query("SELECT 1");
        } catch (Throwable $e) {
            error_log("[AutoReconnectPDO] Ping failed ({$e->getMessage()}), reconnecting...");
            $this->connect();
        }
    }

    public function prepare($statement, $options = []) {
        $this->ensureConnection();
        try {
            return $this->pdo->prepare($statement, $options);
        } catch (PDOException $e) {
            if ($this->isConnectionError($e)) {
                error_log("[AutoReconnectPDO] Retrying prepare() after reconnect...");
                $this->connect();
                return $this->pdo->prepare($statement, $options);
            }
            throw $e;
        }
    }

    public function query($statement, $mode = null, ...$fetch_mode_args) {
        $this->ensureConnection();
        try {
            if ($mode !== null) {
                return $this->pdo->query($statement, $mode, ...$fetch_mode_args);
            }
            return $this->pdo->query($statement);
        } catch (PDOException $e) {
            if ($this->isConnectionError($e)) {
                error_log("[AutoReconnectPDO] Retrying query() after reconnect...");
                $this->connect();
                if ($mode !== null) {
                    return $this->pdo->query($statement, $mode, ...$fetch_mode_args);
                }
                return $this->pdo->query($statement);
            }
            throw $e;
        }
    }

    public function exec($statement) {
        $this->ensureConnection();
        try {
            return $this->pdo->exec($statement);
        } catch (PDOException $e) {
            if ($this->isConnectionError($e)) {
                error_log("[AutoReconnectPDO] Retrying exec() after reconnect...");
                $this->connect();
                return $this->pdo->exec($statement);
            }
            throw $e;
        }
    }

    public function lastInsertId($name = null) {
        $this->ensureConnection();
        return $this->pdo->lastInsertId($name);
    }

    public function beginTransaction() {
        $this->ensureConnection();
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollBack() {
        return $this->pdo->rollBack();
    }

    public function setAttribute($attribute, $value) {
        $this->ensureConnection();
        return $this->pdo->setAttribute($attribute, $value);
    }

    public function getAttribute($attribute) {
        $this->ensureConnection();
        return $this->pdo->getAttribute($attribute);
    }

    private function isConnectionError(PDOException $e) {
        $msg = strtolower($e->getMessage());
        $code = (int)$e->getCode();
        return (
            $code === 2006 || 
            $code === 2013 || 
            strpos($msg, 'server has gone away') !== false || 
            strpos($msg, 'lost connection') !== false ||
            strpos($msg, 'is dead') !== false
        );
    }
}

global $pdo;
try {
    $pdo = new AutoReconnectPDO($db_host, $db_name, $db_user, $db_pass);
} catch (PDOException $e) {
    error_log("DB Connection failed: " . $e->getMessage());
    die("Database connection failed.");
}

try {
    $pdo->exec("ALTER TABLE submissions MODIFY file_id TEXT;");
    $pdo->exec("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS local_media TEXT;");
    $pdo->exec("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS scheduled_at TIMESTAMP NULL DEFAULT NULL;");
    $pdo->exec("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS posted_to_channel TINYINT(1) DEFAULT 1;");
    $pdo->exec("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS feedback TEXT NULL;");
    $pdo->exec("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS is_challenge TINYINT(1) DEFAULT 0;");
    $pdo->exec("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS channel_message_id BIGINT NULL;");
    
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS referred_by BIGINT NULL;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_reward_given TINYINT(1) DEFAULT 0;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_blocked TINYINT(1) DEFAULT 0;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_banned TINYINT(1) DEFAULT 0;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS ban_reason TEXT NULL;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS balance DECIMAL(10,2) DEFAULT 0.00;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS streak_count INT DEFAULT 0;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_checkin_date DATE NULL;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS streak_freeze_count INT DEFAULT 0;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS freezes_awarded_at INT DEFAULT 0;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT UNIQUE NOT NULL,
        added_by BIGINT NOT NULL,
        permissions TEXT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");
    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS permissions TEXT NULL;");
    if (defined('ADMIN_ID')) {
        $pdo->exec("INSERT IGNORE INTO admins (telegram_id, added_by) VALUES (" . (int)ADMIN_ID . ", " . (int)ADMIN_ID . ")");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        upi_id VARCHAR(255) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        processed_by BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS money_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        transaction_type VARCHAR(20) NOT NULL,
        source VARCHAR(50) NOT NULL,
        description TEXT NULL,
        balance_after DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS challenges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        theme VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_active TINYINT(1) DEFAULT 1
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT NOT NULL,
        category VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'open',
        admin_reply TEXT NULL,
        replied_by BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_bonuses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT NOT NULL,
        bonus_date DATE NOT NULL,
        prompts_count INT DEFAULT 5,
        bonus_amount DECIMAL(10,2) DEFAULT 0.10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_date_unique (telegram_id, bonus_date)
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id BIGINT NOT NULL,
        chat_id BIGINT NOT NULL,
        message_id BIGINT NOT NULL,
        status_msg_id BIGINT NULL,
        audience VARCHAR(50) DEFAULT 'all',
        button_text VARCHAR(100) NULL,
        button_url VARCHAR(255) NULL,
        status VARCHAR(20) DEFAULT 'pending',
        total_count INT DEFAULT 0,
        delivered_count INT DEFAULT 0,
        failed_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );");
    $pdo->exec("ALTER TABLE broadcast_queue ADD COLUMN IF NOT EXISTS status_msg_id BIGINT NULL;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        broadcast_id INT NOT NULL,
        user_id BIGINT NOT NULL,
        delivered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY b_user_unique (broadcast_id, user_id)
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_rewards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referrer_id BIGINT NOT NULL,
        referee_id BIGINT NOT NULL,
        reward_amount DECIMAL(10,2) NOT NULL,
        reward_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ref_pair_unique (referrer_id, referee_id)
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_checkins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT NOT NULL,
        checkin_date DATE NOT NULL,
        streak INT DEFAULT 1,
        reward_amount DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_checkin_unique (telegram_id, checkin_date)
    );");

    // Seed default categories if table is empty
    $cat_count = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($cat_count == 0) {
        $defaults = ['Photo Editing', 'Gaming Banner', 'AI Art', 'Thumbnail', 'Content Writing', 'Ad Copy', 'Product Mockup', 'Video Editing', 'ChatGPT Prompt', 'Marketing', 'Other'];
        $cat_stmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        foreach ($defaults as $cat) {
            $cat_stmt->execute([$cat]);
        }
    }
} catch (PDOException $e) {
    // Ignore error if already modified or permissions issue
}

function getUser($telegram_id, $username = '') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, step, credits, balance, is_blocked) VALUES (?, ?, 'none', 0, 0.00, 0)");
        $stmt->execute([$telegram_id, $username]);
        return ['telegram_id' => $telegram_id, 'username' => $username, 'step' => 'none', 'credits' => 0, 'balance' => 0.00, 'referred_by' => null, 'referral_reward_given' => 0, 'is_blocked' => 0];
    }
    
    // Update last_active, username, and ensure is_blocked is 0 (unblocked)
    $update_fields = [];
    $params = [];
    
    if ($user['username'] !== $username) {
        $update_fields[] = "username = ?";
        $params[] = $username;
        $user['username'] = $username;
    }
    
    if ($user['is_blocked'] == 1) {
        $update_fields[] = "is_blocked = 0";
        $user['is_blocked'] = 0;
    }
    
    // Always update last_active
    $update_fields[] = "last_active = NOW()";

    if (!empty($update_fields)) {
        $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE telegram_id = ?";
        $params[] = $telegram_id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    return $user;
}

function updateUserStep($telegram_id, $step) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET step = ? WHERE telegram_id = ?");
    $stmt->execute([$step, $telegram_id]);
}

function addCredits($telegram_id, $amount) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET credits = credits + ? WHERE telegram_id = ?");
    $stmt->execute([$amount, $telegram_id]);
}

function createNewDraft($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM submissions WHERE telegram_id = ? AND status = 'draft'");
    $stmt->execute([$telegram_id]);
    
    $stmt = $pdo->prepare("INSERT INTO submissions (telegram_id, status) VALUES (?, 'draft')");
    $stmt->execute([$telegram_id]);
    $draft_id = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
    $stmt->execute([$draft_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getDraftSubmission($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE telegram_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$telegram_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Sanitize double/multiple consecutive spaces into a single space.
 * If $multiline is true, preserves line breaks (\r, \n) while compressing horizontal spaces/tabs.
 *
 * @param string $text
 * @param bool $multiline
 * @return string
 */
function sanitizeSpaces($text, $multiline = false) {
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

function updateDraftSubmission($id, $field, $value) {
    global $pdo;
    $allowed_fields = ['category', 'output_type', 'file_id', 'text_output', 'prompt', 'tags', 'status', 'local_media', 'scheduled_at', 'posted_to_channel', 'feedback', 'is_challenge', 'channel_message_id'];
    if (in_array($field, $allowed_fields)) {
        if ($field === 'text_output' && is_string($value)) {
            $value = sanitizeSpaces($value, false);
        } elseif ($field === 'prompt' && is_string($value)) {
            $value = sanitizeSpaces($value, true);
        }
        $stmt = $pdo->prepare("UPDATE submissions SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
    }
}

function getSubmissionById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateSubmissionStatus($id, $status) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE submissions SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
}

function getAllUsers($filter = 'all') {
    global $pdo;
    $sql = "SELECT telegram_id FROM users WHERE is_blocked = 0";
    
    if ($filter === 'active') {
        $sql .= " AND last_active >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($filter === 'inactive') {
        $sql .= " AND last_active < DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($filter === 'top') {
        $sql .= " AND credits >= 50";
    }
    
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function markUserBlocked($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET is_blocked = 1 WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
}

function getUserApprovedSubmissions($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE telegram_id = ? AND status = 'approved' ORDER BY created_at DESC");
    $stmt->execute([$telegram_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTopUsers($limit = 10) {
    global $pdo;
    $limit = (int)$limit;
    $sql = "
        SELECT 
            u.username,
            u.telegram_id,
            u.balance,
            (u.balance + COALESCE(w.total_withdrawn, 0)) AS lifetime_earnings,
            COALESCE(s.approved_count, 0) AS approved_count
        FROM users u
        LEFT JOIN (
            SELECT telegram_id, SUM(amount) AS total_withdrawn
            FROM withdrawals
            WHERE status != 'rejected'
            GROUP BY telegram_id
        ) w ON u.telegram_id = w.telegram_id
        LEFT JOIN (
            SELECT telegram_id, COUNT(*) AS approved_count
            FROM submissions
            WHERE status = 'approved'
            GROUP BY telegram_id
        ) s ON u.telegram_id = s.telegram_id
        ORDER BY lifetime_earnings DESC, approved_count DESC
        LIMIT $limit
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTopReferrers($limit = 10) {
    global $pdo;
    $limit = (int)$limit;
    $stmt = $pdo->query("SELECT u.username, COUNT(r.telegram_id) as count FROM users r JOIN users u ON r.referred_by = u.telegram_id WHERE r.referral_reward_given = 1 GROUP BY u.telegram_id ORDER BY count DESC LIMIT $limit");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDetailedReferralStats($limit = 10) {
    global $pdo;
    $limit = (int)$limit;
    $stmt = $pdo->query("
        SELECT 
            u.username, 
            u.telegram_id as referrer_id,
            COUNT(r.telegram_id) as total_invites, 
            SUM(CASE WHEN r.referral_reward_given = 1 THEN 1 ELSE 0 END) as successful_invites
        FROM users r 
        JOIN users u ON r.referred_by = u.telegram_id 
        GROUP BY u.telegram_id 
        ORDER BY successful_invites DESC, total_invites DESC 
        LIMIT $limit
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getWeeklyTopCreators($limit = 3) {
    global $pdo;
    $limit = (int)$limit;
    $stmt = $pdo->query("SELECT u.username, COUNT(s.id) as count FROM submissions s JOIN users u ON s.telegram_id = u.telegram_id WHERE s.status = 'approved' AND s.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY u.username ORDER BY count DESC LIMIT $limit");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStats() {
    global $pdo;
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $stats['total_users'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM submissions");
    $stats['total_submissions'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT status, COUNT(*) FROM submissions GROUP BY status");
    $stats['status_breakdown'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE(NOW())");
    $stats['new_today'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_week'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE last_active >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['active_7d'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT category FROM submissions WHERE status = 'approved' GROUP BY category ORDER BY COUNT(*) DESC LIMIT 1");
    $stats['top_category'] = $stmt->fetchColumn();
    
    return $stats;
}

function getUserStats($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT 
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM submissions WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserRank($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT (u.balance + COALESCE(w.total_withdrawn, 0)) AS lifetime_earnings
        FROM users u
        LEFT JOIN (
            SELECT telegram_id, SUM(amount) AS total_withdrawn
            FROM withdrawals
            WHERE status != 'rejected'
            GROUP BY telegram_id
        ) w ON u.telegram_id = w.telegram_id
        WHERE u.telegram_id = ?
    ");
    $stmt->execute([$telegram_id]);
    $user_earnings = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1
        FROM users u
        LEFT JOIN (
            SELECT telegram_id, SUM(amount) AS total_withdrawn
            FROM withdrawals
            WHERE status != 'rejected'
            GROUP BY telegram_id
        ) w ON u.telegram_id = w.telegram_id
        WHERE (u.balance + COALESCE(w.total_withdrawn, 0)) > ?
    ");
    $stmt->execute([$user_earnings]);
    return (int)$stmt->fetchColumn();
}

function getCurrentChallenge() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM challenges WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getActiveChallenges() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM challenges WHERE is_active = 1 ORDER BY id DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setChallenge($theme) {
    return addChallengeKeywords($theme);
}

function addChallengeKeywords($keywords_input) {
    global $pdo;
    $keywords = array_filter(array_map('trim', explode(',', $keywords_input)));
    $added = [];
    foreach ($keywords as $kw) {
        if ($kw !== '') {
            $stmt = $pdo->prepare("INSERT INTO challenges (theme, is_active) VALUES (?, 1)");
            $stmt->execute([$kw]);
            $added[] = $kw;
        }
    }
    return $added;
}

function deleteChallenge($id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE challenges SET is_active = 0 WHERE id = ?");
    return $stmt->execute([(int)$id]);
}

function clearAllChallenges() {
    global $pdo;
    return $pdo->exec("UPDATE challenges SET is_active = 0");
}

function checkChallengeMatch($title, $prompt, $tags) {
    $active_challenges = getActiveChallenges();
    if (empty($active_challenges)) return false;

    $combined_text = mb_strtolower($title . ' ' . $prompt . ' ' . $tags, 'UTF-8');

    foreach ($active_challenges as $c) {
        $kw = mb_strtolower(trim($c['theme']), 'UTF-8');
        if ($kw !== '' && mb_strpos($combined_text, $kw) !== false) {
            return $c['theme'];
        }
    }
    return false;
}

function applyWatermarkToImageFile($filepath, $w_text = '@ai_prompt_store') {
    if (!file_exists($filepath) || filesize($filepath) === 0) return false;

    $info = @getimagesize($filepath);
    if (!$info) return false;

    $mime = $info['mime'];
    $source = null;
    switch ($mime) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($filepath);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($filepath);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $source = @imagecreatefromwebp($filepath);
            }
            break;
    }

    if (!$source) return false;

    $curr_w = imagesx($source);
    $curr_h = imagesy($source);

    $padding_x = 10;
    $padding_y = 6;
    $text_len  = strlen($w_text);
    $text_w    = $text_len * 9;
    $text_h    = 15;

    $margin_right  = 16;
    $margin_bottom = 16;

    $rect_x1 = $curr_w - $margin_right - $text_w - ($padding_x * 2);
    $rect_y1 = $curr_h - $margin_bottom - $text_h - ($padding_y * 2);
    $rect_x2 = $curr_w - $margin_right;
    $rect_y2 = $curr_h - $margin_bottom;

    if ($rect_x1 > 0 && $rect_y1 > 0) {
        $bg_color = imagecolorallocatealpha($source, 0, 0, 0, 50);
        imagefilledrectangle($source, $rect_x1, $rect_y1, $rect_x2, $rect_y2, $bg_color);

        $text_color = imagecolorallocate($source, 255, 255, 255);
        $text_x = $rect_x1 + $padding_x;
        $text_y = $rect_y1 + $padding_y;
        imagestring($source, 5, $text_x, $text_y, $w_text, $text_color);
    }

    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($source, $filepath, 85);
            break;
        case 'image/png':
            imagepng($source, $filepath, 8);
            break;
        case 'image/webp':
            if (function_exists('imagewebp')) {
                imagewebp($source, $filepath, 85);
            } else {
                imagejpeg($source, $filepath, 85);
            }
            break;
    }

    imagedestroy($source);
    return true;
}

function getUpcomingHourlySlots($count = 6) {
    global $pdo;
    date_default_timezone_set('Asia/Kolkata');

    $gap_hours = (int)getSetting('schedule_hour_gap', '1');
    if ($gap_hours < 1) $gap_hours = 1;

    // Fetch all currently booked schedule hour slots formatted as 'Y-m-d H:00:00'
    $stmt = $pdo->query("SELECT DATE_FORMAT(scheduled_at, '%Y-%m-%d %H:00:00') FROM submissions WHERE status = 'approved' AND posted_to_channel = 0 AND scheduled_at IS NOT NULL");
    $booked_hours = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $booked_set = array_flip($booked_hours);

    $slots = [];
    $current_time = time();
    // Top of next hour in IST (ensures 00 minutes)
    $next_hour_str = date('Y-m-d H:00:00', strtotime("+{$gap_hours} hour", $current_time));
    $start = strtotime($next_hour_str);
    
    for ($i = 0; $i < 48 && count($slots) < $count; $i++) {
        $timestamp = $start + ($i * 3600 * $gap_hours);
        $hour = (int)date('G', $timestamp);
        
        if ($hour >= 6 && $hour <= 23) {
            $slot_datetime = date('Y-m-d H:00:00', $timestamp);
            
            // Skip slot if another post is ALREADY scheduled for this exact hour!
            if (isset($booked_set[$slot_datetime])) {
                continue;
            }
            
            $date_str = date('Y-m-d', $timestamp);
            $today_str = date('Y-m-d', $current_time);
            $tomorrow_str = date('Y-m-d', strtotime('+1 day', $current_time));
            
            if ($date_str === $today_str) {
                $day_label = 'Today';
            } elseif ($date_str === $tomorrow_str) {
                $day_label = 'Tomorrow';
            } else {
                $day_label = date('d M', $timestamp);
            }
            $time_label = date('h:00 A', $timestamp);
            $slots[] = [
                'timestamp' => $timestamp,
                'label'     => "⏰ {$day_label} {$time_label}",
                'datetime'  => date('Y-m-d H:00:00', $timestamp)
            ];
        }
    }

    return $slots;
}

function getDailyCategorySubmissionCount($telegram_id, $category) {
    global $pdo;
    date_default_timezone_set('Asia/Kolkata');
    $today_start = date('Y-m-d 00:00:00');
    $today_end   = date('Y-m-d 23:59:59');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE telegram_id = ? AND category = ? AND status IN ('pending', 'approved', 'scheduled') AND created_at >= ? AND created_at <= ?");
    $stmt->execute([$telegram_id, $category, $today_start, $today_end]);
    return (int)$stmt->fetchColumn();
}

function getScheduledPosts() {
    global $pdo;
    // Use PHP time (IST) instead of MySQL NOW() to avoid timezone mismatch
    $now_ist = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE status = 'approved' AND posted_to_channel = 0 AND scheduled_at <= ?");
    $stmt->execute([$now_ist]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllScheduledPosts() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT s.id, s.text_output, s.category, s.scheduled_at, s.output_type, s.local_media, s.is_challenge, u.username
        FROM submissions s
        JOIN users u ON s.telegram_id = u.telegram_id
        WHERE s.status = 'approved' AND s.posted_to_channel = 0 AND s.scheduled_at IS NOT NULL
        ORDER BY s.scheduled_at ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCalendarPosts() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT s.id, s.text_output, s.category, s.scheduled_at, s.created_at, s.output_type, s.local_media, s.is_challenge, s.posted_to_channel, s.status, u.username
        FROM submissions s
        JOIN users u ON s.telegram_id = u.telegram_id
        WHERE s.status IN ('approved', 'scheduled') OR s.posted_to_channel = 1
        ORDER BY COALESCE(s.scheduled_at, s.created_at) ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSetting($key, $default = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false) ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setSetting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    return $stmt->execute([$key, $value]);
}

function markAsPosted($id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE submissions SET posted_to_channel = 1 WHERE id = ?");
    $stmt->execute([$id]);
}

function setReferrer($telegram_id, $referrer_id) {
    global $pdo;
    if ((int)$telegram_id === (int)$referrer_id) {
        return false;
    }
    // Only allow setting referrer if user account was created within the last 5 minutes
    $stmt = $pdo->prepare("UPDATE users SET referred_by = ? WHERE telegram_id = ? AND referred_by IS NULL AND created_at >= (NOW() - INTERVAL 5 MINUTE)");
    $stmt->execute([(int)$referrer_id, (int)$telegram_id]);
    return $stmt->rowCount() > 0;
}

function getReferralCount($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ?");
    $stmt->execute([$telegram_id]);
    return $stmt->fetchColumn();
}

function markReferralRewardGiven($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET referral_reward_given = 1 WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
}

function addBalance($telegram_id, $amount, $type = 'CREDIT', $category = 'BALANCE_ADD', $details = 'Balance credited') {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?");
    $stmt->execute([(float)$amount, (int)$telegram_id]);
    
    $new_bal = getBalance($telegram_id);
    $user = getUserByTelegramId($telegram_id);
    $uname = $user['username'] ?? '';

    try {
        $ins = $pdo->prepare("INSERT INTO money_transactions (telegram_id, amount, transaction_type, source, description, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([(int)$telegram_id, (float)$amount, strtoupper($type), strtoupper($category), $details, (float)$new_bal]);
    } catch (Exception $e) {}

    logMoneyTransaction($telegram_id, $uname, $type, $category, $amount, $new_bal, $details);
}

function deductBalance($telegram_id, $amount, $type = 'DEBIT', $category = 'WITHDRAWAL_DEBIT', $details = 'Balance deducted') {
    global $pdo;
    $amount = (float)$amount;
    if ($amount <= 0) return false;
    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE telegram_id = ? AND balance >= ?");
    $stmt->execute([$amount, (int)$telegram_id, $amount]);
    $success = $stmt->rowCount() > 0;
    if ($success) {
        $new_bal = getBalance($telegram_id);
        $user = getUserByTelegramId($telegram_id);
        $uname = $user['username'] ?? '';

        try {
            $ins = $pdo->prepare("INSERT INTO money_transactions (telegram_id, amount, transaction_type, source, description, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([(int)$telegram_id, -$amount, strtoupper($type), strtoupper($category), $details, (float)$new_bal]);
        } catch (Exception $e) {}

        logMoneyTransaction($telegram_id, $uname, $type, $category, -$amount, $new_bal, $details);
    }
    return $success;
}

function deductBalanceAdmin($telegram_id, $amount, $type = 'DEBIT', $category = 'ADMIN_DEBIT', $details = 'Admin deduction') {
    global $pdo;
    $amount = (float)$amount;
    if ($amount <= 0) return false;
    
    $stmt = $pdo->prepare("UPDATE users SET balance = GREATEST(0.00, balance - ?) WHERE telegram_id = ?");
    $stmt->execute([$amount, (int)$telegram_id]);
    
    $new_bal = getBalance($telegram_id);
    $user = getUserByTelegramId($telegram_id);
    $uname = $user['username'] ?? '';

    try {
        $ins = $pdo->prepare("INSERT INTO money_transactions (telegram_id, amount, transaction_type, source, description, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([(int)$telegram_id, -$amount, strtoupper($type), strtoupper($category), $details, (float)$new_bal]);
    } catch (Exception $e) {}

    logMoneyTransaction($telegram_id, $uname, $type, $category, -$amount, $new_bal, $details);
    return true;
}

function findUserByInput($target_input) {
    global $pdo;
    $target_input = trim($target_input);
    if (empty($target_input)) return null;

    if (strpos($target_input, '@') === 0) {
        $target_input = substr($target_input, 1);
    }

    if (is_numeric($target_input)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([(int)$target_input]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR username = ?");
        $stmt->execute([$target_input, '@' . $target_input]);
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getBalance($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    return (float)$stmt->fetchColumn();
}

function getDailySubmissionCount($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE telegram_id = ? AND status != 'draft' AND DATE(created_at) = CURDATE()");
    $stmt->execute([$telegram_id]);
    return (int)$stmt->fetchColumn();
}

function isAdmin($telegram_id) {
    global $pdo;
    if ($telegram_id == ADMIN_ID) return true;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    return $stmt->fetchColumn() > 0;
}

function addAdmin($telegram_id, $added_by) {
    global $pdo;
    $default_json = json_encode(getDefaultAdminPermissions());
    $stmt = $pdo->prepare("INSERT INTO admins (telegram_id, added_by, permissions) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE added_by = VALUES(added_by)");
    $stmt->execute([$telegram_id, $added_by, $default_json]);
}

function removeAdmin($telegram_id) {
    global $pdo;
    if ($telegram_id == ADMIN_ID) return false;
    $stmt = $pdo->prepare("DELETE FROM admins WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    return true;
}

function getAllAdmins() {
    global $pdo;
    $stmt = $pdo->query("SELECT telegram_id FROM admins");
    $raw_admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $admins = array_map('intval', $raw_admins);
    if (defined('ADMIN_ID') && !in_array((int)ADMIN_ID, $admins)) {
        $admins[] = (int)ADMIN_ID;
    }
    return array_values(array_unique(array_filter($admins)));
}

function getDefaultAdminPermissions() {
    return [
        'can_approve'   => 1,
        'can_schedule'  => 1,
        'can_delete'    => 0,
        'can_challenge' => 0,
        'can_settings'  => 0,
        'can_broadcast' => 0
    ];
}

function getAdminPermissions($telegram_id) {
    global $pdo;
    if ($telegram_id == ADMIN_ID) {
        return [
            'can_approve'   => 1,
            'can_schedule'  => 1,
            'can_delete'    => 1,
            'can_challenge' => 1,
            'can_settings'  => 1,
            'can_broadcast' => 1
        ];
    }
    try {
        $stmt = $pdo->prepare("SELECT permissions FROM admins WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $raw = $stmt->fetchColumn();
        if ($raw) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                return array_merge(getDefaultAdminPermissions(), $parsed);
            }
        }
    } catch (Exception $e) {}
    return getDefaultAdminPermissions();
}

function hasAdminPermission($telegram_id, $permission_key) {
    if ($telegram_id == ADMIN_ID) return true; // Super Admin bypass
    if (!isAdmin($telegram_id)) return false;
    $perms = getAdminPermissions($telegram_id);
    return !empty($perms[$permission_key]);
}

function toggleAdminPermission($telegram_id, $permission_key) {
    global $pdo;
    if ($telegram_id == ADMIN_ID) return false; // Super Admin cannot be restricted
    $perms = getAdminPermissions($telegram_id);
    $perms[$permission_key] = empty($perms[$permission_key]) ? 1 : 0;
    $json = json_encode($perms);
    $stmt = $pdo->prepare("UPDATE admins SET permissions = ? WHERE telegram_id = ?");
    return $stmt->execute([$json, $telegram_id]);
}

function createWithdrawal($telegram_id, $amount, $upi_id) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, upi_id) VALUES (?, ?, ?)");
    $stmt->execute([$telegram_id, $amount, $upi_id]);
    return $pdo->lastInsertId();
}

function getPendingWithdrawals() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM withdrawals WHERE status = 'pending' ORDER BY created_at ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function processWithdrawal($id, $status, $processed_by) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE withdrawals SET status = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$status, $processed_by, $id]);
}

function getWithdrawalById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ==========================================
// CATEGORY MANAGEMENT
// ==========================================

function getCategories($active_only = true) {
    global $pdo;
    if ($active_only) {
        $stmt = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name ASC");
    } else {
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addCategory($name) {
    global $pdo;
    $name = trim($name);
    if (empty($name)) return false;
    $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name, is_active) VALUES (?, 1)");
    $stmt->execute([$name]);
    return $pdo->lastInsertId() > 0;
}

function removeCategory($id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->rowCount() > 0;
}

function getCategoryById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function toggleCategory($id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE categories SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->rowCount() > 0;
}

function updateCategoryName($id, $new_name) {
    global $pdo;
    $new_name = trim($new_name);
    if (empty($new_name)) return false;
    $cat = getCategoryById($id);
    if (!$cat) return false;
    $old_name = $cat['name'];

    $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
    $stmt->execute([$new_name, (int)$id]);

    if ($old_name !== $new_name) {
        $stmt2 = $pdo->prepare("UPDATE submissions SET category = ? WHERE category = ?");
        $stmt2->execute([$new_name, $old_name]);
    }
    return true;
}

function getUserByTelegramId($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function createSupportTicket($telegram_id, $category, $message) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO support_tickets (telegram_id, category, message, status) VALUES (?, ?, ?, 'open')");
    $stmt->execute([(int)$telegram_id, trim($category), trim($message)]);
    return $pdo->lastInsertId();
}

function getSupportTicketById($ticket_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
    $stmt->execute([(int)$ticket_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function replySupportTicket($ticket_id, $admin_id, $reply_text) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'replied', admin_reply = ?, replied_by = ? WHERE id = ?");
    $stmt->execute([trim($reply_text), (int)$admin_id, (int)$ticket_id]);
    return $stmt->rowCount() > 0;
}

function closeSupportTicket($ticket_id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'closed' WHERE id = ?");
    $stmt->execute([(int)$ticket_id]);
    return $stmt->rowCount() > 0;
}

function getSupportTickets($status = 'open', $limit = 20) {
    global $pdo;
    $limit = (int)$limit;
    if ($status === 'all') {
        $stmt = $pdo->prepare("
            SELECT st.*, u.username 
            FROM support_tickets st 
            LEFT JOIN users u ON st.telegram_id = u.telegram_id 
            ORDER BY st.id DESC LIMIT $limit
        ");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT st.*, u.username 
            FROM support_tickets st 
            LEFT JOIN users u ON st.telegram_id = u.telegram_id 
            WHERE st.status = ? 
            ORDER BY st.id DESC LIMIT $limit
        ");
        $stmt->execute([$status]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getOpenSupportTicketsCount() {
    global $pdo;
    $stmt = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'");
    return (int)$stmt->fetchColumn();
}

function isMaintenanceModeEnabled() {
    return getSetting('maintenance_mode', '0') === '1';
}

function getMaintenanceReason() {
    return getSetting('maintenance_reason', 'Upgrading system servers for better performance.');
}

function isUserBanned($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT is_banned FROM users WHERE telegram_id = ?");
    $stmt->execute([(int)$telegram_id]);
    return (int)$stmt->fetchColumn() === 1;
}

function getUserBanInfo($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT is_banned, ban_reason FROM users WHERE telegram_id = ?");
    $stmt->execute([(int)$telegram_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function banUser($target_input, $reason = 'Violation of community guidelines') {
    global $pdo;
    $target_input = trim($target_input);
    if (strpos($target_input, '@') === 0) {
        $target_input = substr($target_input, 1);
    }
    
    if (is_numeric($target_input)) {
        $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE telegram_id = ?");
        $stmt->execute([trim($reason), (int)$target_input]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE username = ? OR username = ?");
        $stmt->execute([trim($reason), $target_input, '@' . $target_input]);
    }
    return $stmt->rowCount() > 0;
}

function unbanUser($target_input) {
    global $pdo;
    $target_input = trim($target_input);
    if (strpos($target_input, '@') === 0) {
        $target_input = substr($target_input, 1);
    }
    
    if (is_numeric($target_input)) {
        $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE telegram_id = ?");
        $stmt->execute([(int)$target_input]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE username = ? OR username = ?");
        $stmt->execute([$target_input, '@' . $target_input]);
    }
    return $stmt->rowCount() > 0;
}

function getBannedUsers() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM users WHERE is_banned = 1 ORDER BY last_active DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTodayApprovedCount($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE telegram_id = ? AND status = 'approved' AND DATE(created_at) = CURDATE()");
    $stmt->execute([(int)$telegram_id]);
    return (int)$stmt->fetchColumn();
}

function hasEarnedDailyBonusToday($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_bonuses WHERE telegram_id = ? AND bonus_date = CURDATE()");
    $stmt->execute([(int)$telegram_id]);
    return (int)$stmt->fetchColumn() > 0;
}

function awardDailyBonus($telegram_id, $amount = 0.10) {
    global $pdo;
    if (hasEarnedDailyBonusToday($telegram_id)) {
        return false;
    }
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO daily_bonuses (telegram_id, bonus_date, bonus_amount) VALUES (?, CURDATE(), ?)");
    $stmt->execute([(int)$telegram_id, (float)$amount]);
    
    if ($stmt->rowCount() > 0) {
        addBalance($telegram_id, (float)$amount);
        return true;
    }
    return false;
}

function createBroadcastQueue($admin_id, $chat_id, $message_id, $audience, $button_text = null, $button_url = null, $status_msg_id = null) {
    global $pdo;
    $users = getAllUsers($audience);
    $total = count($users);
    $stmt = $pdo->prepare("INSERT INTO broadcast_queue (admin_id, chat_id, message_id, status_msg_id, audience, button_text, button_url, status, total_count) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
    $stmt->execute([(int)$admin_id, (int)$chat_id, (int)$message_id, $status_msg_id ? (int)$status_msg_id : null, $audience, $button_text, $button_url, $total]);
    return $pdo->lastInsertId();
}

function stopAllBroadcasts() {
    global $pdo;
    $stmt = $pdo->query("UPDATE broadcast_queue SET status = 'stopped' WHERE status IN ('pending', 'processing')");
    return $stmt->rowCount();
}

function getBroadcastQueueById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM broadcast_queue WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getActiveBroadcastQueue() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM broadcast_queue WHERE status IN ('pending', 'processing') ORDER BY id ASC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateBroadcastQueueStatus($id, $status, $delivered = null, $failed = null) {
    global $pdo;
    $fields = ["status = ?"];
    $params = [$status];
    if ($delivered !== null) {
        $fields[] = "delivered_count = ?";
        $params[] = (int)$delivered;
    }
    if ($failed !== null) {
        $fields[] = "failed_count = ?";
        $params[] = (int)$failed;
    }
    $params[] = (int)$id;
    $sql = "UPDATE broadcast_queue SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

function isUserDeliveredBroadcast($broadcast_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM broadcast_deliveries WHERE broadcast_id = ? AND user_id = ?");
    $stmt->execute([(int)$broadcast_id, (int)$user_id]);
    return (int)$stmt->fetchColumn() > 0;
}

function recordBroadcastDelivery($broadcast_id, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT IGNORE INTO broadcast_deliveries (broadcast_id, user_id) VALUES (?, ?)");
    $stmt->execute([(int)$broadcast_id, (int)$user_id]);
    return $stmt->rowCount() > 0;
}

function processBroadcastBatch($b_id) {
    @set_time_limit(0);
    @ignore_user_abort(true);

    $job = getBroadcastQueueById($b_id);
    if (!$job || !in_array($job['status'], ['pending', 'processing'])) {
        return false;
    }

    updateBroadcastQueueStatus($b_id, 'processing');

    $users = getAllUsers($job['audience']);
    $total = count($users);
    $delivered = (int)$job['delivered_count'];
    $failed = (int)$job['failed_count'];
    $admin_id = $job['admin_id'];
    $admin_chat = $job['chat_id'];
    $msg_id = $job['message_id'];
    $status_msg_id = $job['status_msg_id'];

    $copy_params = [
        'from_chat_id' => $admin_chat,
        'message_id' => $msg_id
    ];

    if (!empty($job['button_text']) && !empty($job['button_url'])) {
        $copy_params['reply_markup'] = [
            'inline_keyboard' => [
                [['text' => $job['button_text'], 'url' => $job['button_url']]]
            ]
        ];
    }

    $processed_in_this_run = 0;

    foreach ($users as $uid) {
        $current_job = getBroadcastQueueById($b_id);
        if (!$current_job || $current_job['status'] === 'stopped') {
            break;
        }

        if (isUserDeliveredBroadcast($b_id, $uid)) {
            continue;
        }

        $copy_params['chat_id'] = $uid;
        $res = apiRequest("copyMessage", $copy_params, true);

        if ($res && isset($res['ok']) && $res['ok']) {
            $delivered++;
            recordBroadcastDelivery($b_id, $uid);
        } else {
            $failed++;
            if ($res && isset($res['description']) && strpos($res['description'], 'blocked by the user') !== false) {
                markUserBlocked($uid);
            }
            recordBroadcastDelivery($b_id, $uid);
        }

        $processed_in_this_run++;
        $sent_now = $delivered + $failed;

        // Real-time update every 10 users or when complete
        if ($processed_in_this_run % 10 === 0 || $sent_now >= $total) {
            updateBroadcastQueueStatus($b_id, 'processing', $delivered, $failed);

            if (!empty($status_msg_id)) {
                $pct = $total > 0 ? round(($sent_now / $total) * 100) : 100;
                $bar_filled = (int)($pct / 5);
                $bar_empty = 20 - $bar_filled;
                $progress_bar = str_repeat('▓', $bar_filled) . str_repeat('░', $bar_empty);

                $stop_kb = ['inline_keyboard' => [[['text' => '🛑 Stop Broadcast', 'callback_data' => "b_stop_{$b_id}"]]]];

                try {
                    apiRequest("editMessageText", [
                        'chat_id' => $admin_chat,
                        'message_id' => $status_msg_id,
                        'text' => "📡 <b>Broadcasting to " . strtoupper($job['audience']) . " Users... (ID: #{$b_id})</b>\n\n" .
                                  "$progress_bar <b>{$pct}%</b>\n\n" .
                                  "📤 Sent: <b>$sent_now</b> / <b>$total</b>\n" .
                                  "✅ Delivered: <b>$delivered</b>\n" .
                                  "❌ Failed: <b>$failed</b>\n\n" .
                                  "⏳ <i>Sending live updates...</i>",
                        'parse_mode' => 'HTML',
                        'reply_markup' => $stop_kb
                    ], true);
                } catch (Exception $e) {
                    error_log("Live update edit error: " . $e->getMessage());
                }
            }
        }

        usleep(35000); // 35ms rate limit pause
    }

    $sent = $delivered + $failed;
    $final_status = ($sent >= $total) ? 'completed' : (($current_job['status'] === 'stopped') ? 'stopped' : 'processing');
    updateBroadcastQueueStatus($b_id, $final_status, $delivered, $failed);

    // Final card update
    if (!empty($status_msg_id)) {
        $pct = $total > 0 ? round(($sent / $total) * 100) : 100;
        $bar_filled = (int)($pct / 5);
        $bar_empty = 20 - $bar_filled;
        $progress_bar = str_repeat('▓', $bar_filled) . str_repeat('░', $bar_empty);

        $status_label = ($final_status === 'completed') ? "✅ <b>Completed!</b>" : (($final_status === 'stopped') ? "🛑 <b>Stopped by Admin!</b>" : "⏳ <b>Processing...</b>");
        $stop_kb = ($final_status === 'processing') ? ['inline_keyboard' => [[['text' => '🛑 Stop Broadcast', 'callback_data' => "b_stop_{$b_id}"]]]] : null;

        try {
            apiRequest("editMessageText", [
                'chat_id' => $admin_chat,
                'message_id' => $status_msg_id,
                'text' => "📡 <b>Broadcasting to " . strtoupper($job['audience']) . " Users... (ID: #{$b_id})</b>\n\n" .
                          "$progress_bar <b>{$pct}%</b>\n\n" .
                          "📤 Sent: <b>$sent</b> / <b>$total</b>\n" .
                          "✅ Delivered: <b>$delivered</b>\n" .
                          "❌ Failed: <b>$failed</b>\n\n" .
                          $status_label,
                'parse_mode' => 'HTML',
                'reply_markup' => $stop_kb
            ], true);
        } catch (Exception $e) {
            error_log("Final status card edit error: " . $e->getMessage());
        }
    }

    return $final_status;
}

function getTodayReferralRewardCount($referrer_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral_rewards WHERE referrer_id = ? AND reward_date = CURDATE()");
    $stmt->execute([(int)$referrer_id]);
    return (int)$stmt->fetchColumn();
}

function getTotalReferralRewardCount($referrer_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral_rewards WHERE referrer_id = ?");
    $stmt->execute([(int)$referrer_id]);
    return (int)$stmt->fetchColumn();
}

function recordReferralReward($referrer_id, $referee_id, $reward_amount) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT IGNORE INTO referral_rewards (referrer_id, referee_id, reward_amount, reward_date) VALUES (?, ?, ?, CURDATE())");
    $stmt->execute([(int)$referrer_id, (int)$referee_id, (float)$reward_amount]);
    return $stmt->rowCount() > 0;
}

// ==========================================
// GOOGLE SHEETS 10-TAB MASTER AUDIT LOGGER
// ==========================================

function getGoogleSheetUrl() {
    $setting_url = getSetting('google_sheet_webhook_url');
    if (!empty($setting_url)) {
        return trim($setting_url);
    }
    if (defined('GOOGLE_SHEET_WEBHOOK_URL') && !empty(GOOGLE_SHEET_WEBHOOK_URL)) {
        return trim(GOOGLE_SHEET_WEBHOOK_URL);
    }
    return false;
}

function logToGoogleSheetMultiTab($tab_name, $row_data, $headers, $telegram_id = '', $username = '', $action = '', $details = '', $amount = '-', $status = 'OK') {
    $url = getGoogleSheetUrl();
    if (!$url) return false;

    date_default_timezone_set('Asia/Kolkata');
    $timestamp = date('d/m/Y, h:i:s A');
    $event_id = 'EVT-' . time() . '-' . rand(100, 999);

    $payload = [
        'timestamp'   => $timestamp,
        'event_id'    => $event_id,
        'tab'         => $tab_name,
        'telegram_id' => (string)$telegram_id,
        'username'    => (string)$username,
        'action'      => (string)$action,
        'details'     => (string)$details,
        'amount'      => (string)$amount,
        'status'      => (string)$status,
        'headers'     => $headers,
        'row_data'    => array_merge([$timestamp], $row_data)
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500); // Fast non-blocking timeout
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    @curl_exec($ch);
    @curl_close($ch);
    return true;
}

function logMoneyTransaction($telegram_id, $username, $type, $category, $amount, $balance_after, $details) {
    $headers = ["Timestamp (IST)", "Telegram ID", "Username", "Type (CREDIT/DEBIT)", "Event Category", "Amount (₹)", "Balance After (₹)", "Details / UPI ID"];
    $row_data = [
        (string)$telegram_id,
        (string)$username,
        strtoupper($type),
        strtoupper($category),
        (is_numeric($amount) ? ((float)$amount >= 0 ? '+₹' : '-₹') . number_format(abs($amount), 2) : (string)$amount),
        '₹' . number_format((float)$balance_after, 2),
        (string)$details
    ];
    return logToGoogleSheetMultiTab("02_MONEY_TRANSACTIONS", $row_data, $headers, $telegram_id, $username, $type, $details, $amount, "OK");
}

function logReferralEvent($referrer_id, $ref_username, $referee_id, $referee_username, $status, $amount = '-') {
    $headers = ["Timestamp (IST)", "Referrer ID", "Referrer Username", "Referee ID", "Referee Username", "Qualification Status", "Reward Amount (₹)", "Date Credited"];
    $row_data = [
        (string)$referrer_id,
        (string)$ref_username,
        (string)$referee_id,
        (string)$referee_username,
        (string)$status,
        (is_numeric($amount) ? '₹' . number_format($amount, 2) : (string)$amount),
        date('d/m/Y')
    ];
    return logToGoogleSheetMultiTab("03_REFERRALS", $row_data, $headers, $referrer_id, $ref_username, "REFERRAL_" . $status, "Referee: $referee_username", $amount, $status);
}

function logAdminActivity($admin_id, $admin_username, $action, $target_id, $details) {
    $headers = ["Timestamp (IST)", "Admin ID", "Admin Username", "Action Performed", "Target ID / User", "Action Details"];
    $row_data = [
        (string)$admin_id,
        (string)$admin_username,
        strtoupper($action),
        (string)$target_id,
        (string)$details
    ];
    return logToGoogleSheetMultiTab("04_ADMIN_ACTIVITIES", $row_data, $headers, $admin_id, $admin_username, $action, $details, "-", "OK");
}

function logUserActivity($telegram_id, $username, $action, $step, $details) {
    $headers = ["Timestamp (IST)", "Telegram ID", "Username", "Command / Action", "Current Step", "Step Input Details"];
    $row_data = [
        (string)$telegram_id,
        (string)$username,
        (string)$action,
        (string)$step,
        (string)$details
    ];
    return logToGoogleSheetMultiTab("05_USER_ACTIVITIES", $row_data, $headers, $telegram_id, $username, $action, $details, "-", "OK");
}

function logApprovalRejection($sub_id, $creator_id, $creator_uname, $category, $output_type, $admin_uname, $decision, $reason_or_date) {
    $headers = ["Timestamp (IST)", "Submission ID", "Creator ID", "Creator Username", "Category", "Output Type", "Reviewer Admin", "Decision", "Rejection Reason / Scheduled Date"];
    $row_data = [
        "#" . $sub_id,
        (string)$creator_id,
        (string)$creator_uname,
        (string)$category,
        strtoupper($output_type),
        (string)$admin_uname,
        strtoupper($decision),
        (string)$reason_or_date
    ];
    return logToGoogleSheetMultiTab("06_APPROVALS_REJECTIONS", $row_data, $headers, $creator_id, $creator_uname, $decision, "Sub #$sub_id ($category) reviewed by $admin_uname", "-", $decision);
}

function logContentEdit($sub_id, $admin_uname, $field, $old_val, $new_val) {
    $headers = ["Timestamp (IST)", "Submission ID", "Admin Editor", "Field Modified", "Previous Content", "Updated Content"];
    $row_data = [
        "#" . $sub_id,
        (string)$admin_uname,
        strtoupper($field),
        mb_strimwidth((string)$old_val, 0, 150, "..."),
        mb_strimwidth((string)$new_val, 0, 150, "...")
    ];
    return logToGoogleSheetMultiTab("07_EDITS_MODIFICATIONS", $row_data, $headers, "-", $admin_uname, "EDIT_" . strtoupper($field), "Sub #$sub_id field $field updated", "-", "EDITED");
}

function logBanUnban($admin_uname, $target_id, $target_uname, $action, $reason) {
    $headers = ["Timestamp (IST)", "Admin Handler", "Target Telegram ID", "Target Username", "Action (BAN/UNBAN)", "Ban Reason / Appeal Note"];
    $row_data = [
        (string)$admin_uname,
        (string)$target_id,
        (string)$target_uname,
        strtoupper($action),
        (string)$reason
    ];
    return logToGoogleSheetMultiTab("08_BAN_UNBAN_LOG", $row_data, $headers, $target_id, $target_uname, $action, $reason, "-", $action);
}

function logTechnicalIssue($severity, $file, $line, $message, $user_id = '', $stack_trace = '') {
    $headers = ["Timestamp (IST)", "Error Severity", "Source File", "Line Number", "Error Message", "Affected User", "Stack Trace Snippet"];
    $row_data = [
        strtoupper($severity),
        basename((string)$file),
        (string)$line,
        (string)$message,
        (string)$user_id,
        mb_strimwidth((string)$stack_trace, 0, 200, "...")
    ];
    return logToGoogleSheetMultiTab("09_TECHNICAL_ISSUES", $row_data, $headers, $user_id, "", "ERROR_" . strtoupper($severity), "$message ($file:$line)", "-", "ERROR");
}

function logSupportTicket($ticket_id, $user_id, $username, $category, $user_msg, $admin_reply = '', $status = 'OPEN') {
    $headers = ["Timestamp (IST)", "Ticket ID", "User ID", "Username", "Support Category", "User Question / Issue", "Admin Reply", "Ticket Status"];
    $row_data = [
        "#T-" . $ticket_id,
        (string)$user_id,
        (string)$username,
        (string)$category,
        mb_strimwidth((string)$user_msg, 0, 200, "..."),
        mb_strimwidth((string)$admin_reply, 0, 200, "..."),
        strtoupper($status)
    ];
    return logToGoogleSheetMultiTab("10_SUPPORT_TICKETS", $row_data, $headers, $user_id, $username, "TICKET_" . strtoupper($status), "Ticket #T-$ticket_id ($category)", "-", $status);
}

// ─────────────────────────────────────────────────────────────────────────────
// DAILY CHECK-IN & STREAK SYSTEM HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function getCheckinRewardForStreak($streak) {
    $streak = (int)$streak;
    if ($streak <= 1) return 0.10;
    if ($streak === 2) return 0.15;
    if ($streak === 3) return 0.20;
    if ($streak === 4) return 0.25;
    if ($streak === 5) return 0.30;
    if ($streak === 6) return 0.35;
    return 0.40; // Day 7 and above continues at ₹0.40
}

function getUserCheckinStatus($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT streak_count, last_checkin_date, streak_freeze_count FROM users WHERE telegram_id = ?");
    $stmt->execute([(int)$telegram_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $streak_count = (int)($row['streak_count'] ?? 0);
    $last_date = $row['last_checkin_date'] ?? null;
    $freeze_count = (int)($row['streak_freeze_count'] ?? 0);

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $day_before_yesterday = date('Y-m-d', strtotime('-2 days'));

    $claimed_today = ($last_date === $today);

    if ($claimed_today) {
        $current_streak = max(1, $streak_count);
        $next_streak = $current_streak + 1;
        $today_reward = getCheckinRewardForStreak($current_streak);
        $next_reward = getCheckinRewardForStreak($next_streak);
        return [
            'can_claim'      => false,
            'claimed_today'  => true,
            'current_streak' => $current_streak,
            'next_streak'    => $next_streak,
            'today_reward'   => $today_reward,
            'next_reward'    => $next_reward,
            'last_checkin'   => $last_date,
            'streak_freeze'  => $freeze_count,
            'freeze_used'    => false
        ];
    }

    $freeze_used = false;

    // Check streak continuity:
    if ($last_date === $yesterday) {
        // Checked in yesterday: normal continuation
        $current_streak = $streak_count;
        $next_streak = $streak_count + 1;
    } elseif ($last_date === $day_before_yesterday && $freeze_count > 0 && $streak_count > 0) {
        // Missed exactly 1 day (yesterday) AND has a streak freeze shield!
        // Freeze protects the streak!
        $current_streak = $streak_count;
        $next_streak = $streak_count + 1;
        $freeze_used = true;
    } else {
        // Missed day with no freeze, or missed 2+ days -> streak resets to 1
        $current_streak = 0;
        $next_streak = 1;
    }

    $reward = getCheckinRewardForStreak($next_streak);

    return [
        'can_claim'      => true,
        'claimed_today'  => false,
        'current_streak' => $current_streak,
        'next_streak'    => $next_streak,
        'today_reward'   => $reward,
        'next_reward'    => $reward,
        'last_checkin'   => $last_date,
        'streak_freeze'  => $freeze_count,
        'freeze_used'    => $freeze_used
    ];
}

function claimDailyCheckin($telegram_id) {
    global $pdo;
    $status = getUserCheckinStatus($telegram_id);
    if (!$status['can_claim']) {
        return [
            'success' => false,
            'error'   => 'ALREADY_CLAIMED',
            'message' => 'You have already checked in today! Come back tomorrow at 12:00 AM IST.',
            'status'  => $status
        ];
    }

    $new_streak = $status['next_streak'];
    $reward = $status['today_reward'];
    $today = date('Y-m-d');
    $freeze_used = $status['freeze_used'];

    try {
        // Atomic insert guarded by UNIQUE KEY (telegram_id, checkin_date)
        $ins = $pdo->prepare("INSERT INTO daily_checkins (telegram_id, checkin_date, streak, reward_amount) VALUES (?, ?, ?, ?)");
        $ins->execute([(int)$telegram_id, $today, $new_streak, $reward]);
    } catch (Exception $e) {
        // Duplicate check-in race condition caught
        return [
            'success' => false,
            'error'   => 'ALREADY_CLAIMED',
            'message' => 'You have already checked in today! Come back tomorrow at 12:00 AM IST.',
            'status'  => getUserCheckinStatus($telegram_id)
        ];
    }

    // Update user streak count and last checkin date, and deduct freeze if consumed
    if ($freeze_used) {
        $upd = $pdo->prepare("UPDATE users SET streak_count = ?, last_checkin_date = ?, streak_freeze_count = GREATEST(0, streak_freeze_count - 1) WHERE telegram_id = ?");
    } else {
        $upd = $pdo->prepare("UPDATE users SET streak_count = ?, last_checkin_date = ? WHERE telegram_id = ?");
    }
    $upd->execute([$new_streak, $today, (int)$telegram_id]);

    // Credit balance and record transaction (addBalance logs to money_transactions and Google Sheets)
    $desc = ($new_streak >= 7) ? "Day {$new_streak} (7+ Milestone) Check-in" : "Day {$new_streak} Daily Check-in";
    if ($freeze_used) {
        $desc .= " (🛡️ Streak Freeze Shield Used)";
    }
    addBalance($telegram_id, $reward, 'CREDIT', 'DAILY_CHECKIN', "{$desc} (+₹" . number_format($reward, 2) . ")");

    $updated_status = getUserCheckinStatus($telegram_id);
    return [
        'success'        => true,
        'streak'         => $new_streak,
        'reward'         => $reward,
        'freeze_used'    => $freeze_used,
        'updated_status' => $updated_status
    ];
}

function checkAndAwardStreakFreeze($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT streak_freeze_count, freezes_awarded_at FROM users WHERE telegram_id = ?");
    $stmt->execute([(int)$telegram_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    $freeze_count = (int)($row['streak_freeze_count'] ?? 0);
    $last_awarded_at = (int)($row['freezes_awarded_at'] ?? 0);

    // If user already holds 1 active streak freeze, max capacity reached
    if ($freeze_count >= 1) {
        return false;
    }

    // Check approved prompts count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE telegram_id = ? AND status = 'approved'");
    $stmt->execute([(int)$telegram_id]);
    $approved_count = (int)$stmt->fetchColumn();

    // Earn 1 freeze after every 3 approved prompts
    if ($approved_count >= 3 && ($approved_count - $last_awarded_at) >= 3) {
        $upd = $pdo->prepare("UPDATE users SET streak_freeze_count = 1, freezes_awarded_at = ? WHERE telegram_id = ?");
        $upd->execute([$approved_count, (int)$telegram_id]);
        return true;
    }
    return false;
}