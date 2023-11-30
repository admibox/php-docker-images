# Admibox PHP Docker Images

PHP Docker images from [Admibox SL](https://admibox.com/) | @getadmibox | <dev@admibox.com>.
**Based on official PHP Debian Docker images.**

These images are ready for use with Laravel, Magento 1/2, and WordPress.

Pre-built PHP extensions include:

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

Extensions disabled by default:

    openswoole
    rdkafka
    swoole
    trapbox (by Admibox)
    uopz
    xdebug
    xhprof

Available in two versions: FPM and CLI.

Images can be found at <https://hub.docker.com/u/admibox>:

    admibox/php:8.3-fpm
    admibox/php:8.3-cli
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

CLI tools included:

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

```bash
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
