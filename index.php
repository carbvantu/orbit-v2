<?php
// ============================================================
// ORBIT - Trang Dashboard chính
// File: index.php
// ============================================================

require_once 'config.php';

// --- Đọc trạng thái Worker từ file heartbeat ---
$heartbeat_file = __DIR__ . '/worker_heartbeat.json';
$worker = null;
$worker_online = false;
$worker_uptime = '';

if (file_exists($heartbeat_file)) {
    $raw = @file_get_contents($heartbeat_file);
    if ($raw !== false) {
        $worker = json_decode($raw, true);
        if ($worker && isset($worker['last_beat'])) {
            // Worker được coi là ONLINE nếu heartbeat trong vòng 25 giây gần nhất
            $age = time() - strtotime($worker['last_beat']);
            $worker_online = ($age <= 25);

            // Tính thời gian uptime
            if (isset($worker['started_at'])) {
                $uptime_secs = time() - strtotime($worker['started_at']);
                $h = floor($uptime_secs / 3600);
                $m = floor(($uptime_secs % 3600) / 60);
                $s = $uptime_secs % 60;
                $worker_uptime = sprintf('%02d:%02d:%02d', $h, $m, $s);
            }
        }
    }
}

// --- Lấy danh sách tài khoản để hiển thị trong form và quản lý ---
$all_accounts = [];
try {
    $_db_acc = getDB();
    $_stmt_acc = $_db_acc->query("SELECT id, platform, account_name, username, status FROM accounts ORDER BY platform, account_name");
    $all_accounts = $_stmt_acc->fetchAll();
} catch (Exception $_e_acc) { /* bỏ qua nếu bảng chưa tồn tại */ }

// Group theo platform để dùng trong form
$accounts_by_platform = [];
foreach ($all_accounts as $_acc) {
    $accounts_by_platform[$_acc['platform']][] = $_acc;
}

// --- Lấy cài đặt Telegram ---
$tg_settings = [
    'telegram_enabled'        => '0',
    'telegram_bot_token'      => '',
    'telegram_chat_id'        => '',
    'telegram_notify_success' => '1',
    'telegram_notify_failed'  => '1',
];
try {
    $_db_s = getDB();
    $_stmt_s = $_db_s->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'telegram%'");
    foreach ($_stmt_s->fetchAll() as $_row_s) {
        $tg_settings[$_row_s['key']] = $_row_s['value'];
    }
} catch (Exception $_e_s) { /* bảng settings chưa tồn tại */ }

// --- Đọc trạng thái Pause/Resume từ file điều khiển ---
$control_file   = __DIR__ . '/worker_control.json';
$worker_paused  = false;
$control_changed_at = '';
if (file_exists($control_file)) {
    $ctrl_raw = @file_get_contents($control_file);
    if ($ctrl_raw !== false) {
        $ctrl = json_decode($ctrl_raw, true);
        if ($ctrl) {
            $worker_paused      = (bool)($ctrl['paused']     ?? false);
            $control_changed_at = $ctrl['changed_at'] ?? '';
        }
    }
}

// --- Lấy flash messages từ session ---
session_start();
$success   = $_SESSION['success']   ?? null;
$errors    = $_SESSION['errors']    ?? [];
$old_input = $_SESSION['old_input'] ?? [];
unset($_SESSION['success'], $_SESSION['errors'], $_SESSION['old_input']);

// --- Lấy danh sách task từ DB ---
$tasks = [];
try {
    $db    = getDB();
    $stmt  = $db->query("SELECT * FROM video_tasks ORDER BY created_at DESC LIMIT 50");
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = 'Không thể tải danh sách task: ' . $e->getMessage();
}

// --- Hàm trả về class Badge theo status ---
function statusBadge(string $status): string {
    return match($status) {
        'pending'    => 'badge-pending',
        'processing' => 'badge-processing',
        'completed'  => 'badge-completed',
        'failed'     => 'badge-failed',
        default      => 'bg-secondary'
    };
}

function statusLabel(string $status): string {
    return match($status) {
        'pending'    => 'Chờ xử lý',
        'processing' => 'Đang xử lý',
        'completed'  => 'Hoàn thành',
        'failed'     => 'Thất bại',
        default      => $status
    };
}

