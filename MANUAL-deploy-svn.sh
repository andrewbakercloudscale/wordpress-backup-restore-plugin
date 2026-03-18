#!/bin/bash
# Deploy plugin to WordPress.org SVN.
# Syncs repo/ → trunk/, svn-assets/ → assets/, handles adds/deletes, commits, and tags the release.
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_NAME="cloudscale-free-backup-and-restore"
SVN_URL="https://plugins.svn.wordpress.org/$PLUGIN_NAME"
SVN_USERNAME="andrewjbaker"
SVN_WORKING="$SCRIPT_DIR/.svn-working-copy"

# ── Credentials ──────────────────────────────────────────────────────────────
# Load from .svn-credentials.sh if present (gitignored), otherwise prompt.
CREDS_FILE="$SCRIPT_DIR/.svn-credentials.sh"
if [ -f "$CREDS_FILE" ]; then
    # shellcheck source=.svn-credentials.sh
    source "$CREDS_FILE"
fi
if [ -z "$SVN_PASSWORD" ]; then
    echo -n "SVN password: "
    read -rs SVN_PASSWORD
    echo
fi
SVN_AUTH="--username $SVN_USERNAME --password $SVN_PASSWORD --non-interactive"

# ── Version ───────────────────────────────────────────────────────────────────
VERSION=$(grep "^ \* Version:" "$SCRIPT_DIR/cloudscale-backup.php" \
    | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [ -z "$VERSION" ]; then
    echo "ERROR: Could not read version from plugin header."
    exit 1
fi
echo "Deploying v$VERSION to WordPress.org SVN..."
echo "Plugin slug: $PLUGIN_NAME"
echo ""

# ── Checkout or update working copy ──────────────────────────────────────────
if [ ! -d "$SVN_WORKING/.svn" ]; then
    echo "Checking out SVN repository (first run — may take a moment)..."
    svn co "$SVN_URL" "$SVN_WORKING" $SVN_AUTH
else
    echo "Updating SVN working copy..."
    cd "$SVN_WORKING" && svn up $SVN_AUTH
fi

# ── Sync repo/ → trunk/ ──────────────────────────────────────────────────────
echo ""
echo "Syncing repo/ to trunk/..."
rsync -a --delete \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='*.zip' \
    --exclude='.DS_Store' \
    --exclude='._*' \
    "$SCRIPT_DIR/repo/" "$SVN_WORKING/trunk/"

# Remove any dot-files rsync may have copied (WordPress.org rejects them)
find "$SVN_WORKING/trunk" -name ".*" ! -path "*/.svn*" -delete 2>/dev/null || true

# ── Sync svn-assets/ → assets/ ───────────────────────────────────────────────
echo "Syncing svn-assets/ to assets/..."
mkdir -p "$SVN_WORKING/assets"
rsync -a --delete \
    --exclude='.DS_Store' \
    --exclude='._*' \
    "$SCRIPT_DIR/svn-assets/" "$SVN_WORKING/assets/"

# ── Stage adds and deletes ────────────────────────────────────────────────────
cd "$SVN_WORKING"

# New files not yet tracked
svn status trunk assets | grep "^?" | awk '{print $2}' | while IFS= read -r f; do
    svn add "$f"
done

# Files removed from local that still exist in SVN
svn status trunk assets | grep "^!" | awk '{print $2}' | while IFS= read -r f; do
    svn delete "$f"
done

# ── Show diff summary ─────────────────────────────────────────────────────────
echo ""
echo "Changes staged for commit:"
svn status trunk assets
echo ""

# ── Commit trunk and assets ───────────────────────────────────────────────────
if svn status trunk assets | grep -qE "^[AMDR]"; then
    svn ci trunk assets -m "Update trunk and assets to v$VERSION" $SVN_AUTH
    echo "Trunk and assets committed."
else
    echo "Trunk and assets already up to date — skipping commit."
fi

# ── Tag the release ───────────────────────────────────────────────────────────
echo ""
if svn ls "$SVN_URL/tags/$VERSION" $SVN_AUTH > /dev/null 2>&1; then
    echo "Tag $VERSION already exists — replacing..."
    svn rm "tags/$VERSION"
    svn ci -m "Remove stale tag $VERSION" $SVN_AUTH
fi

echo "Tagging v$VERSION..."
svn cp trunk "tags/$VERSION"
svn ci -m "Tag version $VERSION" $SVN_AUTH

echo ""
echo "Done. v$VERSION is live on WordPress.org."
echo "Plugin page : https://wordpress.org/plugins/$PLUGIN_NAME/"
echo "SVN browser : https://plugins.svn.wordpress.org/$PLUGIN_NAME/"
