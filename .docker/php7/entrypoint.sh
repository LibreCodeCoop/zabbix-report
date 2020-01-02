#!/bin/bash
. `pwd`/.env
if [ ! -d "vendor" ]; then
    composer global require hirak/prestissimo
    composer install
fi
php -S 0.0.0.0:${PORT}