# Build & Release Guide

## Overview

This document provides guidance for building, testing, and releasing the WPHaven Connect plugin.

## Prerequisites

- Git with tags configured
- Composer (for local development)
- Docker & Docker Compose (for local environment)
- WP-CLI (for local building, optional if using Docker)

## Version Management

### Understanding Versions

Versions follow semantic versioning: `MAJOR.MINOR.PATCH` (e.g., `0.19.0`)

- **MAJOR**: Breaking changes
- **MINOR**: New features (backward compatible)
- **PATCH**: Bug fixes

### Creating Releases

1. **Update version in files** (or use build script to auto-fix):
   ```bash
   bash scripts/build.sh --fix
   ```
   This updates:
   - Version in `wphaven.php` header
   - Stable tag in `readme.txt`

2. **Create a Git tag**:
   ```bash
   git tag 0.19.0
   git push origin 0.19.0
   ```

3. **Create prerelease tags** (optional):
   ```bash
   git tag 0.19.0-pre
   git push origin 0.19.0-pre
   ```

## Local Development

### Setting Up

1. Start Docker environment:
   ```bash
   docker compose up -d
   ```

2. Install Composer dependencies:
   ```bash
   composer install
   ```

### Available Scripts

**Linting & Formatting**:
```bash
composer lint          # Check code quality
composer format        # Auto-format code
```

**Building**:
```bash
composer build        # Build production distribution
composer build:dev    # Build without version checks
composer version:fix  # Auto-fix version mismatches
```

## Building

### Production Build

```bash
bash scripts/build.sh
```

**What it does**:
1. Verifies version numbers match Git tag
2. Installs production dependencies
3. Creates distribution ZIP in `dist/archives/`
4. Auto-detects environment (CI, local WP-CLI, or Docker)

### Development Build

```bash
bash scripts/build.sh --dev
```

**Skips version validation** - useful during development.

### Fix Version Mismatches

```bash
bash scripts/build.sh --fix
```

**Auto-updates** `wphaven.php` and `readme.txt` to match Git tag.

## GitHub Actions Release Process

Releases are automatically triggered when you push a Git tag matching:
- Standard releases: `0.19.0`
- Prerelease: `0.19.0-pre`
- Optional v prefix: `v0.19.0`

### Workflow Steps

1. ✅ Checkout code
2. ✅ Set up PHP 8.3, Composer, WP-CLI
3. ✅ Cache Composer dependencies
4. ✅ Verify/sync versions from tag
5. ✅ Install dist-archive WP-CLI command
6. ✅ Install dependencies
7. ✅ Build distribution
8. ✅ Create GitHub Release with ZIP attached

## Troubleshooting

### Version Mismatch Error

**Error**: `Version Mismatch! Git Tag: 0.19.0, File: 0.18.5`

**Solution**:
```bash
bash scripts/build.sh --fix
git add wphaven.php readme.txt
git commit -m "🔖 Sync version to 0.19.0"
```

### Docker Build Issues

**Issue**: Container not found

**Solution**:
```bash
docker compose ps -q cli    # Check if running
docker compose up cli -d    # Start container
bash scripts/build.sh       # Try build again
```

### Local WP-CLI Not Found

**Issue**: `wp: command not found`

**Solution**: Either install WP-CLI or use Docker:
```bash
docker compose up -d
bash scripts/build.sh  # Will auto-detect Docker
```

## File Structure

```
wphaven-connect/
├── scripts/
│   ├── build.sh              # Main build script
│   └── sync-version-local.sh # Version sync utility
├── docker/
│   ├── Dockerfile.cli        # WP-CLI image
│   ├── docker.env            # Environment config
│   └── docker.env.example    # Example config
├── dist/
│   ├── archives/             # Distribution ZIPs
│   └── extracted/            # Extracted files
├── .github/
│   └── workflows/
│       └── release.yml       # GitHub Actions workflow
├── composer.json             # PHP dependencies
└── wphaven.php              # Main plugin file
```

## Tips & Best Practices

1. **Always use git tags** for releases - the build system relies on them
2. **Test locally first** using `--dev` mode before creating actual releases
3. **Keep versions synced** - use `build.sh --fix` before tagging
4. **Review the build output** to ensure all steps complete successfully
5. **Test dist-archive output** to ensure the ZIP includes only necessary files