function platformIcon(string $platform): string {
    return match($platform) {
        'facebook' => '<svg class="platform-icon facebook-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'tiktok'   => '<svg class="platform-icon tiktok-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
        'youtube'  => '<svg class="platform-icon youtube-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M23.495 6.205a3.007 3.007 0 0 0-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 0 0 .527 6.205a31.247 31.247 0 0 0-.522 5.805 31.247 31.247 0 0 0 .522 5.783 3.007 3.007 0 0 0 2.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 0 0 2.088-2.088 31.247 31.247 0 0 0 .5-5.783 31.247 31.247 0 0 0-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"/></svg>',
        default    => ''
    };
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ORBIT — Video Scheduler</title>

    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- SweetAlert2 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* =====================================================
           ORBIT - Custom CSS
           ===================================================== */

        :root {
            /* Màu nền tối chủ đạo */
            --bg-deep:        #0a0d1a;
            --bg-card:        #111527;
            --bg-input:       #1a1f35;
            --bg-hover:       #1e2440;

            /* Màu accent chính - tím-xanh gradient */
            --accent-start:   #6c63ff;
            --accent-end:     #3ecfcf;
            --accent-mid:     #8a6fff;

            /* Màu platform */
            --fb-color:       #1877f2;
            --tt-color:       #ff0050;
            --yt-color:       #ff0000;

            /* Màu border */
            --border-color:   rgba(108, 99, 255, 0.2);
            --border-hover:   rgba(108, 99, 255, 0.5);

            /* Text */
            --text-primary:   #e8eaf6;
            --text-secondary: #8892b0;
            --text-muted:     #4a5568;

            /* Badge màu */
            --badge-pending:    #f59e0b;
            --badge-processing: #3b82f6;
            --badge-completed:  #10b981;
            --badge-failed:     #ef4444;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-deep);
            color: var(--text-primary);
            min-height: 100vh;
            /* Lưới chấm nền trang trí */
            background-image: radial-gradient(rgba(108,99,255,0.08) 1px, transparent 1px);
            background-size: 28px 28px;
        }

        /* ---- Thanh điều hướng ---- */
        .orbit-navbar {
            background: rgba(17, 21, 39, 0.85);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            padding: 0.85rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .orbit-logo {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, var(--accent-start), var(--accent-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .orbit-logo span {
            font-weight: 300;
            opacity: 0.7;
        }

        .nav-badge {
            background: linear-gradient(135deg, var(--accent-start), var(--accent-end));
            color: white;
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        /* ---- Card container ---- */
        .glass-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow:
                0 4px 6px -1px rgba(0,0,0,0.3),
                0 2px 4px -1px rgba(0,0,0,0.2),
                inset 0 1px 0 rgba(255,255,255,0.05);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .glass-card:hover {
            border-color: var(--border-hover);
            box-shadow:
                0 10px 25px -5px rgba(108,99,255,0.15),
                0 4px 6px -2px rgba(0,0,0,0.3),
                inset 0 1px 0 rgba(255,255,255,0.07);
        }

        .card-header-orbit {
            padding: 1.5rem 1.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title-orbit {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .title-icon {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--accent-start), var(--accent-end));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        /* ---- Form elements ---- */
        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            background-color: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.9rem;
            padding: 0.65rem 1rem;
            transition: all 0.25s ease;
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--bg-input);
            border-color: var(--accent-start);
            color: var(--text-primary);
            box-shadow: 0 0 0 3px rgba(108,99,255,0.2);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* ---- Platform selector (radio buttons tùy chỉnh) ---- */
        .platform-group {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .platform-option input[type="radio"] {
            display: none; /* Ẩn radio gốc của trình duyệt */
        }

        .platform-label {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.65rem 1.1rem;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            background: var(--bg-input);
            user-select: none;
        }

        .platform-label:hover {
            border-color: var(--border-hover);
            color: var(--text-primary);
            background: var(--bg-hover);
        }

        /* Khi radio được chọn - highlight label tương ứng */
        .platform-option input[type="radio"]:checked + .platform-label {
            color: white;
            font-weight: 600;
        }

        .platform-option input[value="facebook"]:checked + .platform-label {
            background: rgba(24,119,242,0.15);
            border-color: var(--fb-color);
            box-shadow: 0 0 0 2px rgba(24,119,242,0.2);
        }

        .platform-option input[value="tiktok"]:checked + .platform-label {
            background: rgba(255,0,80,0.12);
            border-color: var(--tt-color);
            box-shadow: 0 0 0 2px rgba(255,0,80,0.2);
        }

        .platform-option input[value="youtube"]:checked + .platform-label {
            background: rgba(255,0,0,0.12);
            border-color: var(--yt-color);
            box-shadow: 0 0 0 2px rgba(255,0,0,0.2);
        }

        .platform-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .facebook-icon { color: var(--fb-color); }
        .tiktok-icon   { color: var(--tt-color); }
        .youtube-icon  { color: var(--yt-color);  }

        /* ---- Input file tùy chỉnh ---- */
        .file-input-wrapper {
            position: relative;
        }

        .file-input-hidden {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            cursor: pointer;
            z-index: 2;
        }

        .file-input-display {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: var(--bg-input);
            border: 1.5px dashed var(--border-color);
            border-radius: 10px;
            padding: 0.8rem 1rem;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .file-input-display:hover {
            border-color: var(--accent-start);
            background: var(--bg-hover);
        }

        .file-btn {
            background: linear-gradient(135deg, var(--accent-start), var(--accent-mid));
            color: white;
            border: none;
            border-radius: 7px;
            padding: 0.3rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
            letter-spacing: 0.3px;
        }

        .file-name-display {
            font-size: 0.83rem;
            color: var(--text-muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ---- Nút Submit ---- */
        .btn-orbit {
            background: linear-gradient(135deg, var(--accent-start), var(--accent-end));
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 0.75rem 1.75rem;
            letter-spacing: 0.3px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-orbit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, var(--accent-end), var(--accent-start));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Hiệu ứng hover - đảo gradient */
        .btn-orbit:hover::before { opacity: 1; }
        .btn-orbit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(108,99,255,0.45);
            color: white;
        }

        .btn-orbit:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-orbit span { position: relative; z-index: 1; }

        /* ---- Alert thông báo ---- */
        .orbit-alert {
            border: none;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .orbit-alert-success {
            background: rgba(16,185,129,0.15);
            color: #6ee7b7;
            border-left: 3px solid var(--badge-completed);
        }

        .orbit-alert-error {
            background: rgba(239,68,68,0.12);
            color: #fca5a5;
            border-left: 3px solid var(--badge-failed);
        }

        /* ---- Bảng task list ---- */
        .orbit-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 4px; /* Khoảng cách giữa các hàng */
        }

        .orbit-table thead th {
            font-size: 0.73rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            padding: 0.6rem 1rem;
            border: none;
            background: transparent;
        }

        .orbit-table tbody tr {
            background: rgba(26, 31, 53, 0.6);
            transition: background 0.2s ease;
        }

        .orbit-table tbody tr:hover {
            background: var(--bg-hover);
        }

        .orbit-table tbody td {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            border: none;
            vertical-align: middle;
        }

        .orbit-table tbody td:first-child {
            border-radius: 10px 0 0 10px; /* Bo góc trái của hàng */
        }

        .orbit-table tbody td:last-child {
            border-radius: 0 10px 10px 0;  /* Bo góc phải của hàng */
        }

        .task-id {
            font-family: 'Courier New', monospace;
            font-size: 0.78rem;
            color: var(--accent-start);
            font-weight: 600;
        }

        .platform-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .caption-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .date-cell {
            font-size: 0.8rem;
            white-space: nowrap;
        }

        /* ---- Status badges ---- */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .badge-pending {
            background: rgba(245,158,11,0.15);
            color: #fcd34d;
        }
        .badge-pending::before { background: var(--badge-pending); }

        .badge-processing {
            background: rgba(59,130,246,0.15);
            color: #93c5fd;
            animation: pulse-badge 1.5s infinite; /* Nhấp nháy khi đang xử lý */
        }
        .badge-processing::before {
            background: var(--badge-processing);
            animation: pulse-dot 1.5s infinite;
        }

        .badge-completed {
            background: rgba(16,185,129,0.15);
            color: #6ee7b7;
        }
        .badge-completed::before { background: var(--badge-completed); }

        .badge-failed {
            background: rgba(239,68,68,0.12);
            color: #fca5a5;
        }
        .badge-failed::before { background: var(--badge-failed); }

        @keyframes pulse-badge {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.75; }
        }

        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%       { transform: scale(1.4); opacity: 0.6; }
        }

        /* ---- Stats row ---- */
        .stat-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            text-align: center;
            transition: all 0.2s ease;
        }

        .stat-box:hover {
            border-color: var(--border-hover);
            transform: translateY(-2px);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
        }

        /* ---- Responsive ---- */
        @media (max-width: 768px) {
            .platform-group { flex-direction: column; }
            .platform-label { width: 100%; }
        }

        /* ---- Scrollbar tùy chỉnh ---- */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent-start); }

        /* ============================================
           UPLOAD PROGRESS BAR
        ============================================ */
        .upload-progress-wrap {
            display: none; /* Ẩn mặc định, hiện khi đang upload */
            margin-bottom: 1rem;
        }

        .upload-progress-wrap.visible { display: block; }

        .upload-progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.4rem;
        }

        .upload-progress-bar-track {
            height: 6px;
            background: var(--bg-input);
            border-radius: 10px;
            overflow: hidden;
        }

        .upload-progress-bar-fill {
            height: 100%;
            width: 0%;
            border-radius: 10px;
            background: linear-gradient(90deg, var(--accent-start), var(--accent-end));
            transition: width 0.2s ease;
        }

        /* Hiệu ứng sọc khi upload chưa biết tiến độ (indeterminate) */
        .upload-progress-bar-fill.indeterminate {
            width: 100%;
            background-size: 200% 100%;
            background-image: repeating-linear-gradient(
                -45deg,
                var(--accent-start) 0, var(--accent-start) 25%,
                var(--accent-mid)   25%, var(--accent-mid)   50%,
                var(--accent-start) 50%, var(--accent-start) 75%,
                var(--accent-mid)   75%, var(--accent-mid)   100%
            );
            animation: progress-stripe 1s linear infinite;
        }

        @keyframes progress-stripe {
            from { background-position: 0 0; }
            to   { background-position: 40px 0; }
        }

        /* Nút submit khi loading */
        .btn-orbit.loading {
            opacity: 0.75;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-orbit .spinner {
            display: none;
            width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            flex-shrink: 0;
        }

        .btn-orbit.loading .spinner { display: inline-block; }
        .btn-orbit.loading .btn-label { opacity: 0.7; }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ============================================
           SWEETALERT2 DARK THEME OVERRIDE
        ============================================ */
        .swal2-popup {
            background: var(--bg-card) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 16px !important;
            font-family: 'Inter', sans-serif !important;
        }

        .swal2-title   { color: var(--text-primary) !important; }
        .swal2-html-container { color: var(--text-secondary) !important; }
        .swal2-icon.swal2-success .swal2-success-ring { border-color: rgba(16,185,129,0.3) !important; }
        .swal2-icon.swal2-error   { border-color: rgba(239,68,68,0.3) !important; }

        /* Toast góc màn hình */
        .swal2-container.swal2-top-end .swal2-popup,
        .swal2-container.swal2-top-right .swal2-popup {
            margin-top: 1rem;
            margin-right: 1rem;
        }

        /* ============================================
           NAV SETTINGS BUTTON
        ============================================ */
        .nav-settings-btn {
            background: rgba(108,99,255,0.12);
            border: 1px solid rgba(108,99,255,0.3);
            border-radius: 8px;
            color: #a78bfa;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 0.35rem 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .nav-settings-btn:hover {
            background: rgba(108,99,255,0.22);
            color: #c4b5fd;
        }

        /* ============================================
           SETTINGS MODAL
        ============================================ */
        .settings-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .settings-modal-box {
            background: #0f1428;
            border: 1px solid rgba(108,99,255,0.3);
            border-radius: 18px;
            width: 100%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.04);
        }

        .settings-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.1rem 1.4rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--text-primary);
        }

        .settings-close-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.1rem;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 6px;
            transition: all 0.15s;
        }

        .settings-close-btn:hover { background: rgba(255,255,255,0.08); color: #fff; }

        .settings-modal-body { padding: 1.2rem 1.4rem; }

        .settings-section {
            background: rgba(255,255,255,0.025);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.1rem;
            margin-bottom: 1rem;
        }

        .settings-section-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .settings-section-desc {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 0.9rem;
            line-height: 1.5;
        }

        /* Toggle switch */
        .settings-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.9rem;
        }

        .settings-toggle-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 22px;
        }

        .toggle-switch input { opacity: 0; width: 0; height: 0; }

        .toggle-slider {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.1);
            border-radius: 999px;
            transition: 0.3s;
            cursor: pointer;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 16px; height: 16px;
            left: 3px; bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: 0.3s;
        }

        .toggle-switch input:checked + .toggle-slider { background: #6c63ff; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

        /* ============================================
           FILTER & SEARCH BAR
        ============================================ */
        .task-filter-bar {
            padding: 0.9rem 1rem 0.7rem;
            border-bottom: 1px solid var(--border-color);
            background: rgba(255,255,255,0.015);
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: flex-end;
            margin-bottom: 0.6rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 110px;
        }

        .filter-label {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .filter-select,
        .filter-input {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.78rem;
            padding: 0.3rem 0.55rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
        }

        .filter-select:focus,
        .filter-input:focus {
            border-color: rgba(108,99,255,0.55);
            box-shadow: 0 0 0 3px rgba(108,99,255,0.12);
        }

        /* Trình duyệt WebKit - ẩn caret xanh trên date input */
        .filter-input::-webkit-calendar-picker-indicator {
            filter: invert(0.6);
            cursor: pointer;
        }

        .filter-select option {
            background: #12172b;
            color: var(--text-primary);
        }

        /* Nút Xoá lọc */
        .filter-clear-btn {
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.25);
            border-radius: 8px;
            color: #fca5a5;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.3rem 0.7rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .filter-clear-btn:hover {
            background: rgba(239,68,68,0.2);
            filter: brightness(1.1);
        }

        /* Thanh tìm kiếm caption */
        .filter-search-wrap {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 0.3rem 0.75rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .filter-search-wrap:focus-within {
            border-color: rgba(108,99,255,0.5);
            box-shadow: 0 0 0 3px rgba(108,99,255,0.1);
        }

        .filter-search-icon { font-size: 0.85rem; opacity: 0.6; }

        .filter-search-input {
            flex: 1;
            background: none;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-size: 0.8rem;
            font-family: 'Inter', sans-serif;
        }

        .filter-search-input::placeholder {
            color: var(--text-muted);
            opacity: 0.7;
        }

        /* Badge hiện số kết quả khớp */
        .filter-result-badge {
            background: rgba(108,99,255,0.2);
            border: 1px solid rgba(108,99,255,0.3);
            border-radius: 999px;
            color: #a78bfa;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 8px;
            white-space: nowrap;
        }

        /* Highlight hàng khi filter đang bật */
        .filter-active-indicator {
            display: inline-block;
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #6c63ff;
            margin-left: 5px;
            vertical-align: middle;
        }

        /* ============================================
           TASK ACTION BUTTONS
        ============================================ */
        .action-btn {
            border: none;
            border-radius: 7px;
            padding: 0.25rem 0.6rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.15);
        }

        .action-btn:active { transform: translateY(0); }

        .btn-retry {
            background: rgba(245,158,11,0.15);
            color: #fcd34d;
            border: 1px solid rgba(245,158,11,0.3);
        }

        .btn-cancel {
            background: rgba(239,68,68,0.12);
            color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.25);
        }

        .btn-delete {
            background: rgba(107,114,128,0.15);
            color: #9ca3af;
            border: 1px solid rgba(107,114,128,0.25);
        }

        /* Worker pause button in status panel */
        .btn-pause {
            border: none;
            border-radius: 8px;
            padding: 0.4rem 0.85rem;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            letter-spacing: 0.3px;
        }

        .btn-pause.paused {
            background: rgba(16,185,129,0.15);
            color: #34d399;
            border: 1px solid rgba(16,185,129,0.3);
        }

        .btn-pause.running {
            background: rgba(245,158,11,0.12);
            color: #fcd34d;
            border: 1px solid rgba(245,158,11,0.25);
        }

        .btn-pause:hover {
            filter: brightness(1.2);
            transform: translateY(-1px);
        }

        /* Paused state visual for worker panel */
        .worker-panel.paused {
            border-color: rgba(245,158,11,0.35);
            box-shadow: 0 0 20px rgba(245,158,11,0.06);
        }

        .worker-panel.paused::before {
            background: linear-gradient(180deg, #f59e0b, #fcd34d);
        }

        .worker-status-dot.paused {
            background: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245,158,11,0.2);
        }

        /* ============================================
           WORKER STATUS PANEL
        ============================================ */
        .worker-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        /* Đường viền gradient phát sáng khi online */
        .worker-panel.online {
            border-color: rgba(16, 185, 129, 0.4);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.08), inset 0 1px 0 rgba(255,255,255,0.04);
        }

        .worker-panel.offline {
            border-color: rgba(239, 68, 68, 0.3);
            box-shadow: 0 0 20px rgba(239, 68, 68, 0.05);
        }

        /* Dải gradient trang trí bên trái */
        .worker-panel::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            border-radius: 14px 0 0 14px;
        }
        .worker-panel.online::before  { background: linear-gradient(180deg, #10b981, #34d399); }
        .worker-panel.offline::before { background: linear-gradient(180deg, #ef4444, #f87171); }

        .worker-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .worker-status-dot.online {
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.25);
            animation: pulse-online 2s infinite;
        }

        .worker-status-dot.offline {
            background: #ef4444;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.2);
        }

        @keyframes pulse-online {
            0%, 100% { box-shadow: 0 0 0 3px rgba(16,185,129,0.25); }
            50%       { box-shadow: 0 0 0 6px rgba(16,185,129,0.1); }
        }

        .worker-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }

        .worker-value {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .worker-value.online  { color: #34d399; }
        .worker-value.offline { color: #f87171; }

        .worker-divider {
            width: 1px;
            height: 36px;
            background: var(--border-color);
            flex-shrink: 0;
        }

        .worker-meta {
            margin-left: auto;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: right;
        }

        .worker-current-task {
            background: rgba(108,99,255,0.12);
            border: 1px solid rgba(108,99,255,0.25);
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            color: #a78bfa;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .worker-current-task::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #a78bfa;
            animation: pulse-dot 1s infinite;
        }

        .log-preview {
            font-family: 'Courier New', monospace;
            font-size: 0.73rem;
            color: #6ee7b7;
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            max-height: 90px;
            overflow-y: auto;
            line-height: 1.6;
            border: 1px solid rgba(16,185,129,0.1);
            width: 100%;
        }

        .log-line { display: block; }
        .log-time { color: var(--text-muted); margin-right: 0.4rem; }
        .log-ok   { color: #34d399; }
        .log-err  { color: #f87171; }
        .log-info { color: #93c5fd; }
    </style>
</head>
<body>

<!-- ============================================================
     THANH ĐIỀU HƯỚNG
============================================================ -->
<nav class="orbit-navbar">
    <div class="container-lg d-flex justify-content-between align-items-center">
        <div class="orbit-logo">ORBIT <span>Scheduler</span></div>
        <div class="d-flex align-items-center gap-3">
            <span class="nav-badge">MMO TOOL</span>
            <small class="text-muted" id="clock"></small>
            <button class="nav-settings-btn" onclick="openSettingsModal()" title="Cài đặt Telegram &amp; hệ thống">
                ⚙️ Cài đặt
            </button>
        </div>
    </div>
</nav>

<div class="container-lg py-4">

    <!-- ============================================================
         THỐNG KÊ TỔNG QUAN
    ============================================================ -->
    <?php
    $stats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
    foreach ($tasks as $t) {
        if (isset($stats[$t['status']])) $stats[$t['status']]++;
    }
    ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number" id="stat_pending" style="color: var(--badge-pending);"><?= $stats['pending'] ?></div>
                <div class="stat-label">Chờ xử lý</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number" id="stat_processing" style="color: var(--badge-processing);"><?= $stats['processing'] ?></div>
                <div class="stat-label">Đang xử lý</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number" id="stat_completed" style="color: var(--badge-completed);"><?= $stats['completed'] ?></div>
                <div class="stat-label">Hoàn thành</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="stat-number" id="stat_failed" style="color: var(--badge-failed);"><?= $stats['failed'] ?></div>
                <div class="stat-label">Thất bại</div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         WORKER STATUS PANEL
    ============================================================ -->
    <?php
    // Tính class và màu theo trạng thái
    $is_paused  = $worker_online && ($worker['state'] ?? '') === 'paused';
    $is_paused  = $is_paused || ($worker_online && $worker_paused); // fallback từ control file
    $panelClass = $worker_online ? ($is_paused ? 'paused' : 'online') : 'offline';
    $dotClass   = $is_paused ? 'paused' : ($worker_online ? 'online' : 'offline');

    // Xác định state label và màu
    $state_label = 'Chưa khởi động';
    $state_color = 'var(--text-muted)';
    if ($worker) {
        $state_label = match($worker['state'] ?? 'idle') {
            'idle'       => 'Đang chờ task',
            'processing' => 'Đang xử lý task',
            'paused'     => 'Đang tạm dừng',
            'db_error'   => 'Lỗi kết nối DB',
            default      => ucfirst($worker['state'])
        };
        if (!$worker_online) {
            $state_label = 'Mất kết nối';
            $state_color = '#f87171';
        } elseif ($is_paused) {
            $state_color = '#fcd34d';
        } elseif (($worker['state'] ?? '') === 'db_error') {
            $state_color = '#fcd34d';
        }
    }
    // Xác định nút Pause/Resume
    $can_control   = $worker_online;
    $btn_pause_cls = $is_paused ? 'paused' : 'running';
    $btn_pause_lbl = $is_paused ? '▶ Resume Worker' : '⏸ Pause Worker';
    $btn_action    = $is_paused ? 'resume' : 'pause';
    ?>
    <div class="worker-panel <?= $panelClass ?>" id="workerPanel">

        <!-- Đèn trạng thái + tên worker -->
        <div class="d-flex align-items-center gap-2">
            <div class="worker-status-dot <?= $dotClass ?>" id="workerDot"></div>
            <div>
                <div class="worker-title">Python Worker</div>
                <div class="worker-value <?= $dotClass ?>" id="workerStatusLabel"
                     style="<?= ($worker_online && !$is_paused) ? '' : "color:{$state_color};" ?>">
                    <?= $worker_online ? ($is_paused ? 'PAUSED' : 'ONLINE') : 'OFFLINE' ?>
                </div>
            </div>
        </div>

        <div class="worker-divider d-none d-sm-block"></div>

        <!-- Trạng thái hiện tại -->
        <div>
            <div class="worker-title">Trạng thái</div>
            <div class="worker-value" id="workerStateText" style="font-size:0.82rem; color:<?= $state_color ?>;">
                <?php if ($worker && $worker_online && ($worker['state'] ?? '') === 'processing' && !empty($worker['current_task'])): ?>
                    <span class="worker-current-task">
                        Task #<?= $worker['current_task']['id'] ?> · <?= ucfirst($worker['current_task']['platform']) ?>
                    </span>
                <?php else: ?>
                    <?= htmlspecialchars($state_label) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($worker): ?>
        <div class="worker-divider d-none d-md-block"></div>

        <!-- Thống kê xử lý -->
        <div>
            <div class="worker-title">Đã xử lý</div>
            <div class="worker-value"><?= (int)($worker['total_processed'] ?? 0) ?> task</div>
        </div>

        <div class="worker-divider d-none d-md-block"></div>

        <!-- Thành công / thất bại -->
        <div>
            <div class="worker-title">Kết quả</div>
            <div style="display:flex; gap:0.6rem; font-size:0.82rem; font-weight:600;">
                <span style="color:#34d399;">✓ <?= (int)($worker['total_completed'] ?? 0) ?></span>
                <span style="color:var(--text-muted);">·</span>
                <span style="color:#f87171;">✗ <?= (int)($worker['total_failed'] ?? 0) ?></span>
            </div>
        </div>

        <?php if ($worker_uptime): ?>
        <div class="worker-divider d-none d-lg-block"></div>
        <!-- Uptime -->
        <div>
            <div class="worker-title">Uptime</div>
            <div class="worker-value" style="font-family:'Courier New',monospace; font-size:0.85rem;">
                <?= htmlspecialchars($worker_uptime) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Meta: heartbeat cuối + interval -->
        <div class="worker-meta d-none d-lg-block">
            <div>Heartbeat: <strong><?= htmlspecialchars($worker['last_beat'] ?? '-') ?></strong></div>
            <div>Quét mỗi <strong><?= (int)($worker['scan_interval'] ?? 10) ?>s</strong></div>
        </div>

        <?php else: ?>
        <!-- Worker chưa chạy bao giờ -->
        <div style="font-size:0.8rem; color:var(--text-muted);">
            Chưa phát hiện worker. Chạy: <code style="color:#a78bfa;">python worker.py</code>
        </div>
        <?php endif; ?>

        <!-- Nút Pause / Resume — luôn hiển thị nếu worker đang online -->
        <?php if ($can_control): ?>
        <div class="worker-divider d-none d-sm-block"></div>
        <div>
            <button
                id="btnPauseWorker"
                class="btn-pause <?= $btn_pause_cls ?>"
                onclick="toggleWorkerPause('<?= $btn_action ?>')"
                title="<?= $is_paused ? 'Tiếp tục xử lý task' : 'Tạm dừng worker, không lấy task mới' ?>">
                <?= $btn_pause_lbl ?>
            </button>
            <?php if ($is_paused && $control_changed_at): ?>
            <div style="font-size:0.65rem; color:var(--text-muted); margin-top:4px; text-align:center;">
                Tạm dừng lúc <?= htmlspecialchars($control_changed_at) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <div class="row g-4">

        <!-- ============================================================
             CỘT TRÁI: FORM LÊN LỊCH
        ============================================================ -->
        <div class="col-lg-5">
            <div class="glass-card h-100">
                <div class="card-header-orbit">
                    <h2 class="card-title-orbit">
                        <div class="title-icon">🚀</div>
                        Lên lịch đăng video
                    </h2>
                </div>
                <div class="p-4">

                    <form id="scheduleForm" novalidate>

                        <!-- 1. Chọn nền tảng -->
                        <div class="mb-4">
                            <label class="form-label">Nền tảng đăng</label>
                            <div class="platform-group">

                                <div class="platform-option">
                                    <input type="radio" name="platform" id="fb"
                                           value="facebook"
                                           <?= ($old_input['platform'] ?? '') === 'facebook' ? 'checked' : '' ?>
                                           required>
                                    <label class="platform-label" for="fb">
                                        <?= platformIcon('facebook') ?>
                                        Facebook
                                    </label>
                                </div>

                                <div class="platform-option">
                                    <input type="radio" name="platform" id="tt"
                                           value="tiktok"
                                           <?= ($old_input['platform'] ?? '') === 'tiktok' ? 'checked' : '' ?>>
                                    <label class="platform-label" for="tt">
                                        <?= platformIcon('tiktok') ?>
                                        TikTok
                                    </label>
                                </div>

                                <div class="platform-option">
                                    <input type="radio" name="platform" id="yt"
                                           value="youtube"
                                           <?= ($old_input['platform'] ?? '') === 'youtube' ? 'checked' : '' ?>>
                                    <label class="platform-label" for="yt">
                                        <?= platformIcon('youtube') ?>
                                        YouTube
                                    </label>
                                </div>

                            </div>
                        </div>

                        <!-- 1b. Chọn tài khoản đăng (lọc theo platform đã chọn) -->
                        <div class="mb-4" id="accountSelectWrap">
                            <label class="form-label" for="account_id">
                                Tài khoản đăng
                                <span style="font-size:0.7rem; color:var(--text-muted); font-weight:400;">(tuỳ chọn)</span>
                            </label>
                            <select name="account_id" id="account_id" class="form-control">
                                <option value="">— Dùng profile mặc định —</option>
                                <?php foreach ($all_accounts as $_a): ?>
                                <option value="<?= $_a['id'] ?>"
                                        data-platform="<?= $_a['platform'] ?>"
                                        data-status="<?= $_a['status'] ?>"
                                        <?= $_a['status'] === 'inactive' ? 'disabled' : '' ?>>
                                    <?= platformIcon($_a['platform']) ?>
                                    <?= htmlspecialchars($_a['account_name']) ?>
                                    <?= $_a['username'] ? '· ' . htmlspecialchars($_a['username']) : '' ?>
                                    <?= $_a['status'] === 'inactive' ? '[Vô hiệu]' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="noAccountHint" style="display:none; font-size:0.72rem; color:var(--text-muted); margin-top:4px;">
                                Chưa có tài khoản nào cho platform này.
                                <a href="#accountsSection" style="color:#a78bfa;" onclick="document.getElementById('accountsSection').scrollIntoView({behavior:'smooth'})">Thêm tài khoản ↓</a>
                            </div>
                        </div>

                        <!-- 2. Upload file video vật lý -->
                        <div class="mb-4">
                            <label class="form-label">File Video</label>
                            <div class="file-input-wrapper">
                                <!-- Input file thật - có name="video_file" để FormData gửi lên api.php -->
                                <input type="file"
                                       id="file_picker"
                                       name="video_file"
                                       class="file-input-hidden"
                                       accept=".mp4,.mov,.avi,.mkv,.webm,video/*"
                                       required>
                                <!-- Vùng click tuỳ chỉnh - thay nút file mặc định xấu của trình duyệt -->
                                <div class="file-input-display" id="file_drop_zone"
                                     onclick="document.getElementById('file_picker').click()">
                                    <span class="file-btn">📁 Chọn file</span>
                                    <span class="file-name-display" id="file_name_display">
                                        Chưa chọn — .mp4 · .mov · .avi · .mkv (tối đa 500MB)
                                    </span>
                                </div>
                            </div>
                            <!-- Thông tin file sau khi chọn -->
                            <div id="file_info" style="display:none; font-size:0.75rem; color:#6ee7b7; margin-top:0.4rem; padding:0.3rem 0.5rem; background:rgba(16,185,129,0.08); border-radius:6px;"></div>
                        </div>

                        <!-- 3. Caption -->
                        <div class="mb-4">
                            <label class="form-label" for="caption">Caption / Mô tả</label>
                            <textarea name="caption"
                                      id="caption"
                                      class="form-control"
                                      rows="4"
                                      placeholder="Nhập nội dung caption, hashtag, mô tả video..."
                                      required><?= htmlspecialchars($old_input['caption'] ?? '') ?></textarea>
                        </div>

                        <!-- 4. Ngày giờ lên lịch -->
                        <div class="mb-4">
                            <label class="form-label" for="scheduled_at">Thời gian đăng</label>
                            <input type="datetime-local"
                                   name="scheduled_at"
                                   id="scheduled_at"
                                   class="form-control"
                                   value="<?= htmlspecialchars($old_input['scheduled_at'] ?? '') ?>"
                                   required>
                        </div>

                        <!-- Thanh tiến độ upload (hiện khi đang gửi) -->
                        <div class="upload-progress-wrap" id="progressWrap">
                            <div class="upload-progress-label">
                                <span id="progressText">Đang upload...</span>
                                <span id="progressPct">0%</span>
                            </div>
                            <div class="upload-progress-bar-track">
                                <div class="upload-progress-bar-fill" id="progressBar"></div>
                            </div>
                        </div>

                        <!-- Nút submit với trạng thái loading -->
                        <div class="d-grid">
                            <button type="submit" class="btn-orbit" id="submitBtn"
                                    style="display:flex; align-items:center; justify-content:center; gap:0.5rem;">
                                <div class="spinner"></div>
                                <span class="btn-label">⚡ Lên lịch ngay</span>
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================================
             CỘT PHẢI: DANH SÁCH TASK
        ============================================================ -->
        <div class="col-lg-7">
            <div class="glass-card">
                <div class="card-header-orbit d-flex justify-content-between align-items-center">
                    <h2 class="card-title-orbit">
                        <div class="title-icon">📋</div>
                        Danh sách Task
                    </h2>
                    <small class="text-muted" id="taskCount" style="font-size:0.75rem;">
                        <?= count($tasks) ?> task gần nhất
                    </small>
                </div>

                <!-- ================================================
                     FILTER & TÌM KIẾM TASK
                ================================================ -->
                <div class="task-filter-bar" id="filterBar">

                    <!-- Hàng 1: Platform + Status + Clear -->
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label">Platform</label>
                            <select id="f_platform" class="filter-select" onchange="applyFilters()">
                                <option value="">Tất cả</option>
                                <option value="facebook">Facebook</option>
                                <option value="tiktok">TikTok</option>
                                <option value="youtube">YouTube</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Trạng thái</label>
                            <select id="f_status" class="filter-select" onchange="applyFilters()">
                                <option value="">Tất cả</option>
                                <option value="pending">Chờ xử lý</option>
                                <option value="processing">Đang xử lý</option>
                                <option value="completed">Hoàn thành</option>
                                <option value="failed">Thất bại</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Từ ngày</label>
                            <input type="date" id="f_date_from" class="filter-input"
                                   oninput="applyFilters()">
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Đến ngày</label>
                            <input type="date" id="f_date_to" class="filter-input"
                                   oninput="applyFilters()">
                        </div>

                        <div class="filter-group" style="align-self:flex-end;">
                            <button class="filter-clear-btn" id="btnClearFilter"
                                    onclick="clearFilters()" title="Xoá tất cả bộ lọc"
                                    style="display:none;">
                                ✕ Xoá lọc
                            </button>
                        </div>
                    </div>

                    <!-- Hàng 2: Tìm kiếm theo caption -->
                    <div class="filter-search-wrap">
                        <span class="filter-search-icon">🔍</span>
                        <input type="text" id="f_search"
                               class="filter-search-input"
                               placeholder="Tìm kiếm theo nội dung caption…"
                               oninput="debounceSearch()"
                               autocomplete="off">
                        <span id="filterResultBadge" class="filter-result-badge" style="display:none;"></span>
                    </div>

                </div>

                <div class="p-3" style="overflow-x: auto;">
                    <?php if (empty($tasks)): ?>
                        <div class="text-center py-5" style="color: var(--text-muted);">
                            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📭</div>
                            <div>Chưa có task nào. Hãy lên lịch video đầu tiên!</div>
                        </div>
                    <?php else: ?>
                        <table class="orbit-table">
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Platform</th>
                                    <th>Caption</th>
                                    <th>Lịch đăng</th>
                                    <th>Trạng thái</th>
                                    <th style="text-align:center;">Hành động</th>
                                </tr>
                            </thead>
                            <tbody id="taskTableBody">
                                <?php foreach ($tasks as $task): ?>
                                <tr id="taskRow_<?= $task['id'] ?>">
                                    <td><span class="task-id">#<?= $task['id'] ?></span></td>

                                    <td>
                                        <div class="platform-cell">
                                            <?= platformIcon($task['platform']) ?>
                                            <?= ucfirst($task['platform']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="caption-cell" title="<?= htmlspecialchars($task['caption']) ?>">
                                            <?= htmlspecialchars(mb_substr($task['caption'], 0, 40)) ?>
                                            <?= mb_strlen($task['caption']) > 40 ? '…' : '' ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="date-cell">
                                            <?= date('d/m/Y', strtotime($task['scheduled_at'])) ?>
                                            <br>
                                            <small style="color: var(--text-muted);">
                                                <?= date('H:i', strtotime($task['scheduled_at'])) ?>
                                            </small>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="status-badge <?= statusBadge($task['status']) ?>">
                                            <?= statusLabel($task['status']) ?>
                                        </span>
                                    </td>

                                    <!-- Cột Hành động: nút theo trạng thái task -->
                                    <td style="text-align:center;">
                                        <div style="display:flex; gap:5px; justify-content:center; flex-wrap:wrap;">
                                            <?php if ($task['status'] === 'failed'): ?>
                                                <button class="action-btn btn-retry"
                                                        onclick="retryTask(<?= $task['id'] ?>)"
                                                        title="Đặt lại task về pending để xử lý lại">
                                                    🔄 Retry
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($task['status'] === 'pending'): ?>
                                                <button class="action-btn btn-cancel"
                                                        onclick="cancelTask(<?= $task['id'] ?>)"
                                                        title="Huỷ task, không xử lý nữa">
                                                    ✕ Huỷ
                                                </button>
                                            <?php endif; ?>

                                            <?php if (in_array($task['status'], ['completed', 'failed', 'pending'])): ?>
                                                <button class="action-btn btn-delete"
                                                        onclick="deleteTask(<?= $task['id'] ?>)"
                                                        title="Xóa task khỏi danh sách">
                                                    🗑
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($task['status'] === 'processing'): ?>
                                                <span style="font-size:0.7rem; color:var(--text-muted);">Đang chạy…</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Hướng dẫn nhanh -->
            <div class="glass-card mt-4 p-4">
                <h3 class="card-title-orbit mb-3">
                    <div class="title-icon">⚙️</div>
                    Hướng dẫn nhanh
                </h3>
                <div style="font-size: 0.82rem; color: var(--text-secondary); line-height: 1.8;">
                    <div><strong style="color: var(--accent-start);">1.</strong> Import <code>database.sql</code> vào phpMyAdmin</div>
                    <div><strong style="color: var(--accent-start);">2.</strong> Copy thư mục <code>orbit-php/</code> vào <code>htdocs/orbit/</code></div>
                    <div><strong style="color: var(--accent-start);">3.</strong> Cấu hình đường dẫn Chrome Profile trong <code>worker.py</code></div>
                    <div><strong style="color: var(--accent-start);">4.</strong> Cài thư viện Python: <code>pip install selenium mysql-connector-python requests</code></div>
                    <div><strong style="color: var(--accent-start);">5.</strong> Chạy worker: <code>python worker.py</code></div>
                </div>
            </div>
        </div>

    </div><!-- /.row -->

    <!-- ============================================================
         PHẦN QUẢN LÝ TÀI KHOẢN
    ============================================================ -->
    <div class="row mt-4" id="accountsSection">
        <div class="col-12">
            <div class="glass-card">
                <div class="card-header-orbit d-flex justify-content-between align-items-center">
                    <h2 class="card-title-orbit">
                        <div class="title-icon">👤</div>
                        Quản lý Tài khoản Đa nền tảng
                    </h2>
                    <small style="font-size:0.72rem; color:var(--text-muted);">
                        <?= count($all_accounts) ?> tài khoản
                    </small>
                </div>

                <div class="p-3">
                    <div class="row g-4">

                        <!-- Form thêm tài khoản -->
                        <div class="col-lg-5">
                            <div style="background:rgba(255,255,255,0.025); border:1px solid var(--border-color); border-radius:12px; padding:1.2rem;">
                                <div style="font-size:0.85rem; font-weight:700; color:var(--text-primary); margin-bottom:1rem;">
                                    ➕ Thêm tài khoản mới
                                </div>

                                <form id="addAccountForm" onsubmit="addAccount(event)">
                                    <div class="mb-3">
                                        <label class="filter-label">Platform</label>
                                        <select name="platform" id="acc_platform" class="form-control" required>
                                            <option value="">Chọn platform…</option>
                                            <option value="facebook">Facebook</option>
                                            <option value="tiktok">TikTok</option>
                                            <option value="youtube">YouTube</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="filter-label">Tên tài khoản <span style="color:#f87171;">*</span></label>
                                        <input type="text" name="account_name" class="form-control"
                                               placeholder="VD: Fanpage chính, TikTok MMO 1…" required maxlength="100">
                                    </div>

                                    <div class="mb-3">
                                        <label class="filter-label">Username / Email</label>
                                        <input type="text" name="username" class="form-control"
                                               placeholder="Email hoặc username đăng nhập" maxlength="100">
                                    </div>

                                    <div class="mb-3">
                                        <label class="filter-label">
                                            Chrome Profile Path
                                            <span style="font-size:0.65rem; color:var(--text-muted);">(user-data-dir)</span>
                                        </label>
                                        <input type="text" name="profile_path" class="form-control"
                                               placeholder="VD: C:\Users\ten\AppData\Local\Google\Chrome\User Data"
                                               maxlength="500">
                                    </div>

                                    <div class="mb-3">
                                        <label class="filter-label">
                                            Profile Directory
                                            <span style="font-size:0.65rem; color:var(--text-muted);">(Default, Profile 1…)</span>
                                        </label>
                                        <input type="text" name="profile_dir" class="form-control"
                                               placeholder="Default" value="Default" maxlength="100">
                                    </div>

                                    <button type="submit" class="btn-orbit" id="addAccountBtn"
                                            style="display:flex; align-items:center; justify-content:center; gap:0.5rem; width:100%;">
                                        <div class="spinner" id="addAccountSpinner" style="display:none;"></div>
                                        <span>➕ Thêm tài khoản</span>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Danh sách tài khoản hiện có -->
                        <div class="col-lg-7">
                            <div id="accountsTable">
                                <?php if (empty($all_accounts)): ?>
                                <div style="text-align:center; padding:2rem; color:var(--text-muted);">
                                    <div style="font-size:2rem; margin-bottom:0.5rem;">👤</div>
                                    <div>Chưa có tài khoản nào. Thêm tài khoản đầu tiên!</div>
                                </div>
                                <?php else: ?>
                                <table class="orbit-table">
                                    <thead>
                                        <tr>
                                            <th>Platform</th>
                                            <th>Tên tài khoản</th>
                                            <th>Username</th>
                                            <th>Chrome Profile</th>
                                            <th>Trạng thái</th>
                                            <th style="text-align:center;">Hành động</th>
                                        </tr>
                                    </thead>
                                    <tbody id="accountsTbody">
                                        <?php foreach ($all_accounts as $_acc): ?>
                                        <tr id="accRow_<?= $_acc['id'] ?>">
                                            <td>
                                                <div class="platform-cell">
                                                    <?= platformIcon($_acc['platform']) ?>
                                                    <?= ucfirst($_acc['platform']) ?>
                                                </div>
                                            </td>
                                            <td style="font-weight:600;"><?= htmlspecialchars($_acc['account_name']) ?></td>
                                            <td style="font-size:0.8rem; color:var(--text-muted);"><?= htmlspecialchars($_acc['username'] ?? '—') ?></td>
                                            <td style="font-size:0.72rem; color:var(--text-muted); max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="Profile: <?= htmlspecialchars($_acc['profile_path'] ?? '') ?>">
                                                <?= htmlspecialchars($_acc['profile_path'] ? basename($_acc['profile_path']) . '/' . $_acc['profile_dir'] : '—') ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?= $_acc['status'] === 'active' ? 'badge-completed' : 'badge-failed' ?>"
                                                      style="cursor:pointer;"
                                                      onclick="toggleAccount(<?= $_acc['id'] ?>, this)"
                                                      title="Click để <?= $_acc['status'] === 'active' ? 'vô hiệu hóa' : 'kích hoạt' ?>">
                                                    <?= $_acc['status'] === 'active' ? 'Hoạt động' : 'Vô hiệu' ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;">
                                                <button class="action-btn btn-delete"
                                                        onclick="deleteAccount(<?= $_acc['id'] ?>, '<?= htmlspecialchars(addslashes($_acc['account_name'])) ?>')"
                                                        title="Xóa tài khoản">
                                                    🗑 Xóa
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.container -->

<!-- ============================================================
     MODAL CÀI ĐẶT TELEGRAM
============================================================ -->
<div id="settingsModal" class="settings-modal-overlay" style="display:none;" onclick="closeSettingsOnOverlay(event)">
    <div class="settings-modal-box">
        <div class="settings-modal-header">
            <span>⚙️ Cài đặt hệ thống</span>
            <button class="settings-close-btn" onclick="closeSettingsModal()">✕</button>
        </div>

        <div class="settings-modal-body">

            <!-- Telegram -->
            <div class="settings-section">
                <div class="settings-section-title">
                    <span>📱</span> Thông báo Telegram
                </div>
                <div class="settings-section-desc">
                    Nhận thông báo tự động qua Telegram khi task thành công hoặc thất bại.
                    <a href="https://core.telegram.org/bots#how-do-i-create-a-bot" target="_blank" style="color:#a78bfa;">Cách tạo Bot?</a>
                </div>

                <!-- Toggle bật/tắt Telegram -->
                <div class="settings-toggle-row">
                    <label class="settings-toggle-label">Bật thông báo Telegram</label>
                    <label class="toggle-switch">
                        <input type="checkbox" id="set_telegram_enabled"
                               <?= $tg_settings['telegram_enabled'] === '1' ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="mb-3">
                    <label class="filter-label">Bot Token</label>
                    <div style="display:flex; gap:8px;">
                        <input type="password" id="set_bot_token" class="form-control"
                               placeholder="123456789:ABCdefGhIJklmnoPQRstuvWXyz"
                               value="<?= htmlspecialchars($tg_settings['telegram_bot_token']) ?>"
                               autocomplete="off">
                        <button type="button" class="action-btn btn-retry" style="white-space:nowrap;"
                                onclick="toggleTokenVisibility()">👁</button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="filter-label">Chat ID</label>
                    <input type="text" id="set_chat_id" class="form-control"
                           placeholder="VD: -100123456789 hoặc @username"
                           value="<?= htmlspecialchars($tg_settings['telegram_chat_id']) ?>">
                    <div style="font-size:0.67rem; color:var(--text-muted); margin-top:3px;">
                        Lấy Chat ID: nhắn @userinfobot hoặc dùng bot @getmyid_bot
                    </div>
                </div>

                <!-- Tuỳ chọn loại thông báo -->
                <div style="display:flex; gap:1.2rem; margin-bottom:0.8rem; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:6px; font-size:0.78rem; cursor:pointer;">
                        <input type="checkbox" id="set_notify_success"
                               <?= $tg_settings['telegram_notify_success'] === '1' ? 'checked' : '' ?>>
                        ✅ Thông báo khi thành công
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:0.78rem; cursor:pointer;">
                        <input type="checkbox" id="set_notify_failed"
                               <?= $tg_settings['telegram_notify_failed'] === '1' ? 'checked' : '' ?>>
                        ❌ Thông báo khi thất bại
                    </label>
                </div>

                <!-- Nút Test + Lưu -->
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="action-btn btn-retry" onclick="testTelegram()" id="btnTestTg"
                            style="padding:0.45rem 1rem; font-size:0.78rem;">
                        📤 Gửi tin test
                    </button>
                    <button class="btn-orbit" onclick="saveSettings()" id="btnSaveSettings"
                            style="display:flex; align-items:center; gap:6px; padding:0.45rem 1.2rem; font-size:0.82rem; border-radius:10px; width:auto;">
                        <div class="spinner" id="saveSpinner" style="display:none;"></div>
                        💾 Lưu cài đặt
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
'use strict';

// ================================================================
// ORBIT — MAIN SCRIPT
// ================================================================

// ---- Cấu hình SweetAlert2 Toast (góc phải màn hình) ----
const Toast = Swal.mixin({
    toast:            true,
    position:         'top-end',
    showConfirmButton: false,
    timer:            4500,
    timerProgressBar:  true,
    background:       '#111527',
    color:            '#e8eaf6',
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    },
    customClass: {
        popup:          'swal2-popup',
        timerProgressBar: 'swal2-timer-bar',
    }
});

// Hàm hiện toast thành công
function toastSuccess(message) {
    Toast.fire({
        icon:  'success',
        title: message,
    });
}

// Hàm hiện toast lỗi (tồn tại lâu hơn)
function toastError(message) {
    Toast.fire({
        icon:      'error',
        title:     message,
        timer:     7000,
        iconColor: '#f87171',
    });
}

// ----------------------------------------------------------------
// 1. CLOCK REALTIME
// ----------------------------------------------------------------
function updateClock() {
    const opts = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
    document.getElementById('clock').textContent =
        new Date().toLocaleTimeString('vi-VN', opts);
}
setInterval(updateClock, 1000);
updateClock();

// ----------------------------------------------------------------
// 2. FILE INPUT — Hiển thị thông tin sau khi chọn
// ----------------------------------------------------------------
document.getElementById('file_picker').addEventListener('change', function () {
    const file     = this.files[0];
    const display  = document.getElementById('file_name_display');
    const infoBox  = document.getElementById('file_info');

    if (!file) {
        display.textContent = 'Chưa chọn — .mp4 · .mov · .avi · .mkv (tối đa 500MB)';
        infoBox.style.display = 'none';
        return;
    }

    const sizeMB   = (file.size / 1024 / 1024).toFixed(2);
    const ext      = file.name.split('.').pop().toLowerCase();
    const allowed  = ['mp4', 'mov', 'avi', 'mkv', 'webm'];

    display.textContent = file.name;

    if (!allowed.includes(ext)) {
        infoBox.style.display = 'block';
        infoBox.style.color   = '#f87171';
        infoBox.style.background = 'rgba(239,68,68,0.08)';
        infoBox.textContent   = `✕ Định dạng .${ext} không được hỗ trợ.`;
        return;
    }

    if (file.size > 500 * 1024 * 1024) {
        infoBox.style.display = 'block';
        infoBox.style.color   = '#f87171';
        infoBox.style.background = 'rgba(239,68,68,0.08)';
        infoBox.textContent   = `✕ File quá lớn: ${sizeMB}MB (tối đa 500MB).`;
        return;
    }

    infoBox.style.display = 'block';
    infoBox.style.color   = '#6ee7b7';
    infoBox.style.background = 'rgba(16,185,129,0.08)';
    infoBox.textContent   = `✓ ${file.name}  ·  ${sizeMB} MB  ·  .${ext.toUpperCase()}`;
});

// ----------------------------------------------------------------
// 3. PROGRESS BAR HELPERS
// ----------------------------------------------------------------
const progressWrap = document.getElementById('progressWrap');
const progressBar  = document.getElementById('progressBar');
const progressText = document.getElementById('progressText');
const progressPct  = document.getElementById('progressPct');

function showProgress(pct, label) {
    progressWrap.classList.add('visible');
    progressBar.classList.remove('indeterminate');
    progressBar.style.width = pct + '%';
    progressText.textContent = label || 'Đang upload...';
    progressPct.textContent  = pct + '%';
}

function showIndeterminate(label) {
    progressWrap.classList.add('visible');
    progressBar.classList.add('indeterminate');
    progressText.textContent = label || 'Đang xử lý...';
    progressPct.textContent  = '';
}

function hideProgress() {
    progressWrap.classList.remove('visible');
    progressBar.classList.remove('indeterminate');
    progressBar.style.width = '0%';
}

// ----------------------------------------------------------------
// 4. SET SUBMIT BUTTON STATE
// ----------------------------------------------------------------
const submitBtn = document.getElementById('submitBtn');
const btnLabel  = submitBtn.querySelector('.btn-label');

function setLoading(isLoading) {
    if (isLoading) {
        submitBtn.classList.add('loading');
        btnLabel.textContent = 'Đang xử lý...';
    } else {
        submitBtn.classList.remove('loading');
        btnLabel.textContent = '⚡ Lên lịch ngay';
    }
}

// ----------------------------------------------------------------
// 5. AJAX FORM SUBMIT — Fetch API + FormData (no page reload)
// ----------------------------------------------------------------
document.getElementById('scheduleForm').addEventListener('submit', async function (e) {
    e.preventDefault(); // Chặn submit truyền thống

    // Kiểm tra platform đã chọn chưa
    const platform = this.querySelector('input[name="platform"]:checked');
    if (!platform) {
        toastError('Vui lòng chọn nền tảng đăng (Facebook / TikTok / YouTube).');
        return;
    }

    // Kiểm tra file đã chọn chưa
    const fileInput = document.getElementById('file_picker');
    if (!fileInput.files || fileInput.files.length === 0) {
        toastError('Vui lòng chọn file video để upload.');
        return;
    }

    const caption     = document.getElementById('caption').value.trim();
    const scheduledAt = document.getElementById('scheduled_at').value;

    if (!caption) {
        toastError('Caption không được để trống.');
        return;
    }
    if (!scheduledAt) {
        toastError('Vui lòng chọn thời gian lên lịch.');
        return;
    }

    // --- Bắt đầu upload ---
    setLoading(true);
    showProgress(0, 'Đang chuẩn bị...');

    // Tạo FormData từ form (tự động đính kèm file)
    const formData = new FormData(this);

    try {
        // Dùng XMLHttpRequest để theo dõi tiến độ upload (Fetch API không hỗ trợ)
        const response = await uploadWithProgress('api.php', formData, (pct) => {
            showProgress(pct, pct < 100 ? 'Đang upload...' : 'Đang lưu vào server...');
        });

        const data = response;

        if (data.success) {
            // ✓ THÀNH CÔNG
            hideProgress();
            setLoading(false);

            // Hiện toast thành công
            toastSuccess(data.message);

            // Reset toàn bộ form
            document.getElementById('scheduleForm').reset();
            document.getElementById('file_name_display').textContent =
                'Chưa chọn — .mp4 · .mov · .avi · .mkv (tối đa 500MB)';
            document.getElementById('file_info').style.display = 'none';

            // Refresh bảng task (AJAX, không reload trang)
            await refreshTaskTable();

        } else {
            // ✕ LỖI từ server
            hideProgress();
            setLoading(false);
            toastError(data.message || 'Có lỗi xảy ra. Vui lòng thử lại.');
        }

    } catch (err) {
        hideProgress();
        setLoading(false);
        console.error('Upload error:', err);
        toastError('Lỗi kết nối. Kiểm tra server XAMPP đang chạy.');
    }
});

// ----------------------------------------------------------------
// 6. UPLOAD với PROGRESS (XMLHttpRequest thay vì Fetch)
//    Fetch API không expose upload progress events.
// ----------------------------------------------------------------
function uploadWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();

        // Theo dõi tiến độ upload
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                onProgress(pct);
            } else {
                showIndeterminate('Đang upload...');
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    resolve(JSON.parse(xhr.responseText));
                } catch {
                    reject(new Error('Server trả về dữ liệu không hợp lệ.'));
                }
            } else {
                try {
                    const errData = JSON.parse(xhr.responseText);
                    resolve(errData); // Để handler xử lý lỗi từ api.php
                } catch {
                    reject(new Error(`HTTP ${xhr.status}: ${xhr.statusText}`));
                }
            }
        });

        xhr.addEventListener('error',  () => reject(new Error('Lỗi mạng khi upload.')));
        xhr.addEventListener('abort',  () => reject(new Error('Upload bị huỷ.')));
        xhr.addEventListener('timeout',() => reject(new Error('Upload timeout (file quá lớn?).')));

        xhr.open('POST', url);
        xhr.timeout = 10 * 60 * 1000; // Timeout 10 phút cho file lớn
        xhr.send(formData);
    });
}

// ----------------------------------------------------------------
// 7. REFRESH BẢNG TASK QUA AJAX (có hỗ trợ filter)
// ----------------------------------------------------------------
const statusLabels = {
    pending:    'Chờ xử lý',
    processing: 'Đang xử lý',
    completed:  'Hoàn thành',
    failed:     'Thất bại',
};
const statusClasses = {
    pending:    'badge-pending',
    processing: 'badge-processing',
    completed:  'badge-completed',
    failed:     'badge-failed',
};
const platformIcons = {
    facebook: `<svg class="platform-icon facebook-icon" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>`,
    tiktok:   `<svg class="platform-icon tiktok-icon" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>`,
    youtube:  `<svg class="platform-icon youtube-icon" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M23.495 6.205a3.007 3.007 0 0 0-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 0 0 .527 6.205a31.247 31.247 0 0 0-.522 5.805 31.247 31.247 0 0 0 .522 5.783 3.007 3.007 0 0 0 2.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 0 0 2.088-2.088 31.247 31.247 0 0 0 .5-5.783 31.247 31.247 0 0 0-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"/></svg>`,
};

/**
 * Đọc giá trị bộ lọc hiện tại từ các input trong filter bar.
 * Trả về URLSearchParams sẵn sàng để gắn vào URL fetch.
 */
function getFilterParams() {
    const params = new URLSearchParams();
    const platform  = document.getElementById('f_platform')?.value  || '';
    const status    = document.getElementById('f_status')?.value    || '';
    const date_from = document.getElementById('f_date_from')?.value || '';
    const date_to   = document.getElementById('f_date_to')?.value   || '';
    const search    = document.getElementById('f_search')?.value.trim() || '';
    if (platform)  params.set('platform',  platform);
    if (status)    params.set('status',    status);
    if (date_from) params.set('date_from', date_from);
    if (date_to)   params.set('date_to',   date_to);
    if (search)    params.set('search',    search);
    return params;
}

/** Kiểm tra có bộ lọc nào đang hoạt động không */
function hasActiveFilter() {
    return getFilterParams().toString() !== '';
}

/** Cập nhật hiển thị nút "Xoá lọc" và badge kết quả */
function updateFilterUI(data) {
    const hasFilter = data.has_filter ?? hasActiveFilter();
    const clearBtn  = document.getElementById('btnClearFilter');
    const badge     = document.getElementById('filterResultBadge');
    const taskCount = document.getElementById('taskCount');

    if (clearBtn) clearBtn.style.display = hasFilter ? 'inline-block' : 'none';

    if (badge) {
        if (hasFilter) {
            badge.textContent = `${data.filtered_total ?? data.total} kết quả`;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }

    if (taskCount) {
        if (hasFilter) {
            taskCount.innerHTML = `${data.filtered_total ?? data.total} kết quả lọc <span class="filter-active-indicator"></span>`;
        } else {
            taskCount.textContent = `${data.total} task gần nhất`;
        }
    }
}

/** Áp dụng bộ lọc và refresh bảng ngay lập tức */
function applyFilters() {
    refreshTaskTable();
}

/** Xoá tất cả bộ lọc và refresh bảng */
function clearFilters() {
    const els = ['f_platform', 'f_status', 'f_date_from', 'f_date_to', 'f_search'];
    els.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    refreshTaskTable();
}

/** Debounce cho input tìm kiếm (chờ 350ms sau lần gõ cuối) */
let _searchTimer = null;
function debounceSearch() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => refreshTaskTable(), 350);
}

