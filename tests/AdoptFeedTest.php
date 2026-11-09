<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Manifest;
use Waynestate\Sourdough\Permutation;

/**
 * `sourdough adopt`, then `sourdough feed`, both as commands
 * (design/sourdough-adopt-spec.md, F9 and F12): the first feed adds
 * exactly what adopt listed as missing, a template update reaches a
 * matching file and passes a yours file by, a yours file brought level with
 * the template is taken over, and `adopted: true` survives every feed.
 * Adopted once, fed forever.
 */
final class AdoptFeedTest extends TestCase
{
    use AdoptsApps;

    private const TEMPLATE_LINE = "# an older template wrote this line\n";

    /**
     * F9: the adopted F2 app, fed three times.
     */
    public function testAnAdoptedAppFeedsForward(): void
    {
        [$app, $template] = $this->editedApp();

        $adopt = $this->adopt($app, $this->raw() + ['--json' => true]);
        self::assertSame(0, $adopt->getStatusCode(), $adopt->getDisplay());
        $report = json_decode($adopt->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['files']);
        $missing = [];
        foreach ($report['files'] as $file) {
            if ('missing' === $file['status']) {
                $missing[] = $file['path'];
            }
        }
        self::assertSame(self::DELETED, $missing);
        $this->commitAll($app, 'Adopt with Sourdough ' . self::VERSION);

        // The first feed adds exactly the missing files, and nothing else.
        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(1, $dry->getStatusCode(), $dry->getDisplay());
        self::assertSame(array_map(static fn(string $path): array => ['add', $path], self::DELETED), $this->steps($dry->getDisplay()));

        $fed = $this->feed($app);
        self::assertSame(0, $fed->getStatusCode(), $fed->getDisplay());
        foreach (self::DELETED as $path) {
            self::assertFileExists($app . '/' . $path);
        }
        $this->commitAll($app, 'first feed');
        $fresh = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(0, $fresh->getStatusCode(), $fresh->getDisplay());
        self::assertStringContainsString('Fresh', $fresh->getDisplay());

        // The template moves on for one matching file and one yours file.
        $match = 'src/Oracle.php';
        $yours = self::EDITED[0];
        $files = Manifest::files(Manifest::read($app));
        self::assertSame('managed', $files[$match]['class']);
        self::assertSame('yours', $files[$yours]['class']);
        $matchNow = $this->read($app, $match);
        $matchOld = $this->replaceLine($matchNow, -1, self::TEMPLATE_LINE);
        self::assertNotSame($matchNow, $matchOld);
        $this->put($app, $match, $matchOld);
        $this->rewindManifest($app, $match, $matchOld);
        $this->rewindManifest($app, $yours, self::TEMPLATE_LINE);
        $yoursContent = $this->read($app, $yours);
        $this->commitAll($app, 'as an older template baked it');

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(1, $dry->getStatusCode(), $dry->getDisplay());
        self::assertSame([['update', $match]], $this->steps($dry->getDisplay()), 'the yours file gets no step');
        $fed = $this->feed($app);
        self::assertSame(0, $fed->getStatusCode(), $fed->getDisplay());
        self::assertSame($matchNow, $this->read($app, $match));
        self::assertSame($yoursContent, $this->read($app, $yours), 'feed never writes a yours file');
        self::assertSame('yours', Manifest::files(Manifest::read($app))[$yours]['class']);
        $this->commitAll($app, 'second feed');

        // The developer brings the yours file level with the template.
        $this->put($app, $yours, $template[$yours]);
        $this->commitAll($app, 'take the template\'s ' . $yours);

        $dry = $this->feed($app, ['--dry-run' => true]);
        self::assertSame(1, $dry->getStatusCode(), $dry->getDisplay());
        self::assertSame([['record', $yours]], $this->steps($dry->getDisplay()));
        $fed = $this->feed($app);
        self::assertSame(0, $fed->getStatusCode(), $fed->getDisplay());
        $this->commitAll($app, 'third feed');

        $files = Manifest::files(Manifest::read($app));
        self::assertSame('managed', $files[$yours]['class'], 'managed from here on');
        self::assertSame(hash('sha256', $template[$yours]), $files[$yours]['sha256']);
        self::assertSame(0, $this->feed($app, ['--dry-run' => true])->getStatusCode());
    }

    /**
     * @return array<string, array{Permutation}>
     */
    public static function shapes(): array
    {
        return [
            'raw' => [Permutation::Raw],
            'laravel-inertia' => [Permutation::LaravelInertia],
        ];
    }

    /**
     * F12: a lost manifest adopted back, then fed after the template
     * moved on: `adopted: true` is still in the answers, and so is every
     * other answer adopt recorded.
     */
    #[DataProvider('shapes')]
    public function testTheAdoptedAnswerSurvivesAFeed(Permutation $shape): void
    {
        $app = $this->bake($shape);
        $this->loseManifest($app);
        $this->commitAll($app, 'baked, then the manifest was lost');

        $adopt = $this->adopt($app, ['--shape' => $shape->value, '--name' => self::NAME, '--no-playbook' => true, '--no-advisor' => true]);
        self::assertSame(0, $adopt->getStatusCode(), $adopt->getDisplay());
        self::assertMatchesRegularExpression('/^Adopted: \d+ match, 0 yours, \d+ seeded, 0 missing\. Wrote \.sourdough\/manifest\.json\.$/m', $adopt->getDisplay());
        self::assertStringContainsString("\n      then sourdough feed --dry-run shows the app is fresh.\n", $adopt->getDisplay());
        $adopted = Manifest::read($app)['answers'];
        self::assertIsArray($adopted);
        self::assertTrue($adopted['adopted'] ?? null);
        $this->commitAll($app, 'adopted');

        $file = 'phpstan.neon';
        $now = $this->read($app, $file);
        $old = $this->replaceLine($now, -1, self::TEMPLATE_LINE);
        $this->put($app, $file, $old);
        $this->rewindManifest($app, $file, $old);
        $this->commitAll($app, 'as an older template baked it');

        $fed = $this->feed($app);
        self::assertSame(0, $fed->getStatusCode(), $fed->getDisplay());
        self::assertMatchesRegularExpression('/update\s+phpstan\.neon /', $fed->getDisplay());
        self::assertSame($now, $this->read($app, $file));

        $manifest = Manifest::read($app);
        self::assertSame(self::VERSION, $manifest['sourdough_version']);
        self::assertSame($adopted, $manifest['answers'], 'adopted: true, and every other answer, survive the feed');
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        return ['--shape' => 'raw', '--name' => self::NAME, '--no-playbook' => true, '--no-advisor' => true];
    }

    /**
     * Feed's plan lines, as [action, path].
     *
     * @return list<array{string, string}>
     */
    private function steps(string $display): array
    {
        preg_match_all('/^  (\w+)\s+(\S+) \(/m', str_replace("\r", '', $display), $matches, PREG_SET_ORDER);

        return array_map(static fn(array $m): array => [$m[1], $m[2]], $matches);
    }
}
