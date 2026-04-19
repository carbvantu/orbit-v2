#!/usr/bin/env python3
# ============================================================
# ORBIT - Python Worker Script
# File: worker.py
# Mô tả: Quét database, xử lý task pending, điều khiển Selenium
# Chạy: python worker.py
# ============================================================

import json
import os
import time
import traceback
from datetime import datetime

import mysql.connector
import requests                                   # Gửi thông báo Telegram
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service

# ============================================================
# PHẦN 1: CẤU HÌNH
# ============================================================

# --- Thông số kết nối MySQL (XAMPP mặc định) ---
DB_CONFIG = {
    'host':     'localhost',
    'database': 'orbit_db',
    'user':     'root',
    'password': '',          # XAMPP mặc định không có mật khẩu
    'charset':  'utf8mb4',
    'autocommit': True,
}

# --- Đường dẫn Chrome Profile (QUAN TRỌNG: phải dùng profile thật để có session đăng nhập) ---
# Cách lấy đường dẫn: Mở Chrome, vào chrome://version, tìm "Profile Path"
# Thay đổi 2 dòng dưới đây theo máy của bạn:
CHROME_USER_DATA_DIR  = r'C:\Users\YourName\AppData\Local\Google\Chrome\User Data'
CHROME_PROFILE_DIR    = 'Default'   # Hoặc 'Profile 1', 'Profile 2', v.v.

# --- Thời gian chờ giữa các vòng quét (giây) ---
SCAN_INTERVAL = 10

# --- File heartbeat để dashboard PHP đọc trạng thái ---
HEARTBEAT_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'worker_heartbeat.json')

# --- File điều khiển worker từ dashboard (pause/resume) ---
CONTROL_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'worker_control.json')

# ============================================================
# PHẦN 2: HÀM GHI HEARTBEAT
# ============================================================

# Biến toàn cục theo dõi thống kê session
_worker_started_at  = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
_total_processed    = 0
_total_completed    = 0
_total_failed       = 0
_current_task_id    = None


def write_heartbeat(state: str = 'idle', current_task: dict = None):
    """
    Ghi file JSON heartbeat để dashboard PHP đọc trạng thái worker.
    - state        : 'idle' | 'processing' | 'db_error'
    - current_task : dict thông tin task đang xử lý (hoặc None)
    """
    global _total_processed, _total_completed, _total_failed

    data = {
        'started_at':      _worker_started_at,
        'last_beat':       datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'state':           state,
        'scan_interval':   SCAN_INTERVAL,
        'total_processed': _total_processed,
        'total_completed': _total_completed,
        'total_failed':    _total_failed,
        'current_task':    {
            'id':       current_task['id'],
            'platform': current_task['platform'],
        } if current_task else None,
    }

    try:
        # Ghi ra file tạm rồi rename để tránh PHP đọc file đang ghi dở
        tmp = HEARTBEAT_FILE + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        os.replace(tmp, HEARTBEAT_FILE)
    except OSError as e:
        print(f"[{now()}] [WARN] Không ghi được heartbeat: {e}")


# ============================================================
# PHẦN 2B: HÀM ĐỌC LỆNH ĐIỀU KHIỂN TỪ DASHBOARD
# ============================================================

def read_control() -> dict:
    """
    Đọc file worker_control.json do dashboard ghi ra.
    Trả về dict {'paused': bool, 'changed_at': str}.
    Mặc định: {'paused': False} nếu file chưa tồn tại hoặc lỗi đọc.
    """
    try:
        if os.path.exists(CONTROL_FILE):
            with open(CONTROL_FILE, 'r', encoding='utf-8') as f:
                data = json.load(f)
            return data
    except (OSError, json.JSONDecodeError) as e:
        print(f"[{now()}] [WARN] Không đọc được control file: {e}")
    return {'paused': False}


# ============================================================
# PHẦN 2C: TELEGRAM NOTIFICATIONS
# ============================================================

TELEGRAM_API_BASE = 'https://api.telegram.org'

def send_telegram(token: str, chat_id: str, message: str):
    """
    Gửi thông báo đến Telegram qua Bot API.
    Không làm crash worker nếu gửi thất bại (chỉ log cảnh báo).
    """
    if not token or not chat_id:
        return
    try:
        url  = f'{TELEGRAM_API_BASE}/bot{token}/sendMessage'
        resp = requests.post(url, json={
            'chat_id':    chat_id,
            'text':       message,
            'parse_mode': 'HTML',
        }, timeout=10)
        if resp.ok:
            print(f"[{now()}] [📱 TELEGRAM] Đã gửi thông báo.")
        else:
            data = resp.json()
            print(f"[{now()}] [WARN] Telegram từ chối: {data.get('description','unknown')}")
    except requests.exceptions.ConnectionError:
        print(f"[{now()}] [WARN] Telegram: không có kết nối Internet.")
    except Exception as e:
        print(f"[{now()}] [WARN] Telegram lỗi: {e}")