async function refreshTaskTable() {
    try {
        const params = getFilterParams();
        const url    = 'get_tasks.php' + (params.toString() ? '?' + params.toString() : '');
        const res    = await fetch(url);
        const data   = await res.json();

        if (!data.success) return;

        // Cập nhật UI filter (badge kết quả + nút Xoá lọc)
        updateFilterUI(data);

        const tbody = document.getElementById('taskTableBody');
        if (!tbody) return;

        if (data.tasks.length === 0) {
            const hasFilter = data.has_filter ?? hasActiveFilter();
            const emptyMsg  = hasFilter
                ? '🔍 Không tìm thấy task nào khớp với bộ lọc.'
                : '📭 Chưa có task nào. Hãy lên lịch video đầu tiên!';
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-muted);">${emptyMsg}</td></tr>`;
            return;
        }

        // Xây dựng HTML các hàng mới
        tbody.innerHTML = data.tasks.map(t => {
            const d    = new Date(t.scheduled_at);
            const date = d.toLocaleDateString('vi-VN');
            const time = d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
            const cap  = t.caption.length > 40
                ? t.caption.substring(0, 40) + '…'
                : t.caption;
            const icon     = platformIcons[t.platform] || '';
            const badge    = statusClasses[t.status]   || 'bg-secondary';
            const label    = statusLabels[t.status]    || t.status;
            const capTitle = t.caption.replace(/"/g, '&quot;');

            // Xây dựng nút hành động theo trạng thái
            let actions = '';
            if (t.status === 'failed') {
                actions += `<button class="action-btn btn-retry" onclick="retryTask(${t.id})" title="Retry task">🔄 Retry</button>`;
            }
            if (t.status === 'pending') {
                actions += `<button class="action-btn btn-cancel" onclick="cancelTask(${t.id})" title="Huỷ task">✕ Huỷ</button>`;
            }
            if (['completed','failed','pending'].includes(t.status)) {
                actions += `<button class="action-btn btn-delete" onclick="deleteTask(${t.id})" title="Xóa task">🗑</button>`;
            }
            if (t.status === 'processing') {
                actions += `<span style="font-size:0.7rem; color:var(--text-muted);">Đang chạy…</span>`;
            }

            return `<tr id="taskRow_${t.id}">
                <td><span class="task-id">#${t.id}</span></td>
                <td><div class="platform-cell">${icon}${t.platform.charAt(0).toUpperCase()+t.platform.slice(1)}</div></td>
                <td><span class="caption-cell" title="${capTitle}">${cap}</span></td>
                <td>
                    <div class="date-cell">${date}<br>
                    <small style="color:var(--text-muted);">${time}</small></div>
                </td>
                <td><span class="status-badge ${badge}">${label}</span></td>
                <td style="text-align:center;">
                    <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;">${actions}</div>
                </td>
            </tr>`;
        }).join('');

        // Cập nhật stat boxes
        const c = data.counts;
        ['pending','processing','completed','failed'].forEach(s => {
            const el = document.getElementById('stat_' + s);
            if (el) el.textContent = c[s] || 0;
        });

    } catch (err) {
        console.warn('Không thể refresh bảng task:', err);
    }
}

