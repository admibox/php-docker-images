<?php
// PHP branches built by this repository, newest first. Each branch is described in layers:
//
//   php      Origin: the official php.net release tarball (verified by checksum). Supported branches
//            follow the latest patch release (vendor-php.py --update); end-of-life branches stay on
//            their last release forever.
//   recipe   System base: the official docker-library/php build recipe (Dockerfile and helpers) at a
//            commit, and the Debian release the image is built on. 'variant' is the docker-library
//            directory to take the recipe from when it differs from 'debian' (old recipes predate
//            bookworm and are adapted by vendor-php.py).
//   patches  Our decision, changed exceptionally: a series of source patches applied on top of the
//            origin, with provenance. Today: the Sury Debian packaging series for end-of-life
//            branches. Patches are selected by subject (Sury renumbers its series): from the first
//            patch whose subject starts with 'from' onward (earlier ones are Debian packaging),
//            plus 'also', minus 'skip', minus the README and DNS telemetry patches that
//            vendor-php.py always drops.
//            It includes the October 2026 CVE-2026-109618 / CVE-2026-109619 backports.
//
// Extensions are the fourth layer: the catalog in extensions.php, resolved to the latest stable
// compatible version at every build (PIE on PHP >= 8.1, PECL metadata below).
//
// vendor-php.py materializes a branch into upstream/<branch>/ from this description.
return [
    '8.5' => [
        'php' => '8.5.13',
        'recipe' => ['commit' => '3b25f4162a9bd144d3f447d03cb8fe16c0d2c9cc', 'debian' => 'trixie'],
    ],
    '8.4' => [
        'php' => '8.4.28',
        'recipe' => ['commit' => '3b25f4162a9bd144d3f447d03cb8fe16c0d2c9cc', 'debian' => 'trixie'],
    ],
    '8.3' => [
        'php' => '8.3.37',
        'recipe' => ['commit' => '3b25f4162a9bd144d3f447d03cb8fe16c0d2c9cc', 'debian' => 'trixie'],
    ],
    '8.2' => [
        'php' => '8.2.36',
        'recipe' => ['commit' => '3b25f4162a9bd144d3f447d03cb8fe16c0d2c9cc', 'debian' => 'trixie'],
    ],
    '8.1' => [
        'php' => '8.1.34',
        'recipe' => ['commit' => '6485b53a1da0f2c4c5b564808de75ae62c023286', 'debian' => 'bookworm'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '8.1.34-14+debsuryorg12.1+php+1',
            'sha256' => '8e6ae16c3b5edc588bb9865eee94c2847863b7c542ce2b19af40294cb47f6eec',
            'from' => 'Fail-the-build-when-the-dtrace-call-fails',
        ],
    ],
    '8.0' => [
        'php' => '8.0.30',
        'recipe' => ['commit' => '41c9ad0b43a50bbf46564f27779a01105df49b9a', 'debian' => 'bookworm', 'variant' => 'bullseye'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '8.0.30-31+debsuryorg12.1+php+1',
            'sha256' => '8ab9584903e6ee1c4437cd7bf7c315eb0237092bb5cb16d0247567f5c6ef15da',
            'from' => 'Add-minimal-OpenSSL-3.0-patch',
        ],
    ],
    '7.4' => [
        'php' => '7.4.33',
        'recipe' => ['commit' => 'b9f17156020c3aef71df681b27684533529347a7', 'debian' => 'bookworm', 'variant' => 'bullseye'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '7.4.33-37+debsuryorg12.1+php+1',
            'sha256' => '56360570ece3981c5842871b7f6ed1006cdb60af5e7887a6d5ed63a49d271164',
            'from' => 'Add-minimal-OpenSSL-3.0-patch',
            // Fixes up a Debian packaging patch we do not take, for enchant, which is not built here.
            'skip' => ['add-information-about-binary-distributions', 'Keep-enchant_dict_is_in_session'],
        ],
    ],
    '7.3' => [
        'php' => '7.3.33',
        'recipe' => ['commit' => 'b62fa33a7198244ea296e5f1845f99ff6363743c', 'debian' => 'bookworm', 'variant' => 'bullseye'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '7.3.33-41+debsuryorg12.1+php+1',
            'sha256' => 'd66a0ad728ea98242c3d6718874c048021a1de8c49e2c4ac5f8581f3e93dfcd5',
            'from' => 'Add-minimal-OpenSSL-3.0-patch',
            // FreeType detection through pkg-config; the GD recipe depends on it on bookworm.
            'also' => ['Use-pkg-config-for-FreeType2-detection'],
            // Fixes up a Debian packaging patch we do not take, for enchant, which is not built here.
            'skip' => ['Keep-enchant_dict_is_in_session'],
        ],
    ],
];
