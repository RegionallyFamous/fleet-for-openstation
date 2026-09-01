=== Fleet for OpenStation ===
Contributors: openstation
Tags: openstation, multisite, agency, site management
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage connected WordPress sites inside OpenStation using Core Application Passwords and WordPress REST APIs.

== Description ==

Fleet for OpenStation turns one OpenStation install into an agency hub. Connect another WordPress site through Core's revocable Application Password approval, then manage that site's content, plugins, settings, and any REST API route without opening another wp-admin. Every managed site gets a stable, named OpenStation window, so multiple sites can stay open at the same time.

Fleet never asks for the administrator's normal password. WordPress generates a separate Application Password for Fleet, and Fleet encrypts it at rest on the hub. WordPress capability checks remain authoritative for every request.

After approval, Fleet verifies the connection and installs or activates OpenStation automatically through the Core Plugins API. If installation fails, the WordPress connection remains available so the user can retry without reconnecting.

Focused workspaces cover posts, pages, comments, media, users, plugins, and common Core settings. A live API Explorer lists every route the selected site advertises—including taxonomies, navigation, templates, blocks, widgets, global styles, fonts, themes, Site Health, and plugin routes—and can run any method the approved WordPress account is allowed to use.

Fleet Inbox combines pending comments, drafts, posts awaiting review, scheduled posts, connection issues, and Core Site Health findings across the fleet. Live fleet search finds content, media, comments, and users. Client workspaces open every site assigned to one client as persistent OpenStation windows. These features use only existing WordPress Core REST collections and the Core batch controller; Fleet does not require a companion agent or custom managed-site endpoints.

== Installation ==

1. Install and activate OpenStation on the hub.
2. Upload and activate Fleet for OpenStation.
3. Open Fleet and connect a public HTTPS WordPress site.
4. Approve the native Application Password connection request on the managed site.

== Screenshots ==

1. The Fleet hub, network chart, filters, and connected-site manifest inside OpenStation.
2. Fleet Inbox combining editorial work, moderation, scheduled posts, and Site Health findings.
3. Live search across existing WordPress Core content, media, comments, and user collections.
4. A named client workspace ready to open multiple independent managed-site windows.
5. A managed WordPress site running inside its own focused OpenStation window.
6. The API Explorer running a live WordPress Core settings request.
7. Three independent Fleet windows open together in the OpenStation overview.
8. The guided native WordPress connection flow.

== Changelog ==

= 0.5.0 =

* Add a fleet-wide operations inbox for pending comments, editorial work, scheduled posts, connection issues, and Core Site Health findings.
* Add live search across content, media, comments, and users using only existing WordPress REST collections.
* Add persistent client workspaces that open every client site in its own OpenStation window.
* Refine Fleet as a compact OpenStation-native operations console and replace the complete screenshot set.

= 0.4.2 =

* Raise the Fleet hub reliably when returning from a managed-site workspace.

= 0.4.1 =

* Keep the Fleet hub in place when opening a managed-site window and make the workspace back action reliably focus the hub.

= 0.4.0 =

* Open each managed site in its own stable OpenStation window so multiple remote sites can stay open together.
* Keep navigation inside the selected site's window and make the Fleet back action focus the hub.
* Add a live, searchable API Explorer for every Core and plugin REST route advertised by the managed site.
* Expand capability discovery across content types, taxonomies, navigation, widgets, templates, patterns, blocks, styles, fonts, and statuses.

= 0.3.0 =

* Add an attention queue, Core Site Health summaries, WordPress version checks, and 15-minute background refreshes.
* Add safe bulk status checks and OpenStation install or activation actions.
* Add client names, tags, plan status, private notes, favorites, search, and filters.
* Add focused management for comments, media, users, and WordPress.org plugin installation.
* Add a private Fleet activity history and capability-aware workspace navigation.
* Make connection a single approval flow that automatically installs or activates OpenStation.
* Return connection authentication to WordPress Core Application Passwords.

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
