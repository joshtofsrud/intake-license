# Intake — Deployment Guide

## First deploy (run once)

### 1. Create a DigitalOcean Droplet

- **Image:** Ubuntu 24.04 LTS
- **Size:** Basic — 2 vCPU / 4 GB RAM ($24/mo is fine to start)
- **Add your SSH key** during creation

### 2. Point DNS at the Droplet

In your DNS provider (Cloudflare recommended):

| Type | Name         | Value         |
|------|--------------|---------------|
| A    | `@`          | `YOUR_IP`     |
| A    | `*`          | `YOUR_IP`     |

The wildcard `*` record handles every `tenant.intake.works` subdomain automatically.

> If using Cloudflare, set the wildcard record to **DNS only** (grey cloud), not proxied. The wildcard SSL cert needs to reach your server directly.

### 3. Get a Cloudflare API token

Needed for automatic wildcard SSL cert issuance and renewal.

1. Cloudflare dashboard → My Profile → API Tokens → Create Token
2. Use the **Edit zone DNS** template
3. Scope it to your `intake.works` zone
4. Copy the token — you'll need it below

### 4. Generate a Laravel app key

```bash
# On your local machine (requires PHP)
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
# Or if you have the repo cloned:
php artisan key:generate --show
```

### 5. Run the setup script

SSH into your droplet as root, then:

```bash
curl -fsSL https://raw.githubusercontent.com/YOUR_ORG/intake-license/main/deploy/setup.sh | \
  DOMAIN=intake.works \
  REPO=git@github.com:YOUR_ORG/intake-license.git \
  DB_PASSWORD="$(openssl rand -base64 24)" \
  APP_KEY="base64:YOUR_KEY_HERE" \
  CF_API_TOKEN="YOUR_CLOUDFLARE_TOKEN" \
  bash
```

The script will:
- Install Nginx, PHP 8.3, MySQL, Redis, Supervisor, Certbot
- Create a `deployer` system user
- Set up the MySQL database
- Clone your repo and run migrations
- Write the `.env` file
- Issue a wildcard SSL cert via Cloudflare DNS challenge
- Configure Nginx with HTTPS + HTTP→HTTPS redirect
- Start the queue worker and scheduler via Supervisor
- Enable UFW firewall (SSH + HTTPS only)

**Total time: ~10 minutes** (mostly waiting for DNS propagation during cert issuance).

### 6. Set up the sudoers file

```bash
sudo cp /var/www/intake/deploy/sudoers-deployer /etc/sudoers.d/intake-deployer
sudo chmod 440 /etc/sudoers.d/intake-deployer
```

### 7. Configure the `.env` file

The setup script writes a minimal `.env`. Edit it to add mail credentials:

```bash
nano /var/www/intake/.env
```

Key values to add:

```env
# Mail — pick one provider
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.intake.works
MAILGUN_SECRET=key-XXXXXX

# Or Postmark
# MAIL_MAILER=postmark
# POSTMARK_TOKEN=XXXXXX

# Or SMTP
# MAIL_MAILER=smtp
# MAIL_HOST=smtp.yourprovider.com
# MAIL_PORT=587
# MAIL_USERNAME=you@intake.works
# MAIL_PASSWORD=XXXXXX
# MAIL_ENCRYPTION=tls
```

After editing, rebuild the config cache:

```bash
php /var/www/intake/artisan config:cache
```

---

## GitHub Actions (continuous deployment)

Every push to `main` automatically deploys to production.

### Add GitHub repository secrets

Go to your repo → **Settings → Secrets → Actions → New repository secret**

| Secret name       | Value |
|-------------------|-------|
| `DEPLOY_HOST`     | Your Droplet IP address |
| `DEPLOY_USER`     | `deployer` |
| `DEPLOY_SSH_KEY`  | Your **private** SSH key (the one authorized on the server) |

To get your private key:

```bash
cat ~/.ssh/id_ed25519   # or id_rsa — whatever you added to the Droplet
```

Copy the entire output including `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----`.

### How it works

On every push to `main`, the workflow:

1. SSHes into the server as `deployer`
2. `git pull` — fetches and resets to latest `main`
3. `composer install` — updates dependencies
4. `php artisan down` — puts the site into maintenance mode (shows a friendly page, bypass with `?secret=intake-deploy-bypass`)
5. `php artisan migrate --force` — runs any new migrations
6. Clears and rebuilds all caches
7. `php artisan queue:restart` — gracefully restarts workers on the new code
8. `php artisan up` — takes the site out of maintenance mode
9. Reloads PHP-FPM

**Total deploy time: ~45 seconds.**

---

## Custom domains for tenants

When a Branded/Custom tenant sets a custom domain (e.g. `book.theirshop.com`):

1. They point a `CNAME` from `book.theirshop.com` → `intake.works`
2. Laravel's `ResolveTenant` middleware already handles the routing
3. You issue a cert for their domain:

```bash
certbot --nginx -d book.theirshop.com --non-interactive --agree-tos -m hello@intake.works
```

To automate this, create a queued job that runs when a custom domain is saved in the tenant settings. The job runs the certbot command above via `Process::run()`.

---

## Useful commands post-deploy

```bash
# Tail application logs
tail -f /var/www/intake/storage/logs/laravel.log

# Tail queue worker logs
tail -f /var/www/intake/storage/logs/worker.log

# Restart queue workers manually
supervisorctl restart intake-worker:*

# Check supervisor status
supervisorctl status

# Run migrations manually
php /var/www/intake/artisan migrate

# Put site into maintenance mode manually
php /var/www/intake/artisan down
php /var/www/intake/artisan up

# Clear all caches
php /var/www/intake/artisan optimize:clear

# Check SSL cert expiry
certbot certificates
```

---

## Monitoring checklist (before launch)

- [ ] `https://intake.works` loads correctly
- [ ] `https://app.intake.works/signup` loads correctly
- [ ] SSL cert covers `intake.works` and `*.intake.works`
- [ ] Create a test tenant — confirm subdomain routes correctly
- [ ] Book a test appointment — confirm confirmation email arrives
- [ ] Test Stripe webhook from Stripe dashboard → Webhooks → Send test event
- [ ] Check queue workers are running: `supervisorctl status`
- [ ] Verify log rotation is configured: `cat /etc/logrotate.d/intake` (add if missing)
