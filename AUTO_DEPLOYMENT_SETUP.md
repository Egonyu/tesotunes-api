# Auto-Deployment Setup for TesoTunes API

## Overview
This document describes the auto-deployment setup for the TesoTunes API. When you push code from your local environment to GitHub (main branch), it automatically deploys to `/var/www/api.tesotunes.com` on the production server.

## Architecture

```
Local Dev → GitHub (main) → GitHub Actions → Production Server (/var/www/api.tesotunes.com)
```

## How It Works

1. **Push to GitHub**: When you push to the `main` branch from your local environment
2. **GitHub Actions Triggered**: The workflow `.github/workflows/deploy-production.yml` runs automatically
3. **Tests Run**: PHPUnit tests, Laravel Pint linting, and security audits execute
4. **Deployment**: If tests pass, the code is deployed to the server via SSH
5. **Post-Deploy**: Migrations run, caches clear, and services restart

## Required GitHub Secrets

You need to configure these secrets in your GitHub repository:
**Go to**: `https://github.com/Egonyu/tesotunes-api/settings/secrets/actions`

### Required Secrets:

1. **SERVER_HOST**: The server IP or hostname (e.g., `api.tesotunes.com` or IP address)
2. **SERVER_USER**: SSH username (usually `root` or your server user)
3. **SERVER_SSH_KEY**: Private SSH key for authentication (see below on how to generate)
4. **SERVER_PORT**: SSH port (default: 22)
5. **APP_PATH**: Deployment path (optional, defaults to `/var/www/api.tesotunes.com`)

## Setting Up SSH Key Authentication

### 1. Generate SSH Key Pair (if not already done)

On your local machine or server:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/tesotunes-deploy
```

This creates:
- Private key: `~/.ssh/tesotunes-deploy`
- Public key: `~/.ssh/tesotunes-deploy.pub`

### 2. Add Public Key to Server

On your production server:

```bash
# Add the public key to authorized_keys
cat ~/.ssh/tesotunes-deploy.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Or if setting up for a specific user:

```bash
# As root, add to the deploy user
mkdir -p /home/deployuser/.ssh
cat tesotunes-deploy.pub >> /home/deployuser/.ssh/authorized_keys
chown -R deployuser:deployuser /home/deployuser/.ssh
chmod 700 /home/deployuser/.ssh
chmod 600 /home/deployuser/.ssh/authorized_keys
```

### 3. Add Private Key to GitHub Secrets

1. Copy the private key content:
   ```bash
   cat ~/.ssh/tesotunes-deploy
   ```

2. Go to GitHub: `https://github.com/Egonyu/tesotunes-api/settings/secrets/actions`

3. Click **"New repository secret"**

4. Name: `SERVER_SSH_KEY`

5. Paste the entire private key content (including `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----`)

### 4. Configure Other Secrets

Add these secrets in GitHub:

```
SERVER_HOST: api.tesotunes.com (or your server IP)
SERVER_USER: root (or your SSH user)
SERVER_PORT: 22 (or your SSH port)
APP_PATH: /var/www/api.tesotunes.com (optional)
```

## Server Prerequisites

### 1. Git Configuration

The server must have Git access to pull from GitHub:

```bash
# If using SSH (recommended)
ssh-keygen -t ed25519 -C "server@api.tesotunes.com"
cat ~/.ssh/id_ed25519.pub
# Add this public key as a Deploy Key in GitHub repo settings
```

### 2. Required Software

Ensure these are installed on the server:

```bash
# PHP 8.3 with extensions
php -v

# Composer
composer --version

# Node.js 20+
node -v
npm -v

# Supervisor (for queue workers)
supervisorctl status
```

### 3. File Permissions

Ensure the web server can write to storage:

```bash
cd /var/www/api.tesotunes.com
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

## Workflow Features

### 1. Automatic Testing
- Runs PHPUnit tests
- Runs Laravel Pint (code style)
- Runs Composer and NPM security audits

### 2. Safe Deployment
- Creates backup before deployment
- Enables maintenance mode during deployment
- Pulls latest code from GitHub
- Installs dependencies
- Runs database migrations
- Clears and optimizes caches
- Restarts queue workers

### 3. Automatic Rollback
- If deployment fails, automatically rolls back to previous backup
- Keeps last 5 backups for manual rollback

### 4. Health Check
- Verifies API is responding after deployment
- Checks `/api/health` endpoint

## Usage

### Deploy from Local

Simply push to main branch:

```bash
git add .
git commit -m "feat: your changes"
git push origin main
```

The deployment happens automatically!

### Manual Deployment

You can also trigger deployment manually:

1. Go to: `https://github.com/Egonyu/tesotunes-api/actions`
2. Click "Deploy to Production"
3. Click "Run workflow"
4. Select environment and run

