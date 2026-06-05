<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Lily\Foundation\Application;

abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__));
    }
}
