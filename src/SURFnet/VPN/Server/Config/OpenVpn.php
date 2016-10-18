<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace SURFnet\VPN\Server\Config;

use SURFnet\VPN\Common\ProfileConfig;
use SURFnet\VPN\Server\IP;
use SURFnet\VPN\Common\FileIO;
use RuntimeException;
use SURFnet\VPN\Common\HttpClient\CaClient;

class OpenVpn
{
    /** @var string */
    private $vpnConfigDir;

    /** @var string */
    private $vpnTlsDir;

    public function __construct($vpnConfigDir, $vpnTlsDir)
    {
        FileIO::createDir($vpnConfigDir, 0700);
        $this->vpnConfigDir = $vpnConfigDir;
        FileIO::createDir($vpnTlsDir, 0700);
        $this->vpnTlsDir = $vpnTlsDir;
    }

    public function generateKeys(CaClient $caClient, $commonName, $dhSourceFile)
    {
        $certData = $caClient->addServerCertificate($commonName);

        $certFileMapping = [
            'ca' => sprintf('%s/ca.crt', $this->vpnTlsDir),
            'cert' => sprintf('%s/server.crt', $this->vpnTlsDir),
            'key' => sprintf('%s/server.key', $this->vpnTlsDir),
            'ta' => sprintf('%s/ta.key', $this->vpnTlsDir),
        ];

        foreach ($certFileMapping as $k => $v) {
            FileIO::writeFile($v, $certData[$k], 0600);
        }

        // copy the DH parameter file
        $dhTargetFile = sprintf('%s/dh.pem', $this->vpnTlsDir);
        if (false === copy($dhSourceFile, $dhTargetFile)) {
            throw new RuntimeException('unable to copy DH file');
        }
    }

    public function writePool($instanceNumber, $instanceId, $poolId, ProfileConfig $profileConfig)
    {
        $range = new IP($profileConfig->v('range'));
        $range6 = new IP($profileConfig->v('range6'));
        $processCount = $profileConfig->v('processCount');

        $splitRange = $range->split($processCount);
        $splitRange6 = $range6->split($processCount);

        if ($profileConfig->e('managementIp')) {
            $managementIp = $profileConfig->v('managementIp');
        } else {
            $managementIp = sprintf('127.42.%d.%d', 100 + $instanceNumber, 100 + $profileConfig->v('poolNumber'));
        }

        $processConfig = [
            'managementIp' => $managementIp,
        ];

        for ($i = 0; $i < $processCount; ++$i) {
            // protocol is udp unless it is the last process when there is
            // not just one process
            if (1 === $processCount || $i !== $processCount - 1) {
                $proto = 'udp';
                $port = 1194 + $i;
                $local = $profileConfig->v('listen');
            } else {
                $proto = 'tcp';
                if ($profileConfig->v('hasProxy')) {
                    $port = 1194;
                    $local = $managementIp;
                } else {
                    $port = 443;
                    $local = $profileConfig->v('listen');
                }
            }

            $processConfig['range'] = $splitRange[$i];
            $processConfig['range6'] = $splitRange6[$i];
            $processConfig['dev'] = sprintf('tun-%d-%d-%d', $instanceNumber, $profileConfig->v('poolNumber'), $i);
            $processConfig['proto'] = $proto;
            $processConfig['port'] = $port;
            $processConfig['local'] = $local;
            $processConfig['managementPort'] = 11940 + $i;
            $processConfig['configName'] = sprintf(
                'server-%s-%s-%d.conf',
                $instanceId,
                $poolId,
                $i
            );

            $this->writeProcess($instanceId, $poolId, $profileConfig, $processConfig);
        }
    }