// ---- Tự động refresh bảng task mỗi 15 giây (không reload trang) ----
setInterval(refreshTaskTable, 15000);

// ================================================================
// 8. PLATFORM RADIO → LỌC ACCOUNT DROPDOWN
// ================================================================

/** Khi người dùng chọn platform trong form lên lịch → lọc dropdown account */
function filterAccountsByPlatform(platform) {
    const sel     = document.getElementById('account_id');
    const hint    = document.getElementById('noAccountHint');
    if (!sel) return;

    let hasOptions = false;
    Array.from(sel.options).forEach(opt => {
        if (opt.value === '') { opt.style.display = ''; return; } // "Dùng mặc định" luôn hiện
        const match = opt.dataset.platform === platform;
        opt.style.display = match ? '' : 'none';
        if (match) hasOptions = true;
    });

    // Reset về "Dùng mặc định" khi đổi platform
    sel.value = '';

    if (hint) hint.style.display = (hasOptions || !platform) ? 'none' : 'block';
}

// Gắn event listener cho các radio platform trong form lên lịch
document.querySelectorAll('input[name="platform"]').forEach(radio => {
    radio.addEventListener('change', () => filterAccountsByPlatform(radio.value));
});

// Khởi tạo filter khi load trang (nếu có radio đang checked)
(function initAccountFilter() {
    const checked = document.querySelector('input[name="platform"]:checked');
    if (checked) filterAccountsByPlatform(checked.value);
})();

