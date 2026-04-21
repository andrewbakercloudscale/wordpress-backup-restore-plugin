#!/usr/bin/env bash
# wp-login.sh — Generate a one-time magic login URL for andrewbaker.ninja
# Drops a temporary PHP file in the webroot (token-protected), prints the URL,
# and removes it automatically after first use or after 5 minutes.
#
# Usage:
#   bash wp-login.sh          # generate link and auto-cleanup after 5 min
#   bash wp-login.sh --delete # manually remove any leftover login file

set -euo pipefail

PI_KEY="/Users/cp363412/Desktop/github/pi-monitor/deploy/pi_key"
WP_PATH="/var/www/html"
CONTAINER="pi_wordpress"
SITE_URL="https://andrewbaker.ninja"
ADMIN_EMAIL="andrew.j.baker.007@gmail.com"
TTL=300  # seconds before auto-cleanup

# ── SSH setup ────────────────────────────────────────────────────────────────
PI_LOCAL="andrew-pi-5.local"
if ssh -i "${PI_KEY}" -o StrictHostKeyChecking=no -o ConnectTimeout=4 -o BatchMode=yes \
       "pi@${PI_LOCAL}" "exit" 2>/dev/null; then
    PI_HOST="${PI_LOCAL}"; PI_USER="pi"
    SSH_OPTS=(-i "${PI_KEY}" -o StrictHostKeyChecking=no)
else
    PI_HOST="ssh.andrewbaker.ninja"; PI_USER="pi"
    SSH_OPTS=(-i "${HOME}/.cloudflared/pi-service-key" \
              -o "ProxyCommand=${HOME}/.cloudflared/cf-ssh-proxy.sh" \
              -o StrictHostKeyChecking=no)
fi

pi_ssh() { ssh "${SSH_OPTS[@]}" "${PI_USER}@${PI_HOST}" "$@"; }
wpcli()   { pi_ssh "docker exec ${CONTAINER} php ${WP_PATH}/wp-cli.phar --path=${WP_PATH} --allow-root $* 2>/dev/null"; }

# ── --delete mode ─────────────────────────────────────────────────────────────
if [[ "${1:-}" == "--delete" ]]; then
    pi_ssh "docker exec ${CONTAINER} sh -c 'rm -f ${WP_PATH}/magic-login-*.php' && echo 'Cleared.'"
    exit 0
fi

# ── Generate token ────────────────────────────────────────────────────────────
TOKEN=$(openssl rand -hex 20)
FILENAME="magic-login-${TOKEN}.php"

PHP_SCRIPT=$(cat <<PHPEOF
<?php
require_once '${WP_PATH}/wp-load.php';
\$file = __FILE__;
\$user = get_user_by('email', '${ADMIN_EMAIL}');
if (!\$user) { unlink(\$file); die('User not found.'); }
wp_set_auth_cookie(\$user->ID, false);
unlink(\$file);
wp_redirect(admin_url());
exit;
PHPEOF
)

# ── Deploy file ───────────────────────────────────────────────────────────────
pi_ssh "echo $(printf '%q' "${PHP_SCRIPT}") > /tmp/${FILENAME} && \
        docker cp /tmp/${FILENAME} ${CONTAINER}:${WP_PATH}/${FILENAME} && \
        rm /tmp/${FILENAME}"

LOGIN_URL="${SITE_URL}/${FILENAME}"
echo ""
echo "One-time login URL (valid ${TTL}s, self-deletes on first use):"
echo ""
echo "  ${LOGIN_URL}"
echo ""

# ── Auto-cleanup after TTL ───────────────────────────────────────────────────
(
    sleep "${TTL}"
    pi_ssh "docker exec ${CONTAINER} sh -c 'rm -f ${WP_PATH}/${FILENAME}'" 2>/dev/null || true
    echo "[wp-login] Login file expired and removed." >&2
) &
disown

echo "Auto-cleanup scheduled in ${TTL}s (PID $!)."
echo "Run 'bash wp-login.sh --delete' to remove immediately."
