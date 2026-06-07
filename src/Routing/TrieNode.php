<?php

namespace Lily\Routing;

/**
 * Represents a node in the routing trie.
 */
class TrieNode
{
    /**
     * Literal segment children.
     * 
     * @var array<string, TrieNode>
     */
    public array $children = [];

    /**
     * Dynamic param child (e.g. {id}).
     * 
     * @var TrieNode|null
     */
    public ?TrieNode $dynamicChild = null;

    /**
     * The name of the dynamic parameter.
     * 
     * @var string|null
     */
    public ?string $paramName = null;

    /**
     * Optional regex constraint applied to the dynamic segment.
     * 
     * @var string|null
     */
    public ?string $paramConstraint = null;

    /**
     * Wildcard child (catch-all, {path*}). Captures the remaining path.
     * 
     * @var TrieNode|null
     */
    public ?TrieNode $wildcardChild = null;

    /**
     * The name of the wildcard parameter.
     * 
     * @var string|null
     */
    public ?string $wildcardName = null;

    /**
     * The HTTP method handlers.
     * 
     * @var array<string, mixed>
     */
    public array $handlers = [];

    /**
     * The allowed hosts per HTTP method.
     * 
     * @var array<string, string[]>
     */
    public array $methodAllowHosts = [];
    
    /**
     * The blocked hosts per HTTP method.
     * 
     * @var array<string, string[]>
     */
    public array $methodBlockHosts = [];

    /**
     * Indicates whether this node represents a complete route.
     * 
     * @var bool
     */
    public bool $isLeaf = false;

    /**
     * The full route pattern.
     * 
     * @var string
     */
    public string $routePattern = '';
}
