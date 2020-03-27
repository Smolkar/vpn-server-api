<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use LC\Common\Config;
use LC\Common\Logger;
use LC\Common\ProfileConfig;
use LC\OpenVpn\ManagementSocket;
use LC\Server\Api\OpenVpnDaemonModule;
use LC\Server\OpenVpn\DaemonSocket;
use LC\Server\OpenVpn\ServerManager;
use LC\Server\Storage;

/**
 * @return array
 */
function getMaxClientLimit(Config $config)
{
    $profileIdList = array_keys($config->getItem('vpnProfiles'));

    $maxConcurrentConnectionLimitList = [];
    foreach ($profileIdList as $profileId) {
        $profileConfig = new ProfileConfig($config->getSection('vpnProfiles')->getItem($profileId));
        list($ipFour, $ipFourPrefix) = explode('/', $profileConfig->getItem('range'));
        $vpnProtoPortsCount = count($profileConfig->getItem('vpnProtoPorts'));
        $maxConcurrentConnectionLimitList[$profileId] = ((int) pow(2, 32 - (int) $ipFourPrefix)) - 3 * $vpnProtoPortsCount;
    }

    return $maxConcurrentConnectionLimitList;
}

/**
 * @param string $outFormat
 *
 * @return string
 */
function outputConversion(array $outputData, $outFormat)
{
    switch ($outFormat) {
        case 'csv':
            if (0 === count($outputData)) {
                return;
            }
            $headerKeys = array_keys($outputData[0]);
            echo implode(',', $headerKeys).PHP_EOL;
            foreach ($outputData as $outputRow) {
                echo implode(',', array_values($outputRow)).PHP_EOL;
            }
            break;
        case 'json':
            echo json_encode($outputData, JSON_PRETTY_PRINT);
            break;
        default:
            throw new Exception('unsupported output format "'.$outFormat.'"');
    }
}

try {
    $configDir = sprintf('%s/config', $baseDir);
    $configFile = sprintf('%s/config.php', $configDir);
    $config = ProfileConfig::fromFile($configFile);
    $dataDir = sprintf('%s/data', $baseDir);
    $logger = new Logger($argv[0]);

    $alertOnly = false;
    $outFormat = 'csv';
    $searchForPercentage = false;
    $alertPercentage = 90;
    foreach ($argv as $arg) {
        if ('--alert' === $arg) {
            $alertOnly = true;
            $searchForPercentage = true;
            continue;
        }
        if ($searchForPercentage) {
            // capture parameter after "--alert" and use that as percentage
            if (is_numeric($arg) && 0 <= $arg && 100 >= $arg) {
                $alertPercentage = (int) $arg;
            }
            $searchForPercentage = false;
        }
        if ('--json' === $arg) {
            $outFormat = 'json';
        }
    }

    $maxClientLimit = getMaxClientLimit($config);

    if ($config->hasItem('useVpnDaemon') && $config->getItem('useVpnDaemon')) {
        // with vpn-daemon
        $storage = new Storage(
            new PDO(
                sprintf('sqlite://%s/db.sqlite', $dataDir)
            ),
            sprintf('%s/schema', $baseDir)
        );
        $openVpnDaemonModule = new OpenVpnDaemonModule(
            $config,
            $storage,
            new DaemonSocket(sprintf('%s/vpn-daemon', $configDir))
        );
        $openVpnDaemonModule->setLogger($logger);
        $outputData = [];
        foreach ($openVpnDaemonModule->getConnectionList(null, null) as $profileId => $connectionInfoList) {
            $activeConnectionCount = count($connectionInfoList);
            $profileMaxClientLimit = $maxClientLimit[$profileId];
            $percentInUse = floor($activeConnectionCount / $profileMaxClientLimit * 100);
            if ($alertOnly && $alertPercentage > $percentInUse) {
                continue;
            }
            $outputData[] = [
                'profile_id' => $profileId,
                'active_connection_count' => $activeConnectionCount,
                'max_connection_count' => $profileMaxClientLimit,
                'percentage_in_use' => $percentInUse,
            ];
        }
        echo outputConversion($outputData, $outFormat);
    } else {
        // without vpn-daemon
        $serverManager = new ServerManager(
            $config,
            $logger,
            new ManagementSocket()
        );

        $outputData = [];
        foreach ($serverManager->connections() as $profile) {
            $activeConnectionCount = count($profile['connections']);
            $profileMaxClientLimit = $maxClientLimit[$profile['id']];
            $percentInUse = floor($activeConnectionCount / $profileMaxClientLimit * 100);
            if ($alertOnly && 90 > $percentInUse) {
                continue;
            }
            $outputData[] = [
                'profile_id' => $profile['id'],
                'active_connection_count' => $activeConnectionCount,
                'max_connection_count' => $profileMaxClientLimit,
                'percentage_in_use' => $percentInUse,
            ];
        }
        echo outputConversion($outputData, $outFormat);
    }
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).PHP_EOL;
    exit(1);
}