// ================================================================
// 9. SETTINGS MODAL — TELEGRAM CONFIG
// ================================================================

function openSettingsModal() {
    document.getElementById('settingsModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeSettingsModal() {
    document.getElementById('settingsModal').style.display = 'none';
    document.body.style.overflow = '';
}

function closeSettingsOnOverlay(e) {
    if (e.target === document.getElementById('settingsModal')) closeSettingsModal();
}

function toggleTokenVisibility() {
    const inp = document.getElementById('set_bot_token');
    inp.type  = inp.type === 'password' ? 'text' : 'password';
}

async function saveSettings() {
    const btn     = document.getElementById('btnSaveSettings');
    const spinner = document.getElementById('saveSpinner');
    btn.disabled  = true;
    spinner.style.display = 'block';

    try {
        const fd = new FormData();
        fd.append('action',                   'save');
        fd.append('telegram_bot_token',       document.getElementById('set_bot_token').value.trim());
        fd.append('telegram_chat_id',         document.getElementById('set_chat_id').value.trim());
        fd.append('telegram_enabled',         document.getElementById('set_telegram_enabled').checked ? '1' : '0');
        fd.append('telegram_notify_success',  document.getElementById('set_notify_success').checked  ? '1' : '0');
        fd.append('telegram_notify_failed',   document.getElementById('set_notify_failed').checked   ? '1' : '0');

        const res  = await fetch('settings.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            Swal.fire({
                toast: true, position: 'bottom-end', icon: 'success',
                title: data.message, showConfirmButton: false,
                timer: 2500, timerProgressBar: true,
                background: '#12172b', color: '#34d399',
            });
        } else {
            throw new Error(data.message);
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Lỗi lưu cài đặt', text: err.message,
            background: '#12172b', color: '#e2e8f0' });
    } finally {
        btn.disabled = false;
        spinner.style.display = 'none';
    }
}