### Monitor Deployment

Watch deployment progress:

1. Go to: `https://github.com/Egonyu/tesotunes-api/actions`
2. Click on the latest workflow run
3. View logs for each step

## Deployment Process

When you push code, the workflow:

1. ✅ Checks out code
2. ✅ Sets up PHP 8.3 and Node.js 20
3. ✅ Installs dependencies
4. ✅ Runs tests (PHPUnit)
5. ✅ Runs security audits
6. ✅ Connects to server via SSH
7. ✅ Creates backup of current version
8. ✅ Pulls latest code from GitHub
9. ✅ Installs production dependencies
10. ✅ Builds assets
11. ✅ Runs database migrations
12. ✅ Clears and caches configs
13. ✅ Restarts queue workers
14. ✅ Runs health check
15. ✅ Deployment complete!

## Troubleshooting

### Deployment Fails with SSH Error

Check:
- SSH key is correctly added to GitHub secrets
- Public key is in server's `~/.ssh/authorized_keys`
- Server firewall allows SSH connections
- SERVER_HOST and SERVER_USER are correct

```bash
# Test SSH connection manually
ssh -i ~/.ssh/tesotunes-deploy user@api.tesotunes.com
```

### Tests Fail

The deployment won't proceed if tests fail. Fix tests locally first:

```bash
php artisan test
./vendor/bin/pint --test
```

### Permission Denied Errors

Ensure proper permissions:

```bash
cd /var/www/api.tesotunes.com
sudo chown -R $USER:www-data .
sudo chmod -R 775 storage bootstrap/cache
```

### Supervisor Not Restarting

Ensure the user has sudo access:

```bash
# Add to /etc/sudoers.d/deploy-user
deployuser ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl
```

### Manual Rollback

If needed, manually rollback:

```bash
cd /var/www
ls -lt | grep api.tesotunes.com.backup  # Find latest backup
rm -rf api.tesotunes.com
mv api.tesotunes.com.backup.TIMESTAMP api.tesotunes.com
cd api.tesotunes.com
php artisan up
```

## Deployment Notifications

Consider adding Slack/Discord notifications:

```yaml
- name: Notify Slack
  if: always()
  uses: 8398a7/action-slack@v3
  with:
    status: ${{ job.status }}
    webhook_url: ${{ secrets.SLACK_WEBHOOK }}
```

## Security Best Practices

1. ✅ Never commit `.env` files
2. ✅ Store all secrets in GitHub Secrets
3. ✅ Use SSH keys, not passwords
4. ✅ Limit SSH key permissions (read-only deploy keys when possible)
5. ✅ Keep backups of production database separately
6. ✅ Monitor deployment logs
7. ✅ Test in staging before production

## What Gets Deployed

Files included in deployment:
- All PHP source code (`app/`, `config/`, `routes/`, etc.)
- Composer dependencies (production only)
- Built frontend assets (`public/build/`)
- Database migrations

Files excluded from deployment:
- `.env` (managed on server)
- `node_modules/` (reinstalled on server)
- `tests/`
- Development dependencies
- `.git/` directory
- `storage/logs/` (preserved on server)

## Environment Variables

The `.env` file on the server is NOT overwritten by deployment. Manage it manually:

```bash
ssh user@api.tesotunes.com
cd /var/www/api.tesotunes.com
nano .env
# Make changes
php artisan config:cache
```

## Next Steps

After setting up auto-deployment:

1. ✅ Configure all GitHub Secrets
2. ✅ Test SSH connection from GitHub Actions
3. ✅ Make a small commit to test deployment
4. ✅ Monitor the first deployment
5. ✅ Set up monitoring/alerting for the API
6. ✅ Configure staging environment (optional)

## Related Files

- `.github/workflows/deploy-production.yml` - Main deployment workflow
- `.github/workflows/staging.yml` - Staging deployment
- `.github/workflows/tests.yml` - Test-only workflow
- `.github/deploy-exclude.txt` - Files excluded from deployment

## Support

For issues with deployment:
1. Check GitHub Actions logs
2. Check server logs: `/var/www/api.tesotunes.com/storage/logs/laravel.log`
3. Verify server access: `ssh user@api.tesotunes.com`
4. Test locally first: `php artisan test && ./vendor/bin/pint --test`
