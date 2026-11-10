<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waynestate\Sourdough\Command\NewCommand;
use Waynestate\Sourdough\Generator\RawGenerator;
use Waynestate\Sourdough\OrderMenu;
use Waynestate\Sourdough\Permutation;

/**
 * The front page's order ticket and the CLI are one engine (BEST_PRACTICES
 * 1.8): the menu the ticket reads is current, every order the ticket can
 * write is a command `sourdough new` accepts with exactly the plan the
 * ticket shows, and every oven it leaves out is one the command refuses.
 */
final class OrderMenuTest extends TestCase
{
    private const PAGE = __DIR__ . '/../site/index.html';

    private const SCRIPT = __DIR__ . '/../site/assets/sourdough.js';

    public function testTheMenuFileIsCurrent(): void
    {
        $file = (string) file_get_contents(OrderMenu::FILE);

        self::assertSame(OrderMenu::encode(), str_replace("\r\n", "\n", $file), 'site/assets/order-menu.json is stale: run composer site:order-menu');
    }

    public function testEveryShapeIsOnTheMenuWithFlagsThatResolveBackToIt(): void
    {
        $menu = OrderMenu::build();

        self::assertSame(array_map(static fn(Permutation $p): string => $p->value, Permutation::cases()), array_keys($menu['shapes']));
        foreach (Permutation::cases() as $shape) {
            $flags = array_fill_keys($shape->cliFlags(), true);
            $resolved = Permutation::fromFlags(isset($flags['--raw']), isset($flags['--laravel']), isset($flags['--vue']), isset($flags['--inertia']));
            self::assertSame($shape, $resolved, $shape->value);
            self::assertSame($shape->cliFlags(), $menu['shapes'][$shape->value]['flags']);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function orders(): iterable
    {
        foreach (OrderMenu::build()['shapes'] as $shape => $entry) {
            foreach ($entry['ovens'] as $oven) {
                foreach ([true, false] as $advisor) {
                    yield sprintf('%s %s %s', $shape, $oven, $advisor ? 'advisor' : 'no-advisor') => [$shape, $oven, $advisor];
                }
            }
        }
    }

    #[DataProvider('orders')]
    public function testEveryOrderTheTicketWritesIsThePlanTheCommandMakes(string $shape, string $oven, bool $advisor): void
    {
        $menu = OrderMenu::build();
        $entry = $menu['shapes'][$shape];

        // The command the ticket writes for "Parking Permits", as the page
        // script builds it: dir, --name, the shape's flags, the oven's,
        // then --no-advisor.
        $input = ['dir' => 'parking-permits', '--name' => 'Parking Permits'];
        foreach ([...$entry['flags'], ...$menu['ovens'][$oven]] as $flag) {
            $input[$flag] = true;
        }
        if (!$advisor) {
            $input[$menu['no_advisor']] = true;
        }

        $data = $this->dryRunJson($input);

        $plan = $entry['plans'][$oven][$advisor ? 'advisor' : 'no-advisor'];
        $fill = static fn(array $lines): array => array_map(static fn(string $l): string => str_replace(OrderMenu::DIR, 'parking-permits', $l), $lines);
        self::assertSame($shape, $data['permutation']);
        self::assertSame($fill($plan['steps']), $data['steps']);
        self::assertSame($fill($plan['next_steps']), $data['next_steps']);
    }

    public function testEveryOvenTheTicketLeavesOutIsOneTheCommandRefuses(): void
    {
        $menu = OrderMenu::build();
        $refused = 0;
        foreach ($menu['shapes'] as $entry) {
            foreach (array_diff(array_keys($menu['ovens']), $entry['ovens']) as $oven) {
                $input = ['dir' => 'my-app', '--dry-run' => true];
                foreach ([...$entry['flags'], ...$menu['ovens'][$oven]] as $flag) {
                    $input[$flag] = true;
                }
                $tester = new CommandTester(new NewCommand());
                self::assertSame(1, $tester->execute($input, ['interactive' => false]), implode(' ', array_keys($input)));
                ++$refused;
            }
        }

        // Sail and Docker on the two raw loaves.
        self::assertSame(4, $refused);
    }

    public function testTheNameRuleIsTheGeneratorsInJavaScript(): void
    {
        $js = OrderMenu::build()['name']['pattern'];
        self::assertSame('^[A-Za-z0-9][A-Za-z0-9 ._-]{0,62}$', $js);

        // PCRE with D reads $ the way JavaScript does: the very end, never
        // before a final newline.
        $asJs = '/' . $js . '/D';
        $names = ['my-app', 'Parking Permits', 'a', 'app.v2_beta', str_repeat('a', 63), str_repeat('a', 64), '', '-app', '.app', "app\n", 'app$', 'café', 'a/b', '{{app_slug}}'];
        foreach ($names as $name) {
            self::assertSame(1 === preg_match(RawGenerator::NAME_PATTERN, $name), 1 === preg_match($asJs, $name), var_export($name, true));
        }
    }

    public function testPatternsOtherThanTheGeneratorsKindAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderMenu::jsPattern('/^abc$/i');
    }

    public function testThePageOffersExactlyTheMenusLoavesAndOvens(): void
    {
        $page = (string) file_get_contents(self::PAGE);
        $menu = OrderMenu::build();

        self::assertStringContainsString('data-order-menu="assets/order-menu.json"', $page);
        preg_match_all('/name="order-loaf" value="([^"]+)"/', $page, $loaves);
        preg_match_all('/name="order-oven" value="([^"]+)"/', $page, $ovens);
        self::assertSame(array_keys($menu['shapes']), $loaves[1]);
        self::assertSame(array_keys($menu['ovens']), $ovens[1]);
    }

    public function testTheTicketInTheHtmlIsTheDefaultOrder(): void
    {
        $page = (string) file_get_contents(self::PAGE);
        $plan = OrderMenu::build()['shapes']['raw']['plans']['bench']['advisor'];
        $fill = static fn(array $lines): array => array_map(static fn(string $l): string => str_replace(OrderMenu::DIR, 'my-app', $l), $lines);

        self::assertMatchesRegularExpression('#<code data-order-cmd>sourdough new my-app --raw</code>#', $page);

        self::assertSame(1, preg_match('#<ol class="slip__steps" data-order-steps>(.*?)</ol>#s', $page, $m));
        preg_match_all('#<li>(.*?)</li>#', $m[1] ?? '', $items);
        self::assertSame($fill($plan['steps']), array_map(static fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5), $items[1]));

        self::assertSame(1, preg_match('#<pre data-order-next>(.*?)</pre>#s', $page, $next));
        self::assertSame(implode("\n", $fill($plan['next_steps'])), $next[1] ?? null);
    }

