# Deploying BOC Visa Portal (AWS me-south-1)

## One-time AWS setup
1. Enable the me-south-1 (Bahrain) region on the AWS account (opt-in region).
2. S3: create `boc-visa-files` and `boc-visa-backups`; Block Public Access ON; default encryption SSE-KMS;
   lifecycle rule on `boc-visa-backups`: expire objects after 30 days.
   - **Default SSE-KMS encryption MUST be enabled on `boc-visa-backups`.** The `backups` disk sets no SSE option itself, so backups are encrypted only by the bucket's default encryption. Confirm it under the bucket's Properties → Default encryption before the first `db:backup`.
3. IAM role for EC2 with s3:GetObject/PutObject/DeleteObject/ListBucket on both buckets, kms:Encrypt/Decrypt on the key, ses:SendRawEmail.
4. SES (me-south-1): verify the sending domain (DKIM), request production access.
5. EC2: Ubuntu 24.04, t3.medium, 30 GB gp3 encrypted EBS, attach the IAM role. Security group: 80/443 from anywhere, 22 from BOC IPs only.
6. Data Lifecycle Manager: daily EBS snapshot policy, keep 7.

## Server packages
    sudo apt update && sudo apt install -y nginx mysql-server redis-server tesseract-ocr supervisor certbot python3-certbot-nginx unzip git \
      php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis
    # composer: https://getcomposer.org/download/ ; node 20 for the build
    # php8.3-redis is required because QUEUE_CONNECTION, CACHE_STORE and SESSION_DRIVER are all redis.

## MySQL (localhost only)
    sudo mysql -e "CREATE DATABASE visa; CREATE USER 'visa'@'localhost' IDENTIFIED BY '<strong>'; GRANT ALL ON visa.* TO 'visa'@'localhost';"
    # /etc/mysql/mysql.conf.d/mysqld.cnf → bind-address = 127.0.0.1

## App
    sudo git clone https://github.com/er-athulbaby/visa.git /var/www/visa && cd /var/www/visa
    cp deploy/.env.production.example .env   # fill in secrets
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan key:generate && php artisan migrate --force
    php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan filament:optimize
    sudo chown -R www-data:www-data storage bootstrap/cache

### Trusted proxies (only if a load balancer or CloudFront sits in front of EC2)
The plain single-EC2 nginx setup in this runbook needs no change.
If an AWS ALB or CloudFront is in front of EC2, configure Laravel's trusted proxies in `bootstrap/app.php` **before** setting `ADMIN_IP_ALLOWLIST`. Otherwise `$request->ip()` is the proxy's address and the allowlist blocks everyone:

    ->withMiddleware(fn ($m) => $m->trustProxies(at: '<ALB CIDR or *>'))

## Web, queue, scheduler
    sudo cp deploy/nginx.conf /etc/nginx/sites-available/visa && sudo ln -s /etc/nginx/sites-available/visa /etc/nginx/sites-enabled/
    sudo certbot --nginx -d visa.example.bh
    sudo cp deploy/supervisor.conf /etc/supervisor/conf.d/visa.conf && sudo supervisorctl reread && sudo supervisorctl update
    echo "* * * * * www-data cd /var/www/visa && php artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/visa

## First admin
    php artisan make:filament-user        # name, email, password
    php artisan visa:make-admin admin@boc.bh
    # log in at /admin and set up 2FA

## Tap
    Set TAP_SECRET_KEY (live key only after sandbox sign-off). In the Tap dashboard, no webhook URL is needed —
    each charge sends `post.url` = https://visa.example.bh/webhooks/tap.

## Verify after deploy
- `php artisan schedule:list` shows payments:reconcile (every 10 min), files:purge (02:00), db:backup (01:00).
- `php artisan db:backup` → object appears in s3://boc-visa-backups/mysql/
- Tap sandbox payment end-to-end: pay with a Tap test card and confirm the webhook signature is accepted. Check the logs for `/webhooks/tap`: there must be no 403. Only after this passes, switch TAP_SECRET_KEY to the live key.
- Register → add applicant (OCR fills fields from a real passport photo) → pay with Tap test card → invoice email arrives
- Processor: claim → applied → issue visa → applicant receives visa email
- `curl -I https://visa.example.bh/admin` from a non-allowlisted IP returns 403 (if allowlist set)

## Updating
    cd /var/www/visa && git pull && composer install --no-dev -o && npm ci && npm run build \
      && php artisan migrate --force && php artisan optimize && sudo supervisorctl restart visa-queue:*
