FROM au9500/php:8.5

# ext-memcached for MemcachedDriver (not in the base image)
RUN apt-get update && apt-get install -y libmemcached-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install memcached \
    && docker-php-ext-enable memcached

WORKDIR /var/www/html

CMD ["/usr/local/bin/container-start.sh"]
