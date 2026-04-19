<?php
// ============================================================
// ORBIT - API lấy danh sách Task (AJAX refresh bảng)
// File: get_tasks.php
// Response: JSON array các task theo bộ lọc được chọn
// Tham số GET (tuỳ chọn):
//   platform  - facebook | tiktok | youtube | (rỗng = tất cả)
//   status    - pending | processing | completed | failed | (rỗng)
//   date_from - YYYY-MM-DD  (lọc scheduled_at từ ngày này)
//   date_to   - YYYY-MM-DD  (lọc scheduled_at đến ngày này)
//   search    - chuỗi tìm kiếm trong caption
//   limit     - số lượng tối đa (mặc định 50, tối đa 200)
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET only']);
    exit;
}

try {
    $db = getDB();

    // ---- Đọc và làm sạch tham số filter ----
    $platform  = trim($_GET['platform']  ?? '');
    $status    = trim($_GET['status']    ?? '');
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to   = trim($_GET['date_to']   ?? '');
    $search    = trim($_GET['search']    ?? '');
    $limit     = min((int)($_GET['limit'] ?? 50), 200);
    if ($limit <= 0) $limit = 50;

    // Danh sách hợp lệ để tránh SQL injection từ whitelist check
    $valid_platforms = ['facebook', 'tiktok', 'youtube'];
    $valid_statuses  = ['pending', 'processing', 'completed', 'failed'];

    if ($platform && !in_array($platform, $valid_platforms, true)) $platform = '';
    if ($status   && !in_array($status,   $valid_statuses,  true)) $status   = '';

    // Kiểm tra định dạng ngày YYYY-MM-DD
    $valid_date = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
                            && checkdate((int)substr($d,5,2), (int)substr($d,8,2), (int)substr($d,0,4));
    if ($date_from && !$valid_date($date_from)) $date_from = '';
    if ($date_to   && !$valid_date($date_to))   $date_to   = '';

    // ---- Xây dựng câu truy vấn động ----
    $where  = [];
    $params = [];

    if ($platform) {
        $where[]            = 'platform = :platform';
        $params[':platform'] = $platform;
    }

    if ($status) {
        $where[]          = 'status = :status';
        $params[':status'] = $status;
    }

    if ($date_from) {
        $where[]              = 'scheduled_at >= :date_from';
        $params[':date_from']  = $date_from . ' 00:00:00';
    }

    if ($date_to) {
        $where[]            = 'scheduled_at <= :date_to';
        $params[':date_to']  = $date_to . ' 23:59:59';
    }

    if ($search !== '') {
        $where[]           = 'caption LIKE :search';
        $params[':search']  = '%' . $search . '%';
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT t.id, t.platform, t.account_id, a.account_name,
                   t.caption, t.scheduled_at, t.status, t.created_at
            FROM   video_tasks t
            LEFT JOIN accounts a ON a.id = t.account_id
            $where_sql
            ORDER  BY t.created_at DESC
            LIMIT  :limit";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $tasks = $stmt->fetchAll();

    // ---- Đếm tổng theo status (toàn bộ DB, không theo filter) ----
    // Dùng để cập nhật stat boxes trên dashboard luôn chính xác
    $count_stmt = $db->query("SELECT status, COUNT(*) as cnt FROM video_tasks GROUP BY status");
    $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
    foreach ($count_stmt->fetchAll() as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int)$row['cnt'];
        }
    }

    // ---- Đếm số kết quả khớp với filter (cho UI hiển thị) ----
    $filtered_total_sql = "SELECT COUNT(*) FROM video_tasks t LEFT JOIN accounts a ON a.id = t.account_id $where_sql";
    $ft_stmt = $db->prepare($filtered_total_sql);
    foreach ($params as $key => $val) {
        $ft_stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $ft_stmt->execute();
    $filtered_total = (int)$ft_stmt->fetchColumn();

    echo json_encode([
        'success'        => true,
        'tasks'          => $tasks,
        'counts'         => $counts,
        'total'          => count($tasks),        // số rows trả về (≤ limit)
        'filtered_total' => $filtered_total,       // tổng khớp filter
        'has_filter'     => (bool)($platform || $status || $date_from || $date_to || $search),
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
