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

namespace SURFnet\VPN\Server\CA;

use RuntimeException;
use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Common\FileIO;
use SURFnet\VPN\Server\CA\Exception\CaException;

class EasyRsaCa implements CaInterface
{
    /** @var string */
    private $easyRsaDir;

    /** @var string */
    private $easyRsaDataDir;

    public function __construct($easyRsaDir, $easyRsaDataDir)
    {
        $this->easyRsaDir = $easyRsaDir;
        $this->easyRsaDataDir = $easyRsaDataDir;
        FileIO::createDir($this->easyRsaDataDir, 0700);
    }

    /**
     * Initialize the CA.
     *
     * @param \SURFnet\VPN\Common\Config $config the CA configuration
     */
    public function init(Config $config)
    {
        // only initialize when unitialized, prevent destroying existing CA
        if (!@file_exists(sprintf('%s/vars', $this->easyRsaDataDir))) {
            $configData = [
                sprintf('set_var EASYRSA "%s"', $this->easyRsaDir),
                sprintf('set_var EASYRSA_PKI "%s/pki"', $this->easyRsaDataDir),
                sprintf('set_var EASYRSA_KEY_SIZE %d', $config->i('CA', 'key_size')),
                sprintf('set_var EASYRSA_CA_EXPIRE %d', $config->i('CA', 'ca_expire')),
                sprintf('set_var EASYRSA_CERT_EXPIRE %d', $config->i('CA', 'cert_expire')),
                sprintf('set_var EASYRSA_REQ_CN	"%s"', $config->s('CA', 'ca_cn')),
                'set_var EASYRSA_BATCH "1"',
            ];

            FileIO::writeFile(
                sprintf('%s/vars', $this->easyRsaDataDir),
                implode(PHP_EOL, $configData).PHP_EOL,
                0600
            );

            $this->execEasyRsa(['init-pki']);
            $this->execEasyRsa(['build-ca', 'nopass']);
        }
    }

    /**
     * Get the CA root certificate.
     *
     * @return string the CA certificate in PEM format
     */
    public function caCert()
    {
        $certFile = sprintf('%s/pki/ca.crt', $this->easyRsaDataDir);

        return $this->readCertificate($certFile);
    }

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
        if ($this->hasCert($commonName)) {
            throw new CaException(sprintf('certificate with commonName "%s" already exists', $commonName));
        }

        $this->execEasyRsa(['build-server-full', $commonName, 'nopass']);

        return $this->certInfo($commonName);
    }

    /**
     * Generate a certificate for a VPN client.
     *
     * @param string $commonName
     *
     * @return array the certificate and key in array with keys 'cert', 'key',
     *               'valid_from' and 'valid_to'
     */
    public function clientCert($commonName)
    {
        if ($this->hasCert($commonName)) {
            throw new CaException(sprintf('certificate with commonName "%s" already exists', $commonName));
        }

        $this->execEasyRsa(['build-client-full', $commonName, 'nopass']);

        return $this->certInfo($commonName);
    }

    private function certInfo($commonName)
    {
        $certData = $this->readCertificate(sprintf('%s/pki/issued/%s.crt', $this->easyRsaDataDir, $commonName));
        $keyData = $this->readKey(sprintf('%s/pki/private/%s.key', $this->easyRsaDataDir, $commonName));

        $parsedCert = openssl_x509_parse($certData);

        return [
            'certificate' => $certData,
            'private_key' => $keyData,
            'valid_from' => $parsedCert['validFrom_time_t'],
            'valid_to' => $parsedCert['validTo_time_t'],
        ];
    }

    private function readCertificate($certFile)
    {
        // strip junk before and after actual certificate
        $pattern = '/(-----BEGIN CERTIFICATE-----.*-----END CERTIFICATE-----)/msU';
        if (1 !== preg_match($pattern, FileIO::readFile($certFile), $matches)) {
            throw new CaException('unable to extract certificate');
        }

        return $matches[1];
    }

    private function readKey($keyFile)
    {
        // strip whitespace before and after actual key
        return trim(
            FileIO::readFile($keyFile)
        );
    }

    private function hasCert($commonName)
    {
        return @file_exists(
            sprintf(
                '%s/pki/issued/%s.crt',
                $this->easyRsaDataDir,
                $commonName
            )
        );
    }

    private function execEasyRsa(array $argv)
    {
        $command = sprintf(
            '%s/easyrsa --vars=%s/vars %s >/dev/null 2>/dev/null',
            $this->easyRsaDir,
            $this->easyRsaDataDir,
            implode(' ', $argv)
        );

        exec(
            $command,
            $commandOutput,
            $returnValue
        );

        if (0 !== $returnValue) {
            throw new RuntimeException(
                sprintf('command "%s" did not complete successfully: "%s"', $command, $commandOutput)
            );
        }
    }
}
