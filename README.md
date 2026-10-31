# EcommPro PHP images

PHP images for Laravel, Magento 1/2, Symfony and WordPress, published as
[`ecommpro/php`](https://hub.docker.com/r/ecommpro/php) on Docker Hub (`linux/amd64`), by
[EcommPro SL](https://ecomm.pro/) · <dev@ecomm.pro>.

PHP is compiled from the official [docker-library/php](https://github.com/docker-library/php)
recipe, pinned under `upstream/`, with a complete catalog of extensions on top. One image per PHP
branch: it runs PHP-FPM by default and is the same image you use for cron jobs, queue consumers,
Composer or a shell.

```
ecommpro/php:8.5   ecommpro/php:8.4   ecommpro/php:8.3   ecommpro/php:8.2
ecommpro/php:8.1   ecommpro/php:8.0   ecommpro/php:7.4   ecommpro/php:7.3   (end of life, patched)
```

## The promise

**These images track the latest stable version of everything.** Each build takes the current PHP
patch release of its branch and the latest stable release of every extension compatible with it.
If you need a particular version pinned, or a guarantee that nothing changes between two pulls,
pin a digest, or clone this repository and build your own image.

| Tag | Meaning | Moves |
|---|---|---|
| `ecommpro/php:8.4` | Latest build of the branch | With every PHP patch release |
| `ecommpro/php:8.4.27` | The build of that PHP patch release | Only if an extension ships a serious fix before the next PHP patch |
| `ecommpro/php:8.4@sha256:…` | Exactly that image | Never |

Nothing else is published: no dated tags, no revision suffixes, no variants. `8.4-fpm`, `8.4-cli`,
`8.4.27-fpm` and `8.4.27-cli` still exist as aliases of the same image for the transition and will
be dropped.

- **Builds happen when PHP publishes a patch release** for a branch, or when a security fix needs
  one. Between two PHP patches nothing changes.
- **What is inside is recorded in the image**, not in the tag name. `/usr/local/share/php-image-manifest.json`
  lists PHP, the source recipe, the security patches, every compiled extension with its version,
  which ones are enabled by default and the Composer version. `docker inspect` shows the OCI
  labels (`org.opencontainers.image.version`, `.revision`, `.created`, `pro.ecomm.php.patches`).
- **Every published image is recorded in [`releases/`](releases/)** with its digest, so the diff
  between two records of a branch is the changelog.

## Using the image

The image has no opinion about who runs it or where state lives. It runs as root by default
(PHP-FPM drops its workers to `www-data`), `WORKDIR` is `/app`, and nothing in it depends on a
home directory. If you run it as another uid, give that uid a `HOME` when a tool needs one.

```yaml
services:
  php-fpm:
    image: ecommpro/php:8.4
    volumes: [./www:/app, ./etc/php/conf.d:/usr/local/etc/php/conf.d]
  php-worker:                     # cron, consumers, `compose exec php-worker php bin/magento …`
    image: ecommpro/php:8.4
    command: idle
    user: "1000:1000"
    environment: { HOME: /app }
    volumes: [./www:/app, ./etc/php/conf.d:/usr/local/etc/php/conf.d]
```

- `idle` keeps a container alive and exits on SIGTERM, SIGINT and SIGQUIT.
- **Composer 2** is included. `COMPOSER_HOME` is `/tmp/composer`: ephemeral and writable by any
  uid. Mount a volume there, or set the variable, for a persistent cache.
- A disposable shell with the project mounted, nothing written outside it, nothing persisted:

  ```bash
  nerdctl run --rm -ti --user "$(id -u):$(id -g)" -e HOME=/app -v "$PWD:/app" ecommpro/php:8.4 bash
  ```

- `mail()` goes through `msmtp` with the command taken from the `SENDMAIL_COMMAND` environment
  variable (`sendmail_path = "eval $SENDMAIL_COMMAND"` in `conf.d/msmtp.ini`). Without the variable
  `mail()` fails. Example: `SENDMAIL_COMMAND='msmtp --host=smtp.example.com --port=587 --tls=on --auth=on --user=mta --passwordeval="cat /run/secrets/smtp" --read-envelope-from -t'`.
  Mount your own `conf.d` to replace this with a fixed configuration.

Application tooling (wp-cli, n98-magerun, image optimizers, database clients…) is not part of the
image: add it with Composer, or extend the image. The image carries no compiler: to build a PHP
extension in a derived image, install the toolchain first (the list is in `$PHPIZE_DEPS`, as in
the official image) and use [PIE](https://github.com/php/pie); the PHP headers and `phpize` are
included.

```dockerfile
FROM ecommpro/php:8.4
RUN apt-get update && apt-get install -y --no-install-recommends jpegoptim optipng && rm -rf /var/lib/apt/lists/*
```

## What is inside

### PHP

| Branch | PHP | Debian | Patches |
|---|---|---|---|
| 8.5, 8.4, 8.3, 8.2 | latest official patch release | trixie | none |
| 8.1, 8.0, 7.4, 7.3 | last official release (8.1.34, 8.0.30, 7.4.33, 7.3.33) | bookworm | Sury security backports |

The end-of-life branches no longer receive releases from the PHP project. Their PHP is the last
official tarball plus the patch series of the [Sury](https://deb.sury.org/) Debian packages:
security and compatibility backports from 2022 onward, including the October 2026
CVE-2026-109618 / CVE-2026-109619 fixes that the PHP security team published only as source
backports. Which series and which patches are selected is written in `versions.php` and recorded
in each `upstream/<branch>/patches.json` and in the image manifest. `php -v` still reports the
original version; the manifest and the `pro.ecomm.php.patches` label tell a patched image apart.
Extensions on these branches still follow their latest compatible releases. None of this restores
official support for those branches.

### Extensions

All branches compile the complete catalog in `extensions.php` (plus `geoip` and `mcrypt` on PHP 7).
An extension is **enabled by default** when it is both *passive* (a library that does nothing
until your code calls it: no instrumentation, no threads, no change to how PHP runs) and *common*
(required or habitual in Magento, Laravel, Symfony and WordPress). `opcache` is the one active
component enabled by default, because PHP-FPM without it is a broken setup.

    enabled by default   bcmath gd imagick intl soap xsl zip pdo_mysql mysqli sockets pcntl ftp
                         sodium redis memcached igbinary msgpack opcache
    compiled, disabled   mongodb amqp rdkafka swoole newrelic xhprof xdebug trapbox

Enable a disabled one from your `conf.d` (`extension=swoole`, `zend_extension=xdebug`). Load
`igbinary` and `msgpack` before `redis` and `memcached` if you write your own `extension=` lines.
`memcached` is built with the igbinary, msgpack and json serializers, as the Debian packages are,
so its default `memcached.serializer` is `igbinary` on PHP 8.1+. New Relic's generated template is
kept as `/usr/local/share/newrelic/newrelic.ini.example`; xhprof's web UI is in `/opt/xhprof`.

Versions come from Packagist through PIE on PHP 8.1+ (PIE needs PHP 8.1 to run) and from PECL
metadata on PHP 7.3 to 8.0, always the latest stable release compatible with the branch. Known
exceptions: `igbinary` accepts RC stability on PIE branches because Packagist only lists
`3.2.17RC1`; `xhprof` is built from the latest tag of its upstream repository, which is not on
Packagist; `geoip` (PHP 7 only) is pinned to 1.1.1 because PECL labels it beta; New Relic is
pinned by version and checksum in `extensions/newrelic`, because its agent is distributed outside
PECL and Packagist without published checksums, and is updated by hand.

## How it is built

Every image is the composition of four layers, declared per branch in `versions.php`:

| Layer | What | Who decides | Changes |
|---|---|---|---|
| System base | Debian release + official docker-library recipe at a pinned commit | us, following docker-library | rarely |
| Origin | the php.net release tarball, verified by checksum | PHP, or fixed forever on EOL branches | every PHP patch |
| Patches | a source patch series with provenance (Sury on EOL branches) | us | exceptionally |
| Extensions | the catalog, resolved to the latest stable compatible version | extension authors | every build |

```bash
./vendor-php.py 8.4 --update   # follow the latest docker-library recipe (new PHP patch release)
./vendor-php.py 7.4            # re-materialize an EOL branch after editing its patches entry
./build.sh 8.4                 # generate build/8.4/Dockerfile, build, verify, tag locally; never pushes
./build-all.sh                 # every branch; logs and manifests under build/runs/<run-id>/
./publish.py 8.4               # push, verify remote digests, record releases/<php>.json
```

Requirements: `nerdctl` (or `docker` with `CONTAINER_ENGINE=docker USE_SUDO=0`) with BuildKit,
`python3`, and the `php:8.2-cli` image for the generator, which needs network access to resolve
PECL, Packagist and Composer releases.

`vendor-php.py` materializes a branch into `upstream/<branch>/`: recipe and helpers at the pinned
commit, the Dockerfile adapted to the configured Debian and to apply the patch series, the
selected patches with `patches.json`; it verifies the Sury archive and php.net tarball checksums
and that every patch applies. `build.sh` generates the Dockerfile (stages `php-base`, `pie`,
`extensions` with one cached step per recipe and a shared `ccache`, and the final `image`: a clean
Debian with only the runtime packages the modules link against, `/usr/local` copied from the
build, Composer and `idle`; no compiler, no debug symbols), builds it and verifies the result: PHP starts, `php-fpm -t`, every catalog module loads with its version
and the enabled-by-default set matches the declaration (`verify-image.php`), Composer runs, `idle`
stops on SIGQUIT and SIGTERM, a real FastCGI request succeeds (`verify-fpm.php`) and, on patched
branches, an out-of-memory worker is replaced (`verify-legacy-security.php`). `publish.py` refuses
to overwrite a version tag that exists remotely with a different image unless `--force` is given.
`upstream/` and `build/` are generated and never edited by hand.

### Changing the catalog

Recipes live in `extensions/`: `extensions/<name>` is the base recipe, `extensions/<name>:X.Y`
applies to PHP X.Y and above (the highest matching version wins), and an empty file means "not
available on this branch". PHP 8.1+ recipes use `$PIE_INSTALL vendor/package [configure options]`;
PHP 7.3 to 8.0 recipes use `pecl install name` and the generator pins the resolved version.
Recipes only build; whether the module is enabled by default is the flag in `extensions.php`,
which the generator enforces after every recipe and the verifier checks. Add new names to
`extensions.php` in dependency order and run `php test-generator.php` in the generator container.
