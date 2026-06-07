<?php

namespace Lily\Security;

/**
 * Class CspBuilder
 *
 * Builds Content Security Policy (CSP) headers by aggregating directives and values.
 */
class CspBuilder
{
    /**
     * @var array<string, array<string>> The aggregated CSP policies.
     */
    private array $policies = [];

    /**
     * Adds a directive and its value to the CSP builder.
     *
     * @param string $directive The CSP directive (e.g., 'default-src', 'script-src').
     * @param string $value The value for the directive (e.g., "'self'", 'https://example.com').
     * @return self Returns the builder instance for method chaining.
     */
    public function add(string $directive, string $value): self
    {
        if (!isset($this->policies[$directive])) {
            $this->policies[$directive] = [];
        }
        $this->policies[$directive][] = $value;
        return $this;
    }

    /**
     * Builds and returns the compiled Content Security Policy string.
     *
     * @return string The compiled CSP string.
     */
    public function build(): string
    {
        $policyString = '';
        foreach ($this->policies as $directive => $values) {
            $policyString .= $directive . ' ' . implode(' ', $values) . '; ';
        }
        return trim($policyString);
    }
}
