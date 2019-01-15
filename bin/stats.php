#!/usr/bin/env php
<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Common\FileIO;
use SURFnet\VPN\Server\Stats;
use SURFnet\VPN\Server\Storage;

try {
    $configFile = sprintf('%s/config/config.php', $baseDir);
    $config = Config::fromFile($configFile);

    $dataDir = sprintf('%s/data', $baseDir);
    $db = new PDO(sprintf('sqlite://%s/db.sqlite', $dataDir));
    $storage = new Storage(
        $db,
        sprintf('%s/schema', $baseDir),
        new DateTime('now')
    );

    $outFile = sprintf('%s/stats.json', $dataDir);

    $stats = new Stats($storage, new DateTime());
    $statsData = $stats->get(
        array_keys($config->getSection('vpnProfiles')->toArray())
    );

    FileIO::writeJsonFile(
        $outFile,
        $statsData
    );
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).PHP_EOL;
    exit(1);
}
