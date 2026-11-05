<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pest is the default for new tests: write new tests in Pest. Existing PHPUnit class tests keep running unchanged.
 * Feature tests get the Laravel app and a fresh database.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
