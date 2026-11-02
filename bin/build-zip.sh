#!/usr/bin/env sh
# Builds build/voozin-wordpress.zip containing a voozin/ folder, without the files in .distignore.
set -eu

cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/voozin
rsync -a --exclude-from=.distignore ./ build/voozin/
(cd build && zip -qr voozin-wordpress.zip voozin)
echo "build/voozin-wordpress.zip"
