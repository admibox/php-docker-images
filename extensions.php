<?php
// Extension catalog, in build order (serializers before the clients that link against them).
//
// The value says whether the extension is enabled by default in the image. Enabled by default
// means both: passive (a library that does nothing until called: no instrumentation, no threads,
// no change to how PHP runs) and common (required or habitual in Magento, Laravel, Symfony and
// WordPress). Everything else is compiled and left for the consumer to enable from conf.d.
// opcache is the one active component enabled by default: PHP-FPM without it is a broken setup.
return [
    'bcmath' => true,
    'sockets' => true,
    'opcache' => true,
    'gd' => true,
    'imagick' => true,
    'libsodium' => true,
    'intl' => true,
    'xmlrw' => true,      // soap (xmlreader and xmlwriter are built in)
    'xsl' => true,
    'igbinary' => true,   // dependency of redis and memcached as compiled here
    'msgpack' => true,    // dependency of redis and memcached as compiled here
    'redis' => true,
    'memcached' => true,
    'zip' => true,
    'pcntl' => true,
    'pdo_mysql' => true,
    'mysqli' => true,
    'ftp' => true,        // required by Magento; not guaranteed by the upstream PHP build

    // Uncommon clients
    'mongodb' => false,
    'amqp' => false,
    'rdkafka' => false,

    // Runtime model
    'swoole' => false,

    // Instrumentation: monitoring, profiling, debugging, intrusion detection
    'newrelic' => false,
    'xhprof' => false,
    'xdebug' => false,
    'trapbox' => false,
];
