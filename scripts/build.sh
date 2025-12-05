#!/bin/bash

# Build script for WPHaven Connect
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

# Get the latest local Git tag (e.g., 0.15.1)
# 2>/dev/null suppresses errors if no tags exist
LATEST_TAG=$(git tag --list --sort=-version:refname | head -n 1)

if [ -z "$LATEST_TAG" ]; then
	echo -e "${YELLOW}⚠️  No Git tags found. Skipping version sync.${NC}"
	# Fallback: Extract version from file if no tag exists
	VERSION=$(grep "Version:" "$MAIN_FILE" | head -n1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
else
	VERSION="$LATEST_TAG"

	# Check current file versions
	CURRENT_PLUGIN_VERSION=$(grep -E -o "Version: *[0-9A-Za-z.-]+" "$MAIN_FILE" | head -n1 | sed -E "s/Version: *//")
	CURRENT_README_VERSION=$(grep -E -o "Stable tag: *[0-9A-Za-z.-]+" "$README_FILE" | head -n1 | sed -E "s/Stable tag: *//")

	# Mode check (Fix vs Check vs Dev)
	if [ "$1" == "--dev" ]; then
		echo -e "${YELLOW}🔧 Development mode: Skipping version check.${NC}"
		VERSION="$CURRENT_PLUGIN_VERSION"
	elif [ "$1" == "--fix" ]; then
		if [ "$CURRENT_PLUGIN_VERSION" != "$LATEST_TAG" ] || [ "$CURRENT_README_VERSION" != "$LATEST_TAG" ]; then
			echo -e "${BLUE}📦 Updating file versions to match tag: ${LATEST_TAG}...${NC}"

			# Update Main Plugin File
			sed -i.bak -E "s/(Version: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$MAIN_FILE"

			# Update Readme
			sed -i.bak -E "s/(Stable tag: *)[0-9A-Za-z.-]+/\1$LATEST_TAG/" "$README_FILE"

			# Clean up sed backups (macOS/BSD vs GNU compatibility)
			rm -f "$MAIN_FILE.bak" "$README_FILE.bak"
			echo -e "${GREEN}✅ Files updated.${NC}"
		fi
	else
		# Just Check
		if [ "$CURRENT_PLUGIN_VERSION" != "$LATEST_TAG" ]; then
			echo -e "${RED}❌ Version Mismatch!${NC}"
			echo "   Git Tag: $LATEST_TAG"
			echo "   File:    $CURRENT_PLUGIN_VERSION"
			echo "   Run 'bash scripts/build.sh --fix' to sync them."
			echo "   Or 'bash scripts/build.sh --dev' to skip version check for development."
			exit 1
		fi
		echo -e "${GREEN}✅ Versions match ($LATEST_TAG).${NC}"
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

# Helper function to run wp dist-archive
run_dist_archive() {
	local cmd_prefix="$1" # e.g., "docker compose exec -T cli" or ""
	local target_dir="$2" # e.g., "/var/www/html/..." or "."
	local output_dir="$3" # e.g., "/tmp" or "dist/archives"

	echo -e "${BLUE}🚀 Running dist-archive...${NC}"

	# Construct the command
	if [ -z "$cmd_prefix" ]; then
		# Local execution
		wp dist-archive . "$output_dir" --create-target-dir --format=zip
	else
		# Docker execution
		# 1. Check if dist-archive exists in container
		if ! $cmd_prefix wp cli has-command dist-archive >/dev/null 2>&1; then
			echo -e "${YELLOW}⚠️  'dist-archive' command missing in container. Installing...${NC}"
			$cmd_prefix wp package install wp-cli/dist-archive-command --quiet
		fi

		# 2. Run the build inside container outputting to temp
		$cmd_prefix sh -c "cd $target_dir && wp dist-archive . /tmp/ --format=zip --force"

		# 3. Copy out (Assuming the standard naming convention of dist-archive)
		# Note: dist-archive names files based on the version in the plugin file.
		local container_id
		container_id=$(docker compose ps -q cli)

		# We assume the file generated is plugin-slug.version.zip
		docker cp "${container_id}:/tmp/${PLUGIN_SLUG}.${VERSION}.zip" "./${DIST_DIR}/${PLUGIN_SLUG}.${VERSION}.zip"
	fi
}

# ------------------------------------------------------------------------------
# Environment Detection
# ------------------------------------------------------------------------------

if [ "$CI" = "true" ] || [ "$ACT" = "true" ]; then
	echo "🤖 CI Environment Detected"
	wp dist-archive . "$DIST_DIR" --create-target-dir --format=zip --allow-root

elif command -v wp &>/dev/null && wp core version &>/dev/null; then
	echo "✅ Local WP-CLI Detected"
	run_dist_archive "" "." "$DIST_DIR"

else
	echo "🐳 Docker Environment Detected"

	export COMPOSE_PROJECT_NAME="wphaven-connect"

	# Ensure CLI is up
	if [ -z "$(docker compose ps -q cli 2>/dev/null)" ]; then
		echo -e "${YELLOW}⚠️  CLI container not running. Starting...${NC}"
		docker compose up cli -d
	fi

	# Standard Docker Path for plugins
	DOCKER_PLUGIN_PATH="/var/www/html/wp-content/plugins/${PLUGIN_SLUG}"

	run_dist_archive "docker compose exec -T cli" "$DOCKER_PLUGIN_PATH" ""
fi

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
