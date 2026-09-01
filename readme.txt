=== Fleet for OpenStation ===
Contributors: openstation
Tags: openstation, agency, site management, application passwords
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn one OpenStation install into a calm, windowed workspace for every WordPress site you manage.

== Description ==

One WordPress site is easy. Ten unrelated client sites mean ten dashboards, ten places to check, and ten chances to make the right change on the wrong site.

Fleet gives that work one home. One OpenStation install becomes the hub for every WordPress site you manage. See which sites need attention, open each site in its own clearly named window, and work without juggling bookmarks and wp-admin tabs.

= The work comes to you =

Fleet Inbox brings pending comments, drafts, posts awaiting review, scheduled work, connection problems, and useful Site Health findings into one queue. Fleet Search uses a secure local index refreshed during site checks, so a large fleet does not make you wait for one live request per site.

= Every site stays independent =

Fleet is installed once, on the hub. Managed sites keep their own WordPress installation, hosting, content, users, and permissions. They do not need a second Fleet plugin or custom Fleet endpoints.

Each connected site opens as a separate native OpenStation window. Client workspaces can open up to eight related site windows at once while larger fleets remain available from the Sites tab.

= Manage what WordPress already exposes =

Focused views cover posts, pages, media details, comments, modern block-theme design, plugins, users, settings, and private agency notes. Explorer can use any REST route advertised by the connected site, with an explicit confirmation before write or delete requests. WordPress capability checks remain authoritative.

= Connect without sharing your normal password =

Connections use WordPress Core's own Application Password approval screen. Fleet never receives the administrator's normal password. WordPress creates a separate, revocable credential for Fleet, and Fleet encrypts its copy on the hub.

During setup, Fleet can install or activate OpenStation on the managed site so it can open as a native desktop window. Fleet itself remains installed only on the hub.

Fleet includes no telemetry or third-party tracking.

== Installation ==

1. Install and activate a current OpenStation build with the experimental App Framework on the hub.
2. Upload and activate Fleet for OpenStation on that hub only.
3. Open Fleet and enter a public HTTPS WordPress site address.
4. Approve the connection on the managed site's WordPress screen, then return to Fleet and choose Manage.

== Frequently Asked Questions ==

= Does Fleet need to be installed on every site? =

No. Fleet is installed only on the hub. Managed sites use WordPress Core REST APIs and Application Passwords. Fleet can install or activate OpenStation there during setup.

= Does Fleet receive my WordPress password? =

No. Sign-in and approval happen on the managed WordPress site. Fleet receives a separate Application Password that you can revoke from that site's user profile.

= Can Fleet do something my WordPress account cannot? =

No. The managed site checks the approving account's WordPress capabilities on every request.

= What should I do before uninstalling Fleet? =

Disconnect every managed site from Fleet first. Disconnecting revokes the remote Application Password. Uninstalling Fleet removes its local data but cannot contact a site after its encrypted credential has been deleted.

= Does Fleet work without the OpenStation App Framework? =

No. Fleet is a native App Framework application and intentionally has no classic wp-admin interface.

== Screenshots ==

1. See every site, its status, and the work waiting for you from one OpenStation window.
2. Comments, drafts, scheduled work, and site issues come to one shared inbox.
3. Find recent content, media, comments, and people across the fleet.
4. Open every site for one client as a saved workspace.
5. Manage publishing work without opening another wp-admin.
6. Reach additional APIs already advertised by the connected WordPress site.
7. Keep two client sites open at once without losing track of which site you are changing.
8. Start a connection with only the managed site's HTTPS address—no Fleet plugin installation there.

== Changelog ==

= 0.7.0 =

* Rebuilt Fleet exclusively on OpenStation's experimental App Framework with no classic interface.
* Made each managed site an independent native window while keeping Fleet installed only on the hub.
* Added focused site management, confirmed REST writes, Core Abilities discovery, client workspaces, Inbox, and cached fleet search.
* Added bounded background checks, concurrent per-site state merging, response-size limits, privacy tools, and stricter framework dependency checks.
* Refined the native design, accessibility, packaging, automated checks, documentation, and complete screenshot set.
