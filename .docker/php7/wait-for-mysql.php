#!/usr/bin/env php
<?php
function dbIsUp() {
    try {
        $dsn = 'mysql:dbname='.getenv('DB_NAME').';host='.getenv('DB_HOST');
        new PDO($dsn, getenv('DB_USER'), getenv('DB_PASSWD'));
    } catch(Exception $e) {
        echo $e->getMessage();
        return false;
    }
    return true;
}
while(!dbIsUp()) {
    sleep(1);
}
