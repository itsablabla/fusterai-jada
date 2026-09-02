#!/usr/bin/env bash
# FusterAI Railway entrypoint: bootstraps storage on a fresh volume, runs
# migrations, generates OAuth keys, primes caches, then hands off to supervisord.
set -euo pipefail

cd /var/www/html

# ── Storage skeleton (volume mount overlays ./storage on first boot) ──────────
if [ ! -d storage/framework/cache/data ] || [ ! -d storage/app/public ]; then
  echo "[entrypoint] hydrating storage skeleton onto volume…"
  cp -Rn /var/www/storage-skeleton/. storage/
fi
chown -R www-data:www-data storage bootstrap/cache

# ── Template PORT into nginx.conf ────────────────────────────────────────────
PORT="${PORT:-8000}"
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf

# ── Passport keys (persist on the storage volume) ────────────────────────────
if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
  echo "[entrypoint] generating Passport OAuth keys…"
  gosu www-data php artisan passport:keys --force || true
fi

# ── Wait for the database ─────────────────────────────────────────────────────
echo "[entrypoint] waiting for database…"
for i in $(seq 1 60); do
  if gosu www-data php artisan db:show >/dev/null 2>&1; then
    echo "[entrypoint] database ready"; break
  fi
  sleep 2
done

# ── Migrations ────────────────────────────────────────────────────────────────
echo "[entrypoint] running migrations…"
gosu www-data php artisan migrate --force

# ── Storage symlink for public uploads ───────────────────────────────────────
gosu www-data php artisan storage:link --force >/dev/null 2>&1 || true

# ── Optional: fresh migrate (wipes DB) ───────────────────────────────────────
if [ "${FUSTERAI_FRESH_MIGRATE:-false}" = "true" ]; then
  echo "[entrypoint] FUSTERAI_FRESH_MIGRATE=true — dropping all tables and re-migrating…"
  gosu www-data php artisan migrate:fresh --force
fi

# ── Optional admin bootstrap (opt-in, non-interactive) ───────────────────────
# Set FUSTERAI_BOOTSTRAP_ADMIN_EMAIL, _PASSWORD, _NAME (and optional _WORKSPACE)
# to create the first workspace + admin without opening /register in a browser.
# The values are read via getenv() inside PHP, never printed. Unset the vars
# after the first boot to prevent recreating on redeploys.
if [ -n "${FUSTERAI_BOOTSTRAP_ADMIN_EMAIL:-}" ] && [ -n "${FUSTERAI_BOOTSTRAP_ADMIN_PASSWORD:-}" ]; then
  echo "[entrypoint] bootstrapping admin (${FUSTERAI_BOOTSTRAP_ADMIN_EMAIL})…"
  gosu www-data php /var/www/html/docker/railway/bootstrap-admin.php || echo "[entrypoint] admin bootstrap failed (see error above)"
fi

# ── Optional mailbox bootstrap (opt-in) ───────────────────────────────────────
# FUSTERAI_BOOTSTRAP_MAILBOX_EMAIL / _PASSWORD / _NAME plus IMAP/SMTP host, port,
# encryption (ssl|tls). Creates one email mailbox in the first workspace and grants
# every existing user access. Skips if a mailbox with that email already exists.
if [ -n "${FUSTERAI_BOOTSTRAP_MAILBOX_EMAIL:-}" ] && [ -n "${FUSTERAI_BOOTSTRAP_MAILBOX_PASSWORD:-}" ]; then
  echo "[entrypoint] bootstrapping mailbox (${FUSTERAI_BOOTSTRAP_MAILBOX_EMAIL})…"
  gosu www-data php /var/www/html/docker/railway/bootstrap-mailbox.php || echo "[entrypoint] mailbox bootstrap failed (see error above)"
fi

# ── Optional demo seed (opt-in) ──────────────────────────────────────────────
if [ "${FUSTERAI_SEED_DEMO:-false}" = "true" ]; then
  HAS_WORKSPACE=$(gosu www-data php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo \App\Models\Workspace::exists() ? "yes" : "no";')
  if [ "$HAS_WORKSPACE" = "no" ]; then
    echo "[entrypoint] seeding demo data…"
    gosu www-data php artisan fusterai:install --demo --force || true
  fi
fi

# ── Cache config/routes/views for production ─────────────────────────────────
echo "[entrypoint] priming caches…"
gosu www-data php artisan config:cache
gosu www-data php artisan route:cache
gosu www-data php artisan view:cache

echo "[entrypoint] starting supervisord…"
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/fusterai.conf
