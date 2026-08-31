# Fleet for OpenStation

Fleet for OpenStation is an experimental feature plugin for agencies managing multiple WordPress sites. It connects sites through WordPress Core Application Passwords and installs or activates OpenStation through the Core Plugins REST API.

## What it deliberately reuses

- A normal `wp-admin` menu page, which OpenStation already presents as a window.
- Core Application Password authorization and revocation.
- Core user meta for each local manager's site list.
- WordPress's bundled sodium compatibility layer for encrypted credentials.
- The Core HTTP API with unsafe URL rejection.
- `GET`, `POST`, and `DELETE /wp/v2/plugins` for OpenStation status, installation, and activation.

There is no hosted control plane, custom database table, agent plugin, queue, JavaScript application, or custom REST namespace.

## Hub and managed sites

The WordPress install with this plugin activated is the Fleet hub. Managed sites install ordinary OpenStation only. The relationship is represented by a named, revocable Application Password on each managed site; disconnecting revokes it.

## Build

```sh
./bin/build.sh
```

The installable plugin is written to `dist/fleet-for-openstation.zip`.

## Try it

1. Install and activate OpenStation (`desktop-mode`) on an HTTPS hub site.
2. Install and activate `dist/fleet-for-openstation.zip`.
3. Open **Fleet** in wp-admin or from the OpenStation desktop.
4. Enter an HTTPS WordPress site URL and approve the Application Password request on that site.
5. Use **Install OpenStation** for sites where it is missing.

Connected credentials belong to the current local WordPress user. **Disconnect** revokes the remote Application Password before removing the local record.

## Current experimental boundary

Fleet checks and changes one site at a time. Add batching only after real agency testing shows that the serial workflow is the bottleneck.
