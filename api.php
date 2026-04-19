<?php
// ============================================================
// ORBIT - API Upload & Task Creation
// File: api.php
// Mô tả: Nhận file upload vật lý, kiểm tra bảo mật, lưu vào
//        thư mục uploads/, rồi insert vào DB qua PDO.
// Response: JSON luôn luôn (dùng với Fetch API)
// ============================================================

// --- Luôn trả về JSON ---
header('Content-Type: application/json; charset=utf-8');

// --- Chặn truy cập trực tiếp không phải POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.']);
    exit;
}

require_once 'config.php';

// ============================================================
// CẤU HÌNH UPLOAD
// ============================================================

define('UPLOAD_DIR',    __DIR__ . '/uploads/');   // Thư mục lưu file trên server
define('MAX_FILE_SIZE', 500 * 1024 * 1024);       // Dung lượng tối đa: 500 MB

// Phần mở rộng được phép (lowercase)
define('ALLOWED_EXTS',  ['mp4', 'mov', 'avi', 'mkv', 'webm']);

// MIME type hợp lệ - kiểm tra bằng finfo trên server, không tin client
define('ALLOWED_MIMES', [
    'video/mp4',
    'video/quicktime',         // .mov
    'video/x-msvideo',         // .avi
    'video/x-matroska',        // .mkv
    'video/webm',
    'video/mpeg',
    'application/octet-stream' // Một số browser gửi MIME này cho .mkv
]);

// ============================================================
// BƯỚC 1: VALIDATE CÁC TRƯỜNG POST
// ============================================================

$platform     = trim($_POST['platform']     ?? '');
$caption      = trim($_POST['caption']      ?? '');
$scheduled_at = trim($_POST['scheduled_at'] ?? '');
$account_id   = (int)($_POST['account_id'] ?? 0); // 0 = không chọn tài khoản cụ thể

// Kiểm tra platform
$allowed_platforms = ['facebook', 'tiktok', 'youtube'];
if (!in_array($platform, $allowed_platforms, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nền tảng không hợp lệ. Chọn: Facebook, TikTok hoặc YouTube.']);
    exit;
}

// Kiểm tra caption
if (empty($caption)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Caption không được để trống.']);
    exit;
}

// Kiểm tra & parse datetime
if (empty($scheduled_at)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Thời gian lên lịch không được để trống.']);
    exit;
}

$dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduled_at);
if (!$dt) {
    // Thử parse định dạng khác từ browser (có thể có giây)
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $scheduled_at);
}
if (!$dt) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Định dạng thời gian không hợp lệ.']);
    exit;
}
$scheduled_at_db = $dt->format('Y-m-d H:i:s');

// ============================================================
// BƯỚC 2: KIỂM TRA FILE UPLOAD
// ============================================================

// Kiểm tra file có được gửi lên không
if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vui lòng chọn file video để upload.']);
    exit;
}

$file = $_FILES['video_file'];

// --- Kiểm tra lỗi upload từ PHP ---
if ($file['error'] !== UPLOAD_ERR_OK) {
    $upload_error_messages = [
        UPLOAD_ERR_INI_SIZE   => 'File vượt giới hạn upload_max_filesize trong php.ini. Tăng giá trị này lên trong XAMPP.',
        UPLOAD_ERR_FORM_SIZE  => 'File vượt giới hạn MAX_FILE_SIZE.',
        UPLOAD_ERR_PARTIAL    => 'File chỉ được upload một phần. Thử lại.',
        UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm trên server (upload_tmp_dir).',
        UPLOAD_ERR_CANT_WRITE => 'Server không thể ghi file xuống disk. Kiểm tra quyền thư mục.',
        UPLOAD_ERR_EXTENSION  => 'Extension PHP đã chặn upload file này.',
    ];
    $msg = $upload_error_messages[$file['error']]
        ?? "Lỗi upload không xác định (PHP error code: {$file['error']}).";
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// --- Kiểm tra dung lượng ---
if ($file['size'] > MAX_FILE_SIZE) {
    $max_mb = round(MAX_FILE_SIZE / 1024 / 1024);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "File quá lớn ({$max_mb}MB tối đa). File của bạn: " . round($file['size'] / 1024 / 1024, 1) . 'MB.']);
    exit;
}

