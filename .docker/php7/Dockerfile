FROM php:7.4-fpm

RUN apt-get update

RUN apt-get install -y \
        git \
        libzip-dev \
        unzip \
        && docker-php-ext-configure mysqli -with-mysqli=mysqlnd \
        && docker-php-ext-configure pdo_mysql -with-pdo-mysql=mysqlnd \
        && docker-php-ext-install pdo pdo_mysql mysqli \
        && docker-php-ext-install zip

# Install xdebug
RUN pecl install xdebug-2.8.0beta1 \
    && docker-php-ext-enable xdebug

RUN curl https://getcomposer.org/composer.phar --output /usr/bin/composer
RUN chmod +x /usr/bin/composer

WORKDIR /php7
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT [ "sh", "/usr/local/bin/entrypoint.sh" ]