# ============================================================
# PHẦN 2D: HÀM LẤY SETTINGS VÀ ACCOUNT TỪ DATABASE
# ============================================================

def get_settings(conn) -> dict:
    """Lấy cài đặt hệ thống từ bảng settings."""
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT `key`, `value` FROM settings")
        rows = cursor.fetchall()
        cursor.close()
        return {r['key']: r['value'] for r in rows}
    except Exception as e:
        print(f"[{now()}] [WARN] Không đọc được settings: {e}")
        return {}


def get_account(conn, account_id) -> dict | None:
    """Lấy thông tin tài khoản theo id. Trả về None nếu không tìm thấy."""
    if not account_id:
        return None
    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute(
            "SELECT id, platform, account_name, username, profile_path, profile_dir "
            "FROM accounts WHERE id = %s",
            (account_id,)
        )
        row = cursor.fetchone()
        cursor.close()
        return row
    except Exception as e:
        print(f"[{now()}] [WARN] Không đọc được account #{account_id}: {e}")
        return None


# ============================================================
# PHẦN 3: HÀM KẾT NỐI DATABASE
# ============================================================

def get_db_connection():
    """Tạo kết nối mới tới MySQL."""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except mysql.connector.Error as e:
        print(f"[{now()}] [LỖI] Không thể kết nối DB: {e}")
        return None


def now() -> str:
    """Trả về chuỗi thời gian hiện tại để in log."""
    return datetime.now().strftime('%Y-%m-%d %H:%M:%S')


# ============================================================
# PHẦN 3: KHỞI TẠO SELENIUM WEBDRIVER
# ============================================================

def create_driver(account: dict | None = None) -> webdriver.Chrome:
    """
    Khởi tạo Chrome WebDriver với cấu hình chống phát hiện bot.
    - account: dict từ bảng accounts (profile_path, profile_dir)
               Nếu None → dùng CHROME_USER_DATA_DIR / CHROME_PROFILE_DIR mặc định
    """
    chrome_options = Options()

    # ---- Chọn Chrome profile: ưu tiên từ tài khoản, fallback sang cấu hình mặc định ----
    if account and account.get('profile_path'):
        profile_path = account['profile_path']
        profile_dir  = account.get('profile_dir') or 'Default'
        print(f"[{now()}] [CHROME] Dùng profile tài khoản: {account['account_name']} ({profile_path}/{profile_dir})")
    else:
        profile_path = CHROME_USER_DATA_DIR
        profile_dir  = CHROME_PROFILE_DIR
        print(f"[{now()}] [CHROME] Dùng profile mặc định: {profile_path}/{profile_dir}")

    chrome_options.add_argument(f'--user-data-dir={profile_path}')
    chrome_options.add_argument(f'--profile-directory={profile_dir}')

    # ---- Chế độ ẩn (bỏ dấu # để bật headless, thêm # để tắt khi debug) ----
    # chrome_options.add_argument('--headless=new')  # Chrome 112+ dùng "--headless=new"

    # ---- Các tùy chọn ổn định hoá Chrome ----
    chrome_options.add_argument('--no-sandbox')                  # Cần thiết trong môi trường Linux/Docker
    chrome_options.add_argument('--disable-dev-shm-usage')       # Tránh lỗi shared memory trên Linux
    chrome_options.add_argument('--disable-gpu')                  # Tắt GPU (cần khi chạy headless)
    chrome_options.add_argument('--window-size=1366,768')         # Kích thước cửa sổ trình duyệt
    chrome_options.add_argument('--disable-notifications')        # Chặn popup thông báo
    chrome_options.add_argument('--mute-audio')                   # Tắt âm thanh

    # ---- Ẩn dấu hiệu Selenium để tránh bị checkpoint ----
    chrome_options.add_experimental_option('excludeSwitches', ['enable-automation'])
    chrome_options.add_experimental_option('useAutomationExtension', False)
    chrome_options.add_argument('--disable-blink-features=AutomationControlled')

    # ---- Khởi tạo driver ----
    # Nếu chromedriver không có trong PATH, chỉ định đường dẫn tại đây:
    # service = Service(executable_path=r'C:\path\to\chromedriver.exe')
    # driver = webdriver.Chrome(service=service, options=chrome_options)
    driver = webdriver.Chrome(options=chrome_options)

    # Ẩn thêm thuộc tính navigator.webdriver qua JavaScript
    driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")

    return driver


# ============================================================
# PHẦN 4: HANDLER CHO TỪNG NỀN TẢNG
# ============================================================

