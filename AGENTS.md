# Fleet for OpenStation repository instructions

- Keep Fleet WordPress-native: prefer Core admin pages, forms, nonces, user meta, HTTP APIs, Application Passwords, and REST endpoints over custom infrastructure.
- Never store or log an Application Password in plaintext. Keep credentials per local user and encrypted at rest.
- Use safe HTTP requests and public HTTPS targets; do not weaken the SSRF boundary for private-network convenience.
- The `desktop-mode` WordPress.org slug is the OpenStation installation target and plugin dependency.
- Run `./bin/build.sh` after implementation changes.
