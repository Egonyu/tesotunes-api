# TesoTunes Deployment Guide — Coolify (Self-Hosted)

## Architecture Overview

```
                    ┌────────────────────────────────────┐
                    │         Coolify Server              │
                    │                                     │
  beta.tesotunes.com│   ┌──────────────────┐              │
  ─────────────────►│   │   Next.js (3000)  │              │
                    │   │   Frontend        │              │
                    │   └────────┬─────────┘              │
                    │            │ HTTPS                   │
api.beta.tesotunes  │   ┌────────▼─────────┐              │
  ─────────────────►│   │ Laravel API (80)  │              │
                    │   │ PHP-FPM + Nginx   │              │
                    │   └────────┬─────────┘              │
                    │            │                         │
                    │   ┌────────▼─────────┐              │
                    │   │  MySQL 8.0       │              │
                    │   │  (internal)       │              │
                    │   └──────────────────┘              │
                    └────────────────────────────────────┘
```

**Domains:**
- `beta.tesotunes.com` → Next.js frontend (port 3000)
- `api.beta.tesotunes.com` → Laravel API (port 80 inside container)

**SSL:** Coolify auto-provisions Let's Encrypt certificates.

---

## Pre-Deployment: Git Cleanup

Both the backend and frontend currently live in the same repo (`TesoTunes/tesotunes`).
You have two local checkouts that have diverged. Before deploying, you need to commit
everything to one clean state.

### Step 1: Merge Both Working Directories

Since `c:\Users\egony\Herd\beta` (backend) and `c:\Users\egony\Project\tesotunes` (frontend)
are both clones of the same repo, pick ONE as the source of truth and commit from there.

**Recommended:** Work from `c:\Users\egony\Herd\beta` (has the backend + Docker files).

```powershell
# From the backend directory
cd c:\Users\egony\Herd\beta

# Copy the frontend source files into the repo
Copy-Item -Recurse c:\Users\egony\Project\tesotunes\src .\src
Copy-Item c:\Users\egony\Project\tesotunes\next.config.ts .
Copy-Item c:\Users\egony\Project\tesotunes\postcss.config.mjs .
Copy-Item c:\Users\egony\Project\tesotunes\eslint.config.mjs .
Copy-Item c:\Users\egony\Project\tesotunes\jest.config.ts .
Copy-Item c:\Users\egony\Project\tesotunes\tsconfig.json .
Copy-Item c:\Users\egony\Project\tesotunes\.env.example .\.env.frontend.example
```

### Step 2: Update .gitignore

Make sure these are NOT gitignored (they need to be committed):
```
# Frontend (must be tracked)
# src/
# next.config.ts
# postcss.config.mjs
# tsconfig.json
```

Make sure these ARE gitignored:
```
# Local environment
.env
.env.local
.env.*.local
.env.production

# Build outputs
.next/
node_modules/
vendor/

# Claude temp files
cUsersegony*
```

### Step 3: Commit & Push

```powershell
cd c:\Users\egony\Herd\beta

# Stage everything
git add -A

# Review what you're committing
git status

# Commit
git commit -m "feat: Add frontend source, Docker deployment files for Coolify"

# Push
git push origin main
```

---

## Server Setup

### Step 1: Install Coolify

On your server (Ubuntu recommended):

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

This installs Coolify at `http://YOUR_SERVER_IP:8000`. Follow the setup wizard.

### Step 2: DNS Configuration

In your DNS provider (e.g., Cloudflare, Namecheap), add:

| Type  | Name                      | Value              | Proxy  |
|-------|---------------------------|--------------------|--------|
| A     | beta.tesotunes.com        | YOUR_SERVER_IP     | Off*   |
| A     | api.beta.tesotunes.com    | YOUR_SERVER_IP     | Off*   |

> *Turn proxy OFF initially so Coolify can provision SSL. You can enable Cloudflare proxy later.

### Step 3: Connect GitHub

1. Go to Coolify → **Sources** → Add GitHub App
2. Authenticate with the `TesoTunes` GitHub org
3. Grant access to the `tesotunes` repo

---

## Coolify Deployment Configuration

### Option A: Docker Compose (Recommended)

1. Go to Coolify → **Projects** → Create New Project → "TesoTunes Beta"
2. Add New Resource → **Docker Compose**
3. Select the `TesoTunes/tesotunes` repo, branch `main`
4. Coolify will detect `docker-compose.yml`
5. Set **Environment Variables** (see below)
6. Configure domains per service:

**For the `frontend` service:**
- Domain: `beta.tesotunes.com`
- Port: `3000`

**For the `api` service:**
- Domain: `api.beta.tesotunes.com`
- Port: `80`

**For `mysql`:**
- No domain needed (internal only)

### Option B: Separate Services

If docker-compose doesn't work well in your Coolify version, deploy as 3 separate services:

**Service 1: MySQL**
1. Add Resource → Database → MySQL 8.0
2. Set database name: `tesotunes`
3. Set user/password
4. Note the internal hostname (usually the service name)

**Service 2: Laravel API**
1. Add Resource → Application → Docker (from GitHub)
2. Repo: `TesoTunes/tesotunes`, Branch: `main`
3. Dockerfile: `Dockerfile` (the default)
4. Domain: `api.beta.tesotunes.com`
5. Port: `80`
6. Set environment variables (see below)

