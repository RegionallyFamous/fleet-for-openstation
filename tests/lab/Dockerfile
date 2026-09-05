FROM wordpress:cli-php8.3@sha256:cbdb19366ee143c5fb353181a0f0e2d4fea1562dd28b1c63cf4ec0d086f2fc2d AS cli
FROM wordpress:7.1-php8.3-apache@sha256:5a93c470ae8220fddf71f6ebe3bc94e615ddc2ae4d9810f795b830fb11c41a17
COPY --from=cli /usr/local/bin/wp /usr/local/bin/wp
RUN apt-get update && apt-get install -y --no-install-recommends default-mysql-client && rm -rf /var/lib/apt/lists/* \
    && a2enmod ssl headers rewrite && a2dissite 000-default
COPY wp-config.php /usr/src/wordpress/wp-config.php
COPY fixture.php /opt/fleet-fixture.php
COPY client.cnf /etc/mysql/mariadb.conf.d/99-fleet-lab.cnf
COPY start.sh /usr/local/bin/fleet-lab-start
RUN chmod +x /usr/local/bin/fleet-lab-start
ENTRYPOINT ["/usr/local/bin/fleet-lab-start"]
