# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WPHaven Connect is a WordPress plugin that provides remote maintenance and management functionality. It connects WordPress sites to the WPHaven platform for monitoring, error reporting, and remote administration.

## Architecture

### Core Structure
- **Main Plugin File**: `wphaven.php` - Plugin bootstrap and update checker initialization
- **Service Provider Pattern**: `src/Providers/ServiceProvider.php` serves as the main service container, registering multiple specialized providers
- **Namespace**: All classes use the `WPHavenConnect\` namespace with PSR-4 autoloading

### Key Components
- **ErrorHandler** (`src/ErrorHandler.php`): Global error/exception handler that reports errors to WPHaven platform
- **Service Providers** (`src/Providers/`): Modular providers for different functionality:
  - AssetUrlServiceProvider - Asset URL management
  - ClientAlertsProvider - Client notification system
  - CommandLineServiceProvider - WP-CLI commands
  - CookieServiceProvider - Cookie management
  - DisableMailServiceProvider - Email blocking in non-production environments
  - EnvironmentIndicatorAdminBarBadgeProvider - Environment indicator in admin bar
  - PhpInfoServiceProvider - PHP information API
  - ServerInfoServiceProvider - Server information API
  - WooCommerceServiceProvider - WooCommerce integration
  - WordfenceServiceProvider - Wordfence security integration

### API Security
The plugin includes IP-based access control (`ServiceProvider::apiPermissionsCheck()`) with whitelisted IPs for secure API access.

## Development Commands

### Version Management
```bash
# Check if version numbers match latest Git tag
./scripts/sync-version-local.sh

# Update version numbers to match latest Git tag
./scripts/sync-version-local.sh --fix
```

### Docker Development
```bash
# Start local development environment
docker-compose up -d
```

## Plugin Update System
Uses the Plugin Update Checker library to enable automatic updates from the GitHub repository at `https://github.com/emboldagency/wphaven-connect/`.

## File Structure Notes
- Plugin uses Composer autoloading with PSR-4 mapping
- Third-party library (plugin-update-checker) included in `/plugin-update-checker/`
- Vendor dependencies in `/vendor/` (likely from Composer)
- CSS assets in `/src/assets/css/`

## Environment Handling
The plugin automatically detects and handles different environments (local, staging, production) and adjusts behavior accordingly, particularly for email sending and error reporting.

## WordPress Integration
- Hooks into `plugins_loaded` action for initialization
- Provides WP-CLI commands for terminal access
- Integrates with WordPress admin bar for environment indicators
- Uses WordPress transients for error throttling