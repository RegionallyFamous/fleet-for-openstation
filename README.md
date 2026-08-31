# Fleet for OpenStation

One WordPress dashboard for all the WordPress sites you look after.

Fleet for OpenStation turns one OpenStation install into a simple agency hub. Connect a client site, see whether OpenStation is installed, and install or activate it without bouncing between dashboards.

> [!IMPORTANT]
> Fleet is an early preview. It is ready for hands-on testing, but it does not yet replace a full maintenance platform.

## Who it is for

Fleet is for agencies, freelancers, and anyone responsible for more than one WordPress site. It starts with a deliberately small job: getting every managed site into OpenStation and making those installs easy to reach from one place.

## What you can do today

- Connect a WordPress site through its built-in Application Password approval screen.
- See whether OpenStation is missing, inactive, or active.
- Install and activate OpenStation with one button.
- Open the connected site's WordPress dashboard.
- Refresh a site's status when you need it.
- Disconnect a site and revoke Fleet's Application Password.

Fleet handles one site at a time. It does not yet run updates, backups, security scans, uptime checks, or bulk actions.

## The hub and the sites it manages

The WordPress install running **Fleet for OpenStation** is the **hub**. Install it on the agency's own site or another WordPress install you control.

Connected sites only need OpenStation. They do not install Fleet, an agent, or another background service. Each connected site shows a revocable Application Password named **Fleet for OpenStation on _your-hub-domain_** in the approving user's WordPress profile.

## Before you start

| | Fleet hub | Managed site |
|---|---|---|
| WordPress | 6.5 or newer | 6.0 or newer |
| PHP | 7.4 or newer | Whatever the installed OpenStation release requires |
| HTTPS | Required | Required |
| Plugins | OpenStation and Fleet for OpenStation | OpenStation is installed by Fleet if needed |
| Access | Administrator | A user allowed to install and activate plugins |

The managed site must have WordPress Application Passwords enabled and must be publicly reachable. Its server must also be able to install plugins without stopping to request FTP credentials.

## Install Fleet

1. Download `fleet-for-openstation.zip` from the [latest release](https://github.com/RegionallyFamous/fleet-for-openstation/releases/latest).
2. On the site you want to use as the hub, install and activate [OpenStation](https://github.com/WordPress/openstation).
3. Go to **Plugins → Add New Plugin → Upload Plugin**.
4. Upload `fleet-for-openstation.zip`, then activate it.
5. Open **Fleet** from wp-admin or the OpenStation desktop.

## Connect your first site

1. Enter the site's full HTTPS address, such as `https://client.example`.
2. Select **Connect site**.
3. Sign in to that WordPress site if asked.
4. Approve the Application Password request.
5. Back in Fleet, select **Install OpenStation** if the site does not have it yet.

That is the whole relationship. Fleet never asks for or stores the person's normal WordPress password.

## Disconnect a site

Select **Disconnect** next to the site. Fleet revokes its Application Password on the managed site before removing the local connection.

Disconnect every site before uninstalling Fleet. WordPress cannot contact every managed site during plugin removal, so uninstalling first may leave Application Passwords that need to be revoked manually from **Users → Profile** on those sites.

## Need help?

- [Troubleshooting](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Troubleshooting)
- [How Fleet works](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Architecture)
- [Security model](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Security)
- [Current scope and next steps](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Current-Scope)
- [Development and packaging](https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Development)

Found a bug or a rough edge? [Open an issue](https://github.com/RegionallyFamous/fleet-for-openstation/issues).

## License

Fleet for OpenStation is licensed under GPL-2.0-or-later.
