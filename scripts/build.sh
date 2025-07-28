#!/bin/bash

# Build script for WPHaven Connect plugin
# This script creates a clean distribution build in the dist/ directory

set -e

echo "🔧 Building WPHaven Connect plugin..."

# Set project name explicitly to avoid hash-based container names
PROJECT_NAME="wphaven-connect"
export COMPOSE_PROJECT_NAME="$PROJECT_NAME"

# Extract version from plugin file
VERSION=$(grep "Version:" wphaven.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
echo "📦 Plugin version: $VERSION"

# Ensure CLI container is running
echo "📦 Starting CLI container..."
docker compose up cli -d

# Get the actual container name (more robust)
CLI_CONTAINER=$(docker compose ps -q cli)
if [ -z "$CLI_CONTAINER" ]; then
    echo "❌ CLI container not found!"
    exit 1
fi

CLI_CONTAINER_NAME=$(docker inspect --format='{{.Name}}' $CLI_CONTAINER | sed 's/^.//')
echo "📋 Using CLI container: $CLI_CONTAINER_NAME"

# Create zip distribution archive
echo "📦 Creating zip distribution archive..."
docker compose exec cli sh -c "cd /var/www/html/wp-content/plugins/wphaven-connect && wp dist-archive . /tmp/ --format=zip"

# Set up dist directory structure
echo "📁 Setting up dist directory structure..."
mkdir -p dist/archives
mkdir -p dist/extracted

# Clean existing files (except .gitkeep and directory structure)
find dist/extracted -mindepth 1 -delete 2>/dev/null || true
rm -f dist/archives/wphaven-connect-v*.zip 2>/dev/null || true

# Copy and rename archives with version
echo "📋 Copying versioned archive..."
docker cp "$CLI_CONTAINER_NAME":/tmp/wphaven-connect.${VERSION}.zip "./dist/archives/wphaven-connect.${VERSION}.zip"

# Extract archive to extracted/
echo "📂 Extracting to dist/extracted directory..."
cd dist/extracted
unzip -q "../archives/wphaven-connect.${VERSION}.zip" -d temp
mv temp/*/* . 2>/dev/null || mv temp/* . 2>/dev/null || true
rm -rf temp
cd ../..

echo "✅ Build complete!"
echo "📁 Distribution structure:"
echo "   dist/archives/wphaven-connect.${VERSION}.zip (WordPress-ready)"
echo "   dist/extracted/ (plugin files)"

# Get file sizes
ZIP_SIZE=$(docker compose exec cli sh -c "ls -lh /tmp/wphaven-connect.${VERSION}.zip 2>/dev/null" | awk '{print $5}' 2>/dev/null || echo "unknown")
echo "📊 Archive size: ${ZIP_SIZE}"

# Show directory structure
echo "📁 Dist directory contents:"
ls -la dist/
echo "📁 Archives:"
ls -la dist/archives/ 2>/dev/null || echo "   (empty)"
echo "📁 Extracted files (first 10):"
ls -la dist/extracted/ 2>/dev/null | head -10 || echo "   (empty)"
