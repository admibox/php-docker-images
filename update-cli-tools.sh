#!/bin/sh
TO=${TO:-$(pwd)}
mkdir -p $TO

(

cd $TO

# PHP tools

curl -o composer2.phar https://getcomposer.org/composer-stable.phar \
    && chmod +x composer2.phar \
    && ln -sf composer2.phar composer2

curl -o composer1.phar https://getcomposer.org/composer-1.phar \
    && chmod +x composer1.phar \
    && ln -sf composer1.phar composer1


# Magento 1.x tools

curl -o modman https://raw.githubusercontent.com/colinmollenhour/modman/master/modman \
    && chmod +x modman

curl -o n98-magerun.phar https://files.magerun.net/n98-magerun.phar \
    && chmod +x n98-magerun.phar \
    && ln -sf n98-magerun.phar n98-magerun

# Magento 2.x tools

curl -o n98-magerun2.phar https://files.magerun.net/n98-magerun2.phar \
    && chmod +x n98-magerun2.phar \
    && ln -sf n98-magerun2.phar n98-magerun2

# WordPress tools

curl -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && ln -sf wp-cli.phar wp \
    && ln -sf wp-cli.phar wp-cli

# jq

curl -o jq -L https://github.com/stedolan/jq/releases/latest/download/jq-linux64 \
    && chmod +x jq


tar --exclude=update.sh -czf ../cli-tools.tgz .

)
