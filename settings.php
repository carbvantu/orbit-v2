<?php
// ============================================================
// ORBIT - Settings API
// File: settings.php
// Mô tả: Đọc và ghi cài đặt hệ thống (key-value trong DB).
//        Hiện tại quản lý: cấu hình Telegram Bot.
// GET  → Trả về toàn bộ settings dưới dạng JSON
// POST → Lưu settings (chỉ các key được cho phép)
// POST action=test_telegram → Gửi tin nhắn test đến Telegram
// ============================================================

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_POST['action'] ?? '');

// Danh sách key được phép đọc/ghi
const ALLOWED_SETTINGS = [
    'telegram_bot_token',
    'telegram_chat_id',
    'telegram_enabled',
    'telegram_notify_success',
    'telegram_notify_failed',
];

try {
    $db = getDB();

    // ============================================================
    // GET — Lấy tất cả settings
    // ============================================================
    if ($method === 'GET') {
        $stmt     = $db->query("SELECT `key`, `value` FROM settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
        // Thêm giá trị mặc định nếu key chưa có trong DB
        $defaults = [
            'telegram_bot_token'      => '',
            'telegram_chat_id'        => '',
            'telegram_enabled'        => '0',
            'telegram_notify_success' => '1',
            'telegram_notify_failed'  => '1',
        ];
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $settings)) $settings[$k] = $v;
        }
        // Ẩn một phần token để bảo mật trước khi gửi về frontend
        if (!empty($settings['telegram_bot_token'])) {
            $token = $settings['telegram_bot_token'];
            // Chỉ hiện 6 ký tự đầu + ...
            $settings['telegram_bot_token_masked'] = substr($token, 0, 6) . str_repeat('*', max(0, strlen($token) - 6));
        } else {
            $settings['telegram_bot_token_masked'] = '';
        }

        echo json_encode(['success' => true, 'settings' => $settings]);
        exit;
    }

    // ============================================================
    // POST action=save — Lưu settings
    // ============================================================
    if ($method === 'POST' && ($action === 'save' || $action === '')) {
        $stmt  = $db->prepare(
            "INSERT INTO settings (`key`, `value`)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()"
        );
        $saved = [];
        foreach (ALLOWED_SETTINGS as $key) {
            if (isset($_POST[$key])) {
                $value = trim($_POST[$key]);
                // Chuẩn hoá boolean fields
                if (in_array($key, ['telegram_enabled','telegram_notify_success','telegram_notify_failed'], true)) {
                    $value = $value ? '1' : '0';
                }
                $stmt->execute([':k' => $key, ':v' => $value]);
                $saved[] = $key;
            }
        }
        echo json_encode([
            'success' => true,
            'message' => 'Cài đặt đã được lưu thành công!',
            'saved'   => $saved,
        ]);
        exit;
    }

    // ============================================================
    // POST action=test_telegram — Gửi tin nhắn thử đến Telegram
    // ============================================================
    if ($method === 'POST' && $action === 'test_telegram') {
        $token   = trim($_POST['telegram_bot_token'] ?? '');
        $chat_id = trim($_POST['telegram_chat_id']   ?? '');

        // Nếu không truyền lên, lấy từ DB
        if (empty($token) || empty($chat_id)) {
            $stmt = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('telegram_bot_token','telegram_chat_id')");
            foreach ($stmt->fetchAll() as $row) {
                if ($row['key'] === 'telegram_bot_token') $token   = $row['value'];
                if ($row['key'] === 'telegram_chat_id')   $chat_id = $row['value'];
            }
        }

        if (empty($token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Bot Token chưa được cài đặt.']);
            exit;
        }
        if (empty($chat_id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Chat ID chưa được cài đặt.']);
            exit;
        }

        // Gọi Telegram Bot API
        $api_url = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload  = [
            'chat_id'    => $chat_id,
            'text'       => "✅ <b>ORBIT - Test thành công!</b>\n\nThông báo Telegram đang hoạt động bình thường.\n🕐 " . date('d/m/Y H:i:s'),
            'parse_mode' => 'HTML',
        ];

        // Dùng curl nếu có, fallback sang file_get_contents
        $response_body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($api_url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false, // Cho XAMPP local không có CA bundle
            ]);
            $response_body = curl_exec($ch);
            $curl_err      = curl_error($ch);
            curl_close($ch);
            if ($response_body === false) {
                http_response_code(502);
                echo json_encode(['success' => false, 'message' => "Lỗi kết nối: $curl_err"]);
                exit;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-type: application/x-www-form-urlencoded',
                    'content' => http_build_query($payload),
                    'timeout' => 10,
                ],
            ]);
            $response_body = @file_get_contents($api_url, false, $context);
        }

        if ($response_body === false || $response_body === null) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'Không thể kết nối tới Telegram API. Kiểm tra internet.']);
            exit;
        }

        $tg_resp = json_decode($response_body, true);
        if ($tg_resp && $tg_resp['ok']) {
            echo json_encode(['success' => true, 'message' => 'Tin nhắn test đã gửi thành công đến Telegram!']);
        } else {
            $err = $tg_resp['description'] ?? 'Lỗi không xác định từ Telegram.';
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Telegram từ chối: $err"]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Method/action không hợp lệ.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lỗi database: ' . $e->getMessage()]);
}
