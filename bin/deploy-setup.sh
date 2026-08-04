#!/usr/bin/env bash
# One-shot wrapper: runs the family VPS deploy setup with Haramara's parameters.
# Non-interactive (--yes): requires WP_DB_PASSWORD in the environment.
if [[ -z "${WP_DB_PASSWORD:-}" ]]; then
  echo "Error: set WP_DB_PASSWORD in the environment, e.g.:" >&2
  echo "  WP_DB_PASSWORD='...' $0" >&2
  exit 1
fi
exec "$HOME/setup-vps-deploy.sh" \
  --yes \
  --site-name haramara \
  --site-type wordpress \
  --deploy-path /home/haramara/public_html \
  --owner haramara \
  --wp-theme haramara \
  --wp-plugins "woocommerce,woocommerce-gateway-stripe,haramara-core" \
  --wp-activate \
  --wp-flush-cache \
  --with-cpanel-cache \
  --wp-seed-command "wp haramara install" \
  --wp-bootstrap \
  --wp-site-url https://haramara.cafe \
  --wp-site-title "Haramara Café" \
  --wp-admin-email codecharmer@codecharmer.io \
  --wp-locale es_MX \
  --wp-db-name haramara_haramara \
  --wp-db-user haramara_haramara
