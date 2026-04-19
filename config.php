<?php
// ============================================================
// ORBIT - Cấu hình kết nối Database
// File: config.php
// ============================================================

// --- Thông số kết nối MySQL (XAMPP mặc định) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'orbit_db');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP mặc định không có mật khẩu
define('DB_CHARSET', 'utf8mb4');

// --- Hàm lấy kết nối PDO (Singleton đơn giản) ---
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exception khi lỗi
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Trả về mảng kết hợp
            PDO::ATTR_EMULATE_PREPARES   => false,                   // Dùng prepared statements thật
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Hiển thị lỗi kết nối (không expose password)
            die(json_encode([
                'error'   => true,
                'message' => 'Không thể kết nối database: ' . $e->getMessage()
            ]));
        }
    }

    return $pdo;
}