async function testTelegram() {
    const btn = document.getElementById('btnTestTg');
    const origText = btn.textContent;
    btn.disabled   = true;
    btn.textContent = '⏳ Đang gửi…';

    try {
        const fd = new FormData();
        fd.append('action',              'test_telegram');
        fd.append('telegram_bot_token', document.getElementById('set_bot_token').value.trim());
        fd.append('telegram_chat_id',   document.getElementById('set_chat_id').value.trim());

        const res  = await fetch('settings.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            Swal.fire({
                toast: true, position: 'bottom-end', icon: 'success',
                title: '📱 ' + data.message, showConfirmButton: false,
                timer: 3500, timerProgressBar: true,
                background: '#12172b', color: '#34d399',
            });
        } else {
            throw new Error(data.message);
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Test Telegram thất bại', text: err.message,
            background: '#12172b', color: '#e2e8f0' });
    } finally {
        btn.disabled    = false;
        btn.textContent = origText;
    }
}

// Đóng modal khi nhấn Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSettingsModal();
});

// ================================================================
// 10. QUẢN LÝ TÀI KHOẢN (ACCOUNTS)
// ================================================================

async function addAccount(e) {
    e.preventDefault();
    const form    = document.getElementById('addAccountForm');
    const btn     = document.getElementById('addAccountBtn');
    const spinner = document.getElementById('addAccountSpinner');
    btn.disabled  = true;
    spinner.style.display = 'block';

    try {
        const fd = new FormData(form);
        fd.set('action', 'create');

        const res  = await fetch('accounts.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) throw new Error(data.message);

        Swal.fire({
            toast: true, position: 'bottom-end', icon: 'success',
            title: data.message, showConfirmButton: false,
            timer: 3000, timerProgressBar: true,
            background: '#12172b', color: '#34d399',
        });

        form.reset();
        // Reload trang để cập nhật dropdown tài khoản + bảng
        setTimeout(() => location.reload(), 800);

    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Thêm thất bại', text: err.message,
            background: '#12172b', color: '#e2e8f0' });
    } finally {
        btn.disabled = false;
        spinner.style.display = 'none';
    }
}

