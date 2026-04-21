#!/usr/bin/env bash

# Install and configure WP Mail SMTP to relay WordPress email via SMTP (port 587 STARTTLS).
# Requires WP-CLI to be in PATH. Run on the WordPress server as root or the web server user.

SCRIPT_NAME=$(basename "$0")
DEPENDENCIES=(wp python3)

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

if [[ -n "${NO_COLOR:-}" ]] || [[ "${TERM:-}" == "dumb" ]]; then
    RED="" GREEN="" YELLOW="" BLUE="" NC=""
fi

function usage() {
    cat <<EOM

Install and configure WP Mail SMTP to send WordPress email via SMTP (port 587 STARTTLS).

usage: ${SCRIPT_NAME} [options]

options:
    --wp-path  <path>   Path to WordPress root (default: /var/www/html)
    --wp-url   <url>    WordPress site URL for WP-CLI --url flag (optional, for multisite)
    -h|--help           Show this help message

dependencies: ${DEPENDENCIES[*]}

examples:
    ${SCRIPT_NAME}
    ${SCRIPT_NAME} --wp-path /var/www/html

EOM
    exit 1
}

function main() {
    local wp_path="/var/www/html"
    local wp_url=""

    while [[ "$1" != "" ]]; do
        case $1 in
        --wp-path)
            shift
            wp_path="$1"
            ;;
        --wp-url)
            shift
            wp_url="$1"
            ;;
        -h | --help)
            usage
            ;;
        *)
            echo "Error: Unknown option '$1'" >&2
            usage
            ;;
        esac
        shift
    done

    exit_on_missing_tools "${DEPENDENCIES[@]}"
    validate_wp_path "$wp_path"
    gather_smtp_config
    install_plugin "$wp_path" "$wp_url"
    configure_plugin "$wp_path" "$wp_url"
    send_test_email "$wp_path" "$wp_url"
}

# Config globals populated by gather_smtp_config
SMTP_HOST=""
SMTP_PORT=""
SMTP_ENCRYPTION=""
SMTP_USER=""
SMTP_PASS=""
FROM_EMAIL=""
FROM_NAME=""
TEST_EMAIL=""

function validate_wp_path() {
    local path="$1"
    if [[ ! -f "${path}/wp-config.php" ]]; then
        echo -e "${RED}Error: WordPress not found at '${path}' (no wp-config.php).${NC}" >&2
        exit 1
    fi
    echo -e "${GREEN}WordPress found at ${path}${NC}"
}

function gather_smtp_config() {
    echo ""
    echo -e "${BLUE}=================================${NC}"
    echo -e "${BLUE}  WP Mail SMTP — SMTP Setup      ${NC}"
    echo -e "${BLUE}=================================${NC}"
    echo ""
    echo "Common SMTP hosts:"
    echo "  Gmail (app password):  smtp.gmail.com"
    echo "  Amazon SES (N. Va.):   email-smtp.us-east-1.amazonaws.com"
    echo "  Mailgun:               smtp.mailgun.org"
    echo "  SendGrid:              smtp.sendgrid.net"
    echo ""

    read -rp "SMTP host: " SMTP_HOST
    if [[ -z "$SMTP_HOST" ]]; then
        echo "Error: SMTP host is required." >&2
        exit 1
    fi

    read -rp "SMTP port [587]: " SMTP_PORT
    SMTP_PORT="${SMTP_PORT:-587}"

    # Choose encryption based on port
    if [[ "$SMTP_PORT" == "465" ]]; then
        SMTP_ENCRYPTION="ssl"
    else
        SMTP_ENCRYPTION="tls"
    fi

    read -rp "SMTP username (usually your email address): " SMTP_USER
    if [[ -z "$SMTP_USER" ]]; then
        echo "Error: SMTP username is required." >&2
        exit 1
    fi

    read -s -rp "SMTP password (input hidden): " SMTP_PASS
    echo ""
    if [[ -z "$SMTP_PASS" ]]; then
        echo "Error: SMTP password is required." >&2
        exit 1
    fi

    read -rp "From email [${SMTP_USER}]: " FROM_EMAIL
    FROM_EMAIL="${FROM_EMAIL:-$SMTP_USER}"

    read -rp "From name [WordPress]: " FROM_NAME
    FROM_NAME="${FROM_NAME:-WordPress}"

    read -rp "Send test email to (leave blank to skip): " TEST_EMAIL

    echo ""
    echo -e "${YELLOW}Summary:${NC}"
    echo "  SMTP host:    ${SMTP_HOST}:${SMTP_PORT} (${SMTP_ENCRYPTION^^})"
    echo "  SMTP user:    ${SMTP_USER}"
    echo "  From:         ${FROM_NAME} <${FROM_EMAIL}>"
    [[ -n "$TEST_EMAIL" ]] && echo "  Test email:   ${TEST_EMAIL}"
    echo ""
    read -r -n 1 -p "Proceed? (y/n): " confirm
    echo ""
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 0
    fi
}

