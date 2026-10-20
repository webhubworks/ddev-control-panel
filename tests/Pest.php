<?php

use Native\Desktop\Facades\Settings;
use Tests\TestCase;

pest()->extend(TestCase::class)
    // The popup reads its settings on mount, and Settings is a round trip to
    // Electron, which is not running here. A test that cares mocks it itself.
    ->beforeEach(fn () => Settings::shouldReceive('get')->andReturnNull()->byDefault())
    ->in('Feature');
