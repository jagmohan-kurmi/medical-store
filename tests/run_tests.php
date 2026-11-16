<?php
declare(strict_types=1);

echo "====================================================\n";
echo "   Medical Store Management - Automated Test Suite  \n";
echo "====================================================\n\n";

require_once __DIR__ . '/HelpersTest.php';

$test = new HelpersTest();
$success = $test->run();

if ($success) {
    echo "\n>>> ALL TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo "\n>>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
