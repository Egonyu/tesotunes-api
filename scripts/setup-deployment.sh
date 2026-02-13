#!/bin/bash

# TesoTunes GitHub Actions Deployment Setup Script
# This script helps configure the server for GitHub Actions deployment

set -e

echo "======================================"
echo "TesoTunes Deployment Setup"
echo "======================================"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
APP_PATH="/var/www/tesotunes"
SSH_KEY_NAME="github_deploy"
SSH_KEY_PATH="$HOME/.ssh/$SSH_KEY_NAME"

echo "Step 1: Generating SSH Key for GitHub Actions"
echo "--------------------------------------"

if [ -f "$SSH_KEY_PATH" ]; then
    echo -e "${YELLOW}SSH key already exists at $SSH_KEY_PATH${NC}"
    read -p "Do you want to generate a new one? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Using existing key..."
    else
        rm -f "$SSH_KEY_PATH" "$SSH_KEY_PATH.pub"
        ssh-keygen -t ed25519 -C "github-actions-deploy" -f "$SSH_KEY_PATH" -N ""
        echo -e "${GREEN}New SSH key generated!${NC}"
    fi
else
    ssh-keygen -t ed25519 -C "github-actions-deploy" -f "$SSH_KEY_PATH" -N ""
    echo -e "${GREEN}SSH key generated!${NC}"
fi

echo ""
echo "Step 2: Adding Public Key to Authorized Keys"
echo "--------------------------------------"

if ! grep -q "$(cat $SSH_KEY_PATH.pub)" ~/.ssh/authorized_keys 2>/dev/null; then
    cat "$SSH_KEY_PATH.pub" >> ~/.ssh/authorized_keys
    chmod 600 ~/.ssh/authorized_keys
    echo -e "${GREEN}Public key added to authorized_keys${NC}"
else
    echo -e "${YELLOW}Public key already in authorized_keys${NC}"
fi

echo ""
echo "Step 3: Server Information"
echo "--------------------------------------"

SERVER_IP=$(hostname -I | awk '{print $1}')
SERVER_HOSTNAME=$(hostname)
SSH_PORT=$(ss -tlnp | grep sshd | grep -oP ':\K[0-9]+' | head -1 || echo "22")

echo "Server IP: $SERVER_IP"
echo "Hostname: $SERVER_HOSTNAME"
echo "SSH Port: $SSH_PORT"
echo "SSH User: $(whoami)"
echo "App Path: $APP_PATH"

echo ""
echo "Step 4: Checking Required Services"
echo "--------------------------------------"

# Check PHP
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -1 | awk '{print $2}')
    echo -e "${GREEN}✓${NC} PHP: $PHP_VERSION"
else
    echo -e "${RED}✗${NC} PHP: Not installed"
fi

# Check Composer
if command -v composer &> /dev/null; then
    COMPOSER_VERSION=$(COMPOSER_ALLOW_SUPERUSER=1 composer --version 2>/dev/null | head -1 | awk '{print $3}')
    echo -e "${GREEN}✓${NC} Composer: $COMPOSER_VERSION"
else
    echo -e "${RED}✗${NC} Composer: Not installed"
fi

# Check Node
if command -v node &> /dev/null; then
    NODE_VERSION=$(node -v)
    echo -e "${GREEN}✓${NC} Node.js: $NODE_VERSION"
else
    echo -e "${RED}✗${NC} Node.js: Not installed"
fi

# Check NPM
if command -v npm &> /dev/null; then
    NPM_VERSION=$(npm -v)
    echo -e "${GREEN}✓${NC} NPM: $NPM_VERSION"
else
    echo -e "${RED}✗${NC} NPM: Not installed"
fi

# Check Git
if command -v git &> /dev/null; then
    GIT_VERSION=$(git --version | awk '{print $3}')
    echo -e "${GREEN}✓${NC} Git: $GIT_VERSION"
else
    echo -e "${RED}✗${NC} Git: Not installed"
fi

# Check MySQL
if command -v mysql &> /dev/null; then
    echo -e "${GREEN}✓${NC} MySQL: Installed"
elif systemctl is-active --quiet mysql || systemctl is-active --quiet mariadb; then
    echo -e "${GREEN}✓${NC} MySQL/MariaDB: Running"
else
    echo -e "${YELLOW}⚠${NC} MySQL: Not detected"
fi

# Check Redis
if command -v redis-cli &> /dev/null; then
    if redis-cli ping &> /dev/null; then
        echo -e "${GREEN}✓${NC} Redis: Running"
    else
        echo -e "${YELLOW}⚠${NC} Redis: Installed but not running"
    fi
