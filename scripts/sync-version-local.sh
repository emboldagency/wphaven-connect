#!/bin/bash

# This script checks (by default) or updates (with --fix) version numbers in wphaven.php and readme.txt
# to match the latest local Git tag.

set -e

PLUGIN_MAIN_FILE="wphaven.php"
README_FILE="readme.txt"

# Get the latest local Git tag (e.g., 0.15.1)
LATEST_TAG=$(git tag --list --sort=-version:refname | head -n 1)

if [ -z "$LATEST_TAG" ]; then
  echo "Error: No local Git tags found. Please create a tag first (e.g., git tag 0.1.0)."
  exit 1
fi

MODE="check"
if [ "$1" == "--fix" ]; then
  MODE="fix"
fi

CURRENT_PLUGIN_VERSION=$(grep -E -o "^ \* Version: *[0-9A-Za-z.-]+" "$PLUGIN_MAIN_FILE" | sed -E "s/^ \* Version: *//")
if [ -z "$CURRENT_PLUGIN_VERSION" ]; then
    CURRENT_PLUGIN_VERSION=$(grep -E -o "Version: *[0-9A-Za-z.-]+" "$PLUGIN_MAIN_FILE" | head -n1 | sed -E "s/Version: *//")
fi

CURRENT_README_VERSION=$(grep -E -o "^Stable tag: *[0-9A-Za-z.-]+" "$README_FILE" | sed -E "s/^Stable tag: *//")

if [ "$CURRENT_PLUGIN_VERSION" != "$LATEST_TAG" ] || [ "$CURRENT_README_VERSION" != "$LATEST_TAG" ]; then
  echo "Version mismatch detected!"
  echo "  Git tag: $LATEST_TAG"
  echo "  $PLUGIN_MAIN_FILE: $CURRENT_PLUGIN_VERSION"
  echo "  $README_FILE: $CURRENT_README_VERSION"
  if [ "$MODE" = "fix" ]; then
    echo "Fixing versions to match tag..."
    sed -i -E "s/(^ \* Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$PLUGIN_MAIN_FILE"
    if ! grep -q "^ \* Version: *$LATEST_TAG" "$PLUGIN_MAIN_FILE"; then
      sed -i -E "s/(Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$PLUGIN_MAIN_FILE"
    fi
    sed -i -E "s/(^Stable tag: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$README_FILE"
    echo "Files updated. Please commit and re-tag as needed."
    exit 0
  else
    echo "ERROR: Version numbers do not match the latest tag."
    exit 1
  fi
else
  echo "Versions match the latest local tag '$LATEST_TAG'."
fi

exit 0