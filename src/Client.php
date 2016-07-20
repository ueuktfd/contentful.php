<?php
/**
 * @copyright 2015-2016 Contentful GmbH
 * @license   MIT
 */

namespace Contentful;

use Contentful\Log\NullLogger;
use Contentful\Log\StandardTimer;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Psr7;
use Doctrine\Common\Cache\Cache as CacheInterface;

/**
 * Abstract client for common code for the different clients.
 */
abstract class Client
{

    /**
     * Cache timeout in seconds
     */
    const CACHE_TIMEOUT = 600;

    /**
     * @var GuzzleClient
     */
    private $httpClient;

    /**
     * @var string
     */
    private $baseUri;

    /**
     * @var LoggerInterface
     */
    private $logger = null;

    /**
     * @var string
     */
    private $api;

    /**
     * @var CacheInterface
     */
    private $cache = null;

    /**
     * @var \Closure
     */
    protected $logClosure;

    /**
     * Client constructor.
     *
     * @param string          $token
     * @param string          $baseUri
     * @param string          $api
     * @param LoggerInterface $logger
     * @param CacheInterface  $cache
     */
    public function __construct($token, $baseUri, $api, LoggerInterface $logger = null, CacheInterface $cache = null)
    {
        $stack = HandlerStack::create();
        $stack->push(new BearerToken($token));
        if ($logger) {
            $this->logger = $logger;
            $this->assignClosure();
        }
        if ($cache) {
            $this->cache = $cache;
        }
        $this->api = $api;
        $this->baseUri = $baseUri;
        $this->httpClient = new GuzzleClient([
            'handler' => $stack
        ]);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array  $options
     *
     * @return array|object
     */
    protected function request($method, $path, array $options = [])
    {
        $timer = new StandardTimer;
        $timer->start();

        $query = isset($options['query']) ? $options['query'] : null;
        if ($query) {
            unset($options['query']);
        }
        $request = $this->buildRequest($method, $path, $query);

        // We define this variable so it's also available in the catch block.
        $response = null;
        $result = false;
        preg_match('|.*spaces/(.*)/|', $this->baseUri, $matches);
        $spaceId = $matches[1];
        // for get requests we are trying to get data from cache
        $key = $spaceId ."_". md5(serialize($query) . $this->baseUri . $path);
        if ($method == 'GET' && $data = $this->cache->fetch($key)) {
            $result = $this->decodeJson($data);
        }
        if (!$result) {
            try {
                $response = $this->doRequest($request, $options);
                $body = $response->getBody();
                // save result into cache
                if ($method == 'GET' && $this->cache) {
                    $this->cache->save($key, $body->__toString(), self::CACHE_TIMEOUT);
                }
                $result = $this->decodeJson($body);
            } catch (\Exception $e) {
                $timer->stop();
                //            $this->logger->log($this->api, $request, $timer, $response, $e);

                throw $e;
            }
        }
        $timer->stop();
//        $this->logger->log($this->api, $request, $timer, $response);

        return $result;
    }

    private function doRequest($request, $options)
    {
        if ($this->logger && empty($options[RequestOptions::ON_STATS])) {
            $options[RequestOptions::ON_STATS] = $this->logClosure;
        }
        try {
            return $this->httpClient->send($request, $options);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                throw new ResourceNotFoundException(null, 0, $e);
            }

            throw $e;
        }
    }

    /**
     * @param  string            $method
     * @param  string            $path
     * @param  array|string|null $query
     *
     * @return Psr7\Request
     *
     * @throws \InvalidArgumentException If $query is not a valid type
     */
    private function buildRequest($method, $path, $query = null)
    {
        $contentTypes = [
            'DELIVERY' => 'application/vnd.contentful.delivery.v1+json',
            'PREVIEW' => 'application/vnd.contentful.delivery.v1+json',
            'MANAGEMENT' => 'application/vnd.contentful.management.v1+json'
        ];

        $uri = Psr7\Uri::resolve(Psr7\uri_for($this->baseUri), $path);

        if ($query) {
            if (is_array($query)) {
                $query = http_build_query($query, null, '&', PHP_QUERY_RFC3986);
            }
            if (!is_string($query)) {
                throw new \InvalidArgumentException('query must be a string or array');
            }
            $uri = $uri->withQuery($query);
        }

        return new Psr7\Request($method, $uri, [
            'User-Agent' => $this->getUserAgent(),
            'Content-Type' => $contentTypes[$this->api]
        ], null);
    }

    /**
     * The name of the library to be used in the User-Agent header.
     *
     * @return string
     */
    abstract protected function getUserAgentAppName();

    /**
     * Returns the value of the User-Agent header for any requests made to Contentful
     *
     * @return string
     */
    protected function getUserAgent()
    {
        $agent = $this->getUserAgentAppName() . ' GuzzleHttp/' . GuzzleClient::VERSION;
        if (extension_loaded('curl') && function_exists('curl_version')) {
            $agent .= ' curl/' . \curl_version()['version'];
        }
        $agent .= ' PHP/' . \PHP_VERSION;

        return $agent;
    }

    /**
     * @param  string $json JSON encoded object or array
     *
     * @return object|array
     *
     * @throws \RuntimeException On invalid JSON
     */
    protected function decodeJson($json)
    {
        $result = json_decode($json);
        if ($result === null) {
            throw new \RuntimeException(json_last_error_msg(), json_last_error());
        }

        return $result;
    }

    protected function assignClosure()
    {
        $logger = $this->logger;
        $this->logClosure = function (TransferStats $stats) use ($logger) {
            $logger->notice(
                sprintf("URL: %s\n Duration: %s \n>>>>>>>>\n%s\n<<<<<<<<\n%s\n--------\n%s",
                    $stats->getEffectiveUri(),
                    $stats->getTransferTime(),
                    Psr7\str($stats->getRequest()),
                    Psr7\str($stats->getResponse()),
                    $stats->getHandlerErrorData()
                )
            );

        };
    }

}
