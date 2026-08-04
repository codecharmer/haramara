#!/usr/bin/env bash
# One-shot: regenerate product images on production (drops the media cache,
# re-imports photos + the shared brand-seal placeholder, re-binds products).
exec ssh root@72.167.225.151 \
  "WP_CLI_PHP_ARGS='-d memory_limit=512M' wp --path=/home/haramara/public_html --allow-root haramara import-media --force"
