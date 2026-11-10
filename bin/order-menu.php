<?php

declare(strict_types=1);

/*
 * order-menu.php: writes site/assets/order-menu.json, the menu the front page's
 * order ticket reads (src/OrderMenu.php says what is in it and why).
 *
 * Usage:
 *   php bin/order-menu.php          write the menu
 *   php bin/order-menu.php --check  write nothing; exit 1 when the menu is stale
 *
 * Exit codes: 0 done or current, 1 stale, 2 the tool could not run.
 * The baker writes the order down; the counter only reads it back.
 */

use Waynestate\Sourdough\OrderMenu;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run `composer install` first.\n");
    exit(2);
}
require $autoload;

/** @var list<string> $args */
$args = $_SERVER['argv'] ?? [];
$check = in_array('--check', array_slice($args, 1), true);
$file = OrderMenu::FILE;

try {
    $menu = OrderMenu::encode();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not build the order menu: ' . $e->getMessage() . "\n");
    exit(2);
}

$current = is_file($file) ? file_get_contents($file) : false;

if ($check) {
    if (false === $current || str_replace("\r\n", "\n", $current) !== $menu) {
        fwrite(STDERR, "The order menu (site/assets/order-menu.json) is stale. Run: composer site:order-menu\n");
        exit(1);
    }
    echo "Order menu is current.\n";
    exit(0);
}

if (false === file_put_contents($file, $menu)) {
    fwrite(STDERR, sprintf("Could not write %s.\n", $file));
    exit(2);
}
echo "Wrote site/assets/order-menu.json.\n";
exit(0);
