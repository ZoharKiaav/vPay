# vPay Through Stacker Deployment Checkpoint

## Purpose

This document records the current vPay deployment milestone after moving away from the temporary/manual install path and deploying vPay through Stacker.

The goal is to keep vPay as a Stacker-managed application, not as a permanently manual sibling container.

## Current Status

```text
vPay GHCR image exists
vPay deployed through Stacker
vPay database container running
vPay Redis/cache container running
vPay public domain routes through Stacker/Traefik
HTTPS certificate is valid
Admin login works
Normal browser issue was local browser cache/site-data related
```

## Current Architecture

```text
Public Internet
    ↓
dokploy-traefik / Stacker routing
    ↓
vPay Docker Compose application managed by Stacker
    ↓
vPay app container + MariaDB + Redis/cache
```

## Important Decision

The earlier manual path is no longer the preferred production direction:

```text
/opt/vpay
manual docker compose
manual edge-traefik
manual HTTPS route
```

The preferred direction is:

```text
Install Stacker first
Deploy vPay through Stacker
Let Stacker/Traefik manage domain routing and HTTPS
Use vPay for billing, customer lifecycle, products, invoices, payments, and future provisioning integration
```

## vPay Stack Components

```text
vPay application image: ghcr.io/zoharkiaav/vpay:latest
MariaDB database
Redis cache
Persistent database volume
Persistent storage volume
Persistent logs volume
Persistent public storage volume
```

## Setup Commands Used Inside The vPay Container

```bash
php artisan optimize:clear
php artisan app:init
php artisan db:seed --class=CustomPropertySeeder
php artisan app:user:create
php artisan optimize:clear
```

The vPay image is minimal, so use `sh` instead of `bash` if entering the container manually.

## Final vPay URL

```text
https://vpay.proniit.com
```

## Product Direction

vPay is now the preferred billing/client-area layer over the previous FOSSBilling direction.

Planned future work:

```text
Yoco payment gateway adapter for vPay
Hestia Control Panel integration for traditional hosting
Stacker provisioning integration for VPStacks
Product catalogue setup inside vPay
Branding/sidebar/link cleanup in Stacker and vPay
Potential authentication layer research later, such as Better Auth or Logto
```

## Operating Rule

```text
Keep forked projects close to upstream.
Avoid temporary production architecture unless explicitly marked temporary.
Use Stacker as the deployment platform.
Use vPay as the billing/customer lifecycle layer.
Document each proven layer before adding the next one.
```