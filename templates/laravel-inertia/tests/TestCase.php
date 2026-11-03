<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // composer ci runs in a bare container without Node, so Vite assets are
        // never built. Write a minimal manifest so view-rendering tests can boot.
        $manifest = public_path('build/manifest.json');
        if (! is_dir(dirname($manifest))) {
            mkdir(dirname($manifest), 0755, true);
        }
        if (! file_exists($manifest)) {
            file_put_contents($manifest, json_encode([
                'resources/js/app.ts' => [
                    'file' => 'assets/app.js',
                    'src' => 'resources/js/app.ts',
                    'isDynamicEntry' => true,
                ],
            ]));
        }
    }
}
