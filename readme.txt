=== WPHaven Connect ===
Contributors: itsjustxan, emboldtyler
Tags: admin, management
Requires at least: 6.0
Tested up to: 6.9.0
Stable tag: 0.22.0
Requires PHP: 7.4

Provides functionality to connect to the remote maintenance and management platform.

== Description ==

Provides functionality to connect to the remote maintenance and management platform.

== Changelog ==

= 0.25.0 =
* Add an "Uploads" tab that syncs the wp-content/uploads directory between this environment and production, in either direction. Pair it with a Database Transfer so migrated content finds its media.
* Additive by design: files missing on the destination are copied over (optionally also files that differ), and nothing is ever deleted.
* Transfers compare file manifests first and only move what's needed, chunk large files by byte range, show a progress bar, and are idempotent (re-running only moves what's still missing). Available on non-production environments only.
* Database Transfer now advises taking a RunCloud on-demand backup from WP Haven before pushing (there is no in-plugin snapshot).

= 0.24.0 =
* Add a "Database Transfer" tab to the WP Haven Connect settings page (the existing settings become the first tab).
* Select any tables and Send to Production or Pull from Production; the destination tables are overwritten and the source domain is rewritten to the destination's throughout, safely handling PHP-serialized data.
* Each table is imported into a temporary table and swapped into place atomically; the old table is renamed aside only during the swap and dropped as soon as it succeeds (kept only if the transfer fails) — so the live table is never empty mid-transfer. There is no persistent backup, and the destination needs transient free disk roughly equal to the largest table being transferred.
* Transfers are chunked with a live progress bar, and each direction requires typing an exact confirmation phrase before it will run. Available on non-production environments only.
* Known limitations: multisite blog tables and non-prefixed tables are out of scope; very large tables are slower over the chunked transfer.

= 0.23.0 =
* Add Content Transfer: send an individual post, page or custom post type to production (or pull the production version back) right from the editor, on both the block and classic editors.
* Add a "Connection Settings" section with a Production URL field and an editable, regenerable environment connection secret that must match across every environment.
* Transfers copy the post, its custom fields (including ACF and Yoast, which store as post meta), assigned terms, the featured image and images embedded in the content. Media files are embedded in the transfer payload so they import reliably even when the source environment is not publicly reachable; production-hosted media is linked rather than re-uploaded.
* The source site's domain is automatically rewritten to the destination's across content, excerpt and meta, so references to the origin environment are repointed on arrival (ASSET_URL production media is left untouched).
* Transfers preview a summary before applying, land new items as drafts, keep the target's publish status unless explicitly published, snapshot the target before overwriting, and warn when the target changed more recently.
* Known limitations: nested ACF (repeater/flexible/clone) media, Yoast primary category and OG image IDs, and WooCommerce galleries/variations are not remapped in this version.

= 0.22.0 =
* Add a protected `/health` endpoint (and `wp wphaven health` command) reporting WP-Cron, email delivery, disk usage, PHP fatals, missed scheduled posts, and SSL certificate expiry.
* Surface the same signals on the WordPress Site Health screen.
* Allow authenticating the monitoring endpoints with a per-site bearer token in addition to the IP allowlist.
* Show the admin-bar environment indicator to all content editors, not just WP Haven staff, so non-production is obvious to anyone editing.

= 0.21.1 =
* Maintenance release: repair the automated build/release pipeline so distribution archives publish correctly. No functional changes to the plugin.

= 0.21.0 =
* Automatically bypass custom admin login obfuscation on `embold.dev` dev domains so `/wp-admin` and `wp-login.php` keep working. Override with the `WPH_DISABLE_LOGIN_BYPASS` constant or `wph_login_obfuscation_bypassed` filter.

= 0.20.1 =
* Fix conflict detection to only show when the admin login slug is set, make styling consistent with other notices.

= 0.20.0 =
* Add settings link to plugin list row.
* Allow setting 404 page for admin slug redirection.
* Plugin conflict detection - warn when WPS Hide Login plugin is active.

= 0.19.3 =
* Allow installation via git clone again by re-including vendor in git.
* Add checks if required plugin folders are missing.

= 0.19.2 =
* Fix fatal error during uninstall.

= 0.19.1 =
* Fix fresh plugin install missing composer vendor directory.

