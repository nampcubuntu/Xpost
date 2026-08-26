#!/usr/bin/env bash
#
# First-time server setup for Xpost on Ubuntu 26.04.
#
# Turns docs/DEPLOY.md sections 1-8 into one idempotent run: safe to re-run, and
# it stops at the first failure rather than half-configuring a box. It does not
# touch the database or the application code -- bin/deploy.sh does that.
#
# Usage (as a sudo-capable user, not root):
#   sudo DOMAIN=xposter.zenomedia.co REPO=git@github.com:nampcubuntu/Xpost.git \
#        bash bin/server-bootstrap.sh
#
set -euo pipefail

DOMAIN="${DOMAIN:?Đặt DOMAIN, ví dụ DOMAIN=xposter.zenomedia.co}"
REPO="${REPO:?Đặt REPO, ví dụ REPO=git@github.com:nampcubuntu/Xpost.git}"
APP_DIR="${APP_DIR:-/var/www/xpost}"
# Staging tracks `staging` (advanced by CI only on green); a production box
# would be pointed at a tag instead.
BRANCH="${BRANCH:-staging}"
PHP_VERSION="${PHP_VERSION:-8.5}"
DB_NAME="${DB_NAME:-xpost}"
DB_USER="${DB_USER:-xpost}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "Cần chạy bằng sudo."; exit 1; }

# ── 1. Packages ─────────────────────────────────────────────────────────────
say "Gói hệ thống"

apt-get update -qq

# Ubuntu 26.04 may already carry PHP 8.5; only reach for the PPA if it does not.
if ! apt-cache show "php${PHP_VERSION}-cli" >/dev/null 2>&1; then
    say "PHP ${PHP_VERSION} không có trong repo mặc định — thêm ppa:ondrej/php"
    apt-get install -y -qq software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
fi

apt-get install -y -qq \
    "php${PHP_VERSION}-cli" "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-mysql" \
    "php${PHP_VERSION}-redis" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-gd" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
    nginx mysql-server redis-server supervisor git unzip curl

if ! command -v composer >/dev/null; then
    say "Composer"
    curl -sS https://getcomposer.org/installer | "php${PHP_VERSION}"
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer
fi

if ! command -v node >/dev/null; then
    say "Node 22"
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y -qq nodejs
fi

# ── 2. PHP-FPM ──────────────────────────────────────────────────────────────
say "PHP-FPM"

PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"

# Video creatives are the reason: the app allows 512M and PHP defaults to 2M,
# which fails the upload with a message that blames the file, not the config.
sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 512M/' "$PHP_INI"
sed -i 's/^post_max_size = .*/post_max_size = 512M/' "$PHP_INI"
sed -i 's/^memory_limit = .*/memory_limit = 512M/' "$PHP_INI"
sed -i 's/^max_execution_time = .*/max_execution_time = 300/' "$PHP_INI"

systemctl restart "php${PHP_VERSION}-fpm"

# ── 3. Redis ────────────────────────────────────────────────────────────────
say "Redis"

redis-server --version

REDIS_CONF=/etc/redis/redis.conf
set_redis() {
    if grep -qE "^#? *$1 " "$REDIS_CONF"; then
        sed -i "s|^#\? *$1 .*|$1 $2|" "$REDIS_CONF"
    else
        echo "$1 $2" >> "$REDIS_CONF"
    fi
}

set_redis appendonly yes
set_redis appendfsync everysec
set_redis maxmemory 2gb
# noeviction is mandatory: Redis holds the queue and the distributed locks. An
# evicted job is a post that silently never goes out.
set_redis maxmemory-policy noeviction

systemctl restart redis-server

# ── 4. MySQL ────────────────────────────────────────────────────────────────
say "MySQL"

DB_PASS_FILE=/root/.xpost-db-password

if [[ ! -f $DB_PASS_FILE ]]; then
    # Generated once and kept, so re-running does not orphan the credentials
    # already written into .env.
    openssl rand -base64 24 | tr -d '\n' > "$DB_PASS_FILE"
    chmod 600 "$DB_PASS_FILE"
fi

DB_PASS="$(cat "$DB_PASS_FILE")"

mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

cat > /etc/mysql/mysql.conf.d/99-xpost.cnf <<'MYSQLCNF'
[mysqld]
innodb_buffer_pool_size = 2G
innodb_flush_log_at_trx_commit = 2
max_connections = 500
# The dispatcher claims work with SKIP LOCKED; keep the default isolation.
transaction_isolation = READ-COMMITTED
MYSQLCNF

systemctl restart mysql

# ── 5. Application directory ────────────────────────────────────────────────
say "Mã nguồn tại ${APP_DIR}"

mkdir -p "$APP_DIR"

