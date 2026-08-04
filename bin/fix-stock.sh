#!/usr/bin/env bash
# One-shot: re-run the product seeder on production to repair stock status.
# Idempotent; touches products only (no pages, no media re-import).
exec ssh root@72.167.225.151 \
  "WP_CLI_PHP_ARGS='-d memory_limit=512M' wp --path=/home/haramara/public_html --allow-root haramara seed-products --force --skip-media"