    public function testThePageScriptSlugsNamesLikeTheGenerator(): void
    {
        // RawGenerator::slugify(): lower case, every run outside [a-z0-9-]
        // becomes one dash, dashes trimmed. The script spells it the same.
        $script = (string) file_get_contents(self::SCRIPT);

        self::assertStringContainsString('name.toLowerCase().replace(/[^a-z0-9-]+/g, "-").replace(/^-+|-+$/g, "")', $script);
        self::assertSame('parking-permits', RawGenerator::slugify('Parking Permits'));
        self::assertSame('app-v2-beta', RawGenerator::slugify('App.v2_beta'));
    }

    public function testTheOrderLinkWaitsForTheScriptAndOnlyTakesWhatIsOnTheMenu(): void
    {
        $page = (string) file_get_contents(self::PAGE);
        $script = (string) file_get_contents(self::SCRIPT);

        // Without the script the button could only copy the default order,
        // so the row stays hidden until the counter opens.
        self::assertSame(1, preg_match('#<p class="slip__share" hidden data-order-share-row>(.*?)</p>#s', $page, $row));
        self::assertStringContainsString('data-copy="https://sourdough.apps.wayne.edu/#order?loaf=raw&amp;oven=bench"', $row[1] ?? '');
        self::assertStringContainsString('data-order-share', $row[1] ?? '');
        self::assertStringContainsString('out.shareRow.hidden = false', $script);

        // The link reads back from #order?..., and a loaf or oven counts only
        // when it is a key of the menu the command built.
        self::assertStringContainsString('location.hash.indexOf("#order?") === 0', $script);
        self::assertStringContainsString('Object.prototype.hasOwnProperty.call(known, value)', $script);
        self::assertStringContainsString('choose("order-loaf", q.get("loaf"), menu.shapes)', $script);
        self::assertStringContainsString('choose("order-oven", q.get("oven"), menu.ovens)', $script);
        foreach (['"loaf="', '"&oven="', '"&name="', '"&advisor=no"'] as $part) {
            self::assertStringContainsString($part, $script);
        }

        // Every loaf and oven key travels in a link as it is.
        $menu = OrderMenu::build();
        foreach ([...array_keys($menu['shapes']), ...array_keys($menu['ovens'])] as $key) {
            self::assertSame((string) $key, rawurlencode((string) $key));
        }
    }

    /**
     * @param array<string, string|bool> $input
     *
     * @return array<string, mixed>
     */
    private function dryRunJson(array $input): array
    {
        $tester = new CommandTester(new NewCommand());
        $exit = $tester->execute([...$input, '--dry-run' => true, '--json' => true], ['interactive' => false]);
        self::assertSame(0, $exit, $tester->getDisplay());

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
