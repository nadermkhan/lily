<?php

namespace Lily\Security;

class CspBuilder
{
    private array $policies = [];

    public function add(string $directive, string $value): self
    {
        if (!isset($this->policies[$directive])) {
            $this->policies[$directive] = [];
        }
        $this->policies[$directive][] = $value;
        return $this;
    }

    public function build(): string
    {
        $policyString = '';
        foreach ($this->policies as $directive => $values) {
            $policyString .= $directive . ' ' . implode(' ', $values) . '; ';
        }
        return trim($policyString);
    }
}
