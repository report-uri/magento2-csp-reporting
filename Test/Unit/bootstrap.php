<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 *
 * Finds Composer's autoloader by walking up from here.
 *
 * A fixed relative path cannot do this. Installed by Composer the module sits three levels below
 * the Magento root, dropped into app/code it sits four, and cloned on its own it has a vendor
 * directory of its own - so one path works in one layout and silently fails in the others.
 *
 * Deliberately not Magento's dev/tests bootstrap: these tests only need the autoloader, and
 * dev/tests is absent from any installation built with --no-dev, which is every production one.
 */
declare(strict_types=1);

$directory = __DIR__;

while ($directory !== dirname($directory)) {
    $autoloader = $directory . '/vendor/autoload.php';

    if (is_file($autoloader)) {
        require $autoloader;

        return;
    }

    $directory = dirname($directory);
}

throw new RuntimeException(
    'Could not find vendor/autoload.php above ' . __DIR__ . '. Run composer install first.'
);