    private function writeProcess($instanceId, $poolId, ProfileConfig $profileConfig, array $processConfig)
    {
        $tlsDir = sprintf('/etc/openvpn/tls/%s/%s', $instanceId, $poolId);

        $rangeIp = new IP($processConfig['range']);
        $range6Ip = new IP($processConfig['range6']);

        // static options
        $serverConfig = [
            '# OpenVPN Server Configuration',
            'verb 3',
            'dev-type tun',
            'user openvpn',
            'group openvpn',
            'topology subnet',
            'persist-key',
            'persist-tun',
            'keepalive 10 60',
            'comp-lzo no',
            'remote-cert-tls client',
            'tls-version-min 1.2',
            'tls-cipher TLS-DHE-RSA-WITH-AES-128-GCM-SHA256:TLS-DHE-RSA-WITH-AES-256-GCM-SHA384:TLS-DHE-RSA-WITH-AES-256-CBC-SHA',
            'auth SHA256',
            'cipher AES-256-CBC',
            'client-connect /usr/sbin/vpn-server-api-client-connect',
            'client-disconnect /usr/sbin/vpn-server-api-client-disconnect',
            'push "comp-lzo no"',
            'push "explicit-exit-notify 3"',
            sprintf('ca %s/ca.crt', $tlsDir),
            sprintf('cert %s/server.crt', $tlsDir),
            sprintf('key %s/server.key', $tlsDir),
            sprintf('dh %s/dh.pem', $tlsDir),
            sprintf('tls-auth %s/ta.key 0', $tlsDir),
            sprintf('server %s %s', $rangeIp->getNetwork(), $rangeIp->getNetmask()),
            sprintf('server-ipv6 %s', $range6Ip->getAddressPrefix()),
            sprintf('max-clients %d', $rangeIp->getNumberOfHosts() - 1),
            sprintf('script-security %d', $profileConfig->v('twoFactor') ? 3 : 2),
            sprintf('dev %s', $processConfig['dev']),
            sprintf('port %d', $processConfig['port']),
            sprintf('management %s %d', $processConfig['managementIp'], $processConfig['managementPort']),
            sprintf('setenv INSTANCE_ID %s', $instanceId),
            sprintf('setenv POOL_ID %s', $poolId),
            sprintf('proto %s', 'tcp' === $processConfig['proto'] ? 'tcp-server' : 'udp'),
            sprintf('local %s', $processConfig['local']),

            // increase the renegotiation time to 8h from the default of 1h when
            // using 2FA, otherwise the user would be asked for the 2FA key every
            // hour
            sprintf('reneg-sec %d', $profileConfig->v('twoFactor') ? 28800 : 3600),
        ];

        if (!$profileConfig->v('enableLog')) {
            $serverConfig[] = 'log /dev/null';
        }

        if ('tcp' === $processConfig['proto']) {
            $serverConfig[] = 'tcp-nodelay';
        }

        if ($profileConfig->v('twoFactor')) {
            $serverConfig[] = 'auth-user-pass-verify /usr/sbin/vpn-server-api-verify-otp via-env';
        }

        // Routes
        $serverConfig = array_merge($serverConfig, self::getRoutes($profileConfig));

        // DNS
        $serverConfig = array_merge($serverConfig, self::getDns($profileConfig));

        // Client-to-client
        $serverConfig = array_merge($serverConfig, self::getClientToClient($profileConfig));

        sort($serverConfig, SORT_STRING);

        $configFile = sprintf('%s/%s', $this->vpnConfigDir, $processConfig['configName']);

        FileIO::writeFile($configFile, implode(PHP_EOL, $serverConfig), 0600);
    }

    private static function getRoutes(ProfileConfig $profileConfig)
    {
        $routeConfig = [];
        if ($profileConfig->v('defaultGateway')) {
            $routeConfig[] = 'push "redirect-gateway def1 bypass-dhcp"';

            // for Windows clients we need this extra route to mark the TAP adapter as
            // trusted and as having "Internet" access to allow the user to set it to
            // "Home" or "Work" to allow accessing file shares and printers
            // NOTE: this will break OS X tunnelblick because on disconnect it will
            // remove all default routes, including the one set before the VPN
            // was brought up
            //$routeConfig[] = 'push "route 0.0.0.0 0.0.0.0"';

            // for iOS we need this OpenVPN 2.4 "ipv6" flag to redirect-gateway
            // See https://docs.openvpn.net/docs/openvpn-connect/openvpn-connect-ios-faq.html
            $routeConfig[] = 'push "redirect-gateway ipv6"';

            // we use 2000::/3 instead of ::/0 because it seems to break on native IPv6
            // networks where the ::/0 default route already exists
            $routeConfig[] = 'push "route-ipv6 2000::/3"';
        } else {
            // there may be some routes specified, push those, and not the default
            foreach ($profileConfig->v('routes') as $route) {
                $routeIp = new IP($route);
                if (6 === $routeIp->getFamily()) {
                    // IPv6
                    $routeConfig[] = sprintf('push "route-ipv6 %s"', $routeIp->getAddressPrefix());
                } else {
                    // IPv4
                    $routeConfig[] = sprintf('push "route %s %s"', $routeIp->getAddress(), $routeIp->getNetmask());
                }
            }
        }

        return $routeConfig;
    }

    private static function getDns(ProfileConfig $profileConfig)
    {
        // only push DNS if we are the default route
        if (!$profileConfig->v('defaultGateway')) {
            return [];
        }

        $dnsEntries = [];
        foreach ($profileConfig->v('dns') as $dnsAddress) {
            $dnsEntries[] = sprintf('push "dhcp-option DNS %s"', $dnsAddress);
        }

        // prevent DNS leakage on Windows
        $dnsEntries[] = 'push "block-outside-dns"';

        return $dnsEntries;
    }

    private static function getClientToClient(ProfileConfig $profileConfig)
    {
        if (!$profileConfig->v('clientToClient')) {
            return [];
        }

        $rangeIp = new IP($profileConfig->v('range'));
        $range6Ip = new IP($profileConfig->v('range6'));

        return [
            'client-to-client',
            sprintf('push "route %s %s"', $rangeIp->getAddress(), $rangeIp->getNetmask()),
            sprintf('push "route-ipv6 %s"', $range6Ip->getAddressPrefix()),
        ];
    }
}
