<?php

namespace Lily\Routing;

class TrieNode
{
    /** @var array<string,TrieNode> Literal segment children. */
    public array $children = [];

    /** Dynamic param child (e.g. {id}). */
    public ?TrieNode $dynamicChild = null;
    public ?string   $paramName    = null;

    /** Optional regex constraint applied to the dynamic segment. */
    public ?string $paramConstraint = null;

    /** Wildcard child (catch-all, {path*}). Captures the remaining path. */
    public ?TrieNode $wildcardChild = null;
    public ?string   $wildcardName  = null;

    /** @var array<string,mixed> HTTP method => handler callable/array. */
    public array $handlers = [];

    /** @var array<string,string[]> method => allowed hosts */
    public array $methodAllowHosts = [];
    
    /** @var array<string,string[]> method => blocked hosts */
    public array $methodBlockHosts = [];

    public bool   $isLeaf       = false;
    public string $routePattern = '';
}
