<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Auth\Twitter;

use Flarum\Forum\AuthenticationResponseFactory;
use Flarum\Settings\SettingsRepositoryInterface;
use League\OAuth1\Client\Server\Twitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response\RedirectResponse;

class TwitterAuthController implements RequestHandlerInterface
{
    /**
     * @var AuthenticationResponseFactory
     */
    protected $authResponse;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @param AuthenticationResponseFactory $authResponse
     * @param SettingsRepositoryInterface $settings
     */
    public function __construct(AuthenticationResponseFactory $authResponse, SettingsRepositoryInterface $settings)
    {
        $this->authResponse = $authResponse;
        $this->settings = $settings;
    }

    /**
     * @param Request $request
     * @return ResponseInterface
     */
    public function handle(Request $request): ResponseInterface
    {
        $redirectUri = (string) $request->getAttribute('originalUri', $request->getUri())->withQuery('');

        $server = new Twitter([
            'identifier' => $this->settings->get('flarum-auth-twitter.api_key'),
            'secret' => $this->settings->get('flarum-auth-twitter.api_secret'),
            'callback_uri' => $redirectUri
        ]);

        $session = $request->getAttribute('session');

        $queryParams = $request->getQueryParams();
        $oAuthToken = array_get($queryParams, 'oauth_token');
        $oAuthVerifier = array_get($queryParams, 'oauth_verifier');

        if (! $oAuthToken || ! $oAuthVerifier) {
            $temporaryCredentials = $server->getTemporaryCredentials();

            $session->put('temporary_credentials', serialize($temporaryCredentials));

            $authUrl = $server->getAuthorizationUrl($temporaryCredentials);

            return new RedirectResponse($authUrl);
        }

        $temporaryCredentials = unserialize($session->get('temporary_credentials'));

        $tokenCredentials = $server->getTokenCredentials($temporaryCredentials, $oAuthToken, $oAuthVerifier);

        $user = $server->getUserDetails($tokenCredentials);

        return $this->authResponse->make([
            'identification' => [
                'twitter_id' => $user->uid
            ],
            'attributes' => [
                'avatarUrl' => str_replace('_normal', '', $user->imageUrl)
            ],
            'suggestions' => [
                'username' => $user->nickname
            ]
        ]);
    }
}
