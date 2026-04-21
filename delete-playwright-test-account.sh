#!/bin/bash
# Deletes a Playwright test account on andrewbaker.ninja.
#
# Usage:
#   bash delete-playwright-test-account.sh                 # delete account in .env.test
#   bash delete-playwright-test-account.sh --username test-abc123
#   bash delete-playwright-test-account.sh --user-id 42
#   bash delete-playwright-test-account.sh --all-expired   # purge all expired accounts
#   bash delete-playwright-test-account.sh --list          # list active test accounts

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SHARED_DELETE="${SCRIPT_DIR}/../delete-test-account.sh"

if [ ! -f "${SHARED_DELETE}" ]; then
    echo "ERROR: shared delete-test-account.sh not found at ${SHARED_DELETE}"
    exit 1
fi

# If no args, try to read username from .env.test
if [ $# -eq 0 ]; then
    ENV_FILE="${SCRIPT_DIR}/.env.test"
    if [ -f "${ENV_FILE}" ]; then
        USERNAME=$(grep '^WP_TEST_USER=' "${ENV_FILE}" | cut -d= -f2)
        if [ -n "${USERNAME}" ]; then
            echo "Deleting test account from .env.test: ${USERNAME}"
            bash "${SHARED_DELETE}" --username "${USERNAME}"
            rm -f "${ENV_FILE}"
            echo "Removed .env.test"
            exit 0
        fi
    fi
    echo "No .env.test found and no arguments given. Usage:"
    bash "${SHARED_DELETE}" --list 2>/dev/null || true
    exit 1
fi

bash "${SHARED_DELETE}" "$@"

# If we deleted by username and it matches .env.test, clean up
if [[ "$1" == "--username" ]]; then
    ENV_FILE="${SCRIPT_DIR}/.env.test"
    if [ -f "${ENV_FILE}" ]; then
        ENV_USER=$(grep '^WP_TEST_USER=' "${ENV_FILE}" | cut -d= -f2)
        if [ "${ENV_USER}" = "$2" ]; then
            rm -f "${ENV_FILE}"
            echo "Removed .env.test"
        fi
    fi
fi
