FROM php:8.0-fpm
ARG DEBIAN_FRONTEND=noninteractive

RUN set -ex \
&& apt-get update \
&& apt-get install --no-install-recommends -y git msmtp \
&& docker-php-ext-install bcmath \
\
&& docker-php-ext-install sockets \
\
&& docker-php-ext-install opcache \
\
&& echo '### GD for last PHP version' \
&& apt-get install --no-install-recommends -y libfreetype6 libfreetype6-dev libpng16-16 libpng-dev libjpeg62-turbo libjpeg62-turbo-dev libjpeg-dev libwebp6 libwebp-dev \
	&& docker-php-ext-configure gd --enable-gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ --with-webp=/usr/include/ \
	&& docker-php-ext-install gd \
	&& apt-get --purge remove -y libfreetype6-dev libpng-dev libjpeg62-turbo-dev libjpeg-dev libjpeg-dev \
\
&& echo '### Imagick for last PHP version' \
&& apt-get install --no-install-recommends -y imagemagick libmagickwand-dev \
	&& printf "\n" | pecl install imagick \
	&& apt-get --purge remove -y libmagickwand-dev \
\
&& echo '### Mcrypt for last PHP version' \
&& apt-get install --no-install-recommends -y libmcrypt4 libmcrypt-dev \
	&& printf "\n" | pecl install mcrypt \
\
&& apt-get install --no-install-recommends -y libsodium23 libsodium-dev \
	&& pecl install -f libsodium \
	&& apt-get --purge remove -y libsodium-dev \
\
&& apt-get install --no-install-recommends -y libicu67 libicu-dev \
	&& docker-php-ext-install intl \
	&& apt-get --purge remove -y libicu-dev \
\
&& apt-get install --no-install-recommends -y libxml2-dev \
	&& docker-php-ext-install soap \
	&& CFLAGS=-I/usr/src/php docker-php-ext-install xmlreader \
	&& CFLAGS=-I/usr/src/php docker-php-ext-install xmlwriter \
	&& apt-get --purge remove -y libxml2-dev \
\
&& apt-get install --no-install-recommends -y libxslt1-dev libxslt1.1 \
	&& docker-php-ext-install xsl \
	&& apt-get --purge remove -y libxslt1-dev \
\
&& pecl install igbinary && docker-php-ext-enable igbinary \
\
&& apt-get install --no-install-recommends -y libzstd-dev libzstd1 \
&& printf "yes\nyes\nyes\n" | pecl install redis && docker-php-ext-enable redis \
\
&& apt-get install --no-install-recommends -y libmemcached11 libmemcachedutil2 zlib1g-dev libmemcached-dev libssl-dev \
	&& printf "\n" | pecl install memcached \
	&& docker-php-ext-enable memcached \
	&& apt-get --purge remove -y zlib1g-dev libmemcached-dev libssl-dev \
\
&& apt-get install --no-install-recommends -y zlib1g-dev libzip-dev libzip4 \
	&& docker-php-ext-install zip \
	&& apt-get --purge remove -y zlib1g-dev libzip-dev \
\
&& docker-php-ext-install pcntl \
\
&& docker-php-ext-install pdo_mysql \
\
&& docker-php-ext-install mysqli \
\
&& echo '### MongoDB for last PHP version' \
&& pecl install mongodb \
\
&& echo '### AMQP for last PHP version' \
&& apt-get install --no-install-recommends -y librabbitmq4 librabbitmq-dev \
&& printf "\n" | pecl install amqp \
&& apt-get --purge remove -y librabbitmq-dev \
\
&& echo '### RDKafka for last PHP version' \
&& apt-get install --no-install-recommends -y librdkafka1 librdkafka-dev \
&& printf "\n" | pecl install rdkafka \
&& apt-get --purge remove -y librdkafka-dev \
\
&& echo '### Swoole for PHP >= 8' \
&& apt-get install --no-install-recommends -y libpq5 libpq-dev openssl libssl-dev libc-ares2 libc-ares-dev libcurl4-openssl-dev libcurl4 \
&& printf "yes\nyes\nyes\nyes\nyes\nyes\nyes\n" | pecl install swoole \
&& apt-get --purge remove -y libpq-dev libssl-dev libc-ares-dev \
\
&& echo '### Swoole for PHP >= 7.4' \
&& apt-get install --no-install-recommends -y libpq5 libpq-dev openssl libssl-dev libc-ares2 libc-ares-dev libcurl4-openssl-dev libcurl4 \
&& printf "yes\nyes\nyes\nyes\nyes\nyes\nyes\n" | pecl install openswoole-22.0.0 \
&& apt-get --purge remove -y libpq-dev libssl-dev libc-ares-dev \
\
&& (\
	mkdir -p /var/xhprof /opt/xhprof/xhprof_html /usr/local/share/php && chmod 777 /var/xhprof \
	&& pecl install xhprof \
	&& docker-php-ext-enable xhprof \
	&& mkdir -p /opt/xhprof \
	&& cd /opt/xhprof/ \
	&& pecl download xhprof \
	&& tar --strip 1 -xzvf xhprof-*.tgz \
	&& rm -fr xhprof-*.tgz \
) \
\
&& pecl install xdebug \
\
&& pecl install uopz \
\
&& (\
  mkdir -p /tmp/trapbox \
  && git clone https://github.com/admibox/php-trapbox.git /tmp/trapbox \
  && cd /tmp/trapbox \
  && phpize \
  && ./configure \
  && make \
  && make install \
) \
&& apt-get --purge remove -y git \
&& apt-get --purge autoremove -y \
&& apt-get clean \
&& rm -fr /usr/src

RUN echo '\
sendmail_path = "eval $SENDMAIL_COMMAND"\n'\
>> /usr/local/etc/php/conf.d/msmtp.ini

COPY files/prepend.ini /usr/local/etc/php/conf.d/prepend.ini
COPY files/xhprof.ini /usr/local/etc/php/conf.d/xhprof.ini
COPY files/xhproxy.php /opt/xhprof/xhprof_html/xhproxy.php
COPY files/prepend.php.inc /usr/local/share/php/prepend.php.inc