= 0.19.0 =
* Add update commit message generator.
* Update Docker development configuration build process and doc.
* Add plugin options page with settings.
* Update disable mail function for compatibility with equivelant embold-wordpress-tweaks feature.
* Fix magic login expired token notice when already logged in.
* Move notice suppression to embold-wordpress-tweaks.
* Fix custom admin login slug to properly handle password reset flows and prevent redirect loops.

= 0.18.1 =
* Fix issue with support ticket always showing the success message

= 0.18.0 =
* Create support tickets right from the WordPress dashboard

= 0.17.1 =
* Fix errors with PHP 7.4, replaced match statement with switch

= 0.17.0 =
* Add Custom Admin Login Provider to hide default WordPress admin/login URLs via WPH_ADMIN_LOGIN_SLUG constant
* Add configurable textdomain notice suppression via WPH_SUPPRESS_TEXTDOMAIN_NOTICES constant (defaults to true in development)
* Add Environment utility class for centralized environment detection across providers
* Update environment indicator admin bar badge with new WP Haven brand colors and improved styling
* Fix custom admin login redirect loops and unauthorized access handling
* Add cross-platform development support with comprehensive Copilot instructions for Windows, macOS, and Linux

= 0.16.2 =
* Re-release 0.16.1 to fix missing commit reference. *

= 0.16.1 =
* Add margins around environment indicator.

= 0.16.0 =
* Improved Docker setup with database health checks and environment configuration to resolve startup warnings.
* Added GitHub Action to automatically set plugin version from Git tag during release.
* Added ElevatedUsers gatekeeper function from embold-wordpress-tweaks.
* Refactored and restyled the admin bar environment indicator.
* Bump 'Tested up to' version.

= 0.15.0 =
* Add Docker Compose configuration for local development environment

= 0.14.0 =
* Allow Mailgun "Test Configuration" button to still work when mail is disabled. Add CLI command to send test email.

= 0.13.0 =
* Add terminal command `wp homepage edit` to return the admin URL of the home page

= 0.12.1 =
* Set transient if WP isn't completely dead to only send the error to WP Haven every 5 minutes

= 0.12.0 =
* Allow sending email in local or staging by setting DISABLE_MAIL to false in the wp-config.php of local or staging.

= 0.11.0 =
* Add bot likelihood, user agent, IP address, referrer, and more to error monitor

= 0.10.0 =
* Block sending emails on local, development, maintenance, and staging environments

= 0.9.6 =
* Don't notify WP Haven Wordfence API about files that have been ignored

= 0.9.5 =
* Use the same error filter as the WP Core for what is considered worth notifying about

= 0.9.4 =
* Don't handle shutdown when not in an error state

= 0.9.3 =
* Add a pretty printed and more helpful stacktrace

= 0.9.2 =
* Filter out all notice types, warning types, and non-production domains from this end of the API

= 0.9.1 =
* Enqueue environment indicator stylesheet on the frontend for logged in users

= 0.9.0 =
* Display an environment indicator in the admin bar

= 0.8.1 =
* Disable error handler notifications for anything non-critical

= 0.8.0 =
* Enable the full error monitor

= 0.7.0 =
* Globally block WooCommerce tracking

= 0.6.0 =
* Isolate the class autoloader to avoid conflicts from any other composer.json

= 0.5.0 =
* Query for notices from WP Haven and show alert to go check dashboard

= 0.4.3 =
* Remove unused WP CLI call

= 0.4.2 =
* Combine PHP and DB API routes into one /server-info route. Add a wp-cli command for fetching it

= 0.4.1 =
* Fix for the PHP Basic version number string

= 0.4.0 =
* API endpoints for PHP and DB information

= 0.3.4 =
* Whitelist the office static IP

= 0.3.3 =
* Allow production IP to fetch Wordfence alerts

= 0.3.2 =
* Disable sending Slack notifications until site is live due to htpasswd

= 0.3.1 =
* Only try to push errors to Slack if there's a domain set, aka ignore terminal errors

= 0.3.0 =
* Get the first admin user registered if requested user is missing

= 0.2.0 =
* Fallback to another URLs assets by setting ASSET_URL constant in wp-config

= 0.1.0 =
* Error handling and reporting

= 0.0.5 =
* Revamp the magic login link

= 0.0.4 =
* Add cookie

= 0.0.3 =
* Add Wordfence API

= 0.0.2 =
* Clean up readme

= 0.0.1 =
* Initial commit with custom CLI command
