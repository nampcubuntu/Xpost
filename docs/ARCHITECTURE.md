# Kiến trúc Xpost

Tài liệu này giải thích **tại sao** mọi thứ được dựng như vậy. Phần "cái gì" đã
nằm trong code; ở đây là những đánh đổi không đọc ra được từ code.

---

## Bài toán

Đăng **nội dung khác nhau** từ **1.000–10.000 tài khoản X**, ngay hoặc theo lịch.

Ba ràng buộc định hình toàn bộ thiết kế:

1. **X API có hạn mức theo app, không theo tài khoản.** Một developer app tier
   Basic chỉ cho vài trăm bài mỗi ngày. 10k tài khoản trên một app là bất khả thi.
2. **Đăng đồng loạt là tín hiệu spam.** 10k bài giống hệt nhau, cùng một giây,
   là cách nhanh nhất để mất cả pool tài khoản.
3. **Fan-out 10k hàng không thể nằm trong một HTTP request.** Cũng không thể là
   một job duy nhất có thể chết giữa chừng và không ai biết đã làm tới đâu.

---

## Mô hình dữ liệu

```
teams ──┬── x_apps ────────┐         pool credential
        ├── proxies ───────┤
        ├── x_accounts ────┘         1k-10k hàng, token mã hoá
        │        └── account_groups (n-n)
        │
        ├── campaigns
        └── posts ──┬── post_variants
                    ├── post_media (n-n)
                    └── post_targets            ← bảng lớn duy nhất
```

### post_targets là tâm điểm

Mỗi cặp (bài, tài khoản) là một hàng. Một chiến dịch 10k tài khoản = 10k hàng.

Điểm quan trọng: **mọi thứ cần để đăng đều được đóng băng vào hàng lúc fan-out** —
văn bản đã render, biến thể đã chọn, thời điểm chính xác, danh sách media.

Ba lý do:

- Job đăng không phải đánh giá template lại → nhanh và không phụ thuộc trạng thái.
- Sửa bài sau khi đã đăng một phần **không** viết lại lịch sử. Cái đã gửi là cái
  đang nằm trong `rendered_body`.
- Một target bị kẹt có thể mở ra xem chính xác nó định nói gì.

Index `(status, scheduled_at)` đặt `status` lên đầu là có chủ đích: dispatcher
luôn lọc `status = 'pending'` trước, nên đó phải là tiền tố của index.

---

## Vòng đời một bài đăng

```
draft ──publish()──▶ scheduled ──FanOutPost──▶ post_targets (pending)
                                                     │
                                          xpost:dispatch mỗi phút
                                          SELECT … FOR UPDATE SKIP LOCKED
                                                     │
                                                  queued
                                                     │
                                          PublishPostTarget (Horizon)
                                                     │
                                        ┌────────────┼────────────┐
                                        ▼            ▼            ▼
                                   published     pending      failed
                                              (defer + backoff)
```

### Vì sao claim và dispatch tách rời

Dispatcher đánh dấu hàng thành `queued` và **commit trước**, rồi mới đẩy job.

Nếu process chết giữa hai bước, ta có một hàng mồ côi ở trạng thái `queued` —
`xpost:reap` sẽ tìm thấy và trả lại. Nếu làm ngược lại (đẩy job trước, đánh dấu
sau), cùng một target có thể bị đăng hai lần.

**Đăng thiếu thì sửa được. Đăng trùng thì không.**

### Vì sao `SKIP LOCKED`

Nhiều instance dispatcher chạy song song mà không tranh nhau. Mỗi instance khoá
một lát cắt riêng; instance khác bỏ qua hàng đã bị khoá thay vì chờ.

Đây cũng là lý do MySQL cần `READ-COMMITTED`.

### Vì sao retry do publisher quyết định, không phải hàng đợi

`PublishPostTarget` đặt `$tries = 1`. Toàn bộ logic retry nằm trong
`PostPublisher`, vì chỉ nó biết ta đang chờ **cái gì**:

| Tình huống | Chờ |
|---|---|
| 500 từ X | 30 giây, exponential backoff |
| 429 rate limit | đúng đến `x-rate-limit-reset` |
| Hết hạn mức ngày của tài khoản | tới nửa đêm |
| Pool app hết chỗ | 15 phút |
| Nội dung trùng (code 187) | không bao giờ — hỏng vĩnh viễn |

Retry của queue sẽ đối xử với cả năm trường hợp như nhau, và làm hỏng đếm
`attempts`.

---

## Pool X App

`XAppSelector` gán tài khoản vào app **cố định** (sticky), không xoay vòng:

- `media_id` của X thuộc về app đã upload. Đổi app giữa chiến dịch là mất cache
  media, biến 1 lần upload thành 10.000 lần.
- Một số trạng thái rate limit cũng gắn theo app.

Chỉ khi app của tài khoản đang bị chặn, publisher mới mượn tạm một app anh em
còn hạn mức — để một app cạn kiệt không làm đứng toàn bộ tài khoản gắn với nó.

Gán mới chọn app có tỉ lệ `assigned_count / max_accounts` thấp nhất, trong một
transaction có `lockForUpdate` để hai request đồng thời không cùng lấp một chỗ.

