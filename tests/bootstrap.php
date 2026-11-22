<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// A fresh test database, built by the migrations, for every run of the suite. SQLite
// does not create its directory, which a fresh checkout (the CI) does not have.
(new Symfony\Component\Filesystem\Filesystem())->mkdir(dirname(__DIR__).'/var/data');
array_map(unlink(...), glob(dirname(__DIR__).'/var/data/app_test*.db') ?: []);
passthru(sprintf('php %s/bin/console doctrine:migrations:migrate --no-interaction --env=test -q', dirname(__DIR__)), $status);
if (0 !== $status) {
    exit($status);
}
