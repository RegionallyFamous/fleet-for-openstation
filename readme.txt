=== Fleet for OpenStation ===
Contributors: openstation
Tags: openstation, multisite, agency, site management
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage connected WordPress sites inside OpenStation using OAuth and WordPress REST APIs.

== Description ==

Fleet for OpenStation turns one OpenStation install into an agency hub. Connect another OpenStation site through a revocable OAuth approval, then manage that site's content, plugins, settings, and any REST API route without opening another wp-admin.

OAuth uses Authorization Code with PKCE, short-lived access tokens, and rotating refresh tokens. Tokens are encrypted at rest on the hub. WordPress capability checks remain authoritative for every request.

If a managed site does not have OpenStation yet, Fleet can use a WordPress Core Application Password as a bootstrap connection and install OpenStation through the Core Plugins API. Disconnect and reconnect after installation to switch that site to OAuth.

== Installation ==

1. Install and activate OpenStation on the hub.
2. Upload and activate Fleet for OpenStation.
3. Open Fleet and connect a public HTTPS WordPress site.
4. Approve the Full API access request on the managed site.

== Changelog ==

= 0.2.1 =

* Redesign the hub as a compact OpenStation fleet map and site manifest.
* Make the remote-site context and management navigation clearer.

= 0.2.0 =
* Add OAuth Authorization Code with PKCE, short-lived access tokens, rotating refresh tokens, and two-sided revocation.
* Add an in-window remote management workspace for every connected site.
* Manage Core site settings, post and page status, and installed plugin status.
* Add a Full API console for any REST route available to the connected WordPress account.
* Size the Fleet workspace as a centered OpenStation window instead of using the full desktop.
* Keep Application Passwords only as the bootstrap path for sites that do not have OpenStation yet.

= 0.1.2 =
* Open managed-site authorization outside the OpenStation Fleet window so security headers do not block it.

= 0.1.1 =
* Preserve Fleet's state and nonce when returning from Application Password approval.

= 0.1.0 =
* Initial experimental release.
