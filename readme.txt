=== Fleet for OpenStation ===
Contributors: openstation
Tags: openstation, agency, site management, application passwords
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.10.0-alpha.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn one OpenStation install into a calm, windowed workspace for every WordPress site you manage.

== Description ==

One WordPress site is easy. Ten unrelated client sites mean ten dashboards, ten places to check, and ten chances to make the right change on the wrong site.

Fleet gives that work one home. One OpenStation install becomes the hub for every WordPress site you manage. See which sites need attention, open each site in its own clearly named window, and work without juggling bookmarks and wp-admin tabs.

This source tree is the unreleased 0.10.0-alpha.1 reliability preview for development and staging. The public 0.9.0-rc.1 download does not include every workflow described below. The 0.10 preview's saved multi-window fix is proven locally in OpenStation PR #763 but is not yet part of a released OpenStation build.

= The work comes to you =

Fleet Inbox brings pending comments, drafts, posts awaiting review, scheduled work, connection problems, and useful Site Health findings into one queue. Fleet Search uses a secure incremental index refreshed in short background passes, so a large fleet does not make you wait for one live request per site.

Fast status checks stay frequent, heavier metadata and search work runs less often, and unavailable sites automatically back off until they recover.

= Every site stays independent =

Fleet is installed once, on the hub. Managed sites keep their own WordPress installation, hosting, content, users, and permissions. They do not need a second Fleet plugin or custom Fleet endpoints.

Each connected site opens as a separate native OpenStation window. Client workspaces can open up to eight related site windows at once while larger fleets remain available from the Sites tab.

= Manage what WordPress already exposes =

Focused views cover posts, pages, media details, comments, modern block-theme design, plugins, users, settings, and private agency notes. Explorer can use any REST route advertised by the connected site, with an explicit confirmation before write or delete requests. WordPress capability checks remain authoritative.

= Connect without sharing your normal password =

Connections use WordPress Core's own Application Password approval screen. Fleet never receives the administrator's normal password. WordPress creates a separate, revocable credential for Fleet, and Fleet encrypts its copy on the hub.

Review the detected site before approval, then choose Finish setup to install or activate OpenStation there. Management windows run on the hub. Fleet itself remains installed only on the hub. Repair a revoked connection without losing client details.

Posts and pages have a source editor for HTML and WordPress block markup, with explicit saves, conflict checks, and unsaved-change warnings. It is not the visual block editor or a durable autosave. Application Passwords grant the approving administrator's access and do not automatically expire.

Fleet includes no telemetry or third-party tracking.

Before publishing or scheduling, review the destination and changes, then confirm the save. Compare earlier WordPress revisions and load supported source fields into the editor without saving automatically. Save frequently used content filters per site, and manage editable REST-enabled custom content types with standard WordPress fields.

== Installation ==

1. Install and activate a current OpenStation build with the experimental App Framework on the hub.
2. Upload and activate Fleet for OpenStation on that hub only.
3. Open Fleet and enter a public HTTPS WordPress site address.
4. Choose Check connection, review the site, and continue to WordPress's approval screen.
5. Return to Fleet and choose Finish setup. Each connected site opens in its own named window.

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
9. Edit HTML and WordPress block source in the right site's named window.
10. Review the destination and changes before confirming a publishing or scheduling save.

== Changelog ==

= 0.10.0-alpha.1 =

* Unreleased reliability milestone: opt-in encrypted crash recovery, Core publishing options, media uploads, comment replies and reviewed bulk moderation.
* Explicit hub team roles with live revocation, cache freshness and retry visibility.
* Mandatory browser CI and a disposable multi-origin MySQL/MariaDB load lab.
* Verified saved multi-window identity with the exact OpenStation PR #763 build; an upstream release, long endurance run and independent hosting pilots remain launch gates.

= 0.9.0-rc.1 =

* Target WordPress 7.1+ and PHP 8.3+ with the native OpenStation App Framework.
* Fix unsaved window-close protection, stale health findings, timezone saves and malformed dates.
* Review publishing/scheduling changes and recover supported fields from Core revisions.
* Save per-site work views and discover supported REST-enabled custom content types.
* Preserve existing connection records when new metadata defaults are introduced.
* Review connection readiness and administrator access before WordPress approval.
* Repair revoked connections without losing agency details; retry setup separately.
* Verify authenticated access during status checks so revoked credentials cannot appear healthy.
* Create and edit post/page source, excerpts, slugs, status and UTC schedules.
* Warn about unsaved content and detect changes made elsewhere before saving.
* Prevent automatic replay of uncertain draft creation; confirm before trashing.
* Search and paginate content, media, comments and users in smaller collections.
* Add local redacted support diagnostics. No telemetry or new remote endpoints.

= 0.8.0 =

* Split fleet storage into independent per-site records with automatic migration from earlier versions.
* Added time-budgeted background synchronization, separate refresh cadences, and bounded retry backoff.
* Added incremental paginated search indexing with daily reconciliation and bounded storage.
* Added short-lived read caching and duplicate-action protection while preserving independent site windows.
* Hardened concurrent connection and search updates, multisite isolation, credential cleanup, and Explorer request validation.
* Added reproducible release builds, version consistency checks, immutable CI actions, dependency updates, static analysis, compatibility checks, and an opt-in two-site browser and accessibility suite.

= 0.7.0 =

* Rebuilt Fleet exclusively on OpenStation's experimental App Framework with no classic interface.
* Made each managed site an independent native window while keeping Fleet installed only on the hub.
* Added focused site management, confirmed REST writes, Core Abilities discovery, client workspaces, Inbox, and cached fleet search.
* Added bounded background checks, concurrent per-site state merging, response-size limits, privacy tools, and stricter framework dependency checks.
* Refined the native design, accessibility, packaging, automated checks, documentation, and complete screenshot set.
