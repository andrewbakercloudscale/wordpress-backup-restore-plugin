#!/bin/bash
# Build cloudscale-backup.zip from the repo directory
# Creates a zip with cloudscale-backup/ as the top level folder
# which is the structure WordPress expects for plugin upload
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Load shared Claude model config
GITHUB_DIR="$(dirname "$SCRIPT_DIR")"
# shellcheck source=../.claude-config.sh
source "$GITHUB_DIR/.claude-config.sh"
REPO_DIR="$SCRIPT_DIR"
ZIP_FILE="$SCRIPT_DIR/cloudscale-backup.zip"
PLUGIN_NAME="cloudscale-backup"
TEMP_DIR=$(mktemp -d)

echo "Building plugin zip from $REPO_DIR..."
# ── Auto-increment patch version ─────────────────────────────────────────────
MAIN_PHP=$(grep -rl "^ \* Version:" "$REPO_DIR" --include="*.php" 2>/dev/null | grep -v "repo/" | head -1)
if [ -z "$MAIN_PHP" ]; then
  echo "ERROR: Could not find main plugin PHP file with Version header."
  exit 1
fi
CURRENT_VER=$(grep "^ \* Version:" "$MAIN_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [ -z "$CURRENT_VER" ]; then
  echo "ERROR: Could not extract version from $MAIN_PHP"
  exit 1
fi
VER_MAJOR=$(echo "$CURRENT_VER" | cut -d. -f1)
VER_MINOR=$(echo "$CURRENT_VER" | cut -d. -f2)
VER_PATCH=$(echo "$CURRENT_VER" | cut -d. -f3)
NEW_VER="$VER_MAJOR.$VER_MINOR.$((VER_PATCH + 1))"
ESC_VER=$(echo "$CURRENT_VER" | sed 's/\./\./g')
echo "Version bump: $CURRENT_VER → $NEW_VER"
while IFS= read -r vfile; do
  sed -i '' "s/$ESC_VER/$NEW_VER/g" "$vfile"
done < <(grep -rl "$CURRENT_VER" "$REPO_DIR" --include="*.php" --include="*.js" --include="*.txt" 2>/dev/null | grep -v "\.git" | grep -v "/repo/")

# Guard: ensure CSBR_VERSION constant matches the header after bump.
CONST_VER=$(grep "define.*CSBR_VERSION" "$REPO_DIR/cloudscale-backup.php" | grep -oE "'[0-9]+\.[0-9]+\.[0-9]+'" | tr -d "'")
if [ "$CONST_VER" != "$NEW_VER" ]; then
  echo "WARNING: CSBR_VERSION constant ($CONST_VER) did not update — fixing..."
  sed -i '' "s/define('CSBR_VERSION'.*$/define('CSBR_VERSION',    '$NEW_VER');/" "$REPO_DIR/cloudscale-backup.php"
fi
# ─────────────────────────────────────────────────────────────────────────────

# PHP syntax check — abort before packaging if any file has a parse error
echo "Checking PHP syntax..."
LINT_ERRORS=0
while IFS= read -r -d '' phpfile; do
  result=$(php -l "$phpfile" 2>&1)
  if [ $? -ne 0 ]; then
    echo "$result"
    LINT_ERRORS=1
  fi
done < <(find "$REPO_DIR" -name "*.php" -print0)
if [ "$LINT_ERRORS" -ne 0 ]; then
  echo ""
  echo "ERROR: PHP syntax errors found above. Fix before deploying."
  exit 1
fi
echo "PHP syntax: OK"
echo ""


# Create temp directory with plugin name as wrapper
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"
rsync -a \
  --exclude='.*' \
  --exclude='*.zip' --exclude='*.sh' --exclude='*.xml' \
  --exclude='*.json' \
  --exclude='*.jpg' --exclude='*.png' --exclude='*.svg' \
  --exclude='repo/' --exclude='docs/' --exclude='tests/' \
  --exclude='node_modules/' --exclude='svn-assets/' \
  --exclude='playwright-report/' --exclude='playwright.config.js' \
  --exclude='includes/class-csbr-clone.php' \
  "$REPO_DIR/" "$TEMP_DIR/$PLUGIN_NAME/"

# Versioned asset copies for filename-based cache busting (beats iOS Safari immutable cache)
cp "$TEMP_DIR/$PLUGIN_NAME/style.css"  "$TEMP_DIR/$PLUGIN_NAME/style-${NEW_VER}.css"
cp "$TEMP_DIR/$PLUGIN_NAME/script.js"  "$TEMP_DIR/$PLUGIN_NAME/script-${NEW_VER}.js"

# Build zip with correct structure
rm -f "$ZIP_FILE"
cd "$TEMP_DIR"
zip -r "$ZIP_FILE" "$PLUGIN_NAME/"

# Cleanup
rm -rf "$TEMP_DIR"

# ── Sync repo/ for SVN deployment ────────────────────────────────────────────
# repo/ mirrors what goes into SVN trunk/ — kept up to date on every build
# so MANUAL-deploy-svn.sh always has the latest files ready to commit.
mkdir -p "$REPO_DIR/repo/includes"
rsync -a --delete \
  --exclude='.*' \
  --exclude='*.zip' --exclude='*.sh' --exclude='*.xml' \
  --exclude='*.json' \
  --exclude='*.jpg' --exclude='*.png' --exclude='*.svg' \
  --exclude='repo/' --exclude='docs/' --exclude='tests/' \
  --exclude='node_modules/' --exclude='svn-assets/' \
  --exclude='playwright-report/' --exclude='playwright.config.js' \
  --exclude='includes/class-csbr-clone.php' \
  "$REPO_DIR/" "$REPO_DIR/repo/"
# Sync readme.txt into repo/ so SVN trunk always has the correct Stable tag
cp "$REPO_DIR/readme.txt" "$REPO_DIR/repo/readme.txt"
sed -i '' "s/^ \* Version:.*/ * Version:     $NEW_VER/" "$REPO_DIR/repo/cloudscale-backup.php"
# Remove any dot-files that slipped through
find "$REPO_DIR/repo" -name ".*" -delete 2>/dev/null || true

echo ""
echo "Zip built: $ZIP_FILE"
echo ""
echo "Contents:"
unzip -l "$ZIP_FILE" | head -25
echo ""

# Show version and verify stable tag matches
VERSION=$(grep "^ \* Version:" "$REPO_DIR/cloudscale-backup.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
STABLE_TAG=$(grep "^Stable tag:" "$REPO_DIR/readme.txt" | head -1 | sed 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')
echo "Plugin version: $VERSION"
echo "Stable tag:     $STABLE_TAG"
if [ "$VERSION" != "$STABLE_TAG" ]; then
  echo ""
  echo "ERROR: Version mismatch! Plugin version ($VERSION) != Stable tag ($STABLE_TAG)"
  echo "Update readme.txt Stable tag before deploying."
  exit 1
fi
echo "Version check: OK"
echo ""
echo "To deploy to S3, run:"
  echo "  bash $SCRIPT_DIR/backup-s3.sh"
echo ""
echo "Then on the server:"
echo "  sudo aws s3 cp s3://andrewninjawordpress/cloudscale-backup.zip /tmp/plugin.zip && sudo rm -rf /var/www/html/wp-content/plugins/cloudscale-backup && sudo unzip -q /tmp/plugin.zip -d /var/www/html/wp-content/plugins/ && sudo chown -R apache:apache /var/www/html/wp-content/plugins/cloudscale-backup && php -r \"if(function_exists('opcache_reset'))opcache_reset();\""
