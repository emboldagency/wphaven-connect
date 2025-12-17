#!/bin/bash

# This script checks (by default) or updates (with --fix) version numbers in wphaven.php and readme.txt
# to match the latest local Git tag.

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PLUGIN_MAIN_FILE="wphaven.php"
README_FILE="readme.txt"

# Get the latest tag: Use ENV variable if set (CI), otherwise check local git
if [ -n "$GIT_TAG" ]; then
    LATEST_TAG="$GIT_TAG"
    echo -e "${BLUE}ℹ️  Using provided GIT_TAG: ${LATEST_TAG}${NC}"
else
    LATEST_TAG=$(git tag --list --sort=-version:refname | head -n 1)
fi

if [ -z "$LATEST_TAG" ]; then
    echo -e "${RED}❌ Error: No local Git tags found. Please create a tag first (e.g., git tag 0.1.0).${NC}"
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
    echo -e "${YELLOW}⚠️  Version mismatch detected!${NC}"
    echo "  Git tag: ${YELLOW}$LATEST_TAG${NC}"
    echo "  $PLUGIN_MAIN_FILE: ${YELLOW}$CURRENT_PLUGIN_VERSION${NC}"
    echo "  $README_FILE: ${YELLOW}$CURRENT_README_VERSION${NC}"
    if [ "$MODE" = "fix" ]; then
        echo -e "${BLUE}📦 Fixing versions to match tag...${NC}"
        sed -i -E "s/(^ \* Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$PLUGIN_MAIN_FILE"
        if ! grep -q "^ \* Version: *$LATEST_TAG" "$PLUGIN_MAIN_FILE"; then
            sed -i -E "s/(Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$PLUGIN_MAIN_FILE"
        fi
        sed -i -E "s/(^Stable tag: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$README_FILE"
        echo -e "${GREEN}✅ Files updated. Please commit and re-tag as needed.${NC}"
        exit 0
    else
        echo -e "${RED}❌ Error: Version numbers do not match the latest tag.${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✅ Versions match the latest local tag ${YELLOW}'$LATEST_TAG'${NC}."
fi

exit 0