else
    echo -e "${YELLOW}⚠${NC} Redis: Not installed (optional)"
fi

# Check Supervisor
if command -v supervisorctl &> /dev/null; then
    echo -e "${GREEN}✓${NC} Supervisor: Installed"
else
    echo -e "${YELLOW}⚠${NC} Supervisor: Not installed (recommended)"
fi

# Check web server
if systemctl is-active --quiet nginx; then
    echo -e "${GREEN}✓${NC} Nginx: Running"
elif systemctl is-active --quiet apache2; then
    echo -e "${GREEN}✓${NC} Apache: Running"
else
    echo -e "${YELLOW}⚠${NC} Web Server: Not detected"
fi

echo ""
echo "Step 5: Directory Permissions"
echo "--------------------------------------"

if [ -d "$APP_PATH" ]; then
    echo "Setting permissions for $APP_PATH..."
    
    # Set ownership (if running as root)
    if [ "$(id -u)" -eq 0 ]; then
        chown -R www-data:www-data "$APP_PATH"
        echo -e "${GREEN}✓${NC} Ownership set to www-data:www-data"
    else
        echo -e "${YELLOW}⚠${NC} Not running as root, skipping ownership change"
    fi
    
    # Set permissions
    chmod -R 755 "$APP_PATH"
    chmod -R 775 "$APP_PATH/storage" 2>/dev/null || true
    chmod -R 775 "$APP_PATH/bootstrap/cache" 2>/dev/null || true
    echo -e "${GREEN}✓${NC} Permissions set"
else
    echo -e "${RED}✗${NC} App directory not found: $APP_PATH"
fi

echo ""
echo "======================================"
echo "GitHub Secrets Configuration"
echo "======================================"
echo ""
echo "Add these secrets to your GitHub repository:"
echo "https://github.com/TesoTunes/tesotunes/settings/secrets/actions"
echo ""

echo -e "${GREEN}SERVER_HOST:${NC}"
echo "$SERVER_IP"
echo ""

echo -e "${GREEN}SERVER_USER:${NC}"
echo "$(whoami)"
echo ""

echo -e "${GREEN}SERVER_PORT:${NC}"
echo "$SSH_PORT"
echo ""

echo -e "${GREEN}APP_PATH:${NC}"
echo "$APP_PATH"
echo ""

echo -e "${GREEN}SERVER_SSH_KEY:${NC}"
echo "Copy the entire content below (including BEGIN and END lines):"
echo "--------------------------------------"
cat "$SSH_KEY_PATH"
echo "--------------------------------------"
echo ""

echo -e "${YELLOW}IMPORTANT: Keep this private key secure!${NC}"
echo ""

echo "======================================"
echo "Testing SSH Connection"
echo "======================================"
echo ""
echo "Test the SSH connection with:"
echo "ssh -i $SSH_KEY_PATH $(whoami)@$SERVER_IP"
echo ""

echo "From your local machine, test with:"
echo "ssh -i /path/to/downloaded/private/key $(whoami)@$SERVER_IP"
echo ""

echo "======================================"
echo "Next Steps"
echo "======================================"
echo ""
echo "1. Copy the SERVER_SSH_KEY value above"
echo "2. Add all secrets to GitHub: https://github.com/TesoTunes/tesotunes/settings/secrets/actions"
echo "3. Ensure .env file is configured for production"
echo "4. Push to main branch to trigger deployment"
echo "5. Monitor deployment at: https://github.com/TesoTunes/tesotunes/actions"
echo ""

echo -e "${GREEN}Setup complete!${NC}"
echo ""

# Save configuration to file
CONFIG_FILE="$HOME/github-deployment-config.txt"
cat > "$CONFIG_FILE" << EOF
TesoTunes GitHub Actions Deployment Configuration
Generated: $(date)

GitHub Secrets:
--------------
SERVER_HOST=$SERVER_IP
SERVER_USER=$(whoami)
SERVER_PORT=$SSH_PORT
APP_PATH=$APP_PATH

SSH Private Key Location: $SSH_KEY_PATH
SSH Public Key Location: $SSH_KEY_PATH.pub

Setup Commands:
--------------
Test SSH: ssh -i $SSH_KEY_PATH $(whoami)@$SERVER_IP
View Logs: tail -f $APP_PATH/storage/logs/laravel.log
Restart Workers: sudo supervisorctl restart tesotunes-worker:*
EOF

echo -e "Configuration saved to: ${GREEN}$CONFIG_FILE${NC}"
