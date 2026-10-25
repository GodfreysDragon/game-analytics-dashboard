#!/bin/sh
set -e

# Configuration comes from the runtime environment (Cloud Run variables and secrets),
# so it is cached at start-up rather than at build time.
php artisan config:cache
php artisan migrate --force

# Plain HTTP on Cloud Run's port: TLS is terminated in front of the container.
export SERVER_NAME=":${PORT:-8080}"

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
