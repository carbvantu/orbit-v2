<?php
// ============================================================
// ORBIT - Task Action API
// File: task_action.php
// Mô tả: Xử lý các hành động thủ công lên task:
//        - retry  : Đặt lại task failed → pending
//        - delete : Xóa task khỏi DB (và file video nếu có)
//        - cancel : Đặt task pending → failed (huỷ trước giờ đăng)
// ============================================================

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}

require_once 'config.php';

$action  = trim($_POST['action']  ?? '');
$task_id = (int)($_POST['task_id'] ?? 0);

if ($task_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'task_id không hợp lệ.']);
    exit;
}

$allowed_actions = ['retry', 'delete', 'cancel'];
if (!in_array($action, $allowed_actions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Action '$action' không được hỗ trợ."]);
    exit;
}

try {
    $db = getDB();

    // Lấy thông tin task trước khi xử lý
    $stmt = $db->prepare("SELECT id, status, video_path, platform FROM video_tasks WHERE id = :id");
    $stmt->execute([':id' => $task_id]);
    $task = $stmt->fetch();

    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Không tìm thấy Task #$task_id."]);
        exit;
    }

    // ---- RETRY: failed → pending ----
    if ($action === 'retry') {
        if ($task['status'] !== 'failed') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Chỉ có thể retry task có trạng thái 'failed'. Task #$task_id đang ở trạng thái '{$task['status']}'."
            ]);
            exit;
        }

        $stmt = $db->prepare("UPDATE video_tasks SET status = 'pending' WHERE id = :id AND status = 'failed'");
        $stmt->execute([':id' => $task_id]);

        if ($stmt->rowCount() === 0) {
            // Task đã bị thay đổi trạng thái bởi worker trước khi ta kịp cập nhật (race condition)
            echo json_encode([
                'success' => false,
                'message' => "Task #$task_id đã được xử lý bởi worker, trạng thái không còn là 'failed'."
            ]);
            exit;
        }

        echo json_encode([
            'success'  => true,
            'message'  => "Task #$task_id đã được đặt lại về 'pending' và sẽ được xử lý trong vòng quét tiếp theo.",
            'task_id'  => $task_id,
            'platform' => $task['platform'],
            'action'   => 'retry',
        ]);
    }

    // ---- CANCEL: pending → failed (huỷ task chưa xử lý) ----
    elseif ($action === 'cancel') {
        if ($task['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Chỉ có thể huỷ task ở trạng thái 'pending'. Task #$task_id đang ở '{$task['status']}'."
            ]);
            exit;
        }

        $stmt = $db->prepare("UPDATE video_tasks SET status = 'failed' WHERE id = :id AND status = 'pending'");
        $stmt->execute([':id' => $task_id]);

        echo json_encode([
            'success' => true,
            'message' => "Task #$task_id đã bị huỷ.",
            'task_id' => $task_id,
            'action'  => 'cancel',
        ]);
    }

    // ---- DELETE: xóa task và file video ----
    elseif ($action === 'delete') {
        // Không cho xóa task đang processing (worker đang dùng)
        if ($task['status'] === 'processing') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Không thể xóa Task #$task_id đang được worker xử lý. Hãy đợi hoàn thành."
            ]);
            exit;
        }

        // Xóa khỏi DB trước
        $stmt = $db->prepare("DELETE FROM video_tasks WHERE id = :id");
        $stmt->execute([':id' => $task_id]);

        // Xóa file video nếu tồn tại trong thư mục uploads/
        $video_path = $task['video_path'];
        $deleted_file = false;
        if (!empty($video_path) && file_exists($video_path)) {
            // Chỉ xóa nếu file nằm trong thư mục uploads/ (bảo mật - không xóa file ngoài)
            $real_upload = realpath(__DIR__ . '/uploads/');
            $real_file   = realpath($video_path);
            if ($real_upload && $real_file && str_starts_with($real_file, $real_upload)) {
                @unlink($video_path);
                $deleted_file = true;
            }
        }

        echo json_encode([
            'success'      => true,
            'message'      => "Task #$task_id đã được xóa." . ($deleted_file ? ' File video cũng đã bị xóa.' : ''),
            'task_id'      => $task_id,
            'action'       => 'delete',
            'deleted_file' => $deleted_file,
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lỗi database: ' . $e->getMessage()]);
}
