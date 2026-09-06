<?php
/**
 * Copyright (c) Report URI. All rights reserved.
 * See LICENSE for the licence details.
 *
 * Finds Magento's unit-test bootstrap by walking up from here.
 *
 * A relative path cannot do this: installed by Composer the module sits three levels below the
 * Magento root (vendor/report-uri/magento2-csp-reporting), and dropped into app/code it sits
 * four (app/code/ReportUri/CspReporting). A fixed `../../../..` works in one and silently fails
 * in the other, which is how a downloaded copy ends up with tests nobody can run.
 */
declare(strict_types=1);

$directory = __DIR__;

while ($directory !== dirname($directory)) {
    $bootstrap = $directory . '/dev/tests/unit/framework/bootstrap.php';

    if (is_file($bootstrap)) {
        require $bootstrap;

        return;
    }

    $directory = dirname($directory);
}

fwrite(
    STDERR,
    "Could not find dev/tests/unit/framework/bootstrap.php above " . __DIR__ . ".\n"
    . "These tests need to run from inside a Magento installation.\n"
);
exit(1);
