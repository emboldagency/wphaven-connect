# WPHaven Connect

[![Build and Deploy](https://embold.net/api/github/badge/workflow-status.php?repo=wphaven-connect&workflow=release.yml)](https://github.com/emboldagency/wphaven-connect/actions/workflows/release.yml) <!--
-->![Semantic Versioning](https://embold.net/api/github/badge/semver.php?repo=wphaven-connect)

> **Note**: This is the development documentation. WordPress plugins use `readme.txt` for their official plugin
> information and changelog, not this README.md file.

WordPress plugin that provides functionality to connect to the remote maintenance and management platform.

## Configuration

Configuration is available via:

1. **WordPress Settings Page** (`Settings > WP Haven Connect`):
   - Mail delivery mode (No Override, SMTP Override, Block All)
   - SMTP configuration (host, port, from address, from name)
   - Debug notice suppression
   - Custom notice strings to suppress
   - Elevated admin emails
   - WP Haven API base URL
   - Custom admin login slug

2. **Environment Constants** (in `wp-config.php`):
   - `ELEVATED_EMAILS`: Array of admin emails
   - `WPH_DISABLE_MAIL` (formerly `DISABLE_MAIL`): Block all mail when true
   - `WPH_SMTP_OVERRIDE`: Force SMTP mode when true
   - `WPH_SMTP_HOST`, `WPH_SMTP_PORT`, `WPH_SMTP_FROM_EMAIL`, `WPH_SMTP_FROM_NAME`: SMTP settings
   - `WPH_SUPPRESS_NOTICES` (formerly `WPH_SUPPRESS_TEXT_NOTICES`): Suppress debug notices when true
   - `WPH_ADMIN_LOGIN_SLUG`: Custom admin login URL slug
   - `WPHAVEN_API_BASE`: WP Haven API base URL

Constants take precedence over plugin settings.

Example usage in `wp-config.php`:

`define('ELEVATED_EMAILS', ['worf@embold.com', 'spock@embold.com']);`

## Development Setup

This project uses Docker Compose for local development.

### Prerequisites

- Docker and Docker Compose
- Git

### Getting Started

1. Clone the repository
2. Start the development environment:
   ```bash
   docker compose up -d
   ```
3. Access WordPress at http://localhost:8080

### WP-CLI Usage

The project includes a persistent CLI container for easier package management:

```bash
# Start the CLI container (if not already running)
docker compose up cli -d

# Run WP-CLI commands
docker compose exec cli wp --info

# Install WP-CLI packages (they persist across restarts)
docker compose exec cli wp package install <package-name>
```

## Building for Distribution

### Automated Release via GitHub Actions

The plugin uses **GitHub Actions** to automatically create and publish releases:

**How it works:**

1. Create a new Git tag with semantic versioning:
   ```bash
   git tag 0.19.1
   git push origin 0.19.1
   ```

2. GitHub Actions automatically:
   - Runs the `.github/workflows/release.yml` workflow
   - Installs PHP, Composer, and WP-CLI
   - Installs the `wp-cli/dist-archive-command`
   - Creates a clean distribution ZIP file
   - Publishes it as a GitHub Release
3. The build creates this structure:
   ```
   dist/
   ├── archives/
   │   └── wphaven-connect-v0.17.0.zip  (WordPress-ready)
   └── extracted/
       ├── wphaven.php
       ├── src/
       └── ... (all plugin files)
   └── extracted/
   ├── wphaven.php
   ├── src/
   └── ... (all plugin files)
   ```

4. The ZIP archive is automatically available for:
   - Direct downloads from GitHub Releases
   - Distribution to WordPress.org plugin registry
   - Auto-update functionality via plugin-update-checker

**Testing the workflow locally:**

You can test the GitHub Actions workflow locally using [act](https://github.com/nektos/act):

```bash
# Run the release workflow locally
act --workflows ".github/workflows/release.yml" --job dist

# The workflow will build and verify the archive without publishing
```

### Manual Building to dist/ Directory

For local development builds without triggering a release:

**Option 1: Development build (skip version checks)**
```bash
composer run build:dev
```

**Option 2: Production build (requires version match)**
```bash
composer run build
```
- Verifies that `wphaven.php` version matches the latest Git tag
- Run `composer run version:fix` to auto-sync versions

**Option 3: Using the Build Script Directly**
```bash
./scripts/build.sh              # Check version and build
./scripts/build.sh --dev        # Skip version check (dev mode)
./scripts/build.sh --fix        # Sync versions and build
```

All methods:
- Extract version from `wphaven.php`
- Create a clean distribution archive using `wp dist-archive`
- Generate `dist/archives/wphaven-connect-<VERSION>.zip`
- Respect `.distignore` file for excluding development files

### Build Output Structure

```
dist/
└── archives/
    └── wphaven-connect-v0.19.0.zip  (~186KB, WordPress-ready)
```

### What Gets Excluded

The `.distignore` file excludes development files such as:

- `.git/` directory and Git files
- `node_modules/` and package management files
- `composer.json` and `composer.lock`
- Testing and build configuration files
- Documentation files like `README.md`
- Development scripts and Docker files

The resulting archive contains only the files needed for production WordPress installation.

## Plugin Structure

- `src/` - Main plugin source code
- `vendor/` - Composer dependencies
- `plugin-update-checker/` - Plugin update functionality
- `readme.txt` - Plugin readme/changelog
- `wphaven.php` - Main plugin entrypoint

