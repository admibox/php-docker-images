<?php
if ($prepend = getenv('PHP_AUTO_PREPEND_FILE')) {
    if (file_exists($prepend)) {
        include_once $prepend;
    }
}