#!/bin/bash

if [ -n "$PORT" ]; then
    sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
fi

/etc/init.d/memcached start
docker-php-entrypoint apache2-foreground
