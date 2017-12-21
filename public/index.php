<?php
/**
 * Example Test
 */
namespace {

    use Apatis\Http\Message\Stream;
    use Apatis\Prima\Service;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    if (!file_exists( __DIR__ . '/../vendor/autoload.php')) {
        exit('Please Install Dependency with composer first');
    }

    require __DIR__ . '/../vendor/autoload.php';
    $service = new Service(['displayErrors' => true]);
    $service->get('/[{name: .+}]', function (ServerRequestInterface $request, ResponseInterface $response, array $params) {
        $response->getBody()->write(
            json_encode(
                [
                    "RouteParams"         => $params,
                    "QueryString"    => $request->getQueryParams(),
                    "RouteInfo"      => $request->getAttribute('routeInfo')
                ],
                JSON_PRETTY_PRINT
            )
        );
        return $response->withHeader('Content-Type', 'application/json;charset=utf-8');
    });

    // custom stream to memory
    $stream = new Stream(fopen('php://memory', 'r+'));
    $service->serve(true, $stream);
}