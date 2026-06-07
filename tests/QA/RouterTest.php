<?php

use Lily\Routing\Router;
use Lily\Http\Request;
use Lily\Http\Response;

function getResponseContent(Response $response): string {
    $reflection = new \ReflectionClass($response);
    $property = $reflection->getProperty('content');
    return $property->getValue($response);
}

function getResponseStatusCode(Response $response): int {
    $reflection = new \ReflectionClass($response);
    $property = $reflection->getProperty('statusCode');
    return $property->getValue($response);
}

echo "--- Testing Exact Route Dispatch ---\n";
$router = new Router();
$router->get('/test', function (Request $request) {
    return new Response('Test Passed');
});

$request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'], [], []);
$response = $router->dispatch($request);
assertEquals(200, getResponseStatusCode($response), "Exact route status code");
assertEquals('Test Passed', getResponseContent($response), "Exact route content");

echo "--- Testing Dynamic Parameter ---\n";
$router = new Router();
$router->get('/users/{id}', function (Request $request) {
    return new Response('User ' . $request->getRouteParam('id'));
});

$request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/123'], [], []);
$response = $router->dispatch($request);
assertEquals(200, getResponseStatusCode($response), "Dynamic parameter status code");
assertEquals('User 123', getResponseContent($response), "Dynamic parameter content");

echo "--- Testing Regex Constraint ---\n";
$router = new Router();
$router->get('/items/{id:[0-9]+}', function (Request $request) {
    return new Response('Item ' . $request->getRouteParam('id'));
});

// Valid
$request1 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/items/42'], [], []);
$response1 = $router->dispatch($request1);
assertEquals(200, getResponseStatusCode($response1), "Valid regex constraint");

// Invalid constraint
$request2 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/items/abc'], [], []);
$response2 = $router->dispatch($request2);
assertEquals(404, getResponseStatusCode($response2), "Invalid regex constraint 404s");

echo "--- Testing Wildcard Route ---\n";
$router = new Router();
$router->get('/files/{path*}', function (Request $request) {
    return new Response('File ' . $request->getRouteParam('path'));
});

$request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/files/css/style.css'], [], []);
$response = $router->dispatch($request);
assertEquals(200, getResponseStatusCode($response), "Wildcard route status code");
assertEquals('File css/style.css', getResponseContent($response), "Wildcard route content");

echo "--- Testing Invalid Wildcard Placement ---\n";
$router = new Router();
assertThrows(function() use ($router) {
    $router->get('/files/{path*}/test', function() { return new Response(''); });
}, \InvalidArgumentException::class, "Wildcard must be at the end");

echo "--- Testing Subdomain Routing ---\n";
$router = new Router();
$router->on('api.example.com')->get('/users', function (Request $request) {
    return new Response('API Users');
});
$router->on('*.example.com')->get('/wildcard', function (Request $request) {
    return new Response('Wildcard');
});
$router->except('admin.example.com')->get('/public', function (Request $request) {
    return new Response('Public');
});

// Exact match
$req1 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users', 'HTTP_HOST' => 'api.example.com'], [], []);
assertEquals(200, getResponseStatusCode($router->dispatch($req1)), "Exact domain match");

// Miss
$req2 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users', 'HTTP_HOST' => 'web.example.com'], [], []);
assertEquals(404, getResponseStatusCode($router->dispatch($req2)), "Domain miss is 404");

// Wildcard match
$req3 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wildcard', 'HTTP_HOST' => 'test.example.com'], [], []);
assertEquals(200, getResponseStatusCode($router->dispatch($req3)), "Wildcard domain match");

// Blocklist match (blocked)
$req4 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/public', 'HTTP_HOST' => 'admin.example.com'], [], []);
assertEquals(404, getResponseStatusCode($router->dispatch($req4)), "Blocklist domain blocked is 404");

// Blocklist miss (allowed)
$req5 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/public', 'HTTP_HOST' => 'www.example.com'], [], []);
assertEquals(200, getResponseStatusCode($router->dispatch($req5)), "Blocklist domain miss is 200");

echo "--- Testing Edge Cases & Invalid Actions ---\n";
$router = new Router();
$router->get('/invalid_class', 'NonExistentClass@method');
$req6 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/invalid_class'], [], []);
assertThrows(function() use ($router, $req6) {
    $router->dispatch($req6);
}, \Throwable::class, "Non-existent controller class should throw");

$router->get('/missing_method', 'stdClass@missingMethod');
$req7 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/missing_method'], [], []);
assertThrows(function() use ($router, $req7) {
    $router->dispatch($req7);
}, \Throwable::class, "Missing controller method should throw");

// Method Not Allowed
$router = new Router();
$router->get('/only-get', function() { return new Response('OK'); });
$req8 = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/only-get'], [], []);
$res8 = $router->dispatch($req8);
assertEquals(405, getResponseStatusCode($res8), "Method Not Allowed 405");
