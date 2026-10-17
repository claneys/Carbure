<?php

use Carbure\Resources\BudgetAlert;
use PHPUnit\Framework\TestCase;

/**
 * Budget alerts: percentage, threshold and message (no database)
 */
class BudgetAlertMessageTest extends TestCase
{
    public function testPercentIsRoundedDown()
    {
        $this->assertSame(104, BudgetAlert::percent(312.40, 300));
        $this->assertSame(100, BudgetAlert::percent(300, 300));
        // 99.9 % is not the budget reached
        $this->assertSame(99, BudgetAlert::percent(299.70, 300));
        // No rounding error of the floats: 0.7 / 0.1 is 7, not 6.999...
        $this->assertSame(700, BudgetAlert::percent(0.7, 0.1));
        $this->assertSame(0, BudgetAlert::percent(0, 300));
    }

    public function testMessageInTheLanguageOfTheUser()
    {
        $this->assertSame(['Budget dépassé : Courses', '312,40 € sur 300,00 € (104 %)'],
            BudgetAlert::message('fr', 'Courses', 312.40, 300, 104));
        $this->assertSame(['Budget exceeded: Groceries', '312.40 € of 300.00 € (104%)'],
            BudgetAlert::message('en', 'Groceries', 312.40, 300, 104));
        // Below 100 % (threshold lowered): almost reached; thousands separated
        $this->assertSame(['Budget presque atteint : Loyer', '1 020,00 € sur 1 200,00 € (85 %)'],
            BudgetAlert::message('fr', 'Loyer', 1020, 1200, 85));
        $this->assertSame(['Budget almost reached: Rent', '1,020.00 € of 1,200.00 € (85%)'],
            BudgetAlert::message('en', 'Rent', 1020, 1200, 85));
        // Spanish, Italian, German, Portuguese: their own separators
        $this->assertSame(['Presupuesto superado: Supermercado', '1.312,40 € de 1.300,00 € (101 %)'],
            BudgetAlert::message('es', 'Supermercado', 1312.40, 1300, 101));
        $this->assertSame(['Budget quasi raggiunto: Affitto', '1.020,00 € su 1.200,00 € (85%)'],
            BudgetAlert::message('it', 'Affitto', 1020, 1200, 85));
        $this->assertSame(['Budget überschritten: Einkäufe', '312,40 € von 300,00 € (104 %)'],
            BudgetAlert::message('de', 'Einkäufe', 312.40, 300, 104));
        $this->assertSame(['Orçamento quase atingido: Renda', '1 020,00 € de 1 200,00 € (85 %)'],
            BudgetAlert::message('pt', 'Renda', 1020, 1200, 85));
        // A language not supported: French, as the other notifications
        $this->assertSame(['Budget dépassé : Courses', '312,40 € sur 300,00 € (104 %)'],
            BudgetAlert::message('nl', 'Courses', 312.40, 300, 104));
    }

    public function testThresholdValue()
    {
        $this->assertSame(50, BudgetAlert::thresholdValue('50'));
        $this->assertSame(200, BudgetAlert::thresholdValue(200));
        $this->assertSame(120, BudgetAlert::thresholdValue(120.0));
        foreach (['49', 201, '80.5', 80.5, 'abc', '', ' ', '1e2x'] as $refused) {
            try {
                BudgetAlert::thresholdValue($refused);
                $this->fail('Threshold accepted: ' . var_export($refused, true));
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
    }
}
