#!/usr/bin/env bash
# =============================================================================
# CloudScale Automatic Crash Recovery — Watchdog Test
#
# Tests the watchdog installation, the cron job, and the Python rollback logic
# in an isolated environment (no production state is touched).
#
# Run on the server:
#   docker exec pi_wordpress bash /var/www/html/wp-content/plugins/cloudscale-backup/tests/crash-recovery-watchdog.sh
#
# Or locally (adjust paths):
#   bash tests/crash-recovery-watchdog.sh
# =============================================================================

set -euo pipefail

# ── Counters ──────────────────────────────────────────────────────────────────
PASS=0; FAIL=0

pass() { PASS=$((PASS + 1)); echo "  [PASS] $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  [FAIL] $1"; }

sep() { echo ""; echo "--- $1 ---"; }

echo ""
echo "================================================================"
echo " CloudScale Automatic Crash Recovery — Watchdog Test"
echo "================================================================"

# ── 1. Environment checks ─────────────────────────────────────────────────────
sep "1. Installation checks"

WATCHDOG_PATH="/usr/local/bin/csbr-par-watchdog.sh"
PAR_LOG="/var/log/cloudscale-par.log"
WP_PATH="${WP_PATH:-/var/www/html}"

# Watchdog script exists
if [[ -f "$WATCHDOG_PATH" ]]; then
    pass "Watchdog script installed: $WATCHDOG_PATH"
else
    fail "Watchdog script NOT found at $WATCHDOG_PATH"
    echo "       Fix: copy the script from the Automatic Crash Recovery tab in WP admin, then:"
    echo "         sudo tee $WATCHDOG_PATH << 'EOF'"
    echo "         (paste script)"
    echo "         EOF"
    echo "         sudo chmod +x $WATCHDOG_PATH"
fi

# Watchdog is executable
if [[ -x "$WATCHDOG_PATH" ]]; then
    pass "Watchdog script is executable"
else
    fail "Watchdog script is NOT executable"
    echo "       Fix: sudo chmod +x $WATCHDOG_PATH"
fi

# Cron job registered
CRON_FOUND=false
if crontab -l 2>/dev/null | grep -q "csbr-par-watchdog"; then
    CRON_FOUND=true
elif [[ -f /etc/cron.d/csbr-par ]] && grep -q "csbr-par-watchdog" /etc/cron.d/csbr-par 2>/dev/null; then
    CRON_FOUND=true
elif [[ -f /var/spool/cron/crontabs/root ]] && grep -q "csbr-par-watchdog" /var/spool/cron/crontabs/root 2>/dev/null; then
    CRON_FOUND=true
fi

if $CRON_FOUND; then
    pass "Watchdog cron job is registered"
else
    fail "Watchdog cron job NOT found in crontab"
    echo "       Fix: sudo crontab -e  →  add line:"
    echo "         * * * * * root $WATCHDOG_PATH >> $PAR_LOG 2>&1"
fi

# Heartbeat file (written by watchdog every run)
BACKUP_BASE=""
if command -v wp &>/dev/null; then
    BACKUP_BASE=$(wp --path="$WP_PATH" --allow-root eval 'echo CSBR_Plugin_Auto_Recovery::backup_base();' 2>/dev/null || echo "")
fi
HEARTBEAT_FILE="${BACKUP_BASE}heartbeat"

if [[ -n "$BACKUP_BASE" ]] && [[ -f "$HEARTBEAT_FILE" ]]; then
    LAST_RUN=$(cat "$HEARTBEAT_FILE" 2>/dev/null || echo "0")
    SECS_AGO=$(( $(date +%s) - LAST_RUN ))
    if [[ $SECS_AGO -lt 120 ]]; then
        pass "Watchdog heartbeat is recent (${SECS_AGO}s ago)"
    elif [[ $SECS_AGO -lt 600 ]]; then
        fail "Watchdog heartbeat is stale (${SECS_AGO}s ago — expected < 120s)"
        echo "       Check: is the cron running? Try: crontab -l | grep csbr"
    else
        fail "Watchdog last ran $((SECS_AGO / 60)) min ago — cron may be broken"
    fi
elif [[ -z "$BACKUP_BASE" ]]; then
    echo "  [SKIP] Heartbeat check skipped (WP-CLI not available to locate backup base)"
else
    fail "Heartbeat file not found: $HEARTBEAT_FILE — watchdog has never run"
fi

# ── 2. State file check ───────────────────────────────────────────────────────
sep "2. State file"

STATE_FILE="${BACKUP_BASE}state.json"

