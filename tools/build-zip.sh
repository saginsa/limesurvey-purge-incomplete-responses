#!/usr/bin/env bash
# Builds dist/PurgeIncompleteResponses-<version>.zip for "Upload & install" in LimeSurvey.
# The zip contains the folder PurgeIncompleteResponses/ with runtime files only
# (LimeSurvey accepts only some file extensions in plugin uploads).
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's:.*<version>\([0-9][0-9.]*\)</version>.*:\1:p' config.xml | head -1)
name=PurgeIncompleteResponses
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/$name" dist
for f in locale/*/*.po; do msgfmt --check -o "${f%.po}.mo" "$f"; done
cp -R PurgeIncompleteResponses.php config.xml README.md CHANGELOG.md LICENSE.txt assets locale "$tmp/$name/"
find "$tmp/$name" -name '.DS_Store' -delete
rm -f "dist/$name-$version.zip"
(cd "$tmp" && COPYFILE_DISABLE=1 zip -qrX "$OLDPWD/dist/$name-$version.zip" "$name")
(cd dist && shasum -a 256 "$name-$version.zip" > "$name-$version.zip.sha256")
unzip -l "dist/$name-$version.zip"
cat "dist/$name-$version.zip.sha256"
