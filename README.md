## PRIMA

> The Slowest Performance Micro Framework


## USAGE

```php
<?php
use Apatis\Middleware\MiddlewareStorage;
use Apatis\Prima\Service;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

$app = new Service();
// add middleware
$app->addMiddleware(function (
    ServerRequestInterface $request,
    ResponseInterface $response,
    // callable or object invokable
    // this commonly use
    MiddlewareStorage $next
) : ResponseInterface {
    // example of use json as return header
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    return $next($request, $response);
});

// add route
$app->get('/{endpoint: [a-zA-Z0-9]+}[/]', function (ServerRequestInterface $request, ResponseInterface $response, array $params) {
    $body = $response->getBody();
    $data = [
        'method'   => $request->getMethod(),
        'endpoint' => $params['endpoint']
    ];
    $body->write(json_encode($data));
    return $response->withBody($body);
});
$app->serve();

```


## CONFIGURATION

```php
<?php
/**
 * List of Existing configuration
 * The values is default configuration
 */
$defaultConfiguration = [
    /**
     * http version (HTTP/[httpVersion])
     * @var string
     */
    'httpVersion'         => '1.1',
    /**
     * resolve CLI Request
     * set into boolean -> true if the Request need to auto resolve
     * for CLI Request
     * @var boolean
     */
    'resolveCLIRequest'   => true,
     /**
      * fix http scheme if use behind proxy
      * @var boolean
      */
    'fixProxy'            => true,
    /**
     * append content length into headers
     * @var boolean
     */
    'setContentLength'    => false,
    /**
     * allow empty response code to be serve : code 204, 205, 304
     * @var boolean 
     */
    'serveEmptyResponse'  => false,
    /**
     * add buffer
     * @var boolean 
     */
    'useBuffer'           => true,
    /**
     * response chunk size read from stream
     * @var integer
     */
    'responseChunkSize'   => 4096,
    /**
     * display details error
     * @var boolean
     */
    'displayErrors'       => false,
    /**
     * Full path for route cache file
     * @var string 
     */
    'routerCacheFile'     => false,
    /**
     * clear the stored middleware after successfully called
     * @var boolean
     */
    'clearMiddlewareAfterExecute' => true,
    /**
     * by default middleware called last set first, use `sortMiddleware` to re-arrange first
     * middleware to be called
     * @var boolean 
     */
    'sortMiddleware' => false,
];

```