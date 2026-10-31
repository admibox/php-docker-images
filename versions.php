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
//            branches, selected from patch 'from' onward, plus 'also', minus 'skip', minus the
//            Debian packaging/README/telemetry patches that vendor-php.py always drops.
//            It includes the October 2026 CVE-2026-109618 / CVE-2026-109619 backports.
//
// Extensions are the fourth layer: the catalog in extensions.php, resolved to the latest stable
// compatible version at every build (PIE on PHP >= 8.1, PECL metadata below).
//
// vendor-php.py materializes a branch into upstream/<branch>/ from this description.
return [
    '8.5' => [
        'php' => '8.5.12',
        'recipe' => ['commit' => '5ec2a6a0dad498e89a91a39454dbb52e98ce2bdb', 'debian' => 'trixie'],
    ],
    '8.4' => [
        'php' => '8.4.27',
        'recipe' => ['commit' => 'f6048b5f6e3c2202ee082665a4ccc0d90de291a3', 'debian' => 'trixie'],
    ],
    '8.3' => [
        'php' => '8.3.36',
        'recipe' => ['commit' => 'f6048b5f6e3c2202ee082665a4ccc0d90de291a3', 'debian' => 'trixie'],
    ],
    '8.2' => [
        'php' => '8.2.35',
        'recipe' => ['commit' => '5ec2a6a0dad498e89a91a39454dbb52e98ce2bdb', 'debian' => 'trixie'],
    ],
    '8.1' => [
        'php' => '8.1.34',
        'recipe' => ['commit' => '6485b53a1da0f2c4c5b564808de75ae62c023286', 'debian' => 'bookworm'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '8.1.34-9+0~20261021.82+debian12~1.gbp4e430a',
            'sha256' => '03a83aeb5b1912d7b427e331c1287860d31a3a6c8b49ff40ac83df371283979c',
            'from' => '0043',
        ],
    ],
    '8.0' => [
        'php' => '8.0.30',
        'recipe' => ['commit' => '41c9ad0b43a50bbf46564f27779a01105df49b9a', 'debian' => 'bookworm', 'variant' => 'bullseye'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '8.0.30-25+0~20261021.88+debian12~1.gbpabebea',
            'sha256' => '8dd2d897829859df423919744fa4db2a4a61c027ffa6afdef01d276262f5df6e',
            'from' => '0042',
        ],
    ],
    '7.4' => [
        'php' => '7.4.33',
        'recipe' => ['commit' => 'b9f17156020c3aef71df681b27684533529347a7', 'debian' => 'bookworm', 'variant' => 'bullseye'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '7.4.33-31+0~20261021.121+debian13~1.gbpe9c428',
            'sha256' => '931faf5908545becc2d3089c1e3905befa61cee545459e80a217670e64fcaa56',
            'from' => '0048',
            'skip' => ['0053-add-information-about-binary-distributions.patch'],
        ],
    ],
    '7.3' => [
        'php' => '7.3.33',
        'recipe' => ['commit' => 'b62fa33a7198244ea296e5f1845f99ff6363743c', 'debian' => 'bookworm', 'variant' => 'bullseye'],
        'patches' => [
            'vendor' => 'sury',
            'package' => '7.3.33-35+0~20261021.144+debian13~1.gbpecf76f',
            'sha256' => '0c65625f2cbd18749891f7a0bba653c7eabe5864155036cac9407508560eb983',
            'from' => '0059',
            // FreeType detection through pkg-config; the GD recipe depends on it on bookworm.
            'also' => ['0046-Use-pkg-config-for-FreeType2-detection.patch'],
        ],
    ],
];
