<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Server\Tests;

use DateTime;
use LC\Common\Config;
use LC\Server\CA\CaInterface;

class TestCa implements CaInterface
{
    /**
     * Generate a certificate for the VPN server.
     *
     * @param string $commonName
     *
     * @return array the certificate, key in array with keys
     *               'cert', 'key', 'valid_from' and 'valid_to'
     */
    public function serverCert($commonName)
    {
        return [
            'certificate' => sprintf('ServerCert for %s', $commonName),
            'private_key' => sprintf('ServerCert for %s', $commonName),
            'valid_from' => 1234567890,
            'valid_to' => 2345678901,
        ];
    }

    /**
     * Generate a certificate for a VPN client.
     *
     * @param string    $commonName
     * @param \DateTime $expiresAt
     *
     * @return array the certificate and key in array with keys 'cert', 'key',
     *               'valid_from' and 'valid_to'
     */
    public function clientCert($commonName, DateTime $expiresAt)
    {
        return [
            'certificate' => sprintf('ClientCert for %s', $commonName),
            'private_key' => sprintf('ClientKey for %s', $commonName),
            'valid_from' => 1234567890,
            'valid_to' => $expiresAt->getTimestamp(),
        ];
    }

    /**
     * Get the CA root certificate.
     *
     * @return string the CA certificate in PEM format
     */
    public function caCert()
    {
        return 'Ca';
    }

    public function init(Config $config)
    {
        // NOP
    }
}
