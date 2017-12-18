<?php
/**
 * MIT License
 *
 * Copyright (c) 2017  Pentagonal Development
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(strict_types=1);

namespace Apatis\Prima;

use Apatis\CallbackResolver\CallbackResolver;
use Apatis\CallbackResolver\CallbackResolverInterface;
use Apatis\Config\Config;
use Apatis\Config\ConfigInterface;
use Apatis\Container\Container;
use Apatis\Handler\Response\ErrorHandler;
use Apatis\Handler\Response\ErrorHandlerInterface;
use Apatis\Handler\Response\ExceptionHandler;
use Apatis\Handler\Response\ExceptionHandlerInterface;
use Apatis\Handler\Response\NotAllowedHandler;
use Apatis\Handler\Response\NotAllowedHandlerInterface;
use Apatis\Handler\Response\NotFoundHandler;
use Apatis\Handler\Response\NotFoundHandlerInterface;
use Apatis\Http\Message\Request;
use Apatis\Http\Message\Response;
use Apatis\Http\Message\Uri;
use Apatis\Middleware\Middleware;
use Apatis\Middleware\MiddlewareInterface;
use Apatis\Middleware\MiddlewareLockedException;
use Apatis\Middleware\MiddlewareStorage;
use Apatis\Route\RouteGroupInterface;
use Apatis\Route\RouteInterface;
use Apatis\Route\Router;
use Apatis\Route\RouterInterface;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class Service
 * @package Apatis\Service
 *
 * @see Service::map()
 *
 * @method ResponseInterface get(string $pattern, callable $callable);
 * @method ResponseInterface post(string $pattern, callable $callable);
 * @method ResponseInterface put(string $pattern, callable $callable);
 * @method ResponseInterface patch(string $pattern, callable $callable);
 * @method ResponseInterface delete(string $pattern, callable $callable);
 * @method ResponseInterface head(string $pattern, callable $callable);
 * @method ResponseInterface connect(string $pattern, callable $callable);
 * @method ResponseInterface trace(string $pattern, callable $callable);
 * @method ResponseInterface options(string $pattern, callable $callable);
 */
class Service extends Middleware implements \ArrayAccess
{
    /**
     * @type integer
     */
    const DEFAULT_CHUNK_SIZE = 4096;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * @var CallbackResolverInterface
     */
    protected $callbackResolver;

    /**
     * @var array
     */
    protected $defaultConfiguration = [
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

    /**
     * @var array
     */
    protected $standardHttpRequestMethods = [
        'GET',
        'HEAD',
        'POST',
        'PUT',
        'DELETE',
        'CONNECT',
        'OPTIONS',
        'TRACE'
    ];

    /**
     * @var NotFoundHandlerInterface
     */
    private $notFoundHandler;

    /**
     * @var NotAllowedHandlerInterface
     */
    private $notAllowedHandler;

    /**
     * @var ErrorHandlerInterface
     */
    private $errorHandler;

    /**
     * @var ExceptionHandlerInterface
     */
    private $exceptionHandler;

    /**
     * @var bool
     */
    private $middlewareSorted = false;

    /**
     * Service constructor.
     *
     * @param array|ConfigInterface $config Service Configuration
     * @param ContainerInterface|null $container
     */
    public function __construct($config = [], ContainerInterface $container = null)
    {
        if (!$config instanceof ConfigInterface && !is_array($config)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Configurations must be an array or instance of %s, %s given',
                    ConfigInterface::class,
                    is_object($config)
                        ? get_class($config)
                        : gettype($config)
                )
            );
        }

        if (!is_object($config)) {
            $config = new Config($config);
        }

        // re-set configuration
        $this->defaultConfiguration['responseChunkSize'] = static::DEFAULT_CHUNK_SIZE;
        // use default config
        $this->config = new Config($this->defaultConfiguration);
        // merge the config
        $this->config->merge($config);

