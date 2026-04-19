<?php
// ============================================================
// ORBIT - PHP Backend xử lý Form
// File: process.php
// Mô tả: Nhận POST data, validate, insert vào DB với PDO
// ============================================================

require_once 'config.php';

// --- Chỉ chấp nhận POST request ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// --- Thu thập và làm sạch dữ liệu đầu vào ---
$platform     = trim($_POST['platform']    ?? '');
$video_path   = trim($_POST['video_path']  ?? '');
$caption      = trim($_POST['caption']     ?? '');
$scheduled_at = trim($_POST['scheduled_at'] ?? '');

// --- Validate cơ bản ---
$errors = [];

$allowed_platforms = ['facebook', 'tiktok', 'youtube'];
if (!in_array($platform, $allowed_platforms, true)) {
    $errors[] = 'Nền tảng không hợp lệ. Chọn: Facebook, TikTok hoặc YouTube.';
}

if (empty($video_path)) {
    $errors[] = 'Đường dẫn file video không được để trống.';
} elseif (strlen($video_path) > 512) {
    $errors[] = 'Đường dẫn file video quá dài (tối đa 512 ký tự).';
}

if (empty($caption)) {
    $errors[] = 'Caption không được để trống.';
}

if (empty($scheduled_at)) {
    $errors[] = 'Thời gian lên lịch không được để trống.';
} else {
    // Kiểm tra định dạng datetime hợp lệ
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduled_at);
    if (!$dt) {
        $errors[] = 'Định dạng thời gian không hợp lệ.';
    } else {
        // Chuyển sang định dạng MySQL DATETIME
        $scheduled_at = $dt->format('Y-m-d H:i:s');
    }
}

// --- Nếu có lỗi, redirect về form kèm thông báo ---
if (!empty($errors)) {
    session_start();
    $_SESSION['errors']    = $errors;
    $_SESSION['old_input'] = $_POST; // Giữ lại dữ liệu đã nhập
    header('Location: index.php');
    exit;
}

// --- Insert vào database bằng Prepared Statement (chống SQL Injection) ---
try {
    $db = getDB();

    $sql = "INSERT INTO video_tasks (platform, video_path, caption, scheduled_at, status)
            VALUES (:platform, :video_path, :caption, :scheduled_at, 'pending')";

    $stmt = $db->prepare($sql);

    $stmt->execute([
        ':platform'     => $platform,
        ':video_path'   => $video_path,
        ':caption'      => $caption,
        ':scheduled_at' => $scheduled_at,
    ]);

    // Redirect về trang chính với thông báo thành công
    session_start();
    $_SESSION['success'] = 'Task đã được lên lịch thành công!';
    header('Location: index.php');
    exit;

} catch (PDOException $e) {
    session_start();
    $_SESSION['errors'] = ['Lỗi database: ' . $e->getMessage()];
    header('Location: index.php');
    exit;
}
