<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Server\Api;

use DateTime;
use LC\Common\Http\ApiErrorResponse;
use LC\Common\Http\ApiResponse;
use LC\Common\Http\AuthUtils;
use LC\Common\Http\InputValidation;
use LC\Common\Http\Request;
use LC\Common\Http\Service;
use LC\Common\Http\ServiceModuleInterface;
use LC\Common\RandomInterface;
use LC\Server\CA\CaInterface;
use LC\Server\Storage;
use LC\Server\TlsAuth;

class CertificatesModule implements ServiceModuleInterface
{
    /** @var \LC\Server\CA\CaInterface */
    private $ca;

    /** @var \LC\Server\Storage */
    private $storage;

    /** @var \LC\Server\TlsAuth */
    private $tlsAuth;

    /** @var \LC\Common\RandomInterface */
    private $random;

    public function __construct(CaInterface $ca, Storage $storage, TlsAuth $tlsAuth, RandomInterface $random)
    {
        $this->ca = $ca;
        $this->storage = $storage;
        $this->tlsAuth = $tlsAuth;
        $this->random = $random;
    }

    /**
     * @return void
     */
    public function init(Service $service)
    {
        /* CERTIFICATES */
        $service->post(
            '/add_client_certificate',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $displayName = InputValidation::displayName($request->getPostParameter('display_name'));
                $clientId = $request->getPostParameter('client_id', false);
                if (null !== $clientId) {
                    $clientId = InputValidation::clientId($clientId);
                }

                $expiresAt = InputValidation::expiresAt($request->getPostParameter('expires_at'));

                // generate a random string as the certificate's CN
                $commonName = $this->random->get(16);
                $certInfo = $this->ca->clientCert($commonName, $expiresAt);

                $this->storage->addCertificate(
                    $userId,
                    $commonName,
                    $displayName,
                    new DateTime(sprintf('@%d', $certInfo['valid_from'])),
                    new DateTime(sprintf('@%d', $certInfo['valid_to'])),
                    $clientId
                );

                $this->storage->addUserMessage(
                    $userId,
                    'notification',
                    sprintf('new certificate "%s" generated by user', $displayName)
                );

                return new ApiResponse('add_client_certificate', $certInfo, 201);
            }
        );

        /*
         * This provides the CA (public) certificate and the "tls-auth" key
         * for this instance. The API call has a terrible name...
         */
        $service->get(
            '/server_info',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $serverInfo = [
                    'ta' => $this->tlsAuth->get(),
                    'ca' => $this->ca->caCert(),
                ];

                return new ApiResponse('server_info', $serverInfo);
            }
        );

        $service->post(
            '/add_server_certificate',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-server-node']);

                $commonName = InputValidation::serverCommonName($request->getPostParameter('common_name'));

                $certInfo = $this->ca->serverCert($commonName);
                // add TLS Auth
                $certInfo['ta'] = $this->tlsAuth->get();
                $certInfo['ca'] = $this->ca->caCert();

                return new ApiResponse('add_server_certificate', $certInfo, 201);
            }
        );

        $service->post(
            '/delete_client_certificate',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $commonName = InputValidation::commonName($request->getPostParameter('common_name'));
                if (false === $certInfo = $this->storage->getUserCertificateInfo($commonName)) {
                    return new ApiErrorResponse('delete_client_certificate', 'certificate does not exist');
                }

                $this->storage->addUserMessage(
                    $certInfo['user_id'],
                    'notification',
                    sprintf('certificate "%s" deleted by user', $certInfo['display_name'])
                );

                $this->storage->deleteCertificate($commonName);

                return new ApiResponse('delete_client_certificate');
            }
        );

        $service->post(
            '/delete_client_certificates_of_client_id',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $clientId = InputValidation::clientId($request->getPostParameter('client_id'));

                $this->storage->addUserMessage(
                    $userId,
                    'notification',
                    sprintf('certificates for OAuth client "%s" deleted', $clientId)
                );

                $this->storage->deleteCertificatesOfClientId($userId, $clientId);

                return new ApiResponse('delete_client_certificates_of_client_id');
            }
        );

        $service->get(
            '/client_certificate_list',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                return new ApiResponse('client_certificate_list', $this->storage->getCertificates($userId));
            }
        );

        $service->get(
            '/client_certificate_info',
            /**
             * @return \LC\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $commonName = InputValidation::commonName($request->getQueryParameter('common_name'));

                return new ApiResponse('client_certificate_info', $this->storage->getUserCertificateInfo($commonName));
            }
        );
    }
}
