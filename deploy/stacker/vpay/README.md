# Deploy vPay Through Stacker

This folder contains the Stacker-managed Docker Compose deployment definition for vPay.

## Purpose

vPay should be deployed through Stacker instead of being installed manually as a sibling Docker Compose application.

## Stack Components

- vPay application image: `ghcr.io/zoharkiaav/vpay:latest`
- MariaDB database
- Redis cache
- Persistent volumes for database, cache, app storage, logs, and public storage

## Domain Setup In Stacker

After adding the Docker Compose stack in Stacker, configure the domain through Stacker's Domains section:

Domain: vpay.example.com
Service: vpay
Port: 80
Path: /
HTTPS: enabled

For the Proniit/vKloud deployment, the intended domain is:

vpay.proniit.com

## Required Environment Variables

Copy `.env.example` and replace placeholder values inside Stacker's environment variable section.

Generate values with:

bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "MYSQL_PASSWORD=$(openssl rand -base64 40 | tr -dc 'A-Za-z0-9' | head -c 24)"
echo "MYSQL_ROOT_PASSWORD=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32)"

## First-Run Setup

After deployment, enter the vPay container with `sh` and run:

bash
php artisan optimize:clear
php artisan app:init
php artisan db:seed --class=CustomPropertySeeder
php artisan app:user:create
php artisan optimize:clear


During `app:init`, use the final HTTPS domain.

Example:

https://vpay.proniit.com

## Admin Access

The normal login path is:

/login

After login, admin access is available from the user/profile dropdown.