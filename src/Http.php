<?php

namespace Jetimob\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use Jetimob\Http\Exceptions\InvalidArgumentException;
use Jetimob\Http\OAuth\ClientProviders\OAuthClientResolver;
use Jetimob\Http\OAuth\OAuth;
use Jetimob\Http\OAuth\OAuthClient;
use Jetimob\Http\OAuth\OAuthConfig;
use Jetimob\Http\OAuth\Storage\AccessTokenCacheKeyResolver;
use Jetimob\Http\OAuth\Storage\CacheRepositoryContract;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Http
{
    private ?OAuth $oAuth = null;

    private Client $client;
    private array $config;

    public function __construct(array $config = [])
    {
        $stack = HandlerStack::create();
        $guzzleConfig = $config['guzzle'] ?? [];

        if (is_array($middlewares = $guzzleConfig['middlewares'] ?? null)) {
            foreach ($middlewares as $middleware) {
                if (class_exists($middleware) && is_callable($instance = new $middleware($this))) {
                    $stack->push($instance);
                }
            }
        }

        $stack->push(Middleware::retry(function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            RequestException $exception = null
        ) {
            if ($retries >= $this->getConfig('retries', 1)) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                return true;
            }

            if (!$response) {
                return false;
            }

            return in_array($response->getStatusCode(), $this->getConfig('retry_on_status_code', []), true);
        }, function () {
            return $this->getConfig('retry_delay', 1000);
        }));

        $guzzleConfig['handler'] = $stack;

        $this->client = new Client($guzzleConfig);
        $this->config = $config;
    }

    public function sendAsync(Request $request, array $options = []): PromiseInterface
    {
        if ($request->requiresOAuthAuthorization()) {
            $options[OAuthConfig::OAUTH_GRANT_TYPE] = $request->getOAuthGrantType();
        }

        return $this->client->sendAsync($request, $options);
    }

    public function send(Request $request, array $options = []): \GuzzleHttp\Psr7\Response
    {
        $options[RequestOptions::SYNCHRONOUS] = true;

        return $this->sendAsync($request, $options)->wait();
    }

    public function sendExpectingResponseClass(Request $request, string $responseClass, array $options = []): Response
    {
        if (!class_exists($responseClass)) {
            throw new InvalidArgumentException(
                "Expected class \"$responseClass\" is not a valid class name"
            );
        }

        if (!method_exists($responseClass, 'fromGuzzleResponse')) {
            throw new InvalidArgumentException(
                "Expected class \"$responseClass\" doesn't inherit from Jetimob\Http\Response"
            );
        }

        $response = $this->send($request, $options);

        return $responseClass::fromGuzzleResponse($response)->hydrateWithBody();
    }

    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function overwriteConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Returns an instance of the class responsible to manage everything related to OAuths, including OAuthClient and
     * Access Token credentials.
     *
     * Lazy loading because not every request will use OAuth authorization.
     *
     * @link https://tools.ietf.org/html/rfc6749
     * @param OAuthClient|null $client
     * @return OAuth
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function oAuth(?OAuthClient $client = null): OAuth
    {
        if (is_null($this->oAuth)) {
            $this->oAuth = new OAuth(
                app()->make($this->getConfig('oauth_access_token_repository', CacheRepositoryContract::class)),
                app()->make($this->getConfig('oauth_client_resolver', OAuthClientResolver::class)),
                app()->make($this->getConfig('oauth_token_cache_key_resolver', AccessTokenCacheKeyResolver::class)),
                $this->config,
                $client,
            );
        }

        return $this->oAuth;
    }
}
