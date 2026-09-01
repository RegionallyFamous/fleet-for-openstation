# Fleet for OpenStation

Manage all of your WordPress sites from one OpenStation window.

Fleet turns one OpenStation install into an agency hub. Connect a client site, select **Manage site**, and work on that site's content, plugins, settings, and REST APIs without opening its wp-admin.

> [!IMPORTANT]
> Fleet is an early preview built for hands-on testing. It is not yet a replacement for a backup, monitoring, or security service.

## Who Fleet is for

Fleet is for agencies, freelancers, and teams that look after more than one WordPress site. The WordPress install where Fleet is activated becomes the **hub**. Every other connected WordPress install is a **managed site**.

The distinction is simple:

- **Hub:** OpenStation and Fleet for OpenStation are active.
- **Managed site:** OpenStation is active and has approved that hub's connection. Fleet itself is not installed there.

## What you can do

- Connect an OpenStation site with a revocable OAuth approval—no normal WordPress password is shared.
- See whether OpenStation is missing, inactive, or active.
- Install or activate OpenStation with one button when a site does not have it yet.
- Manage the selected site inside the current Fleet window instead of opening another wp-admin.
- Change core site identity, timezone, and date settings.
- Update post and page titles or publishing status.
- Activate and deactivate installed plugins.
- Use the **Full API** console for any WordPress REST route the connected account is allowed to access, including routes added by plugins.
- Disconnect from either side and revoke the connection.

The focused tabs are the friendlier path for common jobs. The API console provides the complete API surface while dedicated Fleet interfaces grow. Fleet cannot manage a feature that the managed site does not expose through the WordPress REST API.

## Before you start

| | Fleet hub | Managed site |
|---|---|---|
| WordPress | 6.5 or newer | 6.0 or newer |
| PHP | 7.4 or newer | Whatever the installed OpenStation release requires |
| HTTPS | Required | Required |
| Plugins | OpenStation and Fleet for OpenStation | OpenStation |
| Access | Administrator | An administrator allowed to perform the work Fleet will do |

Both sites must be publicly reachable over HTTPS. WordPress permissions still apply to every remote request: Fleet can do only what the account that approved the connection can do.

## Install Fleet

1. Download `fleet-for-openstation.zip` from the [newest available release](https://github.com/RegionallyFamous/fleet-for-openstation/releases).
2. On the WordPress site you want to use as the hub, install and activate [OpenStation](https://github.com/WordPress/openstation).
3. Go to **Plugins → Add New Plugin → Upload Plugin**.
4. Upload the ZIP and activate Fleet for OpenStation.
5. Open **Fleet** from wp-admin or the OpenStation desktop.

## Connect your first site

1. Enter the managed site's full HTTPS address, such as `https://client.example`.
2. Select **Connect site**.
3. Sign in to that site if WordPress asks.
4. Review **Full API access**, then select **Connect Fleet**.
5. Back in Fleet, select **Manage site**.

OpenStation uses OAuth Authorization Code with PKCE, short-lived access tokens, and rotating refresh tokens. Fleet never asks for or stores the person's normal WordPress password.

### If OpenStation is not installed yet

Fleet cannot offer OpenStation OAuth before OpenStation exists. It can use WordPress Core's Application Password approval as a bootstrap connection, then install and activate OpenStation through the Core Plugins API. After installation, disconnect that bootstrap connection and connect the site again to use OAuth.

## Manage a connected site

Select **Manage site** on a connected-site card. Fleet keeps the current window and changes its context to the managed site. The window header always names the site being controlled.

The workspace has five tabs:

- **Overview** shows live site details and recent content.
- **Content** edits recent posts and pages.
- **Plugins** manages installed plugin status.
- **Settings** changes common Core settings.
- **API** sends GET, POST, PUT, PATCH, or DELETE requests to any available REST route.

Use **All sites** to return to the hub list. The Fleet window is deliberately capped and centered in OpenStation instead of filling the desktop.

## Disconnect a site

Select **Disconnect** next to the site. OAuth connections revoke their refresh-token grant before Fleet removes the local record. A managed-site administrator can also revoke a hub under **Users → Profile → Fleet connections**.

For a bootstrap Application Password, Fleet revokes that exact credential before removing the site. Disconnect every site before uninstalling Fleet; uninstalling a hub plugin cannot reliably contact every managed site.

## What Fleet does not do yet

Fleet does not yet include bulk actions, background jobs, backups, restores, uptime monitoring, security scanning, client reports, or shared team vaults. Those are separate product decisions, not hidden behind the API console.

## Need help?

- [Troubleshooting](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Troubleshooting)
- [How Fleet works](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Architecture)
- [Security model](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Security)
- [Current scope and next steps](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Current-Scope)
- [Development and packaging](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Development)

Found a bug or a rough edge? [Open an issue](https://github.com/RegionallyFamous/fleet-for-openstation/issues).

## License

Fleet for OpenStation is licensed under GPL-2.0-or-later.
