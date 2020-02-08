FROM php:7.4-fpm

RUN apt-get update

RUN apt-get install -y \
        git \
        libicu-dev \
        libzip-dev \
        unzip \
        && docker-php-ext-configure intl \
        && docker-php-ext-install intl  \
        && docker-php-ext-configure mysqli -with-mysqli=mysqlnd \
        && docker-php-ext-configure pdo_mysql -with-pdo-mysql=mysqlnd \
        && docker-php-ext-install opcache \
        && docker-php-ext-install pdo pdo_mysql mysqli \
        && docker-php-ext-install zip


# Install xdebug
RUN pecl install xdebug-2.9.1 \
    && docker-php-ext-enable xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer --version

COPY config/php.ini /usr/local/etc/php/conf.d/

WORKDIR /app
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT [ "sh", "/usr/local/bin/entrypoint.sh" ]