CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    username VARCHAR(255),
    step VARCHAR(50) DEFAULT 'none',
    credits INT DEFAULT 0,
    balance DECIMAL(10,2) DEFAULT 0.00,
    referred_by BIGINT NULL,
    referral_reward_given TINYINT(1) DEFAULT 0
);

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL,
    category VARCHAR(100),
    output_type VARCHAR(50),
    file_id TEXT,
    text_output TEXT,
    prompt TEXT,
    tags VARCHAR(255),
    status VARCHAR(20) DEFAULT 'draft',
    local_media TEXT,
    scheduled_at TIMESTAMP NULL DEFAULT NULL,
    posted_to_channel TINYINT(1) DEFAULT 1,
    feedback TEXT NULL,
    is_challenge TINYINT(1) DEFAULT 0,
    channel_message_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS challenges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    theme VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    added_by BIGINT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    upi_id VARCHAR(255) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    processed_by BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL
);
