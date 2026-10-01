#!/usr/bin/env bash
#
# Updates the live API and public site. Run ON THE SERVER, over SSH:
#
#     cd ~/rachelscloset-api && ./deploy.sh
#
# Shared hosting often has several PHPs and puts the wrong one first on the
# PATH, so both can be overridden:
#
#     PHP=/usr/local/bin/ea-php83 COMPOSER="ea-php83 /usr/local/bin/composer" ./deploy.sh
#
# What it does NOT do, on purpose: seed. The settings and stage library are
# seeded once, by hand, at first install -- see CLAUDE.md. Re-seeding on every
# deploy would be harmless today (firstOrCreate) and is exactly the habit that
# turns into "ran db:seed on production" the day somebody adds a seeder that
# is not.

set -euo pipefail

cd "$(dirname "$0")"

PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"

# Refuse to run anywhere that is not the live install. `local` here would mean
# this is somebody's own machine, where `down` and `migrate --force` are not
# what anybody meant.
env_name="$(grep -E '^APP_ENV=' .env | cut -d= -f2 | tr -d '"' || true)"
if [ "$env_name" != "production" ]; then
    echo "APP_ENV is '${env_name:-unset}', not 'production'. Not deploying." >&2
    exit 1
fi

# Local edits on the server would be silently mixed into the new code -- or
# make the pull fail halfway. Say so before anything is touched.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "The server has uncommitted changes to tracked files. Not deploying:" >&2
    git status --short --untracked-files=no >&2
    exit 1
fi

echo "==> Fetching"
git fetch --quiet origin
if [ "$(git rev-parse HEAD)" = "$(git rev-parse '@{u}')" ]; then
    echo "Already up to date; refreshing caches only."
fi

# Down for the length of the update: a request that lands between the new
# code and the migration would meet a schema it does not expect. --retry
# tells a phone to try again shortly rather than show an error page as final.
echo "==> Maintenance mode on"
"$PHP" artisan down --retry=30 || true

# If anything below fails the site STAYS DOWN, deliberately. Half-new code
# against a half-migrated database is worse than a maintenance page, and
# `php artisan up` is one command once somebody has looked.
echo "==> Pulling"
git merge --ff-only --quiet '@{u}'

echo "==> Composer"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Migrating"
"$PHP" artisan migrate --force

echo "==> Caching config, routes and views"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "==> Maintenance mode off"
"$PHP" artisan up

echo "Deployed $(git rev-parse --short HEAD): $(git log -1 --pretty=%s)"
