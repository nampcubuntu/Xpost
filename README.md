# Xpost

Đăng và lên lịch bài lên X (Twitter) từ **1.000–10.000 tài khoản**, với giao diện
Composer dựng theo X Ads Manager.

**Stack:** Laravel 13 · PHP 8.5 · MySQL 8.4 · Redis 8.10 · Vue 3 (SPA) · Tailwind 4 · ClickHouse (tuỳ chọn)

---

## Chạy nhanh

```bash
# 1. Hạ tầng
cp .env.example .env
docker compose up -d                 # MySQL 8.4, Redis 8.10, ClickHouse, Mailpit

# 2. Ứng dụng
composer install
php artisan key:generate
php artisan migrate --seed           # ACCOUNTS=5000 php artisan db:seed để tạo fixture lớn
php artisan xpost:clickhouse-migrate # chỉ khi CLICKHOUSE_ENABLED=true

# 3. Frontend
npm install && npm run build         # hoặc `npm run dev` khi đang code

# 4. Chạy
php artisan serve                    # http://localhost:8000
php artisan horizon                  # worker
php artisan schedule:work            # dispatcher + token refresh + metrics
```

Đăng nhập demo: `admin@xpost.test` / `password`

### Trên Windows

Kho này đi kèm PHP 8.5.9 portable ở `.tools/php85/php.exe` (PHP hệ thống là 8.2,
không chạy được Laravel 13). Có sẵn wrapper để khỏi gõ đường dẫn dài:

```cmd
dev.bat              :: mở 4 cửa sổ: web + worker + scheduler + vite
art.bat migrate      :: = php artisan migrate
cmp.bat require ...  :: = composer require
test.bat             :: = phpunit
```

`dev.bat` là cách nhanh nhất để có một hệ thống chạy đầy đủ. **Cửa sổ scheduler
là bắt buộc** — nó chạy `xpost:dispatch` mỗi phút; thiếu nó thì bài đăng nằm mãi
ở trạng thái `pending`.

---

## Nó hoạt động thế nào

```
Composer (Vue)
      │  POST /api/v1/posts
      ▼
   posts ─── FanOutPost (queue: fanout)
      │            │  1 hàng cho mỗi (bài × tài khoản), chèn theo lô 500
      │            ▼
      │      post_targets ◄── xpost:dispatch (mỗi phút)
      │                          │  SELECT … FOR UPDATE SKIP LOCKED
      │                          ▼
      │                   PublishPostTarget (queue: publish)
      │                          │
      │                          ├─ XAppSelector    → chọn app còn hạn mức
      │                          ├─ XRateLimiter    → token bucket Redis
      │                          ├─ XMediaUploader  → upload 1 lần / app
      │                          └─ XApiClient      → POST /2/tweets
      │                                 │
      └────────── ClickHouse ◄── MetricsRecorder (đệm Redis, flush theo lô)
```

### Vài quyết định đáng chú ý

| Vấn đề | Cách xử lý |
|---|---|
| 10k bài đăng cùng một giây trông như bot | `SchedulePlanner` có 5 chế độ: đăng ngay, đúng giờ, rải đều, ngẫu nhiên (jitter), theo múi giờ từng tài khoản |
| Nội dung giống hệt nhau trên mọi tài khoản | Spintax `{a\|b\|c}` + biến thể luân phiên/ngẫu nhiên/1:1, render và **đóng băng** ngay lúc fan-out |
| Hạn mức POST của một X App là nút thắt | Pool nhiều app, `XAppSelector` gán tài khoản vào app ít tải nhất và tự né app đang bị chặn |
| Worker chết giữa chừng → bài kẹt mãi | Claim có lease; `xpost:reap` trả lại việc bị bỏ rơi sau 15 phút |
| Đăng trùng khi hai worker cùng chạy | Claim trong transaction + `WithoutOverlapping` theo target |
| Token OAuth 2.0 hết hạn giữa chiến dịch | `xpost:refresh-tokens` chạy trước 30 phút; refresh có Redis lock chống đua |
| Đếm 280 ký tự sai với tiếng Việt/URL | `TweetTextWeigher` cài đúng quy tắc weighted length của X (URL = 23, CJK = 2) |
| Analytics chết kéo theo cả hệ thống | `ClickHouseConnection` tự ngắt mạch, mọi truy vấn fallback về MySQL |

---

## Lệnh Artisan

| Lệnh | Việc |
|---|---|
| `xpost:dispatch` | Nhận bài đến hạn và đẩy vào hàng đợi (mỗi phút) |
| `xpost:reap` | Trả lại các target bị worker bỏ rơi (mỗi 5 phút) |
| `xpost:refresh-tokens` | Làm mới token OAuth 2.0 sắp hết hạn (mỗi 10 phút) |
| `xpost:sync-metrics` | Kéo số liệu tương tác về (mỗi giờ) |
| `xpost:flush-metrics` | Đẩy buffer Redis sang ClickHouse (mỗi phút) |
| `xpost:clickhouse-migrate` | Tạo bảng + materialized view phân tích |
| `xpost:import-accounts file.csv --team=1` | Import hàng loạt token OAuth 1.0a |

CSV import cần tối thiểu: `username,access_token,token_secret`
(tuỳ chọn: `x_user_id,app_name,timezone`).

---

## API

Base: `/api/v1` — Sanctum cookie cho SPA, bearer token cho automation.

```
POST   /posts                     tạo bài
POST   /posts/{id}/publish        lên lịch + fan-out
GET    /posts/{id}/targets        nhật ký từng tài khoản
POST   /posts/{id}/retry-failed   chạy lại phần lỗi
POST   /composer/measure          đếm ký tự theo chuẩn X
POST   /composer/estimate         số tài khoản + thời gian rải
GET    /accounts                  danh sách tài khoản (lọc, phân trang)
POST   /accounts/bulk             thao tác hàng loạt tới 10k tài khoản
GET    /analytics/overview        dashboard
```

Chi tiết: `php artisan route:list --path=api`

---

## Kiểm thử

```bash
php artisan test          # 62 test
vendor/bin/pint           # format
```

Đáng chú ý: `XOAuth1SignerTest` đối chiếu với **chữ ký mẫu X công bố trong tài liệu**
— nếu test này đỏ thì mọi tài khoản OAuth 1.0a sẽ không đăng được.

---

## Tài liệu thêm

- [docs/DEPLOY.md](docs/DEPLOY.md) — triển khai lên Ubuntu 26.04
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — schema, hàng đợi, giới hạn quy mô

---

## Trước khi chạy thật

X API là dịch vụ trả phí và có hạn mức thật. Vài điểm cần xác nhận với hạ tầng
của bạn, không phải giả định của code:

1. **Hạn mức mỗi tier** trong `XAppTier` là số dự phòng để tính toán. Đối chiếu
   với hợp đồng thực tế rồi chỉnh `daily_post_cap` / `monthly_post_cap` trên từng app.
2. **Điều khoản dịch vụ của X** giới hạn việc đăng nội dung trùng lặp và vận hành
   nhiều tài khoản. Mặc định `XPOST_ACCOUNT_DAILY_CAP=50` là con số thận trọng;
   nâng lên là quyết định của bạn và đi kèm rủi ro khoá tài khoản.
3. **Mỗi tài khoản phải tự uỷ quyền** qua OAuth, hoặc bạn phải sở hữu token hợp lệ.
   Xpost không và không thể lấy quyền truy cập mà chủ tài khoản chưa cấp.
