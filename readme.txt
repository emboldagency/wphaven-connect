=== WPHaven Connect ===
Contributors: itsjustxan, emboldtyler
Tags: admin, management
Requires at least: 6.0
Tested up to: 6.9.0
Stable tag: 0.19.0
Requires PHP: 7.4

Provides functionality to connect to the remote maintenance and management platform.

== Description ==

Provides functionality to connect to the remote maintenance and management platform.

== Changelog ==

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
