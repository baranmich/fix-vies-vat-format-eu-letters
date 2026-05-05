<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocParser;
use PHPUnit\Framework\TestCase;

final class IsdocParserTest extends TestCase
{
    private const NS = 'http://isdoc.cz/namespace/2013';

    private IsdocParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IsdocParser();
    }

    private function minimalIsdoc(): string
    {
        $ns = self::NS;
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="$ns">
  <DocumentType>1</DocumentType>
  <ID>2605001</ID>
  <IssueDate>2026-05-01</IssueDate>
  <TaxPointDate>2026-05-01</TaxPointDate>
  <PaymentMeans>
    <Payment>
      <Details>
        <PaymentDueDate>2026-05-15</PaymentDueDate>
      </Details>
    </Payment>
  </PaymentMeans>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <CurrencyCode>CZK</CurrencyCode>
  <AccountingSupplierParty>
    <Party>
      <PartyIdentification>
        <ID>21370362</ID>
      </PartyIdentification>
    </Party>
  </AccountingSupplierParty>
  <AccountingCustomerParty>
    <Party>
      <PartyName>
        <Name>Test Klient s.r.o.</Name>
      </PartyName>
      <PartyIdentification>
        <ID>12345678</ID>
      </PartyIdentification>
    </Party>
  </AccountingCustomerParty>
  <InvoiceLines>
    <InvoiceLine>
      <Item>
        <Description>Konzultace</Description>
      </Item>
      <InvoicedQuantity unitCode="hod">10</InvoicedQuantity>
      <UnitPrice>1500</UnitPrice>
      <ClassifiedTaxCategory>
        <Percent>21</Percent>
      </ClassifiedTaxCategory>
    </InvoiceLine>
  </InvoiceLines>
</Invoice>
XML;
    }

    public function testHappyPathExtractsBasicFields(): void
    {
        $result = $this->parser->parse($this->minimalIsdoc());
        self::assertSame('21370362', $result['supplier_ic']);
        self::assertCount(1, $result['invoices']);

        $inv = $result['invoices'][0];
        self::assertArrayNotHasKey('__error', $inv);
        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame('2605001', $inv['varsymbol']);
        self::assertSame('2026-05-01', $inv['issue_date']);
        self::assertSame('2026-05-15', $inv['due_date']);
        self::assertSame('CZK', $inv['currency']);
        self::assertSame('Test Klient s.r.o.', $inv['client']['company_name']);
        self::assertSame('12345678', $inv['client']['ic']);
        self::assertCount(1, $inv['items']);
        self::assertSame(10.0, $inv['items'][0]['quantity']);
        self::assertSame('hod', $inv['items'][0]['unit']);
        self::assertSame(1500.0, $inv['items'][0]['unit_price_without_vat']);
        self::assertSame(21.0, $inv['items'][0]['vat_rate']);
    }

    public function testRejectsDoctypeBecauseOfXxe(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<Invoice xmlns="http://isdoc.cz/namespace/2013">
  <ID>&xxe;</ID>
</Invoice>
XML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/i');
        $this->parser->parse($xml);
    }

    public function testRejectsBillionLaughsViaDoctype(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE lolz [
  <!ENTITY lol "lol">
  <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
]>
<Invoice xmlns="http://isdoc.cz/namespace/2013">
  <ID>&lol2;</ID>
</Invoice>
XML;
        $this->expectException(\RuntimeException::class);
        $this->parser->parse($xml);
    }

    public function testRejectsNonInvoiceRoot(): void
    {
        $xml = '<?xml version="1.0"?><WrongRoot/>';
        $this->expectException(\RuntimeException::class);
        $this->parser->parse($xml);
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('<not xml>');
    }

    public function testProformaIsRecognized(): void
    {
        $xml = str_replace('<DocumentType>1</DocumentType>', '<DocumentType>2</DocumentType>', $this->minimalIsdoc());
        $result = $this->parser->parse($xml);
        self::assertSame('proforma', $result['invoices'][0]['invoice_type']);
    }

    public function testCreditNoteIsRecognized(): void
    {
        $xml = str_replace('<DocumentType>1</DocumentType>', '<DocumentType>5</DocumentType>', $this->minimalIsdoc());
        $result = $this->parser->parse($xml);
        self::assertSame('credit_note', $result['invoices'][0]['invoice_type']);
    }

    public function testReverseChargeFlag(): void
    {
        $xml = str_replace(
            '<LocalCurrencyCode>CZK</LocalCurrencyCode>',
            '<VATApplicable>false</VATApplicable><LocalCurrencyCode>CZK</LocalCurrencyCode>',
            $this->minimalIsdoc()
        );
        $result = $this->parser->parse($xml);
        self::assertTrue($result['invoices'][0]['reverse_charge']);
    }
}
