<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Core\Csv;
use PHPUnit\Framework\TestCase;

/** Parity with parseCSV() in components/inventory/bulk-csv-import-dialog.tsx + writer safety. */
final class CsvTest extends TestCase
{
    public function testParsesQuotesEscapedQuotesCommasNewlinesAndTrims(): void
    {
        $rows = Csv::parse("name,description\r\n\"Rice, 5kg\",\"He said \"\"hi\"\"\"\r\n  Beans  ,\"line1\nline2\"\r\n");
        $this->assertSame([['name', 'description'], ['Rice, 5kg', 'He said "hi"'], ['Beans', "line1\nline2"]], $rows);
    }

    public function testBomBlankLinesAndMissingTrailingNewline(): void
    {
        $this->assertSame([['a', 'b'], ['1', '2']], Csv::parse("\xEF\xBB\xBFa,b\n\n,\n1,2"));
        $this->assertSame([], Csv::parse("\n\n  \n"));
        $this->assertSame([['x']], Csv::parse('x'));
    }

    public function testBareCarriageReturnsAreLineBreaks(): void
    {
        $this->assertSame([['a'], ['b']], Csv::parse("a\rb"));
    }

    public function testWriterQuotesAndGuardsFormulas(): void
    {
        $this->assertSame("a,\"b,c\",\"d\"\"e\"\r\n", Csv::line(['a', 'b,c', 'd"e']));
        foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tx", "\rx"] as $evil) {
            $this->assertSame("'" . $evil, Csv::safeText($evil), json_encode($evil));
        }
        foreach (['plain', '', 'a=b', "1'2"] as $ok) { $this->assertSame($ok, Csv::safeText($ok)); }
    }
}
