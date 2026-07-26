<?php

test('coverage summary reports aggregate directories and lowest covered files', function () {
    $clover = tempnam(sys_get_temp_dir(), 'ai-clover-');
    file_put_contents($clover, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage>
  <project timestamp="1784425706">
    <file name="/workspace/server/src/Services/FullyCovered.php">
      <metrics statements="2" coveredstatements="2" methods="1" coveredmethods="1"/>
    </file>
    <file name="/workspace/server/src/Services/PartiallyCovered.php">
      <metrics statements="4" coveredstatements="1" methods="2" coveredmethods="1"/>
    </file>
    <metrics statements="6" coveredstatements="3" methods="3" coveredmethods="2" classes="2" coveredclasses="1"/>
  </project>
</coverage>
XML);

    $output = [];
    $exit   = 1;
    exec(PHP_BINARY . ' scripts/coverage-summary.php ' . escapeshellarg($clover), $output, $exit);

    @unlink($clover);

    $text = implode("\n", $output);

    expect($exit)->toBe(0)
        ->and($text)->toContain('Line coverage: 50.00% (3/6 statements)')
        ->and($text)->toContain('Method coverage: 66.67% (2/3 methods)')
        ->and($text)->toContain('Class coverage: 50.00% (1/2 classes)')
        ->and($text)->toContain('Lowest covered directories:')
        ->and($text)->toContain('Lowest covered files:')
        ->and($text)->toContain('server/src/Services/PartiallyCovered.php');
});

test('coverage summary derives covered classes when clover omits aggregate coveredclasses', function () {
    $clover = tempnam(sys_get_temp_dir(), 'ai-clover-');
    file_put_contents($clover, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage>
  <project timestamp="1784425706">
    <file name="/workspace/server/src/Services/FullyCovered.php">
      <class name="FullyCovered">
        <metrics methods="2" coveredmethods="2" statements="4" coveredstatements="4"/>
      </class>
      <metrics classes="1" statements="4" coveredstatements="4" methods="2" coveredmethods="2"/>
    </file>
    <file name="/workspace/server/src/Services/PartiallyCovered.php">
      <class name="PartiallyCovered">
        <metrics methods="2" coveredmethods="1" statements="4" coveredstatements="3"/>
      </class>
      <metrics classes="1" statements="4" coveredstatements="3" methods="2" coveredmethods="1"/>
    </file>
    <metrics statements="8" coveredstatements="7" methods="4" coveredmethods="3" classes="2"/>
  </project>
</coverage>
XML);

    $output = [];
    $exit   = 1;
    exec(PHP_BINARY . ' scripts/coverage-summary.php ' . escapeshellarg($clover), $output, $exit);

    @unlink($clover);

    expect($exit)->toBe(0)
        ->and(implode("\n", $output))->toContain('Class coverage: 50.00% (1/2 classes)');
});

test('coverage summary fails clearly when clover file is missing', function () {
    $missing = sys_get_temp_dir() . '/ai-missing-clover.xml';
    @unlink($missing);

    $output = [];
    $exit   = 0;
    exec(PHP_BINARY . ' scripts/coverage-summary.php ' . escapeshellarg($missing) . ' 2>&1', $output, $exit);

    expect($exit)->toBe(1)
        ->and(implode("\n", $output))->toContain('Coverage file not found: ' . $missing);
});