**Service 3: Next.js Frontend**
1. Add Resource → Application → Docker (from GitHub)
2. Repo: `TesoTunes/tesotunes`, Branch: `main`
3. Dockerfile: `Dockerfile.next`
4. Domain: `beta.tesotunes.com`
5. Port: `3000`
6. Set build arguments and environment variables (see below)

---

## Environment Variables

### API Service (Laravel)

Set these in Coolify's Environment Variables section:

```env
APP_KEY=base64:GENERATE_A_NEW_KEY
DB_PASSWORD=your_strong_db_password
MYSQL_ROOT_PASSWORD=your_strong_root_password
NEXTAUTH_SECRET=generate_a_random_64_char_string
MAIL_USERNAME=info@tesotunes.com
MAIL_PASSWORD=your_zoho_password
ZENGAPAY_API_KEY=your_key
ZENGAPAY_API_SECRET=your_secret
ZENGAPAY_WEBHOOK_SECRET=your_webhook_secret
```

To generate an APP_KEY:
```bash
# Run inside the container or locally
php artisan key:generate --show
```

### Frontend Service (Next.js)

Build arguments (set in Coolify under Build Settings):
```env
NEXT_PUBLIC_APP_NAME=TesoTunes
NEXT_PUBLIC_APP_URL=https://beta.tesotunes.com
NEXT_PUBLIC_API_URL=https://api.beta.tesotunes.com/api
NEXT_PUBLIC_BACKEND_URL=https://api.beta.tesotunes.com
NEXTAUTH_URL=https://beta.tesotunes.com
NEXT_PUBLIC_GA_MEASUREMENT_ID=G-E1VJQ4RJBH
```

Runtime environment variables:
```env
NEXTAUTH_SECRET=same_secret_as_api
NEXTAUTH_URL=https://beta.tesotunes.com
NEXT_PUBLIC_API_URL=https://api.beta.tesotunes.com/api
NEXT_PUBLIC_BACKEND_URL=https://api.beta.tesotunes.com
```

---

## Post-Deployment Checklist

### 1. Run Migrations (auto on first start)
The entrypoint script runs `php artisan migrate --force` automatically.
To run manually:
```bash
# In Coolify, open the API container terminal:
php artisan migrate --force
```

### 2. Create Admin User
```bash
php artisan tinker
# Then:
\App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@tesotunes.com',
    'password' => bcrypt('YourSecurePassword'),
    'role' => 'Super Admin',
    'email_verified_at' => now(),
]);
```

### 3. Create Storage Link
Already done by the entrypoint script, but verify:
```bash
php artisan storage:link
```

### 4. Verify Health
```bash
curl https://api.beta.tesotunes.com/api/health
# Should return: {"status":"ok"}

curl https://beta.tesotunes.com
# Should return the Next.js app HTML
```

---

## File Structure (What Gets Deployed)

```
tesotunes/                      ← Git repo root
├── Dockerfile                  ← Laravel API image
├── Dockerfile.next             ← Next.js frontend image
├── docker-compose.yml          ← Full stack orchestration
├── .dockerignore               ← Excludes dev files from images
├── docker/
│   ├── nginx/site.conf         ← Nginx config for Laravel
│   ├── php/custom.ini          ← PHP production settings
│   ├── supervisor/supervisord.conf  ← Process manager
│   └── entrypoint.sh           ← Startup script (migrations, cache)
├── .env.production             ← Production env template
│
├── app/                        ← Laravel application
├── config/                     ← Laravel config
├── routes/                     ← Laravel routes
├── database/                   ← Migrations & seeders
├── composer.json               ← PHP dependencies
│
├── src/                        ← Next.js application
├── public/                     ← Static assets (shared)
├── next.config.ts              ← Next.js config
├── package.json                ← Node dependencies
└── tsconfig.json               ← TypeScript config
```

---

## Troubleshooting

### "502 Bad Gateway"
- Check if containers are running: Coolify → Logs
- Check MySQL is healthy: it takes ~30s to start
- Check API logs: `/var/www/html/storage/logs/laravel.log`

### "CORS errors"
- Verify `FRONTEND_URL` is set correctly in API env
- Check `config/cors.php` has the production domain
- Check `SANCTUM_STATEFUL_DOMAINS` includes both domains

### "Unauthenticated" on API calls
- Verify `SANCTUM_STATEFUL_DOMAINS` includes `beta.tesotunes.com`
- Verify `SESSION_DOMAIN` is `.beta.tesotunes.com`
- Check browser network tab for the `Authorization` header

### Build fails for Next.js
- Check that `output: "standalone"` is in `next.config.ts`
- Check build logs for missing environment variables
- Ensure all `NEXT_PUBLIC_*` vars are set as build args

### File uploads fail
- Check `client_max_body_size` in nginx config (set to 100M)
- Check `upload_max_filesize` in PHP config (set to 50M)
- Verify the `api-storage` volume is mounted

---

## Updating the App

After pushing changes to `main`:
1. Go to Coolify → your project
2. Click **Deploy** (or enable auto-deploy from GitHub webhooks)
3. Coolify rebuilds the images and restarts containers
4. Migrations run automatically via the entrypoint script

To enable **auto-deploy on push**:
1. In Coolify, go to your project → Settings
2. Enable "Auto Deploy" / Webhook
3. Coolify will set up a GitHub webhook automatically
