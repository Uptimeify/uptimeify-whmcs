#!/bin/sh
# Builds the release ZIP (spec §13): dist/uptimeify-whmcs-<version>.zip with one top-level folder uptimeify/, so it
# unpacks straight into modules/addons/. DIST_DIR overrides the output directory (the packaging test uses it).
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n "s/.*public const VERSION = '\([^']*\)'.*/\1/p" "$ROOT/lib/Version.php")"
[ -n "$VERSION" ] || { echo "version not found in lib/Version.php" >&2; exit 1; }
DIST="${DIST_DIR:-$ROOT/dist}"
mkdir -p "$DIST"
DIST="$(cd "$DIST" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/uptimeify"
# Everything the module needs at runtime and nothing else: no tests, docker, vendor, .github, docs, bin, dotfiles.
for item in uptimeify.php hooks.php whmcs.json logo.png lib templates lang LICENSE README.md CHANGELOG.md; do
  cp -R "$ROOT/$item" "$STAGE/uptimeify/"
done
find "$STAGE/uptimeify" -mindepth 1 -name '.*' -prune -exec rm -rf {} +
OUT="$DIST/uptimeify-whmcs-$VERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && zip -rqX "$OUT" uptimeify)
echo "built $OUT"
unzip -l "$OUT" | tail -n 1
