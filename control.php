<?php
// ============================================================
// ORBIT - Worker Control API
// File: control.php
// Mô tả: Nhận lệnh pause/resume từ dashboard, ghi vào file
//        worker_control.json. Worker Python đọc file này
//        mỗi vòng lặp để biết có cần tạm dừng hay không.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}

$action       = trim($_POST['action'] ?? '');
$control_file = __DIR__ . '/worker_control.json';

if (!in_array($action, ['pause', 'resume'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Action không hợp lệ. Dùng: pause hoặc resume.']);
    exit;
}

$paused = ($action === 'pause');
$data   = [
    'paused'     => $paused,
    'changed_at' => date('Y-m-d H:i:s'),
    'changed_by' => 'dashboard',
];

// Ghi file tạm rồi rename để worker không đọc file đang ghi dở
$tmp = $control_file . '.tmp';
if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Không thể ghi file điều khiển. Kiểm tra quyền thư mục.']);
    exit;
}
rename($tmp, $control_file);

$msg = $paused
    ? 'Worker đã được tạm dừng. Các task đang xử lý sẽ hoàn thành trước khi dừng.'
    : 'Worker đã tiếp tục hoạt động. Sẽ quét task trong vòng ' . (int)($_POST['scan_interval'] ?? 10) . 's tới.';

echo json_encode([
    'success'    => true,
    'paused'     => $paused,
    'message'    => $msg,
    'changed_at' => $data['changed_at'],
]);
