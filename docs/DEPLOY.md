# Triển khai lên Ubuntu 26.04

Hướng dẫn cho một node duy nhất chạy web + worker. Phần cuối nói về cách tách ra
nhiều node khi cần.

## Đường tắt

Hai script gói lại toàn bộ phần dưới. Đọc mục 0 trước — repo private nên bước
clone sẽ hỏng nếu chưa có deploy key.

```bash
# Lần đầu, trên server trắng
sudo DOMAIN=xposter.zenomedia.co REPO=git@github.com:nampcubuntu/Xpost.git \
     bash bin/server-bootstrap.sh
sudo -u www-data bash /var/www/xpost/bin/deploy.sh --first-run

# Mỗi lần deploy sau đó
sudo -u www-data bash /var/www/xpost/bin/deploy.sh
```

Phần còn lại của tài liệu giải thích từng bước script làm gì, và là thứ cần đọc
khi có gì đó hỏng.

---

## 0. Deploy key cho repo private

Server phải tự clone được, mà không được cầm khoá cá nhân của bạn. Deploy key là
khoá chỉ đọc, chỉ dùng cho đúng một repo:

```bash
# Trên server
sudo -u www-data ssh-keygen -t ed25519 -f /var/www/.ssh/xpost_deploy -N "" \
     -C "xpost-deploy@$(hostname)"
sudo -u www-data cat /var/www/.ssh/xpost_deploy.pub
```

Dán nội dung `.pub` vào **GitHub → repo Xpost → Settings → Deploy keys → Add
deploy key**. Không tích "Allow write access": server chỉ cần đọc.

Rồi trỏ SSH tới khoá đó:

```bash
sudo -u www-data tee /var/www/.ssh/config <<'EOF'
Host github.com
    IdentityFile /var/www/.ssh/xpost_deploy
    IdentitiesOnly yes
EOF
sudo -u www-data chmod 600 /var/www/.ssh/config
sudo -u www-data ssh -T git@github.com   # phải chào đúng tên repo
```

Deploy key hơn Personal Access Token ở chỗ phạm vi hẹp hơn hẳn: mất khoá thì
người lấy được chỉ đọc được đúng repo này, không đụng tới tài khoản GitHub.

---

## 1. Gói hệ thống

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

sudo apt install -y \
  php8.5-cli php8.5-fpm php8.5-mysql php8.5-redis php8.5-mbstring \
  php8.5-xml php8.5-curl php8.5-zip php8.5-gd php8.5-bcmath php8.5-intl \
  nginx mysql-server redis-server supervisor git unzip
```

`php8.5-redis` (phpredis) là extension C — nhanh hơn hẳn predis dưới tải hàng đợi.
Sau khi cài, đổi `REDIS_CLIENT=phpredis` trong `.env`.

```bash
# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 22 cho bước build frontend
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

---

## 2. Redis 8.10

Ubuntu 26.04 đóng gói Redis 8.x. Kiểm tra và ghim cấu hình:

```bash
redis-server --version   # cần >= 8.10
sudo nano /etc/redis/redis.conf
```

```conf
appendonly yes
appendfsync everysec
maxmemory 2gb
# noeviction là bắt buộc: Redis giữ hàng đợi và khoá phân tán.
# Nếu cho phép evict, một job bị xoá âm thầm = một bài không bao giờ đăng.
maxmemory-policy noeviction
requirepass <mật-khẩu-mạnh>
```

```bash
sudo systemctl restart redis-server
```

---

## 3. MySQL 8.4

```bash
sudo mysql -e "CREATE DATABASE xpost CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'xpost'@'localhost' IDENTIFIED BY '<mật-khẩu>';"
sudo mysql -e "GRANT ALL ON xpost.* TO 'xpost'@'localhost'; FLUSH PRIVILEGES;"
```

Thêm vào `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
[mysqld]
innodb_buffer_pool_size = 2G
innodb_flush_log_at_trx_commit = 2
max_connections = 500
# Dispatcher dựa vào SKIP LOCKED; giữ mức cách ly mặc định.
transaction_isolation = READ-COMMITTED
```

`max_connections` cần đủ cho tổng số worker Horizon. Với cấu hình
`publish` tối đa 40 process, cộng fanout/tokens/metrics và PHP-FPM,
500 là mức an toàn.

---

## 4. Mã nguồn