def post_to_facebook(driver: webdriver.Chrome, task: dict):
    """
    Đăng video lên Facebook.
    TODO: Điền code Selenium thao tác DOM vào đây.
    """
    print(f"[{now()}] [FACEBOOK] Đang mở Facebook...")
    # driver.get('https://www.facebook.com')
    # time.sleep(3)

    # --- Điền code upload video vào đây ---
    print(f"[{now()}] [FACEBOOK] Đang xử lý đăng video: {task['video_path']}")
    print(f"[{now()}] [FACEBOOK] Caption: {task['caption'][:50]}...")
    time.sleep(5)   # Giả lập thời gian xử lý


def post_to_tiktok(driver: webdriver.Chrome, task: dict):
    """
    Đăng video lên TikTok.
    TODO: Điền code Selenium thao tác DOM vào đây.
    """
    print(f"[{now()}] [TIKTOK] Đang mở TikTok Studio...")
    # driver.get('https://www.tiktok.com/creator-center/upload')
    # time.sleep(3)

    # --- Điền code upload video vào đây ---
    print(f"[{now()}] [TIKTOK] Đang xử lý đăng video: {task['video_path']}")
    print(f"[{now()}] [TIKTOK] Caption: {task['caption'][:50]}...")
    time.sleep(5)


def post_to_youtube(driver: webdriver.Chrome, task: dict):
    """
    Đăng video lên YouTube Studio.
    TODO: Điền code Selenium thao tác DOM vào đây.
    """
    print(f"[{now()}] [YOUTUBE] Đang mở YouTube Studio...")
    # driver.get('https://studio.youtube.com')
    # time.sleep(3)

    # --- Điền code upload video vào đây ---
    print(f"[{now()}] [YOUTUBE] Đang xử lý đăng video: {task['video_path']}")
    print(f"[{now()}] [YOUTUBE] Caption: {task['caption'][:50]}...")
    time.sleep(5)


# ============================================================
# PHẦN 5: HÀM XỬ LÝ MỘT TASK
# ============================================================

def process_task(conn, task: dict):
    """
    Xử lý một task:
    1. Cập nhật status -> 'processing'
    2. Load settings (Telegram) + thông tin tài khoản
    3. Mở Selenium với Chrome profile của tài khoản
    4. Rẽ nhánh theo platform
    5. Cập nhật status -> 'completed' / 'failed'
    6. Gửi thông báo Telegram
    """
    global _total_processed, _total_completed, _total_failed
    task_id    = task['id']
    platform   = task['platform']
    account_id = task.get('account_id')

    print(f"\n[{now()}] {'='*50}")
    print(f"[{now()}] Bắt đầu xử lý Task #{task_id} | Platform: {platform.upper()}")
    print(f"[{now()}] File: {task['video_path']}")
    print(f"[{now()}] Lịch đăng: {task['scheduled_at']}")

    # --- Lấy cài đặt Telegram + thông tin tài khoản từ DB ---
    settings = get_settings(conn)
    account  = get_account(conn, account_id)

    tg_enabled  = settings.get('telegram_enabled', '0') == '1'
    tg_token    = settings.get('telegram_bot_token', '')
    tg_chat_id  = settings.get('telegram_chat_id', '')
    tg_ok_flag  = settings.get('telegram_notify_success', '1') == '1'
    tg_err_flag = settings.get('telegram_notify_failed',  '1') == '1'

    acc_name = account['account_name'] if account else 'Mặc định'
    print(f"[{now()}] Tài khoản: {acc_name}")

    # --- Cập nhật status -> 'processing' + ghi heartbeat ---
    cursor = conn.cursor()
    cursor.execute(
        "UPDATE video_tasks SET status = 'processing' WHERE id = %s",
        (task_id,)
    )
    cursor.close()
    _total_processed += 1
    write_heartbeat(state='processing', current_task=task)

    driver     = None
    error_msg  = ''
    success    = False
    try:
        # --- Khởi tạo Selenium Chrome (dùng profile tài khoản nếu có) ---
        print(f"[{now()}] Đang khởi động Chrome WebDriver...")
        driver = create_driver(account=account)

        # --- Rẽ nhánh theo nền tảng ---
        if platform == 'facebook':
            post_to_facebook(driver, task)
        elif platform == 'tiktok':
            post_to_tiktok(driver, task)
        elif platform == 'youtube':
            post_to_youtube(driver, task)
        else:
            raise ValueError(f"Platform không được hỗ trợ: {platform}")

        # --- Thành công ---
        cursor = conn.cursor()
        cursor.execute(
            "UPDATE video_tasks SET status = 'completed' WHERE id = %s",
            (task_id,)
        )
        cursor.close()
        _total_completed += 1
        success = True
        print(f"[{now()}] [✓] Task #{task_id} hoàn thành thành công!")

        # Gửi Telegram thành công
        if tg_enabled and tg_ok_flag:
            send_telegram(tg_token, tg_chat_id,
                f"✅ <b>ORBIT - Đăng thành công!</b>\n"
                f"📋 Task: <code>#{task_id}</code>\n"
                f"📱 Platform: <b>{platform.upper()}</b>\n"
                f"👤 Tài khoản: {acc_name}\n"
                f"🕐 {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}"
            )

    except Exception as e:
        # --- Thất bại ---
        error_msg = str(e)
        print(f"[{now()}] [✗] Task #{task_id} thất bại: {e}")
        traceback.print_exc()

        cursor = conn.cursor()
        cursor.execute(
            "UPDATE video_tasks SET status = 'failed' WHERE id = %s",
            (task_id,)
        )
        cursor.close()
        _total_failed += 1

        # Gửi Telegram thất bại
        if tg_enabled and tg_err_flag:
            # Rút gọn error_msg nếu quá dài
            short_err = error_msg[:200] + ('…' if len(error_msg) > 200 else '')
            send_telegram(tg_token, tg_chat_id,
                f"❌ <b>ORBIT - Task thất bại!</b>\n"
                f"📋 Task: <code>#{task_id}</code>\n"
                f"📱 Platform: <b>{platform.upper()}</b>\n"
                f"👤 Tài khoản: {acc_name}\n"
                f"⚠️ Lỗi: <code>{short_err}</code>\n"
                f"🕐 {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}"
            )

    finally:
        # --- Luôn đóng driver dù thành công hay thất bại ---
        if driver:
            print(f"[{now()}] Đóng Chrome WebDriver...")
            driver.quit()


