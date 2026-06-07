<?php

use Lily\Support\DomainResolver;
use Lily\Http\Request;

echo "--- Testing DomainResolver normaliseHost ---\n";
assertEquals('example.com', DomainResolver::normaliseHost('example.com'));
assertEquals('example.com', DomainResolver::normaliseHost('http://example.com'));
assertEquals('example.com', DomainResolver::normaliseHost('https://example.com/'));
assertEquals('example.com', DomainResolver::normaliseHost(' HTTP://EXAMPLE.COM/ '));
assertEquals('api.example.com', DomainResolver::normaliseHost('https://api.example.com/'));

echo "--- Testing DomainResolver hostMatches ---\n";
assertTrue(DomainResolver::hostMatches([], 'example.com'), "Empty patterns matches anything");
assertTrue(DomainResolver::hostMatches(['*'], 'example.com'), "Asterisk matches anything");
assertTrue(DomainResolver::hostMatches(['example.com'], 'example.com'), "Exact match");
assertFalse(DomainResolver::hostMatches(['example.com'], 'api.example.com'), "Exact match fails on subdomain");
assertTrue(DomainResolver::hostMatches(['*.example.com'], 'api.example.com'), "Wildcard subdomain match");
assertFalse(DomainResolver::hostMatches(['*.example.com'], 'example.com'), "Wildcard subdomain requires subdomain");
assertTrue(DomainResolver::hostMatches(['example.com', '*.example.com'], 'example.com'), "Array of patterns match exact");
assertTrue(DomainResolver::hostMatches(['example.com', '*.example.com'], 'api.example.com'), "Array of patterns match wildcard");

echo "--- Testing Request Domain Methods ---\n";
$resolver = new DomainResolver();
$requestHttp = new Request([], [], ['HTTP_HOST' => 'test.com', 'HTTPS' => 'off'], [], []);
assertEquals('test.com', $resolver->getHost($requestHttp));
assertEquals('http', $resolver->getScheme($requestHttp));
assertEquals('http://test.com', $resolver->getBaseUrl($requestHttp));

$requestHttps = new Request([], [], ['HTTP_HOST' => 'secure.com', 'HTTPS' => 'on'], [], []);
assertEquals('secure.com', $resolver->getHost($requestHttps));
assertEquals('https', $resolver->getScheme($requestHttps));
assertEquals('https://secure.com', $resolver->getBaseUrl($requestHttps));
