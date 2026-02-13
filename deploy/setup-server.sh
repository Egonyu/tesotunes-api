#!/bin/bash
# =============================================================================
# TesoTunes Beta - Server Setup & Deploy Script
# =============================================================================
# Run this on your DigitalOcean droplet as root.
#
# Usage:
#   chmod +x deploy/setup-server.sh
#   sudo ./deploy/setup-server.sh
#
# What it does:
#   1. Creates the Laravel site directory
#   2. Clones the repo
#   3. Sets up Laravel (composer, .env, migrations)
#   4. Builds & starts Next.js Docker container
#   5. Installs Nginx configs for both domains
#   6. Gets SSL certificates via Certbot
# =============================================================================

set -e

# ── Configuration ────────────────────────────────────────────────────────────
SITE_DIR="/var/www/beta.tesotunes.com"
REPO_URL="https://github.com/TesoTunes/tesotunes-next.git"
BRANCH="main"
DB_NAME="tesotunes_beta"
DB_USER="tesotunes_beta"
PHP_VERSION="8.4"

echo ""
echo "╔══════════════════════════════════════════════════╗"
echo "║   TesoTunes Beta - Server Setup                 ║"
echo "║   beta.tesotunes.com + engine.tesotunes.com     ║"
echo "╚══════════════════════════════════════════════════╝"
echo ""

# ── Check prerequisites ─────────────────────────────────────────────────────
echo "▸ Checking prerequisites..."

if ! command -v docker &> /dev/null; then
    echo "✗ Docker not found. Installing..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

if ! command -v docker compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo "✗ Docker Compose not found. Installing plugin..."
    apt-get update && apt-get install -y docker-compose-plugin
fi

if ! command -v php &> /dev/null; then
    echo "✗ PHP not found. Please install PHP ${PHP_VERSION} first."
    echo "  Run: apt install php${PHP_VERSION}-fpm php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-gd php${PHP_VERSION}-curl php${PHP_VERSION}-intl php${PHP_VERSION}-bcmath php${PHP_VERSION}-zip"
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo "✗ Composer not found. Installing..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

if ! command -v nginx &> /dev/null; then
    echo "✗ Nginx not found. Please install: apt install nginx"
    exit 1
fi

if ! command -v certbot &> /dev/null; then
    echo "⚠ Certbot not found. Install it later for SSL:"
    echo "  apt install certbot python3-certbot-nginx"
fi

echo "✓ All prerequisites met"

# ── Step 1: Clone or update repo ────────────────────────────────────────────
echo ""
echo "▸ Step 1: Setting up project directory..."

if [ -d "$SITE_DIR/.git" ]; then
    echo "  Repo exists, pulling latest..."
    cd "$SITE_DIR"
    git fetch origin
    git reset --hard origin/$BRANCH
else
    echo "  Cloning repo..."
    mkdir -p "$SITE_DIR"
    git clone -b "$BRANCH" "$REPO_URL" "$SITE_DIR"
    cd "$SITE_DIR"
fi

echo "✓ Code is at: $SITE_DIR"

# ── Step 2: Setup MySQL database ────────────────────────────────────────────
echo ""
echo "▸ Step 2: Setting up MySQL database..."

# Generate a random password
DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)

# Check if database already exists
if mysql -u root -e "USE $DB_NAME" 2>/dev/null; then
    echo "  Database '$DB_NAME' already exists, skipping..."
else
    echo "  Creating database and user..."
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    echo "  ┌──────────────────────────────────────────────┐"
    echo "  │ Database: $DB_NAME                           "
    echo "  │ User:     $DB_USER                           "
    echo "  │ Password: $DB_PASS                           "
    echo "  │ SAVE THIS PASSWORD!                          "
    echo "  └──────────────────────────────────────────────┘"
fi

echo "✓ Database ready"

# ── Step 3: Setup Laravel .env ───────────────────────────────────────────────
echo ""
echo "▸ Step 3: Configuring Laravel..."

if [ ! -f "$SITE_DIR/.env" ]; then
    cp "$SITE_DIR/deploy/.env.beta.laravel" "$SITE_DIR/.env"

    # Generate APP_KEY
    cd "$SITE_DIR"
    APP_KEY=$(php artisan key:generate --show)

    # Replace placeholders
    sed -i "s|APP_KEY=.*|APP_KEY=$APP_KEY|" .env
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" .env
    sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|" .env
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env

    # Generate NEXTAUTH_SECRET
    NEXTAUTH_SECRET=$(openssl rand -hex 32)
    echo ""
    echo "  ┌──────────────────────────────────────────────┐"
    echo "  │ NEXTAUTH_SECRET: $NEXTAUTH_SECRET            "
    echo "  │ SAVE THIS - you need it for Docker too       "
    echo "  └──────────────────────────────────────────────┘"

    echo ""
    echo "  ⚠ EDIT .env NOW to set your mail & payment secrets:"
    echo "    nano $SITE_DIR/.env"
else
    echo "  .env already exists, skipping..."
fi

# ── Step 4: Install Laravel dependencies ─────────────────────────────────────
echo ""
echo "▸ Step 4: Installing Laravel dependencies..."

cd "$SITE_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Create storage link
php artisan storage:link --force 2>/dev/null || true

# Run migrations
php artisan migrate --force --no-interaction

# Cache config
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "✓ Laravel configured"

# ── Step 5: Docker .env for Next.js ──────────────────────────────────────────
echo ""
echo "▸ Step 5: Setting up Next.js Docker container..."

if [ ! -f "$SITE_DIR/deploy/.env" ]; then
    cp "$SITE_DIR/deploy/.env.beta.nextjs" "$SITE_DIR/deploy/.env"
    echo "  ⚠ Edit deploy/.env to set NEXTAUTH_SECRET:"
    echo "    nano $SITE_DIR/deploy/.env"
fi

# Build and start Next.js
cd "$SITE_DIR"
docker compose -f deploy/docker-compose.beta.yml up -d --build

echo "✓ Next.js container running"

# ── Step 6: Nginx configuration ─────────────────────────────────────────────
echo ""
echo "▸ Step 6: Configuring Nginx..."

# Copy Nginx configs
cp "$SITE_DIR/deploy/nginx/beta.tesotunes.com.conf" /etc/nginx/sites-available/beta.tesotunes.com
cp "$SITE_DIR/deploy/nginx/engine.tesotunes.com.conf" /etc/nginx/sites-available/engine.tesotunes.com

# Check PHP-FPM socket path (adjust if different version)
PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [ ! -S "$PHP_SOCK" ]; then
    # Try to find the correct socket
    FOUND_SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1)
    if [ -n "$FOUND_SOCK" ]; then
        echo "  Adjusting PHP-FPM socket path to: $FOUND_SOCK"
        sed -i "s|unix:/run/php/php${PHP_VERSION}-fpm.sock|unix:$FOUND_SOCK|" /etc/nginx/sites-available/engine.tesotunes.com
    else
        echo "  ⚠ PHP-FPM socket not found at $PHP_SOCK"
        echo "  Check your PHP-FPM config and adjust the nginx config."
    fi
fi

# Enable sites
ln -sf /etc/nginx/sites-available/beta.tesotunes.com /etc/nginx/sites-enabled/
ln -sf /etc/nginx/sites-available/engine.tesotunes.com /etc/nginx/sites-enabled/

# Test nginx config
nginx -t

# Reload
systemctl reload nginx

echo "✓ Nginx configured"

# ── Step 7: SSL certificates ────────────────────────────────────────────────
echo ""
echo "▸ Step 7: SSL certificates..."

if command -v certbot &> /dev/null; then
    echo "  Getting SSL certificates..."
    certbot --nginx -d beta.tesotunes.com -d engine.tesotunes.com --non-interactive --agree-tos --email info@tesotunes.com
    echo "✓ SSL certificates installed"
else
    echo "  ⚠ Certbot not installed. Install it and run:"
    echo "    apt install certbot python3-certbot-nginx"
    echo "    certbot --nginx -d beta.tesotunes.com -d engine.tesotunes.com"
fi

# ── Step 8: Setup queue worker (systemd) ──────────────────────────────────────
echo ""
echo "▸ Step 8: Setting up queue worker..."

cat > /etc/systemd/system/tesotunes-beta-queue.service <<EOF
[Unit]
Description=TesoTunes Beta Queue Worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=$SITE_DIR
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable tesotunes-beta-queue.service
systemctl start tesotunes-beta-queue.service

echo "✓ Queue worker running"

# ── Done ─────────────────────────────────────────────────────────────────────
echo ""
echo "╔══════════════════════════════════════════════════╗"
echo "║   ✓ Deployment Complete!                        ║"
echo "╠══════════════════════════════════════════════════╣"
echo "║                                                  ║"
echo "║   Frontend: https://beta.tesotunes.com           ║"
echo "║   API:      https://engine.tesotunes.com          ║"
echo "║   Health:   https://engine.tesotunes.com/api/health ║"
echo "║                                                  ║"
echo "║   Next steps:                                    ║"
echo "║   1. Edit .env with mail/payment secrets         ║"
echo "║   2. Edit deploy/.env with NEXTAUTH_SECRET       ║"
echo "║   3. Create admin user (see below)               ║"
echo "║   4. Set up DNS A records                        ║"
echo "║                                                  ║"
echo "╚══════════════════════════════════════════════════╝"
echo ""
echo "To create an admin user:"
echo "  cd $SITE_DIR"
echo "  php artisan tinker --execute=\"App\\Models\\User::create(['name'=>'Admin','email'=>'admin@tesotunes.com','password'=>bcrypt('YOUR_PASSWORD'),'role'=>'Super Admin','email_verified_at'=>now()]);\""
echo ""
echo "To redeploy after code changes:"
echo "  cd $SITE_DIR && git pull origin main"
echo "  composer install --no-dev --optimize-autoloader"
echo "  php artisan migrate --force"
echo "  php artisan config:cache && php artisan route:cache && php artisan view:cache"
echo "  docker compose -f deploy/docker-compose.beta.yml up -d --build"
echo ""