if ($file['size'] === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File trống (0 bytes). Vui lòng chọn file video hợp lệ.']);
    exit;
}

// --- Kiểm tra phần mở rộng ---
$original_name = $file['name'];
$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTS, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "Định dạng file .$ext không được phép. Chỉ chấp nhận: " . implode(', ', ALLOWED_EXTS) . '.'
    ]);
    exit;
}

// --- Kiểm tra MIME type thực sự bằng finfo (chống đổi tên file) ---
$finfo = new finfo(FILEINFO_MIME_TYPE);
$real_mime = $finfo->file($file['tmp_name']);

if (!in_array($real_mime, ALLOWED_MIMES, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "File không phải video hợp lệ. MIME thực tế: $real_mime. Vui lòng chọn file video thật."
    ]);
    exit;
}

// ============================================================
// BƯỚC 3: TẠO THƯ MỤC VÀ LƯU FILE AN TOÀN
// ============================================================

// Tạo thư mục uploads/ nếu chưa tồn tại
if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server không thể tạo thư mục uploads/. Kiểm tra quyền htdocs.']);
        exit;
    }
    // Tạo .htaccess để chặn thực thi PHP trong thư mục uploads/
    file_put_contents(UPLOAD_DIR . '.htaccess', "Options -ExecCGI\nAddHandler cgi-script .php .py .pl .sh\nOptions -Indexes\n");
}

// Tạo tên file an toàn và duy nhất:
// Format: 20250418_153022_abc123_ten_file_goc.mp4
$safe_basename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
$safe_basename = substr($safe_basename, 0, 50); // Giới hạn độ dài tên gốc
$unique_name   = date('Ymd_His') . '_' . substr(uniqid(), -6) . '_' . $safe_basename . '.' . $ext;
$dest_path     = UPLOAD_DIR . $unique_name;

// Chuyển file từ thư mục tạm sang thư mục uploads/
if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server không thể lưu file. Kiểm tra quyền ghi thư mục uploads/.']);
    exit;
}

// ============================================================
// BƯỚC 4: INSERT VÀO DATABASE
// ============================================================

try {
    $db = getDB();

    // Kiểm tra account_id hợp lệ (nếu được truyền)
    $account_id_db = null;
    if ($account_id > 0) {
        $acc_check = $db->prepare("SELECT id FROM accounts WHERE id = :id AND status = 'active' AND platform = :platform");
        $acc_check->execute([':id' => $account_id, ':platform' => $platform]);
        if ($acc_check->fetch()) {
            $account_id_db = $account_id;
        }
        // Nếu account không hợp lệ / không active / sai platform → vẫn tạo task, chỉ không gắn account
    }

    $sql = "INSERT INTO video_tasks (platform, account_id, video_path, caption, scheduled_at, status)
            VALUES (:platform, :account_id, :video_path, :caption, :scheduled_at, 'pending')";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':platform'     => $platform,
        ':account_id'   => $account_id_db,
        ':video_path'   => $dest_path,
        ':caption'      => $caption,
        ':scheduled_at' => $scheduled_at_db,
    ]);

    $task_id   = (int) $db->lastInsertId();
    $file_size = round($file['size'] / 1024 / 1024, 2);

    http_response_code(201);
    echo json_encode([
        'success'      => true,
        'message'      => "Task #{$task_id} đã được lên lịch thành công!",
        'task_id'      => $task_id,
        'platform'     => $platform,
        'file_name'    => $unique_name,
        'file_size_mb' => $file_size,
        'scheduled_at' => $scheduled_at_db,
    ]);

} catch (PDOException $e) {
    // Rollback: xóa file đã upload nếu DB insert thất bại
    @unlink($dest_path);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lỗi database khi lưu task: ' . $e->getMessage()]);
}