async function deleteAccount(id, name) {
    const result = await Swal.fire({
        title:             `🗑 Xóa tài khoản?`,
        html:              `Tài khoản <strong>${name}</strong> sẽ bị xóa vĩnh viễn.`,
        icon:              'warning',
        showCancelButton:  true,
        confirmButtonText: 'Xóa',
        cancelButtonText:  'Không',
        confirmButtonColor:'#ef4444',
        background:        '#12172b',
        color:             '#e2e8f0',
    });
    if (!result.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id',     id);
        const res  = await fetch('accounts.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.message);

        const row = document.getElementById('accRow_' + id);
        if (row) {
            row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            row.style.opacity    = '0';
            row.style.transform  = 'translateX(-10px)';
            setTimeout(() => { row.remove(); }, 320);
        }

        Swal.fire({
            toast: true, position: 'bottom-end', icon: 'success',
            title: data.message, showConfirmButton: false,
            timer: 2500, timerProgressBar: true,
            background: '#12172b', color: '#34d399',
        });

    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Không thể xóa', text: err.message,
            background: '#12172b', color: '#e2e8f0' });
    }
}

async function toggleAccount(id, badgeEl) {
    try {
        const fd = new FormData();
        fd.append('action', 'toggle');
        fd.append('id',     id);
        const res  = await fetch('accounts.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.message);

        const isActive = data.status === 'active';
        badgeEl.className = 'status-badge ' + (isActive ? 'badge-completed' : 'badge-failed');
        badgeEl.textContent = isActive ? 'Hoạt động' : 'Vô hiệu';
        badgeEl.title = 'Click để ' + (isActive ? 'vô hiệu hóa' : 'kích hoạt');

        Swal.fire({
            toast: true, position: 'bottom-end', icon: 'success',
            title: data.message, showConfirmButton: false,
            timer: 2000, timerProgressBar: true,
            background: '#12172b', color: '#34d399',
        });
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Lỗi', text: err.message,
            background: '#12172b', color: '#e2e8f0' });
    }
}

