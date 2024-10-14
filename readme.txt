=== WPHaven Connect ===
Contributors: itsjsutxan
Tags: admin, management
Requires at least: 6.0
Tested up to: 6.3.1
Stable tag: 0.4.2
Requires PHP: 7.4

== Description ==

# A plugin that provides functionality to connect to WPHaven.

== Changelog ==

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
