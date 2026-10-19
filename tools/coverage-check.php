<?php

/**
 * coverage-check.php
 *
 * Fails when the line coverage of a Clover report is below a threshold.
 * Usage: php tools/coverage-check.php <clover.xml> <minimum percent>
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

[$script, $file, $minimum] = $argv + [null, 'coverage.xml', 90];

if (!is_file($file)) {
    fwrite(STDERR, "Coverage report not found: $file\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Invalid coverage report: $file\n");
    exit(2);
}
$metrics = $xml->project->metrics;
$statements = (int)$metrics['statements'];
$covered = (int)$metrics['coveredstatements'];
$percent = $statements > 0 ? round($covered / $statements * 100, 2) : 0;

echo "Line coverage: $percent % ($covered / $statements statements), minimum: $minimum %\n";

exit($percent >= (float)$minimum ? 0 : 1);
