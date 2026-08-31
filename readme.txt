=== Fleet for OpenStation ===
Contributors: openstation
Tags: openstation, multisite, agency, site management
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress sites and install OpenStation using WordPress Core features.

== Description ==

Fleet for OpenStation is an experimental agency workflow. It uses Core Application Passwords to connect client sites and the Core Plugins REST API to inspect, install, and activate OpenStation.

Fleet stores each manager's connected sites in user meta and encrypts Application Passwords at rest. It does not require an agent plugin or hosted service.

== Installation ==

1. Install and activate OpenStation.
2. Upload and activate Fleet for OpenStation.
3. Open Fleet and connect an HTTPS WordPress site.

== Changelog ==

= 0.1.1 =
* Preserve Fleet's state and nonce when returning from Application Password approval.

= 0.1.0 =
* Initial experimental release.
