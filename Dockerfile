FROM php:7.4-apache

RUN apt-get update && apt-get install -y \
		libfreetype6-dev \
		libjpeg62-turbo-dev \
		libpng-dev \
        libz-dev \
        libmemcached-dev \
        memcached \
        libmemcached-tools \
        git \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j$(nproc) gd

RUN a2enmod rewrite

WORKDIR /var/www/html
COPY composer.json /var/www/html
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install

COPY src/* /var/www/html/src
COPY index.php /var/www/html
COPY .htaccess /var/www/html
COPY entrypoint.sh /entrypoint.sh

entrypoint ["/bin/bash", "/entrypoint.sh"]