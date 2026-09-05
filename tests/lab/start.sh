#!/usr/bin/env bash
set -euo pipefail
cp -a /usr/src/wordpress/. /var/www/html/
install -m 0644 /run/secrets/fleet-lab /run/fleet-db-secret
cp /fleet/apache.conf /etc/apache2/sites-enabled/fleet.conf
exec apache2-foreground
