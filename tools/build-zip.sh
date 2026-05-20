#!/usr/bin/env bash
#
# Build a wp.org-shippable distribution ZIP from the current working tree.
#
# Reads Text Domain + Version from agentready.php, runs `npm run build` to
# refresh the JS bundles, rsyncs the working tree into a temp dir while
# respecting .distignore, runs `composer install --no-dev --optimize-autoloader`
# so the ZIP's vendor/ is production-only, strips composer.json + composer.lock
# from the destination, then zips the result into dist/<slug>-<version>.zip.
#
# Optional --verify flag extracts the ZIP and runs `wp plugin-check` against
# the extracted tree. Requires the wp-cli `wp` binary on PATH and the
# `plugin-check` package installed (`wp package install
# wp-cli/plugin-check-package`).
#
# Usage:
#   bash tools/build-zip.sh              # bare build
#   bash tools/build-zip.sh --verify     # build + wp plugin-check
#
# Exit codes:
#   0  build succeeded
#   1  build failed (parse error, dependency install failed, zip failed)
#   2  ZIP exceeded wp.org's 10 MB per-file ceiling
#   3  --verify run found Plugin Check errors

set -euo pipefail

VERIFY=0
for arg in "$@"; do
	case "$arg" in
		--verify) VERIFY=1 ;;
		--help|-h)
			sed -n '2,/^set -euo/p' "$0" | sed -n '/^#/p' | sed 's/^# \?//'
			exit 0
			;;
		*)
			echo "Unknown argument: $arg" >&2
			echo "Usage: bash tools/build-zip.sh [--verify]" >&2
			exit 1
			;;
	esac
done

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# --- 1. Parse slug + version from the plugin header. ---------------------
SLUG=$(grep -E "^\s*\*\s*Text Domain:" agentready.php | sed -E 's/.*Text Domain:[[:space:]]*//; s/[[:space:]]+$//')
VERSION=$(grep -E "^\s*\*\s*Version:" agentready.php | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]+$//')

if [[ -z "$SLUG" || -z "$VERSION" ]]; then
	echo "Error: could not parse Text Domain or Version from agentready.php" >&2
	exit 1
fi

echo "Building wp.org ZIP for $SLUG $VERSION"

# --- 2. Refresh JS bundles. ----------------------------------------------
# The wp.org Plugin Check job in CI runs the plugin from a checkout where
# build/ exists, so we mirror that contract here: a fresh `npm run build`
# before bundling guarantees the ZIP carries the latest compiled assets.
if [[ ! -d node_modules ]]; then
	echo "→ Installing npm dependencies (one-time setup)"
	npm install --silent
fi

echo "→ Building JS bundles (npm run build)"
npm run build > /dev/null

# --- 3. Stage the build in a temp dir. -----------------------------------
BUILD_DIR=$(mktemp -d -t "${SLUG}-build.XXXXXX")
trap 'rm -rf "$BUILD_DIR"' EXIT

DEST="$BUILD_DIR/$SLUG"
mkdir -p "$DEST"

echo "→ Staging files (rsync, respecting .distignore)"
rsync -a \
	--exclude-from="$REPO_ROOT/.distignore" \
	"$REPO_ROOT/" "$DEST/"

# --- 4. Production-only composer deps. -----------------------------------
echo "→ Installing production composer dependencies (--no-dev)"
(
	cd "$DEST"
	composer install \
		--no-dev \
		--no-progress \
		--no-interaction \
		--prefer-dist \
		--optimize-autoloader \
		--quiet
)

# --- 5. Strip composer.json / composer.lock from final ZIP. --------------
# composer.json/lock are needed in step 4 so composer can resolve deps; once
# vendor/ + vendor/autoload.php are in place, the metadata files are dead
# weight in the shipped ZIP. wp.org reviewers and Plugin Check tolerate them
# but the lean ZIP is the convention.
rm -f "$DEST/composer.json" "$DEST/composer.lock"

# --- 6. Create the ZIP. --------------------------------------------------
DIST_DIR="$REPO_ROOT/dist"
mkdir -p "$DIST_DIR"
ZIP_PATH="$DIST_DIR/${SLUG}-${VERSION}.zip"
rm -f "$ZIP_PATH"

echo "→ Creating $ZIP_PATH"
(
	cd "$BUILD_DIR"
	zip -qr "$ZIP_PATH" "$SLUG"
)

# --- 7. Size check. ------------------------------------------------------
# wp.org enforces a 10 MB per-file ceiling on uploads. Plugins beyond that
# get rejected at submission time.
if [[ "$(uname -s)" == "Darwin" ]]; then
	SIZE_BYTES=$(stat -f%z "$ZIP_PATH")
else
	SIZE_BYTES=$(stat -c%s "$ZIP_PATH")
fi
SIZE_MB=$(awk "BEGIN{printf \"%.2f\", $SIZE_BYTES / 1024 / 1024}")
SIZE_HUMAN=$(awk "BEGIN{ if($SIZE_BYTES<1024) printf \"%d B\", $SIZE_BYTES; else if($SIZE_BYTES<1048576) printf \"%.1f KB\", $SIZE_BYTES/1024; else printf \"%.2f MB\", $SIZE_BYTES/1048576 }")

echo ""
echo "✓ Built $ZIP_PATH ($SIZE_HUMAN)"

if (( SIZE_BYTES > 10485760 )); then
	echo "✗ ZIP exceeds wp.org's 10 MB per-file ceiling ($SIZE_MB MB > 10 MB)" >&2
	exit 2
fi

# --- 8. Optional plugin-check verification. ------------------------------
if (( VERIFY == 1 )); then
	if ! command -v wp >/dev/null 2>&1; then
		echo "✗ --verify requested but 'wp' (WP-CLI) is not on PATH" >&2
		exit 3
	fi

	echo ""
	echo "→ Verifying ZIP against wp plugin-check"

	VERIFY_DIR=$(mktemp -d -t "${SLUG}-verify.XXXXXX")
	trap 'rm -rf "$BUILD_DIR" "$VERIFY_DIR"' EXIT

	unzip -q "$ZIP_PATH" -d "$VERIFY_DIR"

	# Run plugin-check against the extracted tree. The package needs to be
	# installed in advance (`wp package install wp-cli/plugin-check-package`)
	# and the user's WP-CLI must have a target install (`--path=...`) or be
	# invoked from inside one.
	if ! wp plugin-check "$VERIFY_DIR/$SLUG" --format=json > "$VERIFY_DIR/check.json" 2> "$VERIFY_DIR/check.err"; then
		echo "✗ wp plugin-check returned a non-zero exit" >&2
		cat "$VERIFY_DIR/check.err" >&2
		exit 3
	fi

	if grep -qiE '"type"\s*:\s*"error"' "$VERIFY_DIR/check.json"; then
		echo "✗ Plugin Check reported errors:" >&2
		cat "$VERIFY_DIR/check.json" >&2
		exit 3
	fi

	echo "✓ wp plugin-check clean"
fi

echo ""
echo "Next: gh release create v${VERSION} \"$ZIP_PATH\" --title \"v${VERSION}\" --notes-file CHANGELOG-v${VERSION}.md"
