# Intake License Server

Laravel application powering `license.intake.works` — license validation,
plugin update delivery, install tracking, and admin panel.

---

## What this does

| Endpoint | Purpose |
|---|---|
| `POST /api/v1/ping` | Free installs phone home (no key required) |
| `POST /api/v1/activate` | Premium key activation |
| `POST /api/v1/deactivate` | Remove a site activation |
| `GET /api/v1/check` | Validate key + return feature flags |
| `GET /api/v1/update` | WordPress update check (Update URI) |
| `GET /api/v1/download/{key}` | Serve signed plugin ZIP to licensees |
| `/admin` | Filament control panel |
| `/health` | Uptime monitoring endpoint |

---

## Stack

- **PHP 8.2+**, Laravel 11
- **PostgreSQL** (on the same Droplet, or managed DB)
- **Redis** (cache + sessions + queues)
- **Nginx** + PHP-FPM
- **Filament 3** admin panel

---

## Droplet setup (DigitalOcean)

### 1. Create the Droplet

- Image: **Ubuntu 24.04 LTS**
- Size: **Basic, 2 GB RAM / 1 vCPU / 50 GB SSD** (~$12/mo)
- Add your SSH key during creation
- Enable the **DigitalOcean firewall**: allow ports 22, 80, 443

### 2. Initial server setup

```bash
ssh root@YOUR_DROPLET_IP

# Create a deploy user
adduser deploy
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy

# Switch to deploy user for the rest
su - deploy
```

### 3. Install PHP 8.2, Nginx, PostgreSQL, Redis

```bash
sudo apt update && sudo apt upgrade -y

# PHP
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.2 php8.2-fpm php8.2-pgsql php8.2-redis \
  php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath \
  php8.2-intl php8.2-tokenizer

# Nginx
sudo apt install -y nginx

# PostgreSQL
sudo apt install -y postgresql postgresql-contrib

# Redis
sudo apt install -y redis-server
sudo systemctl enable redis-server

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 4. Set up PostgreSQL

```bash
sudo -u postgres psql

CREATE USER intake WITH PASSWORD 'your_strong_password';
CREATE DATABASE intake_license OWNER intake;
\q
```

### 5. Deploy the application

```bash
cd /var/www
sudo mkdir intake-license
sudo chown deploy:deploy intake-license

# Upload the project (from your local machine)
# scp -r intake-license/ deploy@YOUR_IP:/var/www/intake-license/
# Or clone from your private repo:
# git clone git@github.com:yourorg/intake-license.git /var/www/intake-license

cd /var/www/intake-license
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

### 6. Configure .env

```bash
nano .env
```

Fill in at minimum:
```
APP_URL=https://license.intake.works
DB_PASSWORD=your_strong_password
ADMIN_EMAIL=you@intake.works
ADMIN_PASSWORD=your_admin_password
PLUGIN_ZIP_PATH=/var/www/intake-license/storage/app/intake.zip
```

### 7. Run migrations and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

### 8. Set storage permissions

```bash
sudo chown -R www-data:www-data /var/www/intake-license/storage
sudo chown -R www-data:www-data /var/www/intake-license/bootstrap/cache
sudo chmod -R 775 /var/www/intake-license/storage
sudo chmod -R 775 /var/www/intake-license/bootstrap/cache
```

### 9. Configure Nginx

```bash
sudo nano /etc/nginx/sites-available/intake-license
```

Paste:
```nginx
server {
    listen 80;
    server_name license.intake.works;
    root /var/www/intake-license/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/intake-license /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 10. SSL with Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d license.intake.works
```

### 11. Queue worker (for future async jobs)

```bash
sudo nano /etc/supervisor/conf.d/intake-worker.conf
```

```ini
[program:intake-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/intake-license/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/intake-license/storage/logs/worker.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start intake-worker:*
```

### 12. Cron (Laravel scheduler)

```bash
sudo crontab -e -u www-data
```

Add:
```
* * * * * cd /var/www/intake-license && php artisan schedule:run >> /dev/null 2>&1
```

---

## Uploading plugin ZIPs

When you ship a new plugin release:

1. Build the ZIP locally
2. Upload to the server:
   ```bash
   scp intake.zip deploy@YOUR_IP:/var/www/intake-license/storage/app/intake.zip
   ```
3. Update `PLUGIN_VERSION` in `.env` and bump `CURRENT_VERSION` in `UpdateController.php`
4. Run `php artisan config:cache` to pick up the new env value

---

## Admin panel

Visit `https://license.intake.works/admin` and log in with the
`ADMIN_EMAIL` / `ADMIN_PASSWORD` you set in `.env`.

From there you can:
- Create customers and issue license keys
- See all free + premium installs
- Suspend, cancel, or regenerate keys
- View the full audit log per license

---

## WordPress plugin integration

The plugin needs three additions (covered separately):

1. **Ping on activation** — call `POST /api/v1/ping` on `register_activation_hook`
2. **License settings page** — field to enter key, calls `/api/v1/activate`
3. **Update URI** — already set to `https://license.intake.works` in `intake.php` header;
   WordPress will automatically call `GET /api/v1/update` when checking for updates

---

## DNS

Point an A record for `license.intake.works` → your Droplet IP before running certbot.

---

## Subdomain routing (wildcard DNS + Nginx)

### DNS setup

Add these records in your DigitalOcean DNS panel:

```
A    intake.works          → YOUR_DROPLET_IP
A    *.intake.works        → YOUR_DROPLET_IP
A    app.intake.works      → YOUR_DROPLET_IP
A    license.intake.works  → YOUR_DROPLET_IP
```

The wildcard `*.intake.works` catches all tenant subdomains automatically.

### Nginx config (replace the single-domain config)

Create `/etc/nginx/sites-available/intake`:

```nginx
# Catch-all server block — handles intake.works + all subdomains + custom domains
server {
    listen 80;
    server_name intake.works *.intake.works;
    root /var/www/intake-license/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### SSL with wildcard certificate

Wildcard SSL covers `*.intake.works` in one cert:

```bash
certbot certonly --manual --preferred-challenges dns \
  -d intake.works -d *.intake.works

# Follow the DNS TXT record instructions, then:
certbot install --cert-name intake.works
```

### Custom domain tenants

For tenants on Branded/Custom tiers with their own domain (e.g. `book.spokescycles.com`):

1. The tenant adds a CNAME record: `book.spokescycles.com → intake.works`
2. You provision SSL for their domain:
   ```bash
   certbot --nginx -d book.spokescycles.com
   ```
3. Add their domain to Nginx:
   ```nginx
   server_name book.spokescycles.com;
   ```
   Or use a dynamic Nginx config approach for automation.

The `ResolveTenant` middleware handles the routing — it looks up the custom domain in `tenants.custom_domain` and loads the correct tenant context.

---

## Environment variables added

| Variable | Description |
|----------|-------------|
| `APP_DOMAIN` | Root domain (default: `intake.works`) |
| `PLAN_PRICE_STARTER` | Starter plan monthly price in cents |
| `PLAN_PRICE_BRANDED` | Branded plan monthly price in cents |
| `PLAN_PRICE_SCALE` | Scale plan monthly price in cents |
| `PLAN_PRICE_BRANDED` | Branded plan monthly price in cents |
| `PLAN_PRICE_CUSTOM` | Custom plan monthly price in cents |
| `ONBOARDING_FEE_CENTS` | One-time onboarding fee in cents |
