<?php namespace Pp\Ola;

use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException as HttpClientException;
use GuzzleHttp\Psr7\Response;
use Stevenmaguire\Uber\Client as UberClient;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;


class Client extends UberClient{

    /**
     * Access token
     *
     * @var string
     */
    private $access_token;

    /**
     * Server token
     *
     * @var string
     */
    private $server_token;

    /**
     * Use sandbox API
     *
     * @var bool
     */
    private $use_sandbox;

    /**
     * Version
     *
     * @var string
     */
    private $version;

    /**
     * Locale
     *
     * @var string
     */
    private $locale;


    private $app_token;

    /**
     * Rate limit
     *
     * @var RateLimit
     */
    private $rate_limit = NULL;

    /**
     * Http client
     *
     * @var HttpClient
     */
    private $http_client;

    /**
     * Creates a new client.
     *
     * @param    array $configuration
     */
    public function __construct($configuration = []) {
        $configuration = $this->parseConfiguration($configuration);
        $this->applyConfiguration($configuration);

        $this->http_client = new HttpClient(['handler' => $stack]);
    }

    /**
     * Parses configuration using defaults.
     *
     * @param    array $configuration
     *
     * @return   array    $configuration
     */
    private function parseConfiguration($configuration = []) {
        $defaults = array(
            'access_token' => NULL,
            'server_token' => NULL,
            'use_sandbox'  => false,
            'version'      => 'v1',
            'locale'       => 'en_US',
        );

        return array_merge($defaults, $configuration);
    }

    /**
     * Applies configuration to client.
     *
     * @param   array $configuration
     *
     * @return  void
     */
    private function applyConfiguration($configuration = []) {
        array_walk(
            $configuration, function ($value, $key) {
            $this->updateAttribute($key, $value);
        }
        );
    }

    /**
     * Updates a specific attribute of the current object.
     *
     * @param    string                 $attribute
     * @param    string|boolean|integer $value
     *
     * @return   object
     */
    private function updateAttribute($attribute, $value) {
        if (property_exists($this, $attribute)) {
            $this->{$attribute} = $value;
        }

        return $this;
    }

    /**
     * Sets Http Client.
     *
     * @param    HttpClient $client
     *
     * @return   Client
     */
    public function setHttpClient(HttpClient $client) {
        $this->http_client = $client;

        return $this;
    }

    /**
     * Makes a request to the Uber API and returns the response.
     *
     * @param    string $verb       The Http verb to use
     * @param    string $path       The path of the APi after the domain
     * @param    array  $parameters Parameters
     *
     * @return   stdClass             The JSON response from the request
     * @throws   Exception
     */
    protected function request($verb, $path, $parameters = []) {
        $client = $this->http_client;
        $url    = $this->getUrlFromPath($path);
        $verb   = strtolower($verb);
        $config = $this->getConfigForVerbAndParameters($verb, $parameters);

        try {
            $response = $client->$verb($url, $config);
        } catch (HttpClientException $e) {
            $this->handleRequestException($e);
        }

        $this->parseRateLimitFromResponse($response);

        return json_decode($response->getBody());
    }

    /**
     * Builds url from path.
     *
     * @param    string $path
     *
     * @return   string   Url
     */
    public function getUrlFromPath($path) {
        $path = ltrim($path, '/');

        $host = 'https://' . ($this->use_sandbox ? 'sandbox-t1' : 'devapi') . '.olacabs.com';

        return $host . ($this->version ? '/' . $this->version : '') . '/' . $path;
    }

    /**
     * Gets HttpClient config for verb and parameters.
     *
     * @param    string $verb
     * @param    array  $parameters
     *
     * @return   array
     */
    private function getConfigForVerbAndParameters($verb, $parameters = []) {
        $config = [
            'headers' => $this->getHeaders(),
        ];

        if (!empty($parameters)) {
            if (strtolower($verb) == 'get') {
                $config['query'] = $parameters;
            } else {
                $config['json'] = $parameters;
            }
        }

        return $config;
    }

    /**
     * Gets headers for request.
     *
     * @return   array
     */
    public function getHeaders() {
        return [
            'Authorization'   => trim($this->getAuthorizationHeader()),
            'Accept-Language' => trim($this->locale),
            'X-APP-TOKEN'     => $this->app_token,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Gets authorization header value.
     *
     * @return   string
     */
    private function getAuthorizationHeader() {
        if ($this->access_token) {
            return 'Bearer ' . $this->access_token;
        }

        throw new Exception('Provide access token');
    }

    /**
     * Handles http client exceptions.
     *
     * @param    HttpClientException $e
     *
     * @return   void
     * @throws   Exception
     */
    private function handleRequestException(HttpClientException $e) {
        if ($response = $e->getResponse()) {
            $exception = new Exception($response->getReasonPhrase(), $response->getStatusCode(), $e);
            //$exception->setBody(json_decode($response->getBody()));

            throw $exception;
        }

        throw new Exception($e->getMessage(), 500, $e);
    }

    /**
     * Attempts to pull rate limit headers from response and add to client.
     *
     * @param    Response $response
     *
     * @return   void
     */
    private function parseRateLimitFromResponse(Response $response) {
        $rateLimitHeaders = array_filter(
            [
                $response->getHeader('X-Rate-Limit-Limit'),
                $response->getHeader('X-Rate-Limit-Remaining'),
                $response->getHeader('X-Rate-Limit-Reset'),
            ]
        );

        /*if (count($rateLimitHeaders) == 3) {
            $rateLimitClass = new ReflectionClass(RateLimit::class);
            $this->rate_limit = $rateLimitClass->newInstanceArgs($rateLimitHeaders);
        }*/
    }

    /**
     * Throws exception when client is not configured sandbox use. Should only
     * be utilized when attempting to do work against ephemeral sandbox API
     * data.
     *
     * @return   void
     * @throws   Exception
     *
     * @see      https://developer.uber.com/docs/riders/guides/sandbox
     */
    private function enforceSandboxExpectation($message = NULL) {
        if (!$this->use_sandbox) {
            $message = $message ?: 'Attempted to invoke sandbox functionality ' .
                'with production client; this is not recommended';
            throw new Exception($message);
        }
    }

    /**
     * Creates a new ride request.
     *
     * The Request endpoint allows a ride to be requested on behalf of an Uber
     * user given their desired product, start, and end locations.
     *
     * @param    array    $attributes   Query attributes
     *
     * @return   stdClass               The JSON response from the request
     *
     * @see      https://developer.uber.com/docs/riders/references/api/v1.2/requests-post
     */
    public function requestRide($attributes = [])
    {
        return $this->request('post', 'bookings/create', $attributes);
    }
}