```bash
sudo mkdir -p /var/www/xpost && sudo chown -R $USER:www-data /var/www/xpost
cd /var/www/xpost
git clone <repo> .

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
nano .env                      # điền DB, Redis, APP_URL, SANCTUM_STATEFUL_DOMAINS

php artisan migrate --force
php artisan storage:link

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

Cache cấu hình cho production:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## 5. Nginx

`/etc/nginx/sites-available/xpost`:

```nginx
server {
    listen 80;
    server_name xpost.example.com;
    root /var/www/xpost/public;

    index index.php;
    charset utf-8;

    client_max_body_size 512M;   # khớp XPOST_MAX_UPLOAD_MB cho video

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/xpost /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d xpost.example.com
```

Sau khi có HTTPS, cập nhật `APP_URL=https://…` và **dán đúng callback URL đó vào
X Developer Portal** — X từ chối mọi redirect_uri không khớp tuyệt đối.

---

## 6. Horizon (Supervisor)

`/etc/supervisor/conf.d/xpost-horizon.conf`:

```ini
[program:xpost-horizon]
process_name=%(program_name)s
command=/usr/bin/php8.5 /var/www/xpost/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/xpost-horizon.log
stopwaitsecs=3600
```

`stopwaitsecs=3600` là cố ý: Horizon cần thời gian để worker kết thúc job đang
chạy. Kill sớm giữa lúc `POST /2/tweets` đang bay sẽ tạo ra một target không rõ
đã đăng hay chưa.

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start xpost-horizon
```

---

## 7. Scheduler (cron)

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/xpost && /usr/bin/php8.5 artisan schedule:run >> /dev/null 2>&1
```

Một dòng duy nhất — mọi lịch còn lại nằm trong `routes/console.php`.

---

## 8. ClickHouse (tuỳ chọn)

Chỉ cần khi muốn phân tích chuỗi thời gian dài hạn. Không có nó, analytics vẫn
chạy bằng aggregate trên MySQL.

```bash
curl -fsSL https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key \
  | sudo gpg --dearmor -o /usr/share/keyrings/clickhouse-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg] https://packages.clickhouse.com/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/clickhouse.list
sudo apt update && sudo apt install -y clickhouse-server clickhouse-client
sudo systemctl enable --now clickhouse-server
```

```bash
# .env
CLICKHOUSE_ENABLED=true
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=xpost

php artisan xpost:clickhouse-migrate
```

---

## 9. Quy trình deploy

```bash
cd /var/www/xpost
php artisan down --render="errors::503"

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force

php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan horizon:terminate        # worker khởi động lại với code mới

php artisan up
```

`horizon:terminate` chứ không phải restart supervisor: nó để worker hoàn thành
job hiện tại rồi mới thoát, Supervisor tự khởi động lại.

---

## 10. Vận hành

| Kiểm tra | Lệnh |
|---|---|
| Hàng đợi có bị ùn không | `php artisan horizon:status`, dashboard `/horizon` |
| Bài đăng đang lỗi gì | `tail -f storage/logs/xpost-$(date +%F).log` |
| Còn bao nhiêu ngân sách API | Trang **X Apps** trong UI |
| Target bị kẹt | `php artisan xpost:reap --minutes=15` (đã chạy tự động) |
| Buffer metrics tồn đọng | `php artisan xpost:flush-metrics` |

Cảnh báo nên đặt ở:

- `redis:publish` chờ quá 120 giây (Horizon `waits` đã cấu hình sẵn ngưỡng này)
- số tài khoản `needs_reauth` tăng đột biến → thường là app credential sai
- `monthly_posts_used / monthly_post_cap > 0.8` trên mọi app trong pool

---

## Khi cần tách nhiều node

Ba thay đổi, không hơn:

1. **MySQL và Redis ra máy riêng.** Mọi trạng thái chia sẻ (hàng đợi, khoá,
   token bucket) đã nằm ở Redis nên không có gì phải sửa trong code.
2. **Media lên S3/MinIO** (`FILESYSTEM_DISK=s3`). Bắt buộc: worker upload media
   theo chunk, node không đọc được file thì job hỏng.
3. **Cron chỉ chạy trên một node**, hoặc giữ nguyên trên tất cả — mọi entry đã có
   `onOneServer()`, Redis lock sẽ lo phần còn lại.

Node web và node worker dùng chung mã nguồn; khác nhau ở chỗ node worker chạy
Horizon còn node web chạy PHP-FPM.
