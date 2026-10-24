-- Real Simple RSS database schema (MySQL / MariaDB)
-- Executed automatically by setup.php during first-run installation.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feeds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    feed_url TEXT NOT NULL,
    site_url TEXT,
    last_fetched DATETIME NULL,
    last_status VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feed_id INT NOT NULL,
    guid VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    content LONGTEXT,
    author VARCHAR(100),
    pub_date DATETIME,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_post (feed_id, guid),
    KEY idx_feed_read (feed_id, is_read),
    KEY idx_pub_date (pub_date),
    FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Login throttling (also auto-created at runtime for pre-existing installs).
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_identifier (identifier, attempted_at),
    KEY idx_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
