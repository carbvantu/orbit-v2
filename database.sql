-- ============================================================
-- ORBIT - Hệ thống lên lịch đăng video đa kênh
-- File: database.sql
-- Mô tả: Tạo database và tất cả các bảng
-- ============================================================

-- Tạo database nếu chưa có
CREATE DATABASE IF NOT EXISTS orbit_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE orbit_db;

-- ============================================================
-- BẢNG accounts — Quản lý tài khoản đa nền tảng
-- ============================================================
CREATE TABLE IF NOT EXISTS accounts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform     ENUM('facebook','tiktok','youtube') NOT NULL           COMMENT 'Nền tảng',
    account_name VARCHAR(100) NOT NULL                                  COMMENT 'Tên hiển thị (vd: Trang MMO chính)',
    username     VARCHAR(100)                                           COMMENT 'Username / Email đăng nhập',
    profile_path VARCHAR(500)                                           COMMENT 'Đường dẫn Chrome user-data-dir',
    profile_dir  VARCHAR(100) NOT NULL DEFAULT 'Default'               COMMENT 'Tên profile trong user-data-dir',
    status       ENUM('active','inactive') NOT NULL DEFAULT 'active'   COMMENT 'Trạng thái tài khoản',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    INDEX idx_platform_status (platform, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Quản lý tài khoản đăng bài trên các nền tảng';

-- ============================================================
-- BẢNG settings — Cài đặt hệ thống (key-value)
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(100) NOT NULL                                    COMMENT 'Tên cài đặt',
    `value`    TEXT                                                     COMMENT 'Giá trị',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cài đặt hệ thống ORBIT';

-- Cài đặt mặc định Telegram (bỏ trống token và chat_id)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('telegram_bot_token',      ''),
    ('telegram_chat_id',        ''),
    ('telegram_enabled',        '0'),
    ('telegram_notify_success', '1'),
    ('telegram_notify_failed',  '1');

-- ============================================================
-- BẢNG video_tasks
-- ============================================================
-- Xóa bảng cũ nếu tồn tại (dùng khi reset hoàn toàn)
DROP TABLE IF EXISTS video_tasks;

-- Tạo bảng video_tasks
CREATE TABLE video_tasks (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    platform      ENUM('facebook','tiktok','youtube') NOT NULL COMMENT 'Nền tảng đăng video',
    account_id    INT UNSIGNED    NULL                          COMMENT 'Tài khoản đăng (FK → accounts.id)',
    video_path    VARCHAR(512)    NOT NULL                      COMMENT 'Đường dẫn file video trên máy chủ',
    caption       TEXT            NOT NULL                      COMMENT 'Nội dung caption / mô tả video',
    scheduled_at  DATETIME        NOT NULL                      COMMENT 'Thời gian lên lịch đăng',
    status        ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending'
                                                                COMMENT 'Trạng thái task',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY  (id),
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_account_id       (account_id),
    CONSTRAINT fk_task_account FOREIGN KEY (account_id)
        REFERENCES accounts(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bảng lưu các task lên lịch đăng video tự động';

-- ============================================================
-- DỮ LIỆU MẪU (xóa khi production)
-- ============================================================
-- Thêm tài khoản mẫu
INSERT INTO accounts (platform, account_name, username, profile_path, profile_dir) VALUES
('facebook', 'Fanpage MMO chính',       'mmo.page@gmail.com', 'C:/ChromeProfiles/FB_Main',  'Default'),
('tiktok',   'TikTok chính thức',       'orbit.tiktok',       'C:/ChromeProfiles/TK_Main',  'Default'),
('youtube',  'Kênh YouTube ORBIT',      'orbit.yt@gmail.com', 'C:/ChromeProfiles/YT_Main',  'Default');

-- Thêm task mẫu (dùng account_id = 1, 2, 3 từ bảng accounts)
INSERT INTO video_tasks (platform, account_id, video_path, caption, scheduled_at, status) VALUES
('youtube',  3, 'C:/videos/review_phone.mp4',   'Review điện thoại mới nhất 2025 | ORBIT Auto',  NOW() - INTERVAL 2 HOUR,    'completed'),
('facebook', 1, 'C:/videos/vlog_hanoi.mp4',     'Vlog Hà Nội cuối tuần - Khám phá ẩm thực 🍜',   NOW() - INTERVAL 30 MINUTE, 'completed'),
('tiktok',   2, 'C:/videos/trending_dance.mp4', '#trending #dance #viral - ORBIT scheduled',      NOW() + INTERVAL 1 HOUR,    'pending'),
('youtube',  3, 'C:/videos/tutorial_edit.mp4',  'Hướng dẫn chỉnh sửa video chuyên nghiệp',        NOW() + INTERVAL 3 HOUR,    'pending'),
('facebook', 1, 'C:/videos/ads_promo.mp4',      'Quảng cáo sản phẩm - Flash Sale 50%',            NOW() - INTERVAL 10 MINUTE, 'failed');
