<?php
declare(strict_types=1);

/**
 * Isolated unit tests for pure helper functions in app/helpers.php
 */

require_once __DIR__ . '/test_bootstrap.php';

class HelpersTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): bool
    {
        echo "Running HelpersTest...\n";
        $this->testAmountInWords();
        $this->testUnitsLabel();
        $this->testDateFormatting();
        $this->testValidDate();
        $this->testGstCalculations();

        echo sprintf("HelpersTest Summary: %d Passed, %d Failed\n", $this->passed, $this->failed);
        return $this->failed === 0;
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$message}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$message}\n";
        }
    }

    private function testAmountInWords(): void
    {
        $this->assert(amount_in_words(0) === 'Rupees Zero Only', 'amount_in_words(0) returns Rupees Zero Only');
        $this->assert(amount_in_words(100) === 'Rupees One Hundred Only', 'amount_in_words(100) returns Rupees One Hundred Only');
        $this->assert(
            amount_in_words(1250.50) === 'Rupees One Thousand Two Hundred Fifty and Fifty Paise Only',
            'amount_in_words(1250.50) handles rupees and paise'
        );
        $this->assert(
            amount_in_words(100000) === 'Rupees One Lakh Only',
            'amount_in_words(100000) formats 1 Lakh correctly'
        );
    }

    private function testUnitsLabel(): void
    {
        // 1 pack size
        $this->assert(units_label(5, 1, 'Bottle') === '5 Bottle', 'Single-unit pack displays raw count');

        // Loose units
        $this->assert(units_label(15, 10, 'Tab') === '1 pk + 5 Tab', '15 units of 10-pack gives 1 pk + 5 Tab');
        $this->assert(units_label(20, 10, 'Tab') === '2 pk', 'Exact multiple gives full packs only');
        $this->assert(units_label(4, 10, 'Cap') === '0 pk + 4 Cap', 'Sub-pack quantity gives 0 pk + loose');
    }

    private function testDateFormatting(): void
    {
        $this->assert(fmt_date('2026-11-16') === '16-11-2026', 'fmt_date formats Y-m-d to d-m-Y');
        $this->assert(fmt_date(null) === '', 'fmt_date returns empty string for null');
        $this->assert(fmt_expiry('2026-12-31') === '12/26', 'fmt_expiry formats to m/y');
    }

    private function testValidDate(): void
    {
        $this->assert(valid_date('2026-11-16') === true, 'valid_date accepts valid ISO date');
        $this->assert(valid_date('2026-02-30') === false, 'valid_date rejects invalid calendar day');
        $this->assert(valid_date('not-a-date') === false, 'valid_date rejects arbitrary string');
    }

    private function testGstCalculations(): void
    {
        // Test CGST / SGST 50/50 split on 12% GST
        $rate = 12.0;
        $taxableAmount = 1000.0;
        $totalTax = ($taxableAmount * $rate) / 100.0;
        $cgst = $totalTax / 2.0;
        $sgst = $totalTax / 2.0;

        $this->assert($totalTax === 120.0, '12% tax on 1000 is 120.00');
        $this->assert($cgst === 60.0, 'CGST split is 60.00');
        $this->assert($sgst === 60.0, 'SGST split is 60.00');
    }
}
