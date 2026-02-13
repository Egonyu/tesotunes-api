# TesoTunes Beta Deployment Guide

## Architecture

```
Your DigitalOcean Droplet
├── Nginx (already running for tesotunes.com)
│   ├── tesotunes.com        → existing Laravel+Blade site (unchanged)
│   ├── beta.tesotunes.com   → Docker Next.js (port 3000)    ← NEW
│   └── api.beta.tesotunes.com → Laravel PHP-FPM             ← NEW
│
├── PHP-FPM (already running)
│   ├── tesotunes.com site
│   └── beta.tesotunes.com Laravel API                       ← NEW
│
├── MySQL (already running)
│   ├── existing tesotunes database
│   └── tesotunes_beta database                              ← NEW
│
└── Docker
    └── tesotunes-beta-frontend (Next.js on port 3000)       ← NEW
```

**Why this approach:**
- Laravel runs natively (same as your existing site — fast, no overhead)
- Only Next.js runs in Docker (simple, isolated, no conflicts)
- Uses your existing MySQL (no duplicate database server)
- Your existing `tesotunes.com` site is completely untouched

---

## Step 1: DNS Records

Go to your DNS provider (Cloudflare/Namecheap/etc.) and add:

| Type | Name | Value | Proxy |
|------|------|-------|-------|
| A | `beta` | `YOUR_SERVER_IP` | Off* |
| A | `api.beta` | `YOUR_SERVER_IP` | Off* |

> *Keep proxy OFF until SSL is working. Enable Cloudflare proxy later if desired.

Wait 5-10 minutes for DNS to propagate.

---

## Step 2: Commit & Push Your Code

On your **local machine** (Windows), commit all the changes including the deploy files:

```powershell
cd c:\Users\egony\Herd\ateusio\beta

# Stage the deploy folder and all changes
git add -A

# Check what you're committing
git status

# Commit
git commit -m "feat: add deployment configs for beta.tesotunes.com"

# Push
git push origin main
```

---

## Step 3: SSH Into Your Server

```bash
ssh root@YOUR_SERVER_IP
```

---

## Step 4: Clone the Repo on the Server

```bash
# Create the site directory
mkdir -p /var/www/beta.tesotunes.com

# Clone the repo
git clone git@github.com:TesoTunes/tesotunes-next.git /var/www/beta.tesotunes.com

cd /var/www/beta.tesotunes.com
```

> If you haven't set up SSH keys on your server for GitHub, you can use HTTPS:
> ```bash
> git clone https://github.com/TesoTunes/tesotunes-next.git /var/www/beta.tesotunes.com
> ```

---

## Step 5: Setup MySQL Database

```bash
# Create database and user
mysql -u root -p <<'SQL'
CREATE DATABASE tesotunes_beta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tesotunes_beta'@'localhost' IDENTIFIED BY 'PICK_A_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON tesotunes_beta.* TO 'tesotunes_beta'@'localhost';
FLUSH PRIVILEGES;
SQL
```

**Save this password — you need it for the .env file.**

---

## Step 6: Configure Laravel

```bash
cd /var/www/beta.tesotunes.com

# Copy the env template
cp deploy/.env.beta.laravel .env

# Generate APP_KEY
php artisan key:generate

# Edit .env to fill in your secrets
nano .env
```

**In `.env`, change these values:**
```
DB_PASSWORD=your_mysql_password_from_step_5
MAIL_PASSWORD=your_zoho_password
ZENGAPAY_API_KEY=your_key
ZENGAPAY_API_SECRET=your_secret
ZENGAPAY_WEBHOOK_SECRET=your_webhook_secret
```

Then install and setup Laravel:

```bash
# Install dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Storage link
php artisan storage:link

# Run migrations
php artisan migrate --force

# Cache everything
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## Step 7: Setup Next.js Docker Container

```bash
cd /var/www/beta.tesotunes.com

# Create the Docker .env file
cp deploy/.env.beta.nextjs deploy/.env

# Generate a NEXTAUTH_SECRET
openssl rand -hex 32
# Copy the output

# Edit deploy/.env and paste the secret
nano deploy/.env

# Build and start the container
docker compose -f deploy/docker-compose.beta.yml up -d --build
```

This will take 2-5 minutes on first build. Check progress:
```bash
docker compose -f deploy/docker-compose.beta.yml logs -f
```

Verify it's running:
```bash
docker ps
# Should show: tesotunes-beta-frontend

curl http://localhost:3000
# Should return HTML
```

---

## Step 8: Configure Nginx

```bash
# Copy the nginx configs
cp deploy/nginx/beta.tesotunes.com.conf /etc/nginx/sites-available/
cp deploy/nginx/api.beta.tesotunes.com.conf /etc/nginx/sites-available/

