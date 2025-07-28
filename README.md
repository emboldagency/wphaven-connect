# WPHaven Connect

> **Note**: This is the development documentation. WordPress plugins use `readme.txt` for their official plugin information and changelog, not this README.md file.

WordPress plugin that provides functionality to connect to the remote maintenance and management platform.

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

### Building to dist/ Directory

To build the plugin for distribution (excluding development files):

1. Ensure the CLI container is running:
   ```bash
   docker compose up cli -d
   ```

2. Build the plugin to the dist directory:
   ```bash
   # Option 1: Use the build script (recommended)
   ./scripts/build.sh
   
   # Option 2: Manual steps
   # Create distribution archive and extract to dist/
   docker compose exec cli sh -c "cd /var/www/html/wp-content/plugins/wphaven-connect && wp dist-archive . /tmp/ --format=zip"
   
   # Set up directory structure
   mkdir -p dist/archives dist/extracted
   
   # Copy versioned archive (replace ${VERSION} with actual version)
   docker cp wphaven-connect-cli-1:/tmp/wphaven-connect.${VERSION}.zip ./dist/archives/wphaven-connect.${VERSION}.zip
   
   # Extract to dist/extracted/
   cd dist/extracted && unzip -q ../archives/wphaven-connect.${VERSION}.zip && cd ../..
   ```

3. The build creates this structure:
   ```
   dist/
   ├── archives/
   │   └── wphaven-connect-v0.17.0.zip  (WordPress-ready)
   └── extracted/
       ├── wphaven.php
       ├── src/
       └── ... (all plugin files)
   ```
   └── extracted/
       ├── wphaven.php
       ├── src/
       └── ... (all plugin files)
   ```

**Note**: On Windows, you can use WSL to run the bash script.

### Creating Distribution Archives

The project includes the `wp dist-archive` command for creating clean distribution archives that respect the `.distignore` file.

#### Setup (One-time)

The `wp-cli/dist-archive-command` package is pre-installed in the persistent CLI container. The CLI container uses a custom Dockerfile (`Dockerfile.cli`) that extends the standard `wordpress:cli` image with zip utilities pre-installed for faster builds.

#### Creating an Archive

1. Ensure the containers are running:
   ```bash
   docker compose up -d
   ```

2. Create a distribution archive:
   ```bash
   # Navigate to the plugin directory and create archive
   docker compose exec cli sh -c "cd /var/www/html/wp-content/plugins/wphaven-connect && wp dist-archive . /tmp/ --format=zip"
   ```

3. Copy the archive to your host machine:
   ```bash
   docker cp wphaven-connect-cli-1:/tmp/wphaven-connect.${VERSION}.zip .
   ```

The zip format is used as it's the standard format for WordPress plugin installation via the admin interface.

### What Gets Excluded

The `.distignore` file excludes development files such as:
- `.git/` directory and Git files
- `node_modules/` and package management files
- Testing and build configuration files
- Documentation files like `README.md`
- Development scripts and tools

The resulting archive contains only the files needed for production WordPress installation.

### Archive Size Comparison

- **Full directory**: ~40 MB (includes all development files)
- **Distribution archive**: ~99 KB (production files only)

## Environment Constants

The plugin supports several environment-specific constants:

- `WPH_ADMIN_LOGIN_SLUG`: Custom admin login URL slug (hides default wp-admin/wp-login.php)
- `WPH_SUPPRESS_TEXTDOMAIN_NOTICES`: Suppress textdomain loading notices (defaults to true in development)

## Plugin Structure

- `src/` - Main plugin source code
- `vendor/` - Composer dependencies
- `plugin-update-checker/` - Plugin update functionality
- `scripts/` - Build and deployment scripts
- `dist/` - Distribution builds (excluded from Git except .gitkeep files)
  - `archives/` - Versioned archive files (.zip)
  - `extracted/` - Extracted plugin files ready for deployment
- `docker-compose.yml` - Local development environment
- `Dockerfile.cli` - CLI container Dockerfile with zip utility
- `.env` - Docker Compose project configuration
