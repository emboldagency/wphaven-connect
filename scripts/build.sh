#!/bin/bash

# Build script
# 1. Syncs/Checks version numbers against Git tags
# 2. Generates a production-ready ZIP in dist/archives
#
# Usage:
#   bash scripts/build.sh              # Checks version, builds zip
#   bash scripts/build.sh --fix        # Updates file versions to match Git tag, then builds
#   bash scripts/build.sh --dev        # Skips version check (for development), builds zip

set -e

# Configuration
PLUGIN_SLUG="wphaven-connect"
MAIN_FILE="wphaven.php"
README_FILE="readme.txt"
DIST_DIR="dist/archives"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}🔧 Starting Build Process for ${PLUGIN_SLUG}...${NC}"

# ==============================================================================
# Version Synchronization
# ==============================================================================

# Resolve the tag to sync against. CI can pass GIT_TAG explicitly (e.g. GITHUB_REF_NAME)
# to avoid relying on the checkout having fetched tags.
if [ -n "$GIT_TAG" ]; then
	LATEST_TAG="$GIT_TAG"
	echo -e "${BLUE}ℹ️  Using provided GIT_TAG: ${LATEST_TAG}${NC}"
else
	LATEST_TAG=$(git tag --list --sort=-version:refname 2>/dev/null | head -n 1)
fi

