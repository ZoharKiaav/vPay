#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${VPAY_APP_DIR:-/opt/vpay}"
IMAGE="${VPAY_IMAGE:-ghcr.io/zoharkiaav/vpay:latest}"
PORT="${VPAY_PORT:-8088}"
PROJECT_NAME="${VPAY_PROJECT_NAME:-vpay}"

echo "=================================================="
echo " vPay installer v0.2.1"
echo "=================================================="
echo
echo "Install directory : ${APP_DIR}"
echo "Docker image      : ${IMAGE}"
echo "HTTP test port    : ${PORT}"
echo "Compose project   : ${PROJECT_NAME}"
echo

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: Please run this installer as root or with sudo."
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: Docker is not installed or not available in PATH."
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: Docker Compose plugin is not available."
  exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
  echo "ERROR: openssl is required but not installed."
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "ERROR: curl is required but not installed."
  exit 1
fi

if [ -e "${APP_DIR}/docker-compose.yml" ]; then
  echo "ERROR: ${APP_DIR}/docker-compose.yml already exists."
  echo "This looks like an existing vPay installation."
  echo
  echo "To reinstall manually:"
  echo "  cd ${APP_DIR}"
  echo "  docker compose down"
  echo "  mv ${APP_DIR} ${APP_DIR}-backup-\$(date +%Y%m%d-%H%M%S)"
  exit 1
fi

if command -v ss >/dev/null 2>&1; then
  if ss -ltn | awk '{print $4}' | grep -Eq "(:|\\])${PORT}$"; then
    echo "ERROR: Port ${PORT} is already in use."
    echo
    echo "Choose another port like this:"
    echo "  VPAY_PORT=8090 bash install.sh"
    exit 1
  fi
fi

echo "Detecting public IPv4 address..."
PUBLIC_IPV4="$(curl -4 -s --max-time 10 ifconfig.me || true)"

if [ -z "${PUBLIC_IPV4}" ]; then
  PUBLIC_IPV4="$(hostname -I | awk '{print $1}' || true)"
fi

if [ -z "${PUBLIC_IPV4}" ]; then
  echo "WARNING: Could not detect public IPv4. Falling back to localhost."
  PUBLIC_IPV4="127.0.0.1"
fi

APP_URL="http://${PUBLIC_IPV4}:${PORT}"

echo "Using initial APP_URL: ${APP_URL}"
echo

echo "Creating install directory..."
mkdir -p "${APP_DIR}"
cd "${APP_DIR}"

MYSQL_PASSWORD="$(openssl rand -base64 40 | tr -dc 'A-Za-z0-9' | head -c 24)"
MYSQL_ROOT_PASSWORD="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32)"
APP_KEY="base64:$(openssl rand -base64 32)"

echo "Writing ${APP_DIR}/.env..."

cat > .env <<ENV_FILE
APP_ENV=production
APP_DEBUG=false

MYSQL_DATABASE=paymenter
MYSQL_USER=paymenter
MYSQL_PASSWORD=${MYSQL_PASSWORD}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}

APP_URL=${APP_URL}
APP_KEY=${APP_KEY}
ENV_FILE

chmod 600 .env

echo "Writing ${APP_DIR}/docker-compose.yml..."

cat > docker-compose.yml <<COMPOSE_FILE
name: ${PROJECT_NAME}

services:
  database:
    image: mariadb:lts
    restart: unless-stopped
    command: --default-authentication-plugin=mysql_native_password
    environment:
      MYSQL_ROOT_PASSWORD: \${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: \${MYSQL_DATABASE:-paymenter}
      MYSQL_USER: \${MYSQL_USER:-paymenter}
      MYSQL_PASSWORD: \${MYSQL_PASSWORD}
    volumes:
      - vpay-database:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10

  cache:
    image: redis:alpine
    restart: unless-stopped
    volumes:
      - vpay-redis:/data

  vpay:
    image: ${IMAGE}
    restart: unless-stopped
    depends_on:
      database:
        condition: service_healthy
      cache:
        condition: service_started
    ports:
      - "${PORT}:80"
    environment:
      APP_ENV: \${APP_ENV:-production}
      APP_DEBUG: \${APP_DEBUG:-false}
      APP_KEY: \${APP_KEY}
      APP_URL: \${APP_URL}

      DB_CONNECTION: mysql
      DB_HOST: database
      DB_PORT: 3306
      DB_DATABASE: \${MYSQL_DATABASE:-paymenter}
      DB_USERNAME: \${MYSQL_USER:-paymenter}
      DB_PASSWORD: \${MYSQL_PASSWORD}

      CACHE_STORE: redis
      REDIS_HOST: cache
      REDIS_PORT: 6379

      TRUSTED_PROXIES: "*"
    volumes:
      - vpay-storage:/app/var
      - vpay-logs:/app/storage/logs
      - vpay-public:/app/storage/app/public

volumes:
  vpay-database:
  vpay-redis:
  vpay-storage:
  vpay-logs:
  vpay-public:
COMPOSE_FILE

echo
echo "Pulling vPay stack images..."
docker compose pull

echo
echo "Starting vPay..."
docker compose up -d

echo
echo "Waiting for containers to settle..."
sleep 12

echo
echo "Container status:"
docker compose ps

echo
echo "HTTP check:"
curl -I "http://localhost:${PORT}" || true

echo
echo "=================================================="
echo " vPay base installation complete"
echo "=================================================="
echo
echo "vPay files were installed to:"
echo "  ${APP_DIR}"
echo
echo "Temporary setup URL:"
echo "  ${APP_URL}"
echo
echo "Now run these setup commands:"
echo
echo "  cd ${APP_DIR}"
echo "  docker compose exec vpay php artisan optimize:clear"
echo "  docker compose exec vpay php artisan app:init"
echo "  docker compose exec vpay php artisan db:seed --class=CustomPropertySeeder"
echo "  docker compose exec vpay php artisan app:user:create"
echo
echo "When app:init asks for the application URL, use:"
echo "  ${APP_URL}"
echo
echo "After setup, open:"
echo "  ${APP_URL}"
echo
echo "Helpful commands:"
echo "  cd ${APP_DIR} && docker compose ps"
echo "  cd ${APP_DIR} && docker compose logs --tail=100 vpay"
echo "  cd ${APP_DIR} && docker compose restart vpay"
echo
echo "Later, when HTTPS/domain routing is ready, rerun:"
echo "  cd ${APP_DIR}"
echo "  docker compose exec vpay php artisan app:init"
echo
echo "and set the URL to your final domain, for example:"
echo "  https://vpay.example.com"
echo