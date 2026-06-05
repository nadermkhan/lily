<?php

namespace Tests\Unit;

use Tests\TestCase;
use Lily\Routing\Router;
use Lily\Http\Request;
use Lily\Http\Response;

class RouterTest extends TestCase
{
    public function testExactRouteDispatch()
    {
        $router = new Router();
        
        $router->get('/test', function (Request $request) {
            return new Response('Test Passed');
        });

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'], [], []);
        $response = $router->dispatch($request);
        
        $this->assertEquals(200, $this->getStatusCode($response));
        $this->assertEquals('Test Passed', $this->getContent($response));
    }

    public function testDynamicParameter()
    {
        $router = new Router();
        
        $router->get('/users/{id}', function (Request $request) {
            return new Response('User ' . $request->getRouteParam('id'));
        });

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/123'], [], []);
        $response = $router->dispatch($request);
        
        $this->assertEquals(200, $this->getStatusCode($response));
        $this->assertEquals('User 123', $this->getContent($response));
    }

    public function testRegexConstraint()
    {
        $router = new Router();
        
        $router->get('/items/{id:[0-9]+}', function (Request $request) {
            return new Response('Item ' . $request->getRouteParam('id'));
        });

        // Valid
        $request1 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/items/42'], [], []);
        $response1 = $router->dispatch($request1);
        $this->assertEquals(200, $this->getStatusCode($response1));
        
        // Invalid constraint
        $request2 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/items/abc'], [], []);
        $response2 = $router->dispatch($request2);
        $this->assertEquals(404, $this->getStatusCode($response2));
    }

    public function testWildcardRoute()
    {
        $router = new Router();
        
        $router->get('/files/{path*}', function (Request $request) {
            return new Response('File ' . $request->getRouteParam('path'));
        });

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/files/css/style.css'], [], []);
        $response = $router->dispatch($request);
        
        $this->assertEquals(200, $this->getStatusCode($response));
        $this->assertEquals('File css/style.css', $this->getContent($response));
    }

    public function testSubdomainRouting()
    {
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

        // Test exact match
        $req1 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users', 'HTTP_HOST' => 'api.example.com'], [], []);
        $this->assertEquals(200, $this->getStatusCode($router->dispatch($req1)));

        // Test miss
        $req2 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users', 'HTTP_HOST' => 'web.example.com'], [], []);
        $this->assertEquals(404, $this->getStatusCode($router->dispatch($req2)));

        // Test wildcard match
        $req3 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wildcard', 'HTTP_HOST' => 'test.example.com'], [], []);
        $this->assertEquals(200, $this->getStatusCode($router->dispatch($req3)));

        // Test blocklist match (blocked)
        $req4 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/public', 'HTTP_HOST' => 'admin.example.com'], [], []);
        $this->assertEquals(404, $this->getStatusCode($router->dispatch($req4)));

        // Test blocklist miss (allowed)
        $req5 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/public', 'HTTP_HOST' => 'www.example.com'], [], []);
        $this->assertEquals(200, $this->getStatusCode($router->dispatch($req5)));
    }

    private function getContent(Response $response): string
    {
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('content');
        return $property->getValue($response);
    }

    private function getStatusCode(Response $response): int
    {
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('statusCode');
        return $property->getValue($response);
    }
}
