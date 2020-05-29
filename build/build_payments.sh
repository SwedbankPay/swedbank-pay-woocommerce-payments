#!/bin/bash

PlUGIN_NAME="swedbank-pay-woocommerce-payments"
CURRENT_DIR=$(pwd)
TMPDIR="/tmp"
SOURCE_DIR="$TMPDIR/$PlUGIN_NAME"
BUILD_DIR="$CURRENT_DIR"

echo "Source dir: $SOURCE_DIR"
echo "Build dir: $BUILD_DIR"

# Prepare temporary source dir
rm -rf "$SOURCE_DIR" > /dev/null
mkdir $SOURCE_DIR > /dev/null
rsync -av --progress "../" "$SOURCE_DIR" --exclude "$CURRENT_DIR" > /dev/null
cd "$SOURCE_DIR" > /dev/null

# Build static content
npm install
gulp css:build
gulp js:build

# Remove unnecessary files
rm -rf "$SOURCE_DIR/node_modules" > /dev/null
rm -rf "$SOURCE_DIR/package-lock.json" > /dev/null
rm -rf "$SOURCE_DIR/.git" > /dev/null
rm -rf "$SOURCE_DIR/.travis" > /dev/null
rm -rf "$SOURCE_DIR/build" > /dev/null

# Make package
cd $TMPDIR
zip -r "$PlUGIN_NAME.zip" "./$PlUGIN_NAME/"
mv "$TMPDIR/$PlUGIN_NAME.zip" "$BUILD_DIR/"
rm -rf "$SOURCE_DIR/"
