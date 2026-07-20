#!/bin/bash
# Check if the build counter number is provided as an argument
if [ -z "$1" ]; then
    echo "Error: Build counter number is not provided."
    exit 1
fi

# Extract the build counter number from the first argument
BUILD_COUNTER="$1"
FILE_PATH="northstaronlineordering.php"

# Update the version number in the file
sed -i "s/Version: [0-9]\+\.[0-9]\+\.[0-9]\+/Version: $BUILD_COUNTER/" "$FILE_PATH"
composer install --no-dev 
npm install
npm run production
rm -rf node_modules
echo "$(git rev-parse --abbrev-ref HEAD)" > branch.txt
echo "$BUILD_COUNTER" > build.txt
zip -r northstaronlineordering.zip . -x '*.git*' -x '*.scannerwork*'