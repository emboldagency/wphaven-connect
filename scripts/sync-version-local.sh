#!/bin/bash

# This script updates version numbers in wphaven.php and readme.txt
# to match the latest local Git tag.
# Run this *after* creating a tag and *before* pushing it.

set -e # Exit immediately if a command exits with a non-zero status.

PLUGIN_MAIN_FILE="wphaven.php"
README_FILE="readme.txt"

# Get the latest local Git tag (e.g., 0.15.1)
LATEST_TAG=$(git tag --list --sort=-version:refname | head -n 1)

if [ -z "$LATEST_TAG" ]; then
  echo "Error: No local Git tags found. Please create a tag first (e.g., git tag 0.1.0)."
  exit 1
fi

echo "Latest local tag found: $LATEST_TAG. Syncing file versions..."

FILES_CHANGED=0

# --- Update Version in wphaven.php ---
CURRENT_PLUGIN_VERSION=$(grep -E -o "^ \* Version: *[0-9A-Za-z.-]+" "$PLUGIN_MAIN_FILE" | sed -E "s/^ \* Version: *//")
if [ -z "$CURRENT_PLUGIN_VERSION" ]; then # Fallback for different comment style
    CURRENT_PLUGIN_VERSION=$(grep -E -o "Version: *[0-9A-Za-z.-]+" "$PLUGIN_MAIN_FILE" | head -n1 | sed -E "s/Version: *//")
fi

if [ "$CURRENT_PLUGIN_VERSION" != "$LATEST_TAG" ]; then
  echo "Updating Version in $PLUGIN_MAIN_FILE from '$CURRENT_PLUGIN_VERSION' to '$LATEST_TAG'"
  sed -i -E "s/(^ \* Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$PLUGIN_MAIN_FILE"
  # Fallback if the primary sed didn't catch it (e.g. no leading " * ")
  if ! grep -q "^ \* Version: *$LATEST_TAG" "$PLUGIN_MAIN_FILE"; then
       sed -i -E "s/(Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$PLUGIN_MAIN_FILE"
  fi
  FILES_CHANGED=1
else
  echo "Version in $PLUGIN_MAIN_FILE is already $LATEST_TAG."
fi

# --- Update Stable tag in readme.txt ---
CURRENT_README_VERSION=$(grep -E -o "^Stable tag: *[0-9A-Za-z.-]+" "$README_FILE" | sed -E "s/^Stable tag: *//")
if [ "$CURRENT_README_VERSION" != "$LATEST_TAG" ]; then
  echo "Updating Stable tag in $README_FILE from '$CURRENT_README_VERSION' to '$LATEST_TAG'"
  sed -i -E "s/(^Stable tag: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$README_FILE"
  FILES_CHANGED=1
else
  echo "Stable tag in $README_FILE is already $LATEST_TAG."
fi

echo ""
if [ "$FILES_CHANGED" -eq 1 ]; then
  echo "IMPORTANT: Files were modified to match tag '$LATEST_TAG'."
  echo "Please 'git add $PLUGIN_MAIN_FILE $README_FILE' and then either:"
  echo "  1. If the tag was on your latest commit: 'git commit --amend --no-edit'"
  echo "  2. Or, delete the tag (git tag -d $LATEST_TAG) and re-tag the new commit."
  echo "Then push your commit and the tag: 'git push origin <branch> && git push origin $LATEST_TAG'"
else
  echo "File versions already match the latest local tag '$LATEST_TAG'."
fi

exit 0