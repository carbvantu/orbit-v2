<?php
// ============================================================
// ORBIT - Accounts API
// File: accounts.php
// Mô tả: CRUD tài khoản đa nền tảng.
// Actions GET : list   — Lấy danh sách (tuỳ chọn lọc theo platform)
// Actions POST: create — Thêm tài khoản mới
//               delete — Xóa tài khoản (kiểm tra task đang dùng)
//               toggle — Kích hoạt / vô hiệu hóa tài khoản
// ============================================================

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_REQUEST['action'] ?? '');

try {
    $db = getDB();

    // ============================================================
    // LIST — GET ?action=list[&platform=facebook]
    // ============================================================
    if ($method === 'GET' && $action === 'list') {
        $platform = trim($_GET['platform'] ?? '');
        $valid    = ['facebook', 'tiktok', 'youtube'];
        if ($platform && !in_array($platform, $valid, true)) $platform = '';

        if ($platform) {
            $stmt = $db->prepare(
                "SELECT id, platform, account_name, username, profile_path, profile_dir, status, created_at
                 FROM accounts
                 WHERE platform = :platform
                 ORDER BY account_name ASC"
            );
            $stmt->execute([':platform' => $platform]);
        } else {
            $stmt = $db->query(
                "SELECT id, platform, account_name, username, profile_path, profile_dir, status, created_at
                 FROM accounts
                 ORDER BY platform ASC, account_name ASC"
            );
        }
        $accounts = $stmt->fetchAll();

        echo json_encode([
            'success'  => true,
            'accounts' => $accounts,
            'total'    => count($accounts),
        ]);
        exit;
    }

    // ============================================================
    // CREATE — POST action=create
    // ============================================================
    if ($method === 'POST' && $action === 'create') {
        $platform     = trim($_POST['platform']     ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $username     = trim($_POST['username']     ?? '');
        $profile_path = trim($_POST['profile_path'] ?? '');
        $profile_dir  = trim($_POST['profile_dir']  ?? 'Default');

        $valid_platforms = ['facebook', 'tiktok', 'youtube'];
        if (!in_array($platform, $valid_platforms, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Platform không hợp lệ.']);
            exit;
        }

        if (mb_strlen($account_name) < 2) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tên tài khoản phải có ít nhất 2 ký tự.']);
            exit;
        }

        // Giới hạn độ dài để tránh insert quá dài
        $account_name = mb_substr($account_name, 0, 100);
        $username     = mb_substr($username,     0, 100);
        $profile_path = mb_substr($profile_path, 0, 500);
        $profile_dir  = mb_substr($profile_dir ?: 'Default', 0, 100);

        $stmt = $db->prepare(
            "INSERT INTO accounts (platform, account_name, username, profile_path, profile_dir)
             VALUES (:platform, :account_name, :username, :profile_path, :profile_dir)"
        );
        $stmt->execute([
            ':platform'     => $platform,
            ':account_name' => $account_name,
            ':username'     => $username,
            ':profile_path' => $profile_path,
            ':profile_dir'  => $profile_dir,
        ]);

        $new_id = (int) $db->lastInsertId();

        echo json_encode([
            'success' => true,
            'id'      => $new_id,
            'message' => "Tài khoản '$account_name' đã được thêm thành công!",
        ]);
        exit;
    }

    // ============================================================
    // DELETE — POST action=delete
    // ============================================================
    if ($method === 'POST' && $action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID tài khoản không hợp lệ.']);
            exit;
        }

        // Kiểm tra xem tài khoản có đang được dùng bởi task chưa xong không
        $check = $db->prepare(
            "SELECT COUNT(*) FROM video_tasks
             WHERE account_id = :id AND status IN ('pending', 'processing')"
        );
        $check->execute([':id' => $id]);
        $in_use = (int) $check->fetchColumn();

        if ($in_use > 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Không thể xóa: tài khoản đang được dùng bởi {$in_use} task pending/processing.",
            ]);
            exit;
        }

        // Lấy tên tài khoản trước khi xóa để hiển thị thông báo
        $info_stmt = $db->prepare("SELECT account_name FROM accounts WHERE id = :id");
        $info_stmt->execute([':id' => $id]);
        $info = $info_stmt->fetch();

        if (!$info) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Tài khoản không tồn tại.']);
            exit;
        }

        // Đặt account_id = NULL cho các task cũ (completed/failed) để tránh FK error
        $db->prepare("UPDATE video_tasks SET account_id = NULL WHERE account_id = :id")
           ->execute([':id' => $id]);

        $db->prepare("DELETE FROM accounts WHERE id = :id")->execute([':id' => $id]);

        echo json_encode([
            'success' => true,
            'message' => "Tài khoản '{$info['account_name']}' đã được xóa.",
        ]);
        exit;
    }

    // ============================================================
    // TOGGLE STATUS — POST action=toggle
    // ============================================================
    if ($method === 'POST' && $action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID không hợp lệ.']);
            exit;
        }

        $db->prepare(
            "UPDATE accounts SET status = IF(status = 'active', 'inactive', 'active') WHERE id = :id"
        )->execute([':id' => $id]);

        $row = $db->prepare("SELECT status FROM accounts WHERE id = :id");
        $row->execute([':id' => $id]);
        $new_status = $row->fetchColumn();

        echo json_encode([
            'success' => true,
            'status'  => $new_status,
            'message' => $new_status === 'active'
                ? 'Tài khoản đã được kích hoạt.'
                : 'Tài khoản đã bị vô hiệu hóa.',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Action không hợp lệ. Dùng: list, create, delete, toggle.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lỗi database: ' . $e->getMessage()]);
}
