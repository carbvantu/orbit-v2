# ORBIT — Hướng dẫn cài đặt & chạy

> Công cụ lên lịch & tự động đăng video lên Facebook, TikTok, YouTube.

---

## Yêu cầu hệ thống

| Thành phần | Phiên bản tối thiểu |
|---|---|
| XAMPP (Apache + PHP + MySQL) | PHP 7.4+ |
| Python | 3.9+ |
| Google Chrome | Bất kỳ (mới nhất khuyến nghị) |
| ChromeDriver | Khớp với phiên bản Chrome |

---

## Bước 1 — Cài XAMPP & tạo database

1. Tải và cài [XAMPP](https://www.apachefriends.org/).
2. Mở **XAMPP Control Panel** → Start **Apache** và **MySQL**.
3. Truy cập **phpMyAdmin**: `http://localhost/phpmyadmin`
4. Tạo database mới tên `orbit`.
5. Chọn database `orbit` → tab **Import** → chọn file `database.sql` → **Go**.

---

## Bước 2 — Cấu hình kết nối database

Mở file `config.php` và sửa thông tin nếu cần:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'orbit');
define('DB_USER', 'root');      // username MySQL của bạn
define('DB_PASS', '');          // password MySQL (thường để trống với XAMPP)
```

---

## Bước 3 — Copy vào htdocs

Copy **toàn bộ thư mục** `orbit-php/` vào:

```
C:\xampp\htdocs\orbit\
```

Truy cập dashboard: `http://localhost/orbit/`

---

## Bước 4 — Cài Python & thư viện

1. Tải Python: [python.org](https://www.python.org/downloads/) (chọn "Add to PATH" khi cài).
2. Mở **Command Prompt** và chạy:

```bash
pip install selenium mysql-connector-python requests
```

---

## Bước 5 — Cài ChromeDriver

1. Kiểm tra phiên bản Chrome: mở Chrome → `chrome://settings/help`.
2. Tải ChromeDriver tương ứng: [chromedriver.chromium.org](https://chromedriver.chromium.org/downloads)
   hoặc [googlechromelabs.github.io/chrome-for-testing](https://googlechromelabs.github.io/chrome-for-testing/)
3. Giải nén `chromedriver.exe` vào thư mục `orbit-php/` hoặc thêm vào biến môi trường PATH.

---

## Bước 6 — Cấu hình worker.py

Mở `worker.py`, tìm phần cấu hình đầu file và sửa:

```python
DB_HOST     = 'localhost'
DB_NAME     = 'orbit'
DB_USER     = 'root'
DB_PASSWORD = ''

# Đường dẫn ChromeDriver (nếu không có trong PATH)
CHROMEDRIVER_PATH = 'chromedriver.exe'
```

---

## Bước 7 — Chạy Worker

Mở **Command Prompt** trong thư mục `orbit-php/`:

```bash
cd C:\xampp\htdocs\orbit
python worker.py
```

Để worker chạy ngầm (không tắt cửa sổ):

```bash
start /B python worker.py > worker_log.txt 2>&1
```

---

## Bước 8 — Thiết lập Chrome Profile (Đa tài khoản)

Để worker đăng nhập sẵn vào tài khoản mạng xã hội, bạn cần chuẩn bị Chrome Profile:

1. Mở Chrome với profile riêng:
   ```
   "C:\Program Files\Google\Chrome\Application\chrome.exe" --user-data-dir="C:\ChromeProfiles\TK1" --profile-directory="Default"
   ```
2. Đăng nhập vào Facebook/TikTok/YouTube trong cửa sổ Chrome này.
3. Đóng Chrome.
4. Trong ORBIT dashboard → **Quản lý Tài khoản** → thêm tài khoản với:
   - **Chrome Profile Path**: `C:\ChromeProfiles\TK1`
   - **Profile Directory**: `Default`

---

## Bước 9 — Cấu hình Telegram (tuỳ chọn)

1. Nhắn `/newbot` cho [@BotFather](https://t.me/BotFather) → lấy **Bot Token**.
2. Lấy **Chat ID** của bạn: nhắn [@userinfobot](https://t.me/userinfobot).
3. Trong ORBIT dashboard → **⚙️ Cài đặt** → nhập Bot Token + Chat ID → **Lưu**.
4. Nhấn **Gửi tin test** để kiểm tra.

---

## Cấu trúc thư mục

```
orbit-php/
├── index.php          # Dashboard chính
├── api.php            # API tạo task (upload video + lên lịch)
├── get_tasks.php      # API lấy danh sách task (AJAX polling)
├── accounts.php       # API quản lý tài khoản
├── settings.php       # API cài đặt Telegram
├── config.php         # Cấu hình kết nối DB
├── worker.py          # Worker Python tự động đăng video
├── database.sql       # Schema database
├── uploads/           # Thư mục chứa video upload (tự tạo)
├── worker_heartbeat.json   # Trạng thái worker (tự tạo)
└── worker_control.json     # Điều khiển pause/resume (tự tạo)
```

---

## Sử dụng hàng ngày

1. Mở XAMPP → Start Apache + MySQL.
2. Chạy `python worker.py` trong CMD.
3. Truy cập `http://localhost/orbit/`.
4. Chọn Platform → Tài khoản → Upload video → Đặt lịch → **Lên lịch**.
5. Worker sẽ tự động đăng video đúng giờ.

---

## Xử lý sự cố thường gặp

| Lỗi | Giải pháp |
|---|---|
| Worker OFFLINE trên dashboard | Kiểm tra CMD đang chạy `worker.py`, không bị lỗi |
| `selenium.common.exceptions.WebDriverException` | ChromeDriver không khớp phiên bản Chrome, tải lại |
| Đăng nhập thất bại | Profile Chrome chưa đăng nhập sẵn hoặc sai đường dẫn profile |
| Upload chậm | Video quá lớn, kiểm tra `upload_max_filesize` trong `php.ini` |
| Telegram không nhận được tin | Kiểm tra Bot Token + Chat ID, thử nút "Gửi tin test" |

### Tăng giới hạn upload (php.ini)

Tìm file `C:\xampp\php\php.ini`, sửa:

```ini
upload_max_filesize = 2G
post_max_size       = 2G
max_execution_time  = 600
memory_limit        = 512M
```

Restart Apache sau khi sửa.

---

*ORBIT — Tự động hoá đăng video đa nền tảng* 🚀
