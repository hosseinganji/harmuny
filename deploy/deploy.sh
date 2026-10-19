#!/bin/bash
# Deploys the latest main from GitHub. Installed on the server as /usr/local/bin/smart-music-deploy.
#
# GitHub Actions reaches it through an SSH key that is locked to this one command
# (authorized_keys: restrict,command="/usr/local/bin/smart-music-deploy"), so the key cannot run
# anything else on the server. The repository is public, so the server needs no GitHub credentials.
set -euo pipefail

SRC=/opt/smart-music-src    # a clone of github.com/hosseinganji/harmuny
APP=/opt/smart-music        # what docker compose runs; .env and backups there are never touched

exec 9>/var/lock/smart-music-deploy.lock
flock 9 # one deploy at a time

cd "$SRC"
git fetch --quiet origin main
git reset --quiet --hard origin/main
rev=$(git rev-parse --short HEAD)
echo "==> Deploying main at $rev"

rsync -a --delete --exclude=.git --exclude=/.env --exclude='/.env.bak-*' "$SRC/" "$APP/"
echo "$rev" > "$APP/REVISION"

cd "$APP"
docker compose build app
docker compose up -d --remove-orphans
docker builder prune -af >/dev/null
docker image prune -f >/dev/null

# Wait for the first domain in DOMAIN to answer.
domain=$(grep -E '^DOMAIN=' .env | cut -d= -f2- | tr -d '"' | cut -d, -f1 | xargs)
for _ in $(seq 1 40); do
  if curl -fs -o /dev/null "https://$domain/up"; then
    echo "==> Live at https://$domain ($rev)"
    exit 0
  fi
  sleep 3
done
echo "!! The app did not come back up; see: docker compose -f $APP/docker-compose.yml logs app" >&2
exit 1