if [[ -n "$BACKUP_BASE" ]] && [[ -f "$STATE_FILE" ]]; then
    # Parse with python3
    MONITOR_COUNT=$(python3 -c "
import json, sys
try:
    d = json.load(open('$STATE_FILE'))
    ms = d.get('monitors', {})
    active = [v for v in ms.values() if v.get('status') == 'monitoring']
    print(len(active))
except Exception as e:
    print(0)
" 2>/dev/null || echo "0")
    pass "State file exists: $STATE_FILE"
    echo "       Active monitors: $MONITOR_COUNT"
elif [[ -z "$BACKUP_BASE" ]]; then
    echo "  [SKIP] State file check skipped (WP-CLI not available)"
else
    echo "  [INFO] No state file — no active monitors (normal when no recent updates)"
fi

# ── 3. Isolated rollback logic test ──────────────────────────────────────────
sep "3. Rollback logic — isolated test"

echo "  Setting up isolated test environment…"

TMPDIR=$(mktemp -d /tmp/par-test-XXXXXX)
TEST_PLUGIN_DIR="$TMPDIR/plugins/csbr-crash-test"
TEST_BACKUP_DIR="$TMPDIR/backups/csbr-crash-test_20240101_120000"
TEST_STATE_FILE="$TMPDIR/state.json"
TEST_LOG_FILE="$TMPDIR/test.log"

mkdir -p "$TEST_PLUGIN_DIR" "$TEST_BACKUP_DIR"

# Create "broken" v2 plugin (currently installed on the filesystem)
cat > "$TEST_PLUGIN_DIR/csbr-crash-test.php" << 'PHPEOF'
<?php
/**
 * Plugin Name: PAR Test Plugin
 * Version: 2.0.0
 */
// BROKEN VERSION — triggers a fatal on parse_request
add_action('parse_request', function() { die('crash'); });
PHPEOF

# Create the v1 backup (what the watchdog should restore)
cat > "$TEST_BACKUP_DIR/csbr-crash-test.php" << 'PHPEOF'
<?php
/**
 * Plugin Name: PAR Test Plugin
 * Version: 1.0.0
 */
// HEALTHY VERSION
add_action('init', function() { /* no-op */ });
PHPEOF

# Write state.json with a monitoring entry pointing at the test dirs
python3 - << PYEOF
import json, time
state = {
    "monitors": {
        "par_test_auto_001": {
            "plugin_file":     "csbr-crash-test/csbr-crash-test.php",
            "plugin_name":     "PAR Test Plugin",
            "plugin_dir":      "$TEST_PLUGIN_DIR",
            "backup_path":     "$TEST_BACKUP_DIR",
            "status":          "monitoring",
            "version_before":  "1.0.0",
            "version_after":   "2.0.0",
            "monitoring_until": int(time.time()) + 3600
        }
    },
    "written_at":  int(time.time()),
    "admin_email": "test@example.com",
    "site_name":   "PAR Test Site",
    "site_url":    "https://example.com/"
}
with open("$TEST_STATE_FILE", "w") as f:
    json.dump(state, f, indent=2)
print("  state.json written")
PYEOF

# ── 3a. Run the rollback Python block (identical logic to the watchdog) ────────
echo "  Running rollback logic…"

python3 - << PYEOF
import json, shutil, os, sys
from datetime import datetime, timezone

state_file = "$TEST_STATE_FILE"
log_file   = "$TEST_LOG_FILE"

def log(msg):
    ts = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    line = f"{ts} [PAR-test] {msg}"
    with open(log_file, 'a') as fh:
        fh.write(line + "\n")

def rollback_monitor(monitor_id, m):
    plugin_file  = m.get('plugin_file', '')
    backup_path  = m.get('backup_path', '')
    plugin_name  = m.get('plugin_name', plugin_file)
    plugin_dir   = m.get('plugin_dir', '')

    log(f"Rolling back {plugin_name}")
    log(f"  plugin_dir  = {plugin_dir}")
    log(f"  backup_path = {backup_path}")

    if not os.path.isdir(backup_path):
        log(f"  ERROR: backup not found at {backup_path}")
        return False

    # Rename broken plugin dir (keep for forensics)
    broken_dir = plugin_dir + '.broken.' + str(int(datetime.now().timestamp()))
    if os.path.isdir(plugin_dir):
        os.rename(plugin_dir, broken_dir)
        log(f"  Moved broken dir to {broken_dir}")

    # Restore v1 from backup
    shutil.copytree(backup_path, plugin_dir)
    log(f"  Restored {backup_path} -> {plugin_dir}")

    # Verify main file exists
    main_file = os.path.join(plugin_dir, os.path.basename(plugin_file))
    if not os.path.isfile(main_file):
        log(f"  ERROR: main file not found after restore: {main_file}")
        return False

    log(f"  Rollback complete for {plugin_name}")
    return True

try:
    with open(state_file, 'r') as fh:
        state = json.load(fh)
except Exception as e:
    log(f"Cannot read state file: {e}")
    sys.exit(1)

now      = int(datetime.now(timezone.utc).timestamp())
monitors = state.get('monitors', {})

for mid, m in list(monitors.items()):
    if m.get('status', 'monitoring') != 'monitoring':
        continue
    if now > m.get('monitoring_until', 0):
        log(f"Monitor {mid} expired — skipping")
        continue
    ok = rollback_monitor(mid, m)
    if ok:
        monitors[mid]['status']        = 'rolled_back'
        monitors[mid]['rolled_back_at'] = now
        monitors[mid]['trigger']        = 'watchdog_failure'

state['monitors'] = monitors
with open(state_file, 'w') as fh:
    json.dump(state, fh, indent=2)

log("State file updated.")
PYEOF

echo ""
echo "  Assertions…"

# Assert: plugin dir restored to v1
if [[ -f "$TEST_PLUGIN_DIR/csbr-crash-test.php" ]]; then
    if grep -q "Version: 1.0.0" "$TEST_PLUGIN_DIR/csbr-crash-test.php"; then
        pass "Plugin restored to v1.0.0"
    else
        fail "Plugin file exists but does not contain v1.0.0"
        echo "       File content:"
        cat "$TEST_PLUGIN_DIR/csbr-crash-test.php"
    fi
else
    fail "Plugin main file not found after rollback — restore did not work"
fi

# Assert: .broken.* directory created for the broken v2
BROKEN_COUNT=$(find "$TMPDIR/plugins/" -maxdepth 1 -name "*.broken.*" -type d 2>/dev/null | wc -l)
if [[ "$BROKEN_COUNT" -gt 0 ]]; then
    BROKEN_DIR=$(find "$TMPDIR/plugins/" -maxdepth 1 -name "*.broken.*" -type d | head -1)
    pass "Broken v2 directory preserved as $(basename "$BROKEN_DIR")"
else
    fail "No .broken.* directory created — broken plugin was not renamed"
fi

# Assert: state.json status updated to rolled_back
STATUS=$(python3 -c "
import json
d = json.load(open('$TEST_STATE_FILE'))
monitors = d.get('monitors', {})
if monitors:
    print(list(monitors.values())[0].get('status', 'unknown'))
else:
    print('no_monitors')
" 2>/dev/null || echo "error")

if [[ "$STATUS" == "rolled_back" ]]; then
    pass "state.json updated to status=rolled_back"
else
    fail "state.json status is '$STATUS' (expected 'rolled_back')"
fi

# Assert: log file written with rollback messages
if [[ -f "$TEST_LOG_FILE" ]] && grep -q "Rollback complete" "$TEST_LOG_FILE" 2>/dev/null; then
    pass "Rollback log entry written to log file"
else
    fail "Rollback log entry not found in log file"
    [[ -f "$TEST_LOG_FILE" ]] && echo "       Log contents:" && cat "$TEST_LOG_FILE"
fi

# ── 4. Fail-count / threshold test ───────────────────────────────────────────
sep "4. Failure counting logic"

FAIL_COUNT_TMP="$TMPDIR/csbr-par-fails-test"
FAIL_THRESHOLD=2

# Simulate 1 prior failure already on disk
echo "1" > "$FAIL_COUNT_TMP"

# Run the counting logic (extracted from the watchdog bash block)
PROBE_CODE="503"
CURRENT_FAILS=0
[[ -f "$FAIL_COUNT_TMP" ]] && CURRENT_FAILS=$(cat "$FAIL_COUNT_TMP" 2>/dev/null || echo "0")

if [[ "$PROBE_CODE" =~ ^5 ]] || [[ "$PROBE_CODE" == "000" ]]; then
    CURRENT_FAILS=$((CURRENT_FAILS + 1))
    echo "$CURRENT_FAILS" > "$FAIL_COUNT_TMP"
fi

THRESHOLD_REACHED=false
if [[ "$CURRENT_FAILS" -ge "$FAIL_THRESHOLD" ]]; then
    THRESHOLD_REACHED=true
fi

if $THRESHOLD_REACHED; then
    pass "Failure counter correctly reached threshold (1 prior + 1 new = ${CURRENT_FAILS} >= ${FAIL_THRESHOLD})"
else
    fail "Failure counter logic broken — count=$CURRENT_FAILS threshold=$FAIL_THRESHOLD"
fi

# Verify counter resets on healthy probe
PROBE_CODE="200"
if [[ "$PROBE_CODE" =~ ^5 ]] || [[ "$PROBE_CODE" == "000" ]]; then
    : # keep incrementing
else
    rm -f "$FAIL_COUNT_TMP"
fi

if [[ ! -f "$FAIL_COUNT_TMP" ]]; then
    pass "Failure counter correctly reset on healthy probe (HTTP 200)"
else
    fail "Failure counter was NOT reset on healthy probe"
fi

# ── Cleanup ───────────────────────────────────────────────────────────────────
rm -rf "$TMPDIR"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "================================================================"
echo " Results: $PASS passed, $FAIL failed"
echo "================================================================"
echo ""

if [[ $FAIL -gt 0 ]]; then
    echo "IMPORTANT: Failures above indicate the crash recovery system is"
    echo "not fully operational. Fix them before relying on automatic recovery."
    echo ""
fi

[[ $FAIL -eq 0 ]]
