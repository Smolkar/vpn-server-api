#!/usr/bin/env php
<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use LetsConnect\Common\Config;
use LetsConnect\Server\CA\EasyRsaCa;
use LetsConnect\Server\Storage;
use LetsConnect\Server\TlsAuth;

try {
    $configFile = sprintf('%s/config/config.php', $baseDir);
    $config = Config::fromFile($configFile);

    $easyRsaDir = sprintf('%s/easy-rsa', $baseDir);
    $easyRsaDataDir = sprintf('%s/data/easy-rsa', $baseDir);

    $ca = new EasyRsaCa($config, $easyRsaDir, $easyRsaDataDir);

    $dataDir = sprintf('%s/data', $baseDir);
    $storage = new Storage(
        new PDO(
            sprintf('sqlite://%s/db.sqlite', $dataDir)
        ),
        sprintf('%s/schema', $baseDir),
        new DateTime('now')
    );
    $storage->init();

    $tlsAuth = new TlsAuth($dataDir);
    $tlsAuth->init();
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).PHP_EOL;
    exit(1);
}
