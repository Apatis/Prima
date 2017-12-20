<?php
/**
 * Example Test
 */
namespace {

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
                    "QueryString"    => $request->getQueryParams(),
                    "RouteInfo"      => $request->getAttribute('routeInfo')
                ],
                JSON_PRETTY_PRINT
            )
        );
        return $response->withHeader('Content-Type', 'application/json;charset=utf-8');
    });

    $service->serve();
}
