# Fleet for OpenStation

**Your WordPress sites, all in one place.**

Fleet turns one OpenStation install into a desktop for every WordPress site you manage. See what needs attention, open the right site in its own window, and get the work done without bouncing through bookmarks, login screens, and wp-admin tabs.

Each site stays independent. Fleet is installed once on your hub—not on every client site.

![Fleet for OpenStation showing two connected Harbor Arts sites](assets/screenshots/fleet-hub.jpg)

## One place to start the day

Managing one WordPress site is simple. Managing ten unrelated sites means ten dashboards, ten places to check, and ten chances to make the right change on the wrong site.

Fleet gives that work one home without turning your sites into a Multisite network or handing them to a hosted management service. The hub shows every connected site, who it belongs to, whether it is healthy, and what is waiting for you. Client sites keep their own hosting, users, content, and permissions.

## The work comes to you

Fleet Inbox brings pending comments, drafts, posts awaiting review, scheduled work, connection problems, and useful Site Health findings into one queue. Search helps you find content, media, comments, and people across a secure index Fleet updates incrementally in the background.

Instead of visiting every dashboard to look for work, open Fleet and start with the work that already found you.

Large fleets stay responsive because Fleet checks sites in short, resumable passes. Fast status checks stay frequent, heavier metadata and search work runs less often, and unavailable sites automatically back off until they recover.

![Fleet Inbox showing editorial and moderation work across Harbor Arts](assets/screenshots/fleet-inbox.jpg)

## Every site gets its own window

Open two client sites side by side and each keeps its own name, navigation, and state. You can work on one site while another stays open for reference, without losing track of which WordPress install you are changing.

Group related sites under a client name and Fleet builds a reusable workspace for them. One click opens up to eight clearly named site windows; larger workspaces stay easy to reach from Fleet.

![Harbor Arts Center and Harbor Arts Journal open in separate OpenStation windows](assets/screenshots/multiple-sites.jpg)

## Manage the site in front of you

Write a draft, fix a page, schedule a post, or clear the comments waiting for approval—all from the right site's window. Searchable, paginated lists keep the work manageable as a site grows. The post and page editor works with HTML and WordPress block source; it is not the visual block editor.

Clients use more than posts and pages. Fleet also discovers editable, REST-enabled content types such as events and portfolios, when they expose the standard WordPress content fields. Save your most-used content filters for each site—like pending reviews or this week's publishing—and reopen that work without rebuilding the search.

Fleet also has focused views for media details, plugins, users, common settings, and private agency notes. Design shows available block-theme resources. For advanced work, Explorer can use REST routes the connected site advertises, subject to that WordPress account’s normal capability checks.

If someone changes a post while you are editing, Fleet checks for that change before saving and keeps your submitted edits when it detects a conflict. Unsaved-change warnings help you avoid leaving work behind; they are not a replacement for backups or durable autosave.

That means WordPress remains the source of truth. Fleet does not invent a second permissions system or require custom endpoints on client sites.

![Editing a Harbor Arts Center draft in its own named OpenStation window](assets/screenshots/content-editor.jpg)

## Know what you are publishing—and where

Before publishing or scheduling, Fleet shows the destination site, publishing time, and changes for review. Nothing is written until you confirm. If you need an earlier version, compare WordPress revisions and bring one back into the editor before deciding what to save.

![Reviewing a change and its destination before saving to WordPress](assets/screenshots/publishing-review.jpg)

## One Fleet installation

Fleet belongs only on the hub. A managed site needs WordPress Core, HTTPS, and an administrator who can approve the connection. Fleet checks the site before you approve, then offers a separate setup step to install or activate OpenStation there. The management window runs on your hub; the Fleet plugin itself is never copied to client sites.

The approval happens on WordPress’s own Application Password screen. Fleet never receives the administrator’s normal password. WordPress creates a separate administrator-level credential that can be revoked at any time, and Fleet encrypts its copy on the hub. These credentials do not automatically expire. If a connection breaks, repair it without losing your client notes and organization.

## Try Fleet

Fleet targets WordPress 7.1 or newer, PHP 8.3 or newer, HTTPS, and an OpenStation build with the experimental App Framework. Older WordPress versions are not supported.

1. Download `fleet-for-openstation.zip` from the [0.9.0-rc.1 release candidate](https://github.com/RegionallyFamous/fleet-for-openstation/releases/tag/v0.9.0-rc.1).
2. Install OpenStation and Fleet on the WordPress site you want to use as the hub.
3. Open **Fleet**, enter a client site’s HTTPS address, choose **Check connection**, and review the approval on that site.
4. Return to Fleet, choose **Finish setup**, and start managing its named window.

Fleet is preparing for launch. Version `0.9.0-rc.1` is a pre-release for staging and pilot testing, not a general-availability release. Start with a [verified App Framework build](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Framework-Integration). Fleet does not replace backups, uptime monitoring, malware scanning, or a credential vault.

Detailed setup, security, architecture, API coverage, development, and troubleshooting notes live in the [Fleet wiki](https://github.com/RegionallyFamous/fleet-for-openstation/wiki). Found a rough edge? [Open an issue](https://github.com/RegionallyFamous/fleet-for-openstation/issues).

Fleet for OpenStation is licensed under GPL-2.0-or-later.
