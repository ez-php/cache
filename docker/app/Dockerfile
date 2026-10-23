FROM au9500/php:8.5

# ext-memcached for MemcachedDriver (not in the base image)
RUN apt-get update && apt-get install -y libmemcached-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install memcached \
    && docker-php-ext-enable memcached

# ext-apcu for ApcuDriver (not in the base image); the CLI needs apc.enable_cli
RUN pecl install apcu \
    && docker-php-ext-enable apcu \
    && echo "apc.enable_cli=1" >> "$PHP_INI_DIR/conf.d/docker-php-ext-apcu.ini"

WORKDIR /var/www/html

CMD ["/usr/local/bin/container-start.sh"]
