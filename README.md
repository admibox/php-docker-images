# Admibox PHP Docker Images

**NOTICE: 2023-10-29: Added support for PHP 8.2 and update extensions to latest versions**

**NOTICE: 2022-03-03: Added support for PHP 8.1 and rebrand from EcommPro to Admibox**

**NOTICE: 2021-06-19: Set `main` as default branch**

**NOTICE: 2021-06-19: Dropped support for unmaintained PHP versions: 5.6, 7.0, 7.1 and 7.2**

**NOTICE: Tags ending with `-debian` will be deprecated. Please use the `-fpm` and `-cli` ones (based on debian).**

**NOTICE: Dropped support for Alpine-based containers.**

PHP docker images by [Admibox SL](https://admibox.com/) | @getadmibox | <dev@admibox.com>. **Based on the official PHP Debian docker images.**

Ready to use with Laravel, Magento 1/2 and WordPress.

Pre-built PHP extensions:

    bcmath
    gd
    geoip
    igbinary
    imagick
    intl
    mcrypt
    memcached
    mysqli
    opcache
    pdo_mysql
    redis
    sockets
    xmlrw
    xsl
    zip

    openswoole (disabled by default)
    rdkafka (disabled by default)
    swoole (disabled by default)
    trapbox (by Admibox, disabled by default)
    uopz (disabled by default)
    xdebug (disabled by default)
    xhprof (disabled by default)

Two versions: FPM and CLI.

Images (available from <https://hub.docker.com/u/admibox>):

    admibox/php:8.2-fpm
    admibox/php:8.2-cli
    admibox/php:8.1-fpm
    admibox/php:8.1-cli
    admibox/php:8.0-fpm
    admibox/php:8.0-cli
    admibox/php:7.4-fpm
    admibox/php:7.4-cli
    admibox/php:7.3-fpm
    admibox/php:7.3-cli

## CLI

CLI Tools included:

    composer1
    composer2
    jq
    modman (utility for Magento 1.x and OpenMage)
    n98-magerun (utility for Magento 1.x and OpenMage)
    n98-magerun2 (utility for Magento 2 and Mage-OS)
    wp-cli (utility for WordPress)

Useful system packages included:

    bash
    git
    mariadb-client
    msmtp
    vim
    zsh with Oh My ZSH!

```
docker run -u $(id -u):$(id -g) -ti --rm -v $(pwd):/work -w /work admibox/php:8.2-cli zsh
```

Make PHP Great Again. Happy coding!

## Container-friendly SMTP with MSMTP

```
echo -n "Enter SMTP password: " && read -s SMTP_PASSWORD
export SMTP_PASSWORD

docker run -ti --rm \
    -e SMTP_PASSWORD \
    -e SENDMAIL_COMMAND='msmtp --tls=on --tls-starttls=off --tls-trust-file=/etc/ssl/certs/ca-certificates.crt --host=mailer.admibox.com --protocol=smtp --auth=on --user=mta@admibox.com --passwordeval="printf \"%s\n\" \"$SMTP_PASSWORD\"" --port=465 --read-envelope-from -t' \
admibox/php:8.2-cli zsh
```

And then, from container shell:

```
php -r 'mail("manel@admibox.com", "Hey, again!", "Come on, again!", "From: hello@admibox.com");'
```

Et voilà.

We've included the *msmtp* package in the containers, and set the `sendmail_path` PHP setting to `eval $SENDMAIL_COMMAND`. This way you can configure the mail sending command with environment variables. **This is not secure for production environments. You should override this configuration by using, for example, mounted volumes.**:

```
[docker] ➜  ~ cat /usr/local/etc/php/conf.d/msmtp.ini
sendmail_path = "eval $SENDMAIL_COMMAND"
```

```
docker run -ti --rm \
    -v /dev/null:/usr/local/etc/php/conf.d/msmtp.ini
    -e SMTP_PASSWORD \
    -e SENDMAIL_COMMAND='msmtp --tls=on --tls-starttls=off --tls-trust-file=/etc/ssl/certs/ca-certificates.crt --host=mailer.admibox.com --protocol=smtp --auth=on --user=mta@admibox.com --passwordeval="printf \"%s\n\" \"$SMTP_PASSWORD\"" --port=465 --read-envelope-from -t' \
admibox/php:8.2-cli zsh
```

Example using SendGrid:

```
echo -n "Enter SMTP password: " && read -s SMTP_PASSWORD
export SMTP_PASSWORD

docker run -ti --rm \
    -v /dev/null:/usr/local/etc/php/conf.d/msmtp.ini
    -e SMTP_PASSWORD \
    -e SENDMAIL_COMMAND='msmtp --tls=on --tls-starttls=off --tls-trust-file=/etc/ssl/certs/ca-certificates.crt --host=smtp.sendgrid.net --protocol=smtp --auth=on --user=apikey --passwordeval="printf \"%s\n\" \"$SMTP_PASSWORD\"" --port=465 --read-envelope-from -t' \
admibox/php:8.2-cli zsh
```

And then, from container shell:

```
php -r 'mail("manel@admibox.com", "Hey, again (from SendGrid)!", "Come on, again (from SendGrid)!", "From: mysender@mydomain.tld");'
```

## Project and environment aware shell prompt

By using the APP_NAME and APP_ENV environment variables, you can be aware of where you are.

```
docker run -e APP_NAME=satspal -e APP_ENV=production -u $(id -u):$(id -g) -ti --rm -v $(pwd):/work admibox/php:8.2-cli zsh
```

```
production|satspal >  ~
➜
```