if [ -z "$LATEST_TAG" ]; then
	echo -e "${YELLOW}⚠️  No Git tags found. Skipping version sync.${NC}"
	# Fallback: Extract version from file if no tag exists
	VERSION=$(grep "Version:" "$MAIN_FILE" | head -n1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
else
	VERSION="$LATEST_TAG"

	# Check current file versions
	CURRENT_PLUGIN_VERSION=$(grep -E -o "Version: *[0-9A-Za-z.-]+" "$MAIN_FILE" | head -n1 | sed -E "s/Version: *//")
	CURRENT_README_VERSION=$(grep -E -o "Stable tag: *[0-9A-Za-z.-]+" "$README_FILE" | head -n1 | sed -E "s/Stable tag: *//")

	# For prerelease tags (e.g., 0.19.0-pre), extract base version for comparison
	# This allows 0.19.0-pre tag to build with 0.19.0 file version
	TAG_BASE_VERSION=$(echo "$LATEST_TAG" | sed -E 's/(-|\.pre|\.beta|\.rc).*//')

	# Mode check (Fix vs Check vs Dev)
	if [ "$1" == "--dev" ]; then
		echo -e "${YELLOW}🔧 Development mode: Skipping version check.${NC}"
		VERSION="$CURRENT_PLUGIN_VERSION"
	elif [ "$1" == "--fix" ]; then
		NEEDS_UPDATE=0
		if [ "$CURRENT_PLUGIN_VERSION" != "$LATEST_TAG" ] \
			|| [ "$CURRENT_README_VERSION" != "$LATEST_TAG" ]; then
			NEEDS_UPDATE=1
		fi
		if grep -q "^= Unreleased =$" "$README_FILE"; then
			NEEDS_UPDATE=1
		fi

		if [ "$NEEDS_UPDATE" = "1" ]; then
			echo -e "${BLUE}📦 Updating file versions to match tag: ${LATEST_TAG}...${NC}"

			# Main plugin header
			sed -i.bak -E "s/(Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$MAIN_FILE"

			# readme.txt Stable tag
			sed -i.bak -E "s/(Stable tag: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$README_FILE"

			# readme.txt changelog: promote "= Unreleased =" to the release heading
			sed -i.bak -E "s/^= Unreleased =$/= $LATEST_TAG =/" "$README_FILE"

			# Clean up sed backups (macOS/BSD vs GNU compatibility)
			rm -f "$MAIN_FILE.bak" "$README_FILE.bak"
			echo -e "${GREEN}✅ Files updated.${NC}"
		else
			echo -e "${GREEN}✅ Versions already match (${LATEST_TAG}).${NC}"
		fi
	else
		# Just Check - allow base version match for prerelease tags
		if [ "$CURRENT_PLUGIN_VERSION" != "$LATEST_TAG" ] && [ "$CURRENT_PLUGIN_VERSION" != "$TAG_BASE_VERSION" ]; then
			echo -e "${RED}❌ Version Mismatch!${NC}"
			echo "   Git Tag: $LATEST_TAG"
			echo "   $MAIN_FILE:   $CURRENT_PLUGIN_VERSION"
			echo "   $README_FILE: $CURRENT_README_VERSION"
			echo "   Run 'bash scripts/build.sh --fix' to sync them."
			echo "   Or 'bash scripts/build.sh --dev' to skip version check for development."
			exit 1
		fi

		# Use file version for archive naming, but log what we're doing
		if [ "$LATEST_TAG" != "$TAG_BASE_VERSION" ]; then
			echo -e "${GREEN}✅ Prerelease tag detected ($LATEST_TAG) - using file version ($CURRENT_PLUGIN_VERSION) for build.${NC}"
			VERSION="$CURRENT_PLUGIN_VERSION"
		else
			echo -e "${GREEN}✅ Versions match ($LATEST_TAG).${NC}"
		fi
	fi
fi

echo -e "📦 Build Version: ${YELLOW}${VERSION}${NC}"

# ==============================================================================
# Install Production Dependencies
# ==============================================================================

echo -e "${BLUE}📦 Installing production dependencies...${NC}"
composer install --no-dev --prefer-dist --optimize-autoloader --quiet

# ==============================================================================
# Build Distribution Archive
# ==============================================================================

# Setup Directory
mkdir -p "$DIST_DIR"
rm -f "$DIST_DIR/${PLUGIN_SLUG}"*.zip 2>/dev/null || true

# Clean any prior extracted distribution BEFORE archiving. dist-archive runs
# from the plugin root, so a stale dist/extracted from a previous build would
# otherwise get folded into the new zip (compounding on every run).
find "dist/extracted" -mindepth 1 -delete 2>/dev/null || true

# ------------------------------------------------------------------------------
# Archive
# ------------------------------------------------------------------------------

# wp dist-archive is the only thing the build needs from WP-CLI, and it does not
# need a WordPress install to run -- it reads the plugin header and .distignore
# straight from this directory. Pinned to the version CI installs so a local
# archive and a released one are built by identical code.
DIST_ARCHIVE_PACKAGE="wp-cli/dist-archive-command:v3.1.0"

if ! command -v wp &>/dev/null; then
	echo -e "${RED}❌ WP-CLI not found.${NC}"
	echo "   Install it: https://wp-cli.org/#installing"
	exit 1
fi

# --allow-root only when actually root (CI containers, act), since WP-CLI
# refuses to run as root without it and warns about it when you are not.
WP_FLAGS=()
if [ "$(id -u)" = "0" ]; then
	WP_FLAGS+=(--allow-root)
fi

if ! wp help dist-archive "${WP_FLAGS[@]}" &>/dev/null; then
	echo -e "${YELLOW}⚠️  dist-archive not installed. Installing ${DIST_ARCHIVE_PACKAGE}...${NC}"
	if ! wp package install "$DIST_ARCHIVE_PACKAGE" "${WP_FLAGS[@]}"; then
		echo -e "${RED}❌ Could not install ${DIST_ARCHIVE_PACKAGE}.${NC}"
		echo "   Install it manually: wp package install ${DIST_ARCHIVE_PACKAGE}"
		exit 1
	fi
fi

echo -e "${BLUE}🚀 Running dist-archive...${NC}"
wp dist-archive . "$DIST_DIR" --create-target-dir --format=zip "${WP_FLAGS[@]}"

# ==============================================================================
# Verification
# ==============================================================================

if ls "$DIST_DIR/${PLUGIN_SLUG}"*.zip 1>/dev/null 2>&1; then
	echo -e "${GREEN}✅ Build Complete!${NC}"
	echo -e "📁 Archives located in: ${YELLOW}${DIST_DIR}/${NC}"
	ls -lh "$DIST_DIR"
else
	echo -e "${RED}❌ Build Failed: No zip file created.${NC}"
	exit 1
fi

# ==============================================================================
# Extract Distribution for Inspection
# ==============================================================================

EXTRACTED_DIR="dist/extracted"
echo -e "${BLUE}📂 Extracting distribution to ${EXTRACTED_DIR}...${NC}"

# Clean and recreate extracted directory
find "$EXTRACTED_DIR" -mindepth 1 -delete 2>/dev/null || true
mkdir -p "$EXTRACTED_DIR"

# Extract the ZIP
cd "$EXTRACTED_DIR"
unzip -q "../archives/${PLUGIN_SLUG}.${VERSION}.zip" -d temp 2>/dev/null || unzip -q "../archives/"*.zip -d temp 2>/dev/null
mv temp/*/* . 2>/dev/null || mv temp/* . 2>/dev/null || true
rm -rf temp
cd ../..

echo -e "${GREEN}✅ Distribution extracted.${NC}"
echo -e "📁 Files available in: ${YELLOW}${EXTRACTED_DIR}/${NC}"

# ==============================================================================
# Restore Development Dependencies
# ==============================================================================

# vendor/ is tracked in this repo, so the --no-dev install above leaves every
# dev dependency showing as deleted in git status until someone notices. CI
# throws its checkout away and would only pay for the reinstall.
if [ "$CI" != "true" ] && [ "$ACT" != "true" ]; then
	echo -e "${BLUE}🔄 Restoring development dependencies...${NC}"
	composer install --prefer-dist --quiet
	echo -e "${GREEN}✅ Development dependencies restored.${NC}"
fi
