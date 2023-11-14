FROM admibox/php:8.2-fpm
ARG DEBIAN_FRONTEND=noninteractive


### CLI ###

RUN apt-get update && apt-get install --no-install-recommends -y git zsh ssh vim bash rsync mariadb-client unzip wget less iputils-ping procps && apt-get clean

RUN mkdir -p /work /local /opt/bin /etc/zsh && chmod 777 /work /local
WORKDIR /work

RUN git clone https://github.com/robbyrussell/oh-my-zsh.git /usr/local/share/oh-my-zsh

COPY cli-tools /usr/local/bin
COPY update-cli-tools.sh /usr/local/bin

RUN echo \
'\n'\
'# \n'\
'# PHP-CLI docker image by [Admibox](https://admibox.com/) | @getadmibox | <dev@admibox.com>.\n'\
'# Based on the official php:8.2-cli docker image.\n'\
'# \n'\
'# Pre-built PHP extensions included: bcmath, sockets, opcache, gd, imagick, libsodium, intl, xmlrw, xsl, igbinary, redis, memcached, zip, pcntl, pdo_mysql, mysqli, mongodb, amqp, rdkafka, swoole, openswoole, xhprof, xdebug, uopz, trapbox.\n'\
'# \n'\
'# Tools included: composer1.phar, composer2.phar, jq, modman, n98-magerun.phar, n98-magerun2.phar, wp-cli.phar.\n'\
'# \n'\
'# Make PHP Great Again. Happy coding!\n'\
'# \n'\
'# Check <https://github.com/admibox/php-docker-images/blob/master/README.md> for more information.\n'\
'# \n'\
''\
>> /etc/motd.admibox

COPY etc/zshrc /etc/zsh/zshrc
COPY etc/zshenv /etc/zsh/zshenv