# ============================================================
# PHẦN 6: VÒNG LẶP CHÍNH - QUÉT VÀ XỬ LÝ TASK
# ============================================================

def run_worker():
    """Vòng lặp chính: quét DB mỗi SCAN_INTERVAL giây."""
    print(f"[{now()}] ========================================")
    print(f"[{now()}]  ORBIT Worker đã khởi động")
    print(f"[{now()}]  Quét task mỗi {SCAN_INTERVAL} giây")
    print(f"[{now()}]  Heartbeat: {HEARTBEAT_FILE}")
    print(f"[{now()}] ========================================\n")

    # Ghi heartbeat khởi động ngay lập tức
    write_heartbeat(state='idle')

    while True:
        conn = None
        try:
            # ============================================================
            # BƯỚC 0: Kiểm tra lệnh điều khiển từ dashboard
            # ============================================================
            ctrl = read_control()
            if ctrl.get('paused', False):
                # --- Worker đang bị tạm dừng từ dashboard ---
                changed_at = ctrl.get('changed_at', 'N/A')
                print(f"[{now()}] [⏸ PAUSED] Worker đang tạm dừng (lệnh lúc {changed_at}). Chờ {SCAN_INTERVAL}s...")
                write_heartbeat(state='paused')
                time.sleep(SCAN_INTERVAL)
                continue  # Quay lại đầu vòng lặp, không xử lý task

            # --- Worker đang chạy bình thường ---
            conn = get_db_connection()
            if conn is None:
                print(f"[{now()}] Không thể kết nối DB, thử lại sau {SCAN_INTERVAL}s...")
                write_heartbeat(state='db_error')
                time.sleep(SCAN_INTERVAL)
                continue

            # --- Truy vấn task pending đã đến giờ ---
            cursor = conn.cursor(dictionary=True)
            cursor.execute("""
                SELECT id, platform, account_id, video_path, caption, scheduled_at
                FROM   video_tasks
                WHERE  status = 'pending'
                  AND  scheduled_at <= NOW()
                ORDER  BY scheduled_at ASC
                LIMIT  1
            """)
            task = cursor.fetchone()
            cursor.close()

            if task:
                process_task(conn, task)
                write_heartbeat(state='idle')
            else:
                print(f"[{now()}] Không có task nào cần xử lý. Chờ {SCAN_INTERVAL}s...")
                write_heartbeat(state='idle')

        except mysql.connector.Error as e:
            print(f"[{now()}] [LỖI DB] {e}")
            write_heartbeat(state='db_error')

        except Exception as e:
            print(f"[{now()}] [LỖI KHÔNG XÁC ĐỊNH] {e}")
            traceback.print_exc()
            write_heartbeat(state='idle')

        finally:
            if conn and conn.is_connected():
                conn.close()

        # --- Tạm dừng 10 giây trước khi lặp lại ---
        time.sleep(SCAN_INTERVAL)


# ============================================================
# ĐIỂM KHỞI ĐẦU
# ============================================================

if __name__ == '__main__':
    run_worker()
