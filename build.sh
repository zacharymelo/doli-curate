#!/usr/bin/env bash
# Build an installable Dolibarr module zip.
# The zip must have "dolicurate/" at its top level, not "module/".
set -euo pipefail

MODULE=dolicurate
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

VERSION="$(grep -oE "this->version = '[0-9.]+'" "$ROOT/module/core/modules/modDoliCurate.class.php" | grep -oE "[0-9.]+")"
if [ -z "$VERSION" ]; then
	echo "Could not read version from the module descriptor" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$MODULE"
cp -R "$ROOT/module/." "$STAGE/$MODULE/"
find "$STAGE/$MODULE" -name '.DS_Store' -delete

OUT="$ROOT/$MODULE-$VERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && zip -rq "$OUT" "$MODULE")

echo "Built $OUT"
