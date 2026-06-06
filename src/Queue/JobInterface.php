<?php

namespace Lily\Queue;

interface JobInterface
{
    public function handle(): void;
}
