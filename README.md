# Fleet for OpenStation

Stop managing WordPress sites one dashboard at a time.

Fleet turns one OpenStation install into a working desktop for every WordPress site you manage. See the whole fleet, find the work that needs attention, and open each client site in its own clearly named window.

![The Fleet hub inside OpenStation, showing two connected WordPress sites](assets/screenshots/fleet-hub.jpg)

## The problem Fleet solves

One WordPress site is easy. Ten unrelated client sites are not.

The work gets scattered across browser tabs, login screens, saved bookmarks, and separate dashboards. Comments wait for approval. Drafts get forgotten. You lose time checking every site just to find out whether anything needs you. With enough tabs open, it also becomes much too easy to make the right change on the wrong site.

Fleet gives those sites one home without forcing them into a WordPress Multisite network or a hosted management service. Your OpenStation site becomes the hub. Every connected site keeps its own WordPress install, permissions, content, and hosting.

![OpenStation's window overview showing the Fleet hub and two managed WordPress sites open at once](assets/screenshots/multiple-sites.jpg)

## Start with the work, not the dashboards

Fleet Inbox brings pending comments, drafts, posts waiting for review, scheduled posts, connection problems, and WordPress Site Health findings into one queue.

You can search across connected sites when you know what you need but not where it lives. You can group sites by client and open that client's whole workspace at once. When you open a site, Fleet keeps it in a persistent window with the site name and remote context always visible.

![Fleet Inbox combining work and health findings from two connected WordPress sites](assets/screenshots/fleet-inbox.jpg)

The practical difference is simple: instead of visiting every wp-admin to look for work, you open Fleet and start with the work that already found you.

## Manage the site that is in front of you

Each connected site gets focused screens for posts, pages, media, comments, plugins, users, settings, and client notes. If WordPress or an installed plugin exposes something through REST that does not have a dedicated Fleet screen yet, the Explorer lets you use that API from the same site window.

WordPress remains in charge of permissions. Fleet can do only what the account that approved the connection is allowed to do.

![A managed WordPress site open in its own focused OpenStation window](assets/screenshots/managed-site.jpg)

## Connect without handing Fleet your normal password

Enter the site's HTTPS address and continue to that site's native WordPress approval screen. If you need to sign in, you sign in directly to that WordPress site. Fleet never receives your normal password.

After approval, WordPress creates a separate, revocable credential for Fleet. Fleet returns to the hub, installs or activates OpenStation on the connected site, and opens its management window.

![Fleet's guided connection panel explaining the native WordPress approval flow](assets/screenshots/connect-site.jpg)

## Try Fleet

You need administrator access to one public HTTPS WordPress site for the hub and to each site you want to connect.

1. Download `fleet-for-openstation.zip` from the [latest release](https://github.com/RegionallyFamous/fleet-for-openstation/releases).
2. Install [OpenStation](https://github.com/WordPress/openstation) and Fleet on the WordPress site you want to use as the hub.
3. Open **Fleet**, enter a client site's address, and approve the connection on that site.
4. Return to Fleet and select **Manage**.

Fleet is an early preview for hands-on testing. It does not replace backups, uptime monitoring, malware scanning, or a credential vault.

## Guides and details

- [Getting started](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Getting-Started)
- [Managing sites](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Managing-Sites)
- [Current scope](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Current-Scope)
- [Security](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Security)
- [Troubleshooting](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Troubleshooting)
- [Architecture](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Architecture)
- [Development](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Development)

Found a bug or a rough edge? [Open an issue](https://github.com/RegionallyFamous/fleet-for-openstation/issues).

Fleet for OpenStation is licensed under GPL-2.0-or-later.