function wpcli() {
    local wp_path="$1"
    local wp_url="$2"
    shift 2
    if [[ -n "$wp_url" ]]; then
        wp --path="$wp_path" --url="$wp_url" --allow-root "$@"
    else
        wp --path="$wp_path" --allow-root "$@"
    fi
}

function install_plugin() {
    local wp_path="$1"
    local wp_url="$2"

    echo ""
    echo -e "${YELLOW}[1/3] Installing WP Mail SMTP...${NC}"

    if wpcli "$wp_path" "$wp_url" plugin is-installed wp-mail-smtp 2>/dev/null; then
        echo "  Already installed."
    else
        if ! wpcli "$wp_path" "$wp_url" plugin install wp-mail-smtp; then
            echo -e "${RED}Error: Failed to install WP Mail SMTP.${NC}" >&2
            exit 1
        fi
    fi

    if ! wpcli "$wp_path" "$wp_url" plugin activate wp-mail-smtp 2>/dev/null; then
        echo -e "${RED}Error: Failed to activate WP Mail SMTP.${NC}" >&2
        exit 1
    fi
    echo -e "${GREEN}  Plugin installed and active.${NC}"
}

function configure_plugin() {
    local wp_path="$1"
    local wp_url="$2"

    echo ""
    echo -e "${YELLOW}[2/3] Writing SMTP configuration...${NC}"

    # Use python3 to safely build the JSON — avoids escaping issues with
    # special characters in passwords or names.
    local config
    config=$(python3 - "$FROM_EMAIL" "$FROM_NAME" "$SMTP_HOST" "$SMTP_PORT" \
                       "$SMTP_ENCRYPTION" "$SMTP_USER" "$SMTP_PASS" <<'PYEOF'
import json, sys
args = sys.argv[1:]
print(json.dumps({
    "mail": {
        "from_email":       args[0],
        "from_name":        args[1],
        "mailer":           "smtp",
        "return_path":      False,
        "from_email_force": True,
        "from_name_force":  False,
    },
    "smtp": {
        "host":       args[2],
        "port":       int(args[3]),
        "encryption": args[4],
        "auth":       True,
        "user":       args[5],
        "pass":       args[6],
    },
}))
PYEOF
    )

    if [[ -z "$config" ]]; then
        echo -e "${RED}Error: Failed to build configuration JSON.${NC}" >&2
        exit 1
    fi

    if ! wpcli "$wp_path" "$wp_url" option update wp_mail_smtp "$config" --format=json; then
        echo -e "${RED}Error: Failed to write wp_mail_smtp option.${NC}" >&2
        exit 1
    fi
    echo -e "${GREEN}  Configuration saved.${NC}"
}

function send_test_email() {
    local wp_path="$1"
    local wp_url="$2"

    echo ""
    if [[ -z "$TEST_EMAIL" ]]; then
        echo -e "${YELLOW}[3/3] Skipping test email.${NC}"
        print_done
        return
    fi

    echo -e "${YELLOW}[3/3] Sending test email to ${TEST_EMAIL}...${NC}"

    # Use a temp PHP file so we don't have to escape the address inside --eval
    local tmp
    tmp=$(mktemp /tmp/csbr-smtp-test-XXXXXX.php)
    trap "rm -f '$tmp'" RETURN

    cat > "$tmp" <<PHPEOF
<?php
\$ok = wp_mail(
    '${TEST_EMAIL}',
    'WP Mail SMTP Test',
    "SMTP is working correctly.\n\nHost: ${SMTP_HOST}:${SMTP_PORT} (${SMTP_ENCRYPTION^^})\nFrom: ${FROM_NAME} <${FROM_EMAIL}>"
);
if ( \$ok ) {
    echo "Test email sent successfully.\n";
} else {
    fwrite( STDERR, "wp_mail() returned false — check SMTP credentials and try again.\n" );
    exit( 1 );
}
PHPEOF

    if ! wpcli "$wp_path" "$wp_url" eval-file "$tmp"; then
        echo -e "${YELLOW}  wp_mail() returned false. Verify credentials and try again.${NC}"
        echo -e "  Check the WordPress activity log for wp_mail_failed details."
    else
        echo -e "${GREEN}  Test email dispatched — check ${TEST_EMAIL}.${NC}"
    fi

    print_done
}

function print_done() {
    echo ""
    echo -e "${GREEN}Done.${NC} WP Mail SMTP is configured to relay via ${SMTP_HOST}:${SMTP_PORT}."
    echo "WordPress will now use this SMTP server for all outbound email."
}

function exit_on_missing_tools() {
    for cmd in "$@"; do
        if command -v "$cmd" &>/dev/null; then
            continue
        fi
        echo -e "${RED}Error: Required tool '${cmd}' is not installed or not in PATH.${NC}" >&2
        exit 1
    done
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
    exit 0
fi
