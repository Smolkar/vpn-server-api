<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2018, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace SURFnet\VPN\Server\Api;

use fkooman\OAuth\Client\AccessToken;
use fkooman\Otp\Exception\OtpException;
use fkooman\Otp\Totp;
use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Common\Http\ApiErrorResponse;
use SURFnet\VPN\Common\Http\ApiResponse;
use SURFnet\VPN\Common\Http\AuthUtils;
use SURFnet\VPN\Common\Http\InputValidation;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\ServiceModuleInterface;
use SURFnet\VPN\Server\Exception\YubiKeyException;
use SURFnet\VPN\Server\Storage;
use SURFnet\VPN\Server\YubiKey;

class UsersModule implements ServiceModuleInterface
{
    /** @var \SURFnet\VPN\Common\Config */
    private $config;

    /** @var \SURFnet\VPN\Server\Storage */
    private $storage;

    /** @var array */
    private $groupProviders;

    public function __construct(Config $config, Storage $storage, array $groupProviders)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->groupProviders = $groupProviders;
    }

    /**
     * @return void
     */
    public function init(Service $service)
    {
        $service->get(
            '/user_list',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-admin-portal']);

                return new ApiResponse('user_list', $this->storage->getUsers());
            }
        );

        $service->post(
            '/set_yubi_key_id',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $yubiKeyOtp = InputValidation::yubiKeyOtp($request->getPostParameter('yubi_key_otp'));

                // check if there is already a YubiKey ID registered for this user
                if ($this->storage->hasYubiKeyId($userId)) {
                    return new ApiErrorResponse('set_yubi_key_id', 'YubiKey ID already set');
                }

                $yubiKey = new YubiKey();
                try {
                    $yubiKeyId = $yubiKey->getVerifiedId($yubiKeyOtp);
                    $this->storage->setYubiKeyId($userId, $yubiKeyId);
                    $this->storage->addUserMessage($userId, 'notification', sprintf('YubiKey ID "%s" registered', $yubiKeyId));

                    return new ApiResponse('set_yubi_key_id');
                } catch (YubiKeyException $e) {
                    $msg = sprintf('YubiKey OTP verification failed: %s', $e->getMessage());
                    $this->storage->addUserMessage($userId, 'notification', $msg);

                    return new ApiErrorResponse('set_yubi_key_id', $msg);
                }
            }
        );

        $service->post(
            '/verify_yubi_key_otp',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal', 'vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $yubiKeyOtp = InputValidation::yubiKeyOtp($request->getPostParameter('yubi_key_otp'));

                $expectedYubiKeyId = $this->storage->getYubiKeyId($userId);
                if (null === $expectedYubiKeyId) {
                    $msg = 'YubiKey: user not enrolled';
                    $this->storage->addUserMessage($userId, 'notification', $msg);

                    return new ApiErrorResponse('verify_yubi_key_otp', $msg);
                }

                $yubiKey = new YubiKey();
                try {
                    $yubiKey->verifyOtpForId($yubiKeyOtp, $expectedYubiKeyId);

                    return new ApiResponse('verify_yubi_key_otp');
                } catch (YubiKeyException $e) {
                    $msg = sprintf('YubiKey: %s', $e->getMessage());
                    $this->storage->addUserMessage($userId, 'notification', $msg);

                    return new ApiErrorResponse('verify_yubi_key_otp', $msg);
                }
            }
        );

        $service->get(
            '/has_yubi_key_id',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal', 'vpn-admin-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                return new ApiResponse('has_yubi_key_id', $this->storage->hasYubiKeyId($userId));
            }
        );

        $service->get(
            '/yubi_key_id',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal', 'vpn-admin-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                $yubiKeyId = $this->storage->getYubiKeyId($userId);
                if (null === $yubiKeyId) {
                    return new ApiResponse('yubi_key_id', false);
                }

                return new ApiResponse('yubi_key_id', $yubiKeyId);
            }
        );

        $service->post(
            '/delete_yubi_key_id',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));

                $yubiKeyId = $this->storage->getYubiKeyId($userId);
                $this->storage->deleteYubiKeyId($userId);
                $this->storage->addUserMessage($userId, 'notification', sprintf('YubiKey ID "%s" deleted', $yubiKeyId));

                return new ApiResponse('delete_yubi_key_id');
            }
        );

        $service->post(
            '/set_totp_secret',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $totpKey = InputValidation::totpKey($request->getPostParameter('totp_key'));
                $totpSecret = InputValidation::totpSecret($request->getPostParameter('totp_secret'));

                // check if there is already a TOTP secret registered for this user
                if (false !== $this->storage->getOtpSecret($userId)) {
                    return new ApiErrorResponse('set_totp_secret', 'TOTP secret already set');
                }

                $totp = new Totp($this->storage);
                try {
                    $totp->register($userId, $totpSecret, $totpKey);
                    $this->storage->addUserMessage($userId, 'notification', 'TOTP secret registered');

                    return new ApiResponse('set_totp_secret');
                } catch (OtpException $e) {
                    $msg = sprintf('TOTP registration failed: %s', $e->getMessage());
                    $this->storage->addUserMessage($userId, 'notification', $msg);

                    return new ApiErrorResponse('set_totp_secret', $msg);
                }
            }
        );

        $service->post(
            '/verify_totp_key',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal', 'vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $totpKey = InputValidation::totpKey($request->getPostParameter('totp_key'));

                $totp = new Totp($this->storage);
                try {
                    if (false === $totp->verify($userId, $totpKey)) {
                        $msg = 'TOTP validation failed: invalid TOTP key';
                        $this->storage->addUserMessage($userId, 'notification', $msg);

                        return new ApiErrorResponse('verify_totp_key', $msg);
                    }

                    return new ApiResponse('verify_totp_key');
                } catch (OtpException $e) {
                    $msg = sprintf('TOTP validation failed: %s', $e->getMessage());
                    $this->storage->addUserMessage($userId, 'notification', $msg);

                    return new ApiErrorResponse('verify_totp_key', $msg);
                }
            }
        );

        $service->get(
            '/has_totp_secret',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal', 'vpn-admin-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                return new ApiResponse('has_totp_secret', false !== $this->storage->getOtpSecret($userId));
            }
        );

        $service->post(
            '/delete_totp_secret',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));

                $this->storage->deleteOtpSecret($userId);
                $this->storage->addUserMessage($userId, 'notification', 'TOTP secret deleted');

                return new ApiResponse('delete_totp_secret');
            }
        );

        $service->post(
            '/set_voot_token',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));

                // load the POSTed AccessToken from JSON
                $vootToken = AccessToken::fromJson($request->getPostParameter('voot_token'));
                $this->storage->setVootToken($userId, $vootToken);

                return new ApiResponse('set_voot_token');
            }
        );

        $service->post(
            '/delete_voot_token',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal', 'vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $this->storage->deleteVootToken($userId);

                return new ApiResponse('delete_voot_token');
            }
        );

        $service->get(
            '/get_voot_token',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                if (null === $vootToken = $this->storage->getVootToken($userId)) {
                    return new ApiErrorResponse('get_voot_token', 'no voot token available for this user');
                }

                return new ApiResponse('get_voot_token', $vootToken);
            }
        );

        $service->get(
            '/has_voot_token',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal', 'vpn-admin-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                return new ApiResponse('has_voot_token', $this->storage->hasVootToken($userId));
            }
        );

        $service->get(
            '/is_disabled_user',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-admin-portal', 'vpn-user-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                return new ApiResponse('is_disabled_user', $this->storage->isDisabledUser($userId));
            }
        );

        $service->post(
            '/disable_user',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));

                $this->storage->disableUser($userId);
                $this->storage->addUserMessage($userId, 'notification', 'account disabled');

                return new ApiResponse('disable_user');
            }
        );

        $service->post(
            '/enable_user',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));

                $this->storage->enableUser($userId);
                $this->storage->addUserMessage($userId, 'notification', 'account (re)enabled');

                return new ApiResponse('enable_user');
            }
        );

        $service->post(
            '/delete_user',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-admin-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $this->storage->deleteUser($userId);

                return new ApiResponse('delete_user');
            }
        );

        $service->get(
            '/user_groups',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                $userGroups = [];
                foreach ($this->groupProviders as $groupProvider) {
                    $userGroups = array_merge($userGroups, $groupProvider->getGroups($userId));
                }

                return new ApiResponse('user_groups', $userGroups);
            }
        );

        $service->get(
            '/user_last_authenticated_at',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);
                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                return new ApiResponse('user_last_authenticated_at', $this->storage->getLastAuthenticatedAt($userId));
            }
        );

        $service->get(
            '/user_entitlement_list',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);
                $userId = InputValidation::userId($request->getQueryParameter('user_id'));

                return new ApiResponse('user_entitlement_list', $this->storage->getEntitlementList($userId));
            }
        );

        $service->post(
            '/last_authenticated_at_ping',
            /**
             * @return \SURFnet\VPN\Common\Http\Response
             */
            function (Request $request, array $hookData) {
                AuthUtils::requireUser($hookData, ['vpn-user-portal']);

                $userId = InputValidation::userId($request->getPostParameter('user_id'));
                $entitlementList = InputValidation::entitlementList($request->getPostParameter('entitlement_list'));
                $this->storage->lastAuthenticatedAtPing($userId, $entitlementList);

                return new ApiResponse('last_authenticated_at_ping');
            }
        );
    }
}
