<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // per-process caches must not leak between tests
        \App\Models\ScoringSetting::flushCache();
        \App\Services\Autopilot\AutonomyPolicyService::flush();
    }

    //
}