        $this->container = $container;
    }

    /**
     * Get chunk normalized size
     *
     * @return int
     */
    public function getNormalizeResponseChunkSize() : int
    {
        $chunkSize      = $this->getConfiguration('responseChunkSize', static::DEFAULT_CHUNK_SIZE);
        $chunkSize      = ! is_numeric($chunkSize) ? static::DEFAULT_CHUNK_SIZE : intval(abs($chunkSize));
        $chunkSize      = $chunkSize < 512 ? 512 : $chunkSize;
        return $chunkSize;
    }

    /**
     * Create / Add buffer
     *
     * @return void
     */
    protected function addCreateBuffer()
    {
        // check if config useBuffer set into boolean -> true
        if ($this->getConfiguration('useBuffer') === true) {
            ob_start();
        }
    }

    /**
     * Get standard HTTP Request Methods
     *
     * @return string[]
     */
    public function getStandardHttpRequestMethods() : array
    {
        return $this->standardHttpRequestMethods;
    }

    /**
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param ConfigInterface $config
     */
    public function setConfig(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * @return ConfigInterface
     */
    public function getConfig() : ConfigInterface
    {
        return $this->config;
    }

    /**
     * Get Container
     * create new instance container if container has not been set
     *
     * @return ContainerInterface|Container
     */
    public function getContainer() : ContainerInterface
    {
        if (!$this->container) {
            $this->container = new Container();
        }

        return $this->container;
    }

    /**
     * @param RouterInterface $router
     */
    public function setRouter(RouterInterface $router)
    {
        $this->router = $router;
    }

    /**
     * @param string|int $name
     * @param mixed $default
     *
     * @return mixed
     */
    public function getConfiguration($name, $default = null)
    {
        return $this->getConfig()->get($name, $default);
    }

    /**
     * @param string|int $name
     * @param mixed $value
     *
     * @return mixed|void
     */
    public function setConfiguration($name, $value)
    {
        $this->getConfig()->set($name, $value);
    }

    /**
     * @return RouterInterface
     */
    public function getRouter() : RouterInterface
    {
        if (! $this->router instanceof RouterInterface) {
            $router = new Router();
            $resolver = $this->getCallbackResolver();
            if ($resolver instanceof CallbackResolverInterface) {
                $router->setCallbackResolver($resolver);
            }

            $routerCacheFile = $this->getConfiguration('routerCacheFile', false);
            if ($routerCacheFile) {
                $router->setCacheFile($routerCacheFile);
            }

            $this->router = $router;
        }

        return $this->router;
    }

    /**
     * Set callable resolver
     *
     * @param CallbackResolverInterface $resolver
     * @return static
     */
    public function setCallbackResolver(CallbackResolverInterface $resolver) : Service
    {
        $this->callbackResolver = $resolver;
        return $this;
    }

    /**
     * Get callable resolver
     *
     * @return CallbackResolverInterface|null
     */
    public function getCallbackResolver()
    {
        if (! $this->callbackResolver instanceof CallbackResolverInterface) {
            $this->callbackResolver = new CallbackResolver($this);
        }

        return $this->callbackResolver;
    }

    /**
     * Map Route
     *
     * @param array $methods
     * @param string $pattern
     * @param $callable
     *
     * @return RouteInterface
     */
    public function map(array $methods, string $pattern, $callable) : RouteInterface
    {
        $route = $this->getRouter()->map($methods, $pattern, $callable);

        return $route;
    }

    /**
     * Route Groups
     *
     * This method accepts a route pattern and a callback. All route
     * declarations in the callback will be prepended by the group(s)
     * that it is in.
     *
     * @param string   $pattern
     * @param callable $callable
     *
     * @return RouteGroupInterface
     */
    public function group(string $pattern, $callable) : RouteGroupInterface
    {
        $router = $this->getRouter();
        $group  = $router->pushGroup($pattern, $callable);
        if (method_exists($group, 'setCallbackResolver')) {
            $callbackResolver = $this->getCallbackResolver();
            if ($callbackResolver instanceof CallbackResolverInterface) {
                $group->setCallbackResolver($callbackResolver);
            }
        }

        $group($this);
        $router->popGroup();
        return $group;
    }

    /**
     * Check if code is empty response code
     *
     * @param int $code
     *
     * @return bool
     */
    public function isEmptyResponseCode(int $code) : bool
    {
        return in_array($code, [204, 205, 304]);
    }

    /**
     * Override if closure bind to current object
     * {@inheritdoc}
     *
     * @return static|MiddlewareInterface
     */
    public function addMiddleware(callable $callable): MiddlewareInterface
    {
        if ($callable instanceof \Closure) {
            $callable = $callable->bindTo($this);
        }

        return parent::addMiddleware($callable);
    }

    /**
     * {@inheritdoc}
     * Re-arrange on middleware call stack process of `sortMiddleware`
     * set into boolean true
     * Behavior if middleware has called and has been sorted,
     * if the @property $middlewareSorted has been set into true
     * then will be not sorted again,
     * and if sorted again it will be fallback like original process (called from last)
     */
    public function callMiddlewareStack(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->isMiddlewareLocked()) {
            throw new MiddlewareLockedException(
                'Can not call middleware while middleware is locked or in stack queue'
            );
        }

        if ($this->getConfiguration('sortMiddleware') !== true) {
            return parent::callMiddlewareStack($request, $response);
        }

        // prevent to re-reverse middleware
        // if middleware has been sorted and exists
        if ($this->middlewareSorted === true && count($this->middleware) !== 0) {
            parent::callMiddlewareStack($request, $response);
        }

        $this->middlewareSorted = true;

        // reverse middleware
        $middlewareCopy = array_reverse($this->getMiddleware());
        // check if first middleware is current object
        $object = end($middlewareCopy);
        if ($object instanceof MiddlewareStorage
            && ($object = $object->getCallableMiddleware()) instanceof Service
            && spl_object_hash($object) === spl_object_hash($this)
        ) {
            array_pop($middlewareCopy);
        }

        $this->middleware = [];
        /**
         * @var MiddlewareStorage $middleware
         */
        foreach ($middlewareCopy as $key => $middleware) {
            unset($middlewareCopy[$key]);
            $this->addMiddleware($middleware->getCallableMiddleware());
        }

        $this->middlewareLocked = true;
        $response               = $this->currentStackMiddleware()->__invoke($request, $response);
        $this->middlewareLocked = false;
        return $response;
    }

    /**
     * Process a request
     *
     * This method traverses the application middleware stack and then returns the
     * resultant Response object.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response) : ResponseInterface
    {
        try {
            $response = $this->callMiddlewareStack($request, $response);
            // if configuration of : clearMiddlewareAfterExecute
            // set into true , clear the middleware
            if ($this->getConfiguration('clearMiddlewareAfterExecute') === true) {
                // reset middleware sorted
                $this->middlewareSorted = false;
                $this->middleware = [];
            }
        } catch (\Throwable $e) {
            $response = $this->handleForResponseException($request, $response, $e);
        }

        $response = $this->finalizeResponse($response);
        return $response;
    }

    /**
     * Finalize response
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     *
     * @throws \RuntimeException
     */
    protected function finalizeResponse(ResponseInterface $response) : ResponseInterface
    {
        // stop PHP sending a Content-Type automatically
        ini_set('default_mimetype', '');
        if ($this->isEmptyResponseCode($response->getStatusCode())) {
            return $response->withoutHeader('Content-Type')->withoutHeader('Content-Length');
        }

        return $response;
    }

    /**
     * Run application
     *
     * This method traverses the application middleware stack and then sends the
     * resultant Response object to the HTTP client.
     *
     * @param bool $serveToClient set true to doing display response to client
     *
     * @return ResponseInterface
     */
    public function serve(bool $serveToClient = true) : ResponseInterface
    {
        $globals = $this->getConfiguration('fixProxy', true)
            ? Uri::fixSchemeProxyFromGlobals($_SERVER)
            : $_SERVER;

        if ($this->getConfiguration('resolveCLIRequest') !== false) {
            // create request
            $request = Request::createFromGlobalsResolveCLIRequest($globals);
        } else {
            $request = Request::createFromGlobals($globals);
        }

        // create response
        $response = new Response(200, ['Content-Type' => 'text/html; charset=UTF-8']);

        /**
         * @var ResponseInterface $response
         */
        $response = $response->withProtocolVersion($this->getConfiguration('httpVersion', '1.1'));
        $response = $this->process($request, $response);

        if ($serveToClient) {
            $this->serveResponse($response);
        }

        return $response;
    }

    /**
     * Send the response the client
     *
     * @param ResponseInterface $response
     */
    public function serveResponse(ResponseInterface $response)
    {
        $allowServeEmpty = $this->getConfiguration('serveEmptyResponse') === true;
        $setContentLength = $this->getConfiguration('setContentLength') === true;
        // Send response
        if (!headers_sent()) {
            // Headers
            foreach ($response->getHeaders() as $name => $values) {
                if (!$setContentLength && strtolower($name) === 'content-length') {
                    continue;
                }

                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }

            // Re Set Status Header
            header(sprintf(
                'HTTP/%s %s %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ), true, $response->getStatusCode());
        }

        // Serve Response
        if ($allowServeEmpty || ! $this->isEmptyResponseCode($response->getStatusCode())) {
            $body = $response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $chunkSize      = $this->getNormalizeResponseChunkSize();
            $contentLength  = $setContentLength
                ? $response->getHeaderLine('Content-Length')
                : null;

            if (!$contentLength) {
                $contentLength = $body->getSize();
                // set content length
                if ($setContentLength && !headers_sent()) {
                    header(sprintf('Content-Length: %d', $contentLength));
                }
            }

            // if use buffer create buffer
            $this->addCreateBuffer();
            if ($contentLength) {
                $amountToRead = $contentLength;
                while ($amountToRead > 0 && !$body->eof()) {
                    $data = $body->read(min($chunkSize, $amountToRead));
                    echo $data;
                    $amountToRead -= strlen($data);
                    if (connection_status() != CONNECTION_NORMAL) {
                        break;
                    }
                }
            } else {
                while (!$body->eof()) {
                    echo $body->read($chunkSize);
                    if (connection_status() != CONNECTION_NORMAL) {
                        break;
                    }
                }
            }
        }
    }

    /**
     * Handle Response Exception
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param \Throwable $e
     *
     * @return ResponseInterface
     */
    protected function handleForResponseException(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \Throwable $e
    ) : ResponseInterface {
        if ($e instanceof \Exception) {
            $handler = $this->getExceptionHandler();
        } else {
            $handler = $this->getErrorHandler();
        }

        $response = $handler($request, $response, $e);
        return $response;
    }

    /* ----------------------------------------------------
     * Handler Error implementation (getter & setter)
     * ----------------------------------------------------
     */

    /**
     * Set 404 Not found handler
     *
     * @param NotFoundHandlerInterface $handler
     */
    public function setNotFoundHandler(NotFoundHandlerInterface $handler)
    {
        $this->notFoundHandler = $handler;
    }

    /**
     * Get not not found handler
     * (create new instance Not found handler if empty)
     *
     * @return NotFoundHandlerInterface
     */
    public function getNotFoundHandler() : NotFoundHandlerInterface
    {
        if (!$this->notFoundHandler) {
            $this->notFoundHandler = new NotFoundHandler();
        }

        return $this->notFoundHandler;
    }

    /**
     * Set Not allowed method handler
     *
     * @param NotAllowedHandlerInterface $handler
     */
    public function setNotAllowedHandler(NotAllowedHandlerInterface $handler)
    {
        $this->notAllowedHandler = $handler;
    }

    /**
     * Get not allowed method handler
     * (create new instance Not Allowed handler if empty)
     *
     * @return NotAllowedHandlerInterface
     */
    public function getNotAllowedHandler() : NotAllowedHandlerInterface
    {
        if (!$this->notAllowedHandler) {
            $this->notAllowedHandler = new NotAllowedHandler();
        }

        return $this->notAllowedHandler;
    }

    /**
     * Set Exception Handler
     *
     * @param ExceptionHandlerInterface $handler
     */
    public function setExceptionHandler(ExceptionHandlerInterface $handler)
    {
        $this->exceptionHandler = $handler;
    }

    /**
     * Get exception handler
     * (create new instance Exception handler if empty)
     *
     * @return ExceptionHandlerInterface
     */
    public function getExceptionHandler() : ExceptionHandlerInterface
    {
        if (!$this->exceptionHandler) {
            $this->exceptionHandler = new ExceptionHandler(
                (bool) $this->getConfiguration('displayErrors')
            );
        }

        return $this->exceptionHandler;
    }

    /**
     * Set Error Handler
     *
     * @param ErrorHandlerInterface $handler
     */
    public function setErrorHandler(ErrorHandlerInterface $handler)
    {
        $this->errorHandler = $handler;
    }

    /**
     * Get exception handler
     * (create new instance Error handler if empty)
     *
     * @return ErrorHandlerInterface
     */
    public function getErrorHandler() : ErrorHandlerInterface
    {
        if (!$this->errorHandler) {
            $this->errorHandler = new ErrorHandler(
                (bool) $this->getConfiguration('displayErrors')
            );
        }

        return $this->errorHandler;
    }

    /**
     * Dispatch the router to find the route. Prepare the route for use.
     *
     * @param ServerRequestInterface $request
     * @param RouterInterface        $router
     * @return ServerRequestInterface
     */
    protected function dispatchAndPrepareRoute(
        ServerRequestInterface $request,
        RouterInterface $router
    ) : ServerRequestInterface {
        $routeInfo = $router->dispatch($request);
        if ($routeInfo[0] === Dispatcher::FOUND) {
            $routeArguments = [];
            foreach ($routeInfo[2] as $k => $v) {
                $routeArguments[$k] = urldecode($v);
            }

            $route = $router->getRouteByIdentifier($routeInfo[1]);
            $route->prepare($request, $routeArguments);
            // add route to the request's attributes in case a middleware or handler needs access to the route
            $request = $request->withAttribute('route', $route);
        }

        $routeInfo['request'] = [$request->getMethod(), (string) $request->getUri()];
        return $request->withAttribute('routeInfo', $routeInfo);
    }

    /**
     * Invoke application
     *
     * This method implements the middleware interface. It receives
     * Request and Response objects, and it returns a Response object
     * after compiling the routes registered in the Router and dispatching
     * the Request object to the appropriate Route callback routine.
     *
     * @param  ServerRequestInterface $request  The most recent Request object
     * @param  ResponseInterface      $response The most recent Response object
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response) : ResponseInterface
    {
        // Get the route info
        $routeInfo = $request->getAttribute('routeInfo');
        $router = $this->getRouter();

        // If router hasn't been dispatched or the URI changed then dispatch
        if (null === $routeInfo || ($routeInfo['request'] !== [$request->getMethod(), (string) $request->getUri()])) {
            $request = $this->dispatchAndPrepareRoute($request, $router);
            $routeInfo = $request->getAttribute('routeInfo');
        }

        if ($routeInfo[0] === Dispatcher::FOUND) {
            $route = $router->getRouteByIdentifier($routeInfo[1]);
            return $route->process($request, $response);
        } elseif ($routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            $notAllowedHandler = $this->getNotAllowedHandler();
            return $notAllowedHandler($request, $response, $routeInfo[1]);
        }

        $notFoundHandler = $this->getNotFoundHandler();
        return $notFoundHandler($request, $response);
    }

    /* ----------------------------------------------------
     * MAGIC METHOD
     *
     * Magic method __call should be used as instance set
     * of mapping route
     * ----------------------------------------------------
     */

    /**
     * Magic Method call
     *      This use as instance of Service::map((array) $method, (string) $pattern, callable $callable)
     *
     * @param string $name
     * @param array $arguments
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments) : RouteInterface
    {
        $arguments = array_merge([[strtoupper($name)]], $arguments);
        return call_user_func_array([$this, 'map'], $arguments);
    }

    /* ----------------------------------------------------
     * MAGIC METHOD
     *
     * - Magic method __get should be used to get container
     *  value
     * - Magic method __set should be used to set container
     * - Magic method __isset should be used to check
     *  container identifier existences
     * ----------------------------------------------------
     */

    /**
     * @param string $name
     *
     * @return mixed
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __get(string $name)
    {
        $container = $this->getContainer()->get($name);
        return $container;
    }

    /**
     * @see Container::set()
     *
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $container = $this->getContainer();
        $container[$name] = $value;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function __isset($name) : bool
    {
        return $this->getContainer()->has($name);
    }

    /* ----------------------------------------------------
     * ArrayAccess implementation
     *
     * Should be used as access to container abstraction
     * ----------------------------------------------------
     */

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset) : bool
    {
        return $this->getContainer()->has($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($offset)
    {
        return $this->getContainer()->get($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($offset, $value)
    {
        $container = $this->getContainer();
        $container[$offset] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
        $container = $this->getContainer();
        unset($container[$offset]);
    }
}