# Check your PHP-FPM version/socket
ls /run/php/php*-fpm.sock
# If it's NOT php8.4-fpm.sock, edit the API config:
# nano /etc/nginx/sites-available/api.beta.tesotunes.com
# Change the fastcgi_pass line to match your socket

# Enable the sites
ln -s /etc/nginx/sites-available/beta.tesotunes.com /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/api.beta.tesotunes.com /etc/nginx/sites-enabled/

# Test config
nginx -t

# Reload
systemctl reload nginx
```

---

## Step 9: SSL Certificates

```bash
# Install certbot if not already installed
apt install -y certbot python3-certbot-nginx

# Get certificates for both domains
certbot --nginx -d beta.tesotunes.com -d api.beta.tesotunes.com
```

Certbot will auto-modify the Nginx configs to add SSL.

---

## Step 10: Queue Worker (Optional but Recommended)

```bash
# Create systemd service for Laravel queue
cat > /etc/systemd/system/tesotunes-beta-queue.service <<'EOF'
[Unit]
Description=TesoTunes Beta Queue Worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/beta.tesotunes.com
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable tesotunes-beta-queue.service
systemctl start tesotunes-beta-queue.service
```

---

## Step 11: Create Admin User

```bash
cd /var/www/beta.tesotunes.com

php artisan tinker
```

In tinker:
```php
App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@tesotunes.com',
    'password' => bcrypt('YourSecurePassword'),
    'role' => 'Super Admin',
    'email_verified_at' => now(),
]);
```

---

## Step 12: Verify Everything

```bash
# API health check
curl https://api.beta.tesotunes.com/api/health
# → {"status":"ok"}

# Frontend
curl -I https://beta.tesotunes.com
# → HTTP/2 200

# Docker status
docker ps
# → tesotunes-beta-frontend should be running and healthy
```

Visit in your browser:
- **https://beta.tesotunes.com** — Next.js frontend
- **https://api.beta.tesotunes.com/api/health** — API health

---

## Redeploying After Changes

After you push changes locally:

```bash
# On your server:
cd /var/www/beta.tesotunes.com
sudo ./deploy/redeploy.sh
```

Or manually:
```bash
cd /var/www/beta.tesotunes.com
git pull origin main

# Laravel changes
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

# Frontend changes (rebuilds Docker image)
docker compose -f deploy/docker-compose.beta.yml up -d --build
```

---

## Moving to Production (tesotunes.com) Later

When ready to replace the old Blade frontend with Next.js on the main domain:

1. Update DNS: Point `tesotunes.com` and `api.tesotunes.com` appropriately
2. Create new Nginx configs for `tesotunes.com` and `api.tesotunes.com`
3. Update Laravel `.env`:
   - `APP_URL=https://api.tesotunes.com`
   - `SANCTUM_STATEFUL_DOMAINS=tesotunes.com,api.tesotunes.com`
   - `SESSION_DOMAIN=.tesotunes.com`
   - `FRONTEND_URL=https://tesotunes.com`
4. Rebuild Next.js Docker with new URLs:
   - `NEXT_PUBLIC_APP_URL=https://tesotunes.com`
   - `NEXT_PUBLIC_API_URL=https://api.tesotunes.com/api`
5. Migrate the database (or point to the existing one)

---

## Troubleshooting

### "502 Bad Gateway" on beta.tesotunes.com
- Docker container not running: `docker ps` → check if `tesotunes-beta-frontend` is up
- Restart: `docker compose -f deploy/docker-compose.beta.yml restart`

### "502 Bad Gateway" on api.beta.tesotunes.com
- PHP-FPM not running: `systemctl status php8.4-fpm`
- Wrong socket path: `ls /run/php/php*-fpm.sock` and update nginx config

### "CORS errors" in browser console
- Check Laravel `.env` has correct `FRONTEND_URL=https://beta.tesotunes.com`
- Check `SANCTUM_STATEFUL_DOMAINS` includes both domains
- Run `php artisan config:cache` after changes

### Next.js shows "Internal Server Error"
- Check logs: `docker compose -f deploy/docker-compose.beta.yml logs frontend`
- Check NEXTAUTH_SECRET is set in `deploy/.env`

### "Unauthenticated" when making API calls
- Check `SESSION_DOMAIN=.beta.tesotunes.com` in Laravel `.env`
- Check browser is sending cookies (Network tab → look for `Set-Cookie` headers)
- Make sure both domains share the `.beta.tesotunes.com` cookie domain

### Docker build fails
- Clear cache: `docker compose -f deploy/docker-compose.beta.yml build --no-cache`
- Check disk space: `df -h`