---

## Rate limiting

Hai tầng, đều trên Redis, đều bằng Lua script atomic:

```
account:{id}:day    → chống tài khoản trông như bot   (mặc định 50/ngày)
app:{id}:day        → chống vượt hạn mức tier          (theo hợp đồng)
app:{id}:burst      → làm mượt trong 15 phút           (mặc định 300)
```

Fixed window chứ không phải sliding: X công bố hạn mức của họ dưới dạng fixed
window, và một `INCR` + một `EXPIRE` là đủ để quyết định — không cần sorted set.

Khi X trả về header nói cửa sổ đã cạn, `syncFromResponse()` đặt cờ chặn app cho
đến đúng thời điểm reset. **Tin X hơn ước lượng của mình.**

Nếu slot tài khoản đã trừ nhưng app hết hạn mức, slot được **hoàn lại** — nếu
không, một app cạn kiệt sẽ âm thầm đốt hạn mức của mọi tài khoản trên nó.

---

## Nội dung khác nhau

Năm chiến lược, chọn ở Composer:

| Chiến lược | Dùng khi |
|---|---|
| `same` | Thông báo chính thức, cần đồng nhất tuyệt đối |
| `spintax` | Mặc định nên dùng: `{Chào|Xin chào} bạn` → mỗi tài khoản một bản |
| `round_robin` | Có sẵn N bản viết tay, muốn chia đều |
| `random` | N bản có trọng số khác nhau |
| `per_account` | Nội dung 1:1 theo từng tài khoản, import từ CSV |

Spintax được seed bằng `crc32(post_id . account_id)`, nên chạy lại fan-out cho
ra **đúng** kết quả cũ thay vì âm thầm sinh phiên bản thứ hai.

`FitsTweetLength` kiểm tra **hoán vị dài nhất**, không phải hoán vị đầu tiên —
nếu không, một template chỉ tràn ở một nhánh sẽ qua được validation rồi hỏng với
đúng những tài khoản rút trúng nhánh đó.

---

## Đếm ký tự

`TweetTextWeigher` cài quy tắc weighted length (twitter-text v3):

- Latin thường: 1
- CJK, emoji: 2
- **URL bất kỳ: cố định 23**, dài ngắn không quan trọng

Đây không phải chi tiết vụn vặt. Đếm bằng `strlen` sẽ cho phép một bài 250 ký tự
kèm URL 200 ký tự đi qua, rồi X trả 403 cho cả 10.000 tài khoản.

Bộ đếm trong UI gọi thẳng `/composer/measure` — server là nguồn sự thật duy nhất,
frontend không tự đoán.

---

## Multi-tenancy

`TeamScope` là global scope trên mọi model thuộc tenant. Nó đọc team hiện tại từ
`TeamContext`, vốn được xây trên `Illuminate\Support\Facades\Context`.

Chọn `Context` thay vì biến static vì hai lý do:

1. Giá trị **tự động đi theo job** khi dispatch — worker không cần truyền tay.
2. Nó **tự đóng dấu lên mọi dòng log**, nên một lần đăng lỗi truy ngược được về
   team mà không phải nhồi `team_id` vào từng constructor.

Job vẫn gọi `TeamContext::runAs()` một cách tường minh, vì rõ ràng hơn là dựa vào
việc scope đã được kế thừa.

Lệnh bảo trì chạy xuyên team dùng `withoutGlobalScopes()` — cố ý dài dòng, để
việc thoát khỏi tenant luôn hiện rõ khi đọc code.

---

## Analytics

MySQL trả lời "hiện tại đang thế nào". ClickHouse trả lời "theo thời gian thì sao".

Sự kiện đăng được đệm vào một Redis list rồi flush theo lô mỗi phút. INSERT từng
dòng sẽ tạo một part ClickHouse cho mỗi dòng, và merge không bao giờ đuổi kịp ở
mức 10k lượt đăng một lần chạy.

`xpost_daily_stats` là bảng aggregate có materialized view nạp sẵn. Dashboard đọc
bảng này chứ không quét sự kiện thô — khác biệt giữa việc quét một tháng dữ liệu
và đọc vài trăm dòng.

`ClickHouseConnection` tự ngắt mạch khi lỗi: analytics tối đi thì được, analytics
kéo cả hệ thống đăng bài chết theo thì không.

---

## Giới hạn quy mô

Ở mức 10k tài khoản, các con số:

| Thứ | Ước lượng |
|---|---|
| `post_targets` mỗi chiến dịch | 10.000 hàng |
| 10 chiến dịch/ngày | 100k hàng/ngày, ~36M/năm |
| Thời gian fan-out (chèn lô 500) | ~10–20 giây |
| Thông lượng đăng (40 worker) | ~2.000–4.000 bài/giờ, giới hạn bởi API chứ không phải CPU |

MySQL xử lý thoải mái với index hiện tại. Nếu vượt xa mức này, thứ cần làm
**đầu tiên** là partition `post_targets` theo tháng và archive các partition cũ —
không phải sharding, không phải đổi database.

Nút thắt thực tế gần như chắc chắn là **hạn mức X API**, không phải hạ tầng.