if [[ ! -d "$APP_DIR/.git" ]]; then
    # Private repo: this needs a deploy key on the server, or an HTTPS URL
    # carrying a token. See the notes at the bottom of this script.
    git clone "$REPO" "$APP_DIR"
fi

# A staging box tracks `staging`, which CI only advances once tests pass. That
# branch does not exist until the first green build, so fall back to whatever
# was cloned rather than failing the whole bootstrap over it.
if git -C "$APP_DIR" ls-remote --exit-code --heads origin "$BRANCH" >/dev/null 2>&1; then
    git -C "$APP_DIR" fetch origin "$BRANCH"
    git -C "$APP_DIR" checkout -B "$BRANCH" "origin/$BRANCH"
    echo "Đang bám nhánh ${BRANCH}."
else
    echo "Nhánh '${BRANCH}' chưa tồn tại — CI sẽ tạo sau lần build xanh đầu tiên."
    echo "Tạm bám $(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD)."
fi

chown -R www-data:www-data "$APP_DIR"

# ── 6. .env ─────────────────────────────────────────────────────────────────
say ".env"

if [[ ! -f "$APP_DIR/.env" ]]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"

    set_env() { sed -i "s|^$1=.*|$1=$2|" "$APP_DIR/.env"; }

    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://${DOMAIN}"
    set_env LOG_LEVEL warning
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    set_env DB_PASSWORD "$DB_PASS"
    # phpredis is a C extension -- measurably faster than predis under queue load.
    set_env REDIS_CLIENT phpredis
    set_env SANCTUM_STATEFUL_DOMAINS "$DOMAIN"
    set_env FRONTEND_URL "https://${DOMAIN}"

    chown www-data:www-data "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"

    sudo -u www-data "php${PHP_VERSION}" "$APP_DIR/artisan" key:generate --force
else
    echo ".env đã có — giữ nguyên."
fi

# ── 7. Nginx ────────────────────────────────────────────────────────────────
say "Nginx"

cat > /etc/nginx/sites-available/xpost <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;

    index index.php;
    charset utf-8;

    # Matches XPOST_MAX_UPLOAD_MB; a smaller value fails video uploads at the
    # proxy with a 413 the application never sees.
    client_max_body_size 512M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/xpost /etc/nginx/sites-enabled/xpost
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl reload nginx

# ── 8. Horizon under Supervisor ─────────────────────────────────────────────
say "Horizon"

cat > /etc/supervisor/conf.d/xpost-horizon.conf <<SUPERVISOR
[program:xpost-horizon]
process_name=%(program_name)s
command=/usr/bin/php${PHP_VERSION} ${APP_DIR}/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/xpost-horizon.log
; Horizon needs time to let workers finish. Killing mid POST /2/tweets leaves a
; target nobody can tell was published or not.
stopwaitsecs=3600
SUPERVISOR

supervisorctl reread
supervisorctl update

# ── 9. Scheduler ────────────────────────────────────────────────────────────
say "Cron"

CRON_LINE="* * * * * cd ${APP_DIR} && /usr/bin/php${PHP_VERSION} artisan schedule:run >> /dev/null 2>&1"

# One line only; everything else lives in routes/console.php.
( crontab -u www-data -l 2>/dev/null | grep -v 'artisan schedule:run' ; echo "$CRON_LINE" ) \
    | crontab -u www-data -

# ── 10. Auto-deploy timer ───────────────────────────────────────────────────
say "Auto-deploy (staging)"

if [[ "${AUTODEPLOY:-yes}" == "yes" ]]; then
    install -m 644 "${APP_DIR}/deploy/xpost-autodeploy.service" /etc/systemd/system/
    install -m 644 "${APP_DIR}/deploy/xpost-autodeploy.timer" /etc/systemd/system/

    systemctl daemon-reload
    systemctl enable --now xpost-autodeploy.timer

    echo "Đang theo dõi nhánh '${BRANCH}', kiểm tra mỗi phút."
    echo "Nhật ký: journalctl -u xpost-autodeploy -f"
else
    echo "Bỏ qua (AUTODEPLOY=no) — production nên deploy tay."
fi

say "Xong phần hệ thống"

cat <<DONE

Còn ba việc phải làm bằng tay:

  1. Chạy bin/deploy.sh để cài dependency, migrate và build frontend:
       sudo -u www-data bash ${APP_DIR}/bin/deploy.sh --first-run

  2. Bật HTTPS (X từ chối callback không phải https):
       sudo apt install -y certbot python3-certbot-nginx
       sudo certbot --nginx -d ${DOMAIN}

  3. Dán callback URL này vào X Developer Portal, khớp tuyệt đối từng ký tự:
       https://${DOMAIN}/x/oauth/callback

Mật khẩu MySQL đã sinh và lưu ở ${DB_PASS_FILE} (chỉ root đọc được).
DONE
