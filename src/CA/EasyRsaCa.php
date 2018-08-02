<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Server\CA;

use RuntimeException;
use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Common\FileIO;
use SURFnet\VPN\Server\CA\Exception\CaException;

class EasyRsaCa implements CaInterface
{
    /** @var \SURFnet\VPN\Common\Config */
    private $config;

    /** @var string */
    private $easyRsaDir;

    /** @var string */
    private $easyRsaDataDir;

    /**
     * @param \SURFnet\VPN\Common\Config $config
     * @param string                     $easyRsaDir
     * @param string                     $easyRsaDataDir
     */
    public function __construct(Config $config, $easyRsaDir, $easyRsaDataDir)
    {
        $this->config = $config;
        $this->easyRsaDir = $easyRsaDir;
        $this->easyRsaDataDir = $easyRsaDataDir;
        $this->init();
    }

    /**
     * @return void
     */
    public function init()
    {
        FileIO::createDir($this->easyRsaDataDir, 0700);

        // only initialize when unitialized, prevent destroying existing CA
        if (!@file_exists(sprintf('%s/vars', $this->easyRsaDataDir))) {
            $configData = [
                sprintf('set_var EASYRSA "%s"', $this->easyRsaDir),
                sprintf('set_var EASYRSA_PKI "%s/pki"', $this->easyRsaDataDir),
                sprintf('set_var EASYRSA_KEY_SIZE %d', $this->config->getSection('CA')->getItem('key_size')),
                sprintf('set_var EASYRSA_CA_EXPIRE %d', $this->config->getSection('CA')->getItem('ca_expire')),
                sprintf('set_var EASYRSA_REQ_CN	"%s"', $this->config->getSection('CA')->getItem('ca_cn')),
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

        // check if we have a server_cert_expire
        if ($this->config->getSection('CA')->hasItem('server_cert_expire')) {
            $serverCertExpire = $this->config->getSection('CA')->getItem('server_cert_expire');
        } else {
            $serverCertExpire = $this->config->getSection('CA')->getItem('cert_expire');
        }

        $this->execEasyRsa([sprintf('--days=%d', $serverCertExpire), 'build-server-full', $commonName, 'nopass']);

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

        $this->execEasyRsa(
            [
                sprintf(
                    '--days=%d',
                    $this->config->getSection('CA')->getItem('cert_expire')
                ),
                'build-client-full',
                $commonName,
                'nopass',
            ]
        );

        return $this->certInfo($commonName);
    }

    /**
     * @param string $commonName
     *
     * @return array<string,string>
     */
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

    /**
     * @param string $certFile
     *
     * @return string
     */
    private function readCertificate($certFile)
    {
        // strip junk before and after actual certificate
        $pattern = '/(-----BEGIN CERTIFICATE-----.*-----END CERTIFICATE-----)/msU';
        if (1 !== preg_match($pattern, FileIO::readFile($certFile), $matches)) {
            throw new CaException('unable to extract certificate');
        }

        return $matches[1];
    }

    /**
     * @param string $keyFile
     *
     * @return string
     */
    private function readKey($keyFile)
    {
        // strip whitespace before and after actual key
        return trim(
            FileIO::readFile($keyFile)
        );
    }

    /**
     * @param string $commonName
     *
     * @return bool
     */
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

    /**
     * @return void
     */
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
                sprintf('command "%s" did not complete successfully: "%s"', $command, implode(PHP_EOL, $commandOutput))
            );
        }
    }
}