// ================================================================
// 11. ĐIỀU KHIỂN WORKER: PAUSE / RESUME
// ================================================================
async function toggleWorkerPause(action) {
    const isPause = action === 'pause';
    const title   = isPause ? '⏸ Tạm dừng Worker?' : '▶ Tiếp tục Worker?';
    const text    = isPause
        ? 'Worker sẽ ngừng nhận task mới. Task đang xử lý sẽ hoàn thành trước khi dừng.'
        : 'Worker sẽ tiếp tục quét và xử lý task theo lịch.';

    const result = await Swal.fire({
        title,
        text,
        icon:              isPause ? 'warning' : 'question',
        showCancelButton:  true,
        confirmButtonText: isPause ? 'Tạm dừng' : 'Tiếp tục',
        cancelButtonText:  'Huỷ bỏ',
        confirmButtonColor: isPause ? '#f59e0b' : '#10b981',
        background:        '#12172b',
        color:             '#e2e8f0',
    });
    if (!result.isConfirmed) return;

    const btn = document.getElementById('btnPauseWorker');
    if (btn) { btn.disabled = true; btn.textContent = 'Đang gửi lệnh…'; }

    try {
        const fd = new FormData();
        fd.append('action', action);
        const res  = await fetch('control.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            Swal.fire({
                toast:             true,
                position:          'bottom-end',
                icon:              'success',
                title:             data.message,
                showConfirmButton: false,
                timer:             3500,
                timerProgressBar:  true,
                background:        '#12172b',
                color:             '#34d399',
            });
            // Reload trang để cập nhật trạng thái panel ngay
            setTimeout(() => location.reload(), 1200);
        } else {
            throw new Error(data.message);
        }
    } catch (err) {
        Swal.fire({
            icon:        'error',
            title:       'Lỗi điều khiển worker',
            text:        err.message,
            background:  '#12172b',
            color:       '#e2e8f0',
        });
        if (btn) { btn.disabled = false; btn.textContent = isPause ? '⏸ Pause Worker' : '▶ Resume Worker'; }
    }
}

// ================================================================
// 9. HÀNH ĐỘNG TASK: RETRY / CANCEL / DELETE
// ================================================================

/** Gọi task_action.php và xử lý response chung */
async function _callTaskAction(taskId, action) {
    const fd = new FormData();
    fd.append('action',  action);
    fd.append('task_id', taskId);
    const res  = await fetch('task_action.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);
    return data;
}

/** Retry task failed → pending */
async function retryTask(taskId) {
    const result = await Swal.fire({
        title:             '🔄 Retry Task #' + taskId + '?',
        text:              'Task sẽ được đặt lại về trạng thái "Chờ xử lý". Worker sẽ xử lý trong vòng quét tiếp theo.',
        icon:              'question',
        showCancelButton:  true,
        confirmButtonText: 'Retry',
        cancelButtonText:  'Huỷ bỏ',
        confirmButtonColor:'#f59e0b',
        background:        '#12172b',
        color:             '#e2e8f0',
    });
    if (!result.isConfirmed) return;

    try {
        const data = await _callTaskAction(taskId, 'retry');
        Swal.fire({
            toast:             true,
            position:          'bottom-end',
            icon:              'success',
            title:             data.message,
            showConfirmButton: false,
            timer:             3000,
            timerProgressBar:  true,
            background:        '#12172b',
            color:             '#34d399',
        });
        refreshTaskTable(); // Làm mới bảng
    } catch (err) {
        Swal.fire({ icon:'error', title:'Không thể retry', text: err.message,
            background:'#12172b', color:'#e2e8f0' });
    }
}

/** Huỷ task pending → failed */
async function cancelTask(taskId) {
    const result = await Swal.fire({
        title:             '✕ Huỷ Task #' + taskId + '?',
        text:              'Task sẽ bị đánh dấu "Thất bại" và không được xử lý nữa.',
        icon:              'warning',
        showCancelButton:  true,
        confirmButtonText: 'Xác nhận Huỷ',
        cancelButtonText:  'Quay lại',
        confirmButtonColor:'#ef4444',
        background:        '#12172b',
        color:             '#e2e8f0',
    });
    if (!result.isConfirmed) return;

    try {
        const data = await _callTaskAction(taskId, 'cancel');
        Swal.fire({
            toast:             true,
            position:          'bottom-end',
            icon:              'success',
            title:             data.message,
            showConfirmButton: false,
            timer:             3000,
            timerProgressBar:  true,
            background:        '#12172b',
            color:             '#fcd34d',
        });
        refreshTaskTable();
    } catch (err) {
        Swal.fire({ icon:'error', title:'Không thể huỷ task', text: err.message,
            background:'#12172b', color:'#e2e8f0' });
    }
}

/** Xoá task khỏi DB (và file video nếu có) */
async function deleteTask(taskId) {
    const result = await Swal.fire({
        title:             '🗑 Xoá Task #' + taskId + '?',
        html:              'Task sẽ bị <strong>xoá vĩnh viễn</strong> khỏi hệ thống.<br>File video đính kèm cũng sẽ bị xoá.',
        icon:              'warning',
        showCancelButton:  true,
        confirmButtonText: 'Xoá vĩnh viễn',
        cancelButtonText:  'Không, giữ lại',
        confirmButtonColor:'#ef4444',
        background:        '#12172b',
        color:             '#e2e8f0',
    });
    if (!result.isConfirmed) return;

    try {
        const data = await _callTaskAction(taskId, 'delete');

        // Xoá hàng khỏi bảng ngay lập tức (không cần đợi refresh)
        const row = document.getElementById('taskRow_' + taskId);
        if (row) {
            row.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
            row.style.opacity    = '0';
            row.style.transform  = 'translateX(-12px)';
            setTimeout(() => { row.remove(); refreshTaskTable(); }, 380);
        } else {
            refreshTaskTable();
        }

        Swal.fire({
            toast:             true,
            position:          'bottom-end',
            icon:              'success',
            title:             data.message,
            showConfirmButton: false,
            timer:             3000,
            timerProgressBar:  true,
            background:        '#12172b',
            color:             '#34d399',
        });
    } catch (err) {
        Swal.fire({ icon:'error', title:'Không thể xoá task', text: err.message,
            background:'#12172b', color:'#e2e8f0' });
    }
}
</script>
</body>
</html>
