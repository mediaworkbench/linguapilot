#!/usr/bin/env bash
# Builds the release folder and zip that would be uploaded to wordpress.org.
#
# Contents = every tracked or new (non-ignored) file not marked `export-ignore` in .gitattributes,
# plus a production-only vendor/ (Composer autoloader, no dev packages).
#
# Usage (inside the DDEV web container, from the plugin directory):
#   ddev exec -d /var/www/html/wp-content/plugins/multilingual-store-abilities bash bin/build-release.sh
# Output: build/multilingual-store-abilities/ and build/multilingual-store-abilities-<version>.zip
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="multilingual-store-abilities"
VERSION="$(sed -n 's/^ \* Version: *//p' "${SLUG}.php" | tr -d '[:space:]')"
OUT="build/${SLUG}"

rm -rf build
mkdir -p "${OUT}"

# Files git would ship: tracked + untracked-but-not-ignored, minus export-ignore.
git ls-files --cached --others --exclude-standard -z \
	| git check-attr --stdin -z export-ignore \
	| awk 'BEGIN { RS = "\0" } { path = $0; getline; getline value; if (value != "set") print path }' \
	| while IFS= read -r file; do
		[ -f "${file}" ] || continue
		mkdir -p "${OUT}/$(dirname "${file}")"
		cp "${file}" "${OUT}/${file}"
	done

# Production autoloader only (composer.lock is export-ignored, but needed to install the locked versions).
cp composer.lock "${OUT}/"
composer install --working-dir="${OUT}" --no-dev --optimize-autoloader --no-interaction --quiet
rm "${OUT}/composer.lock"

(cd build && zip -qr "${SLUG}-${VERSION}.zip" "${SLUG}")
echo "Built build/${SLUG}-${VERSION}.zip ($(find "${OUT}" -type f | wc -l | tr -d ' ') files)"
