<?php

declare(strict_types=1);

namespace InvoicePress\VampireZugferd\Tests;

use DateTime;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * Test invoices made with the builder of horstoeko/zugferd. All names, numbers and addresses are invented.
 *
 * The data follow the example in the README of horstoeko/zugferd: two lines with 7 % and 19 % VAT, 473.00 net, 529.87 gross.
 */
final class InvoiceFactory
{
    /**
     * @param int  $profile         a constant of ZugferdProfiles
     * @param bool $wrongGrandTotal true writes a grand total that does not match the lines ( rule BR-CO-15 )
     */
    public static function builder(int $profile = ZugferdProfiles::PROFILE_EN16931, bool $wrongGrandTotal = false): ZugferdDocumentBuilder
    {
        $document = ZugferdDocumentBuilder::createNew($profile);

        $document
            ->setDocumentInformation('R-2026-0001', '380', DateTime::createFromFormat('Ymd', '20261020'), 'EUR')
            ->setDocumentSupplyChainEvent(DateTime::createFromFormat('Ymd', '20261020'))
            ->setDocumentSeller('Example Supplier GmbH (TEST)', '549910')
            ->addDocumentSellerTaxRegistration('VA', 'DE123456789')
            ->setDocumentSellerAddress('Teststrasse 20', '', '', '80333', 'Muenchen', 'DE')
            ->setDocumentBuyer('Example Customer AG (TEST)', 'GE2020211')
            ->setDocumentBuyerAddress('Kundenweg 15', '', '', '60311', 'Frankfurt', 'DE')
            ->addDocumentTax('S', 'VAT', 275.0, 19.25, 7.0)
            ->addDocumentTax('S', 'VAT', 198.0, 37.62, 19.0)
            ->setDocumentSummation($wrongGrandTotal ? 530.87 : 529.87, $wrongGrandTotal ? 530.87 : 529.87, 473.00, 0.0, 0.0, 473.00, 56.87, null, 0.0)
            ->addDocumentPaymentTerm('Payable within 30 days', DateTime::createFromFormat('Ymd', '20261119'))
            ->addNewPosition('1')
            ->setDocumentPositionProductDetails('Dividers A4', '', 'TB100A4')
            ->setDocumentPositionGrossPrice(9.90)
            ->setDocumentPositionNetPrice(9.90)
            ->setDocumentPositionQuantity(20, 'H87')
            ->addDocumentPositionTax('S', 'VAT', 19.0)
            ->setDocumentPositionLineSummation(198.0)
            ->addNewPosition('2')
            ->setDocumentPositionProductDetails('Yoghurt banana', '', 'ARNR2')
            ->setDocumentPositionGrossPrice(5.50)
            ->setDocumentPositionNetPrice(5.50)
            ->setDocumentPositionQuantity(50, 'H87')
            ->addDocumentPositionTax('S', 'VAT', 7.0)
            ->setDocumentPositionLineSummation(275.0);

        return $document;
    }

    /**
     * An XRechnung 3 that also carries what the German CIUS requires beyond EN 16931: buyer reference ( Leitweg-ID ),
     * seller contact, electronic addresses of both parties and the payment instructions.
     */
    public static function xrechnung(bool $complete = true): ZugferdDocumentBuilder
    {
        $document = self::builder(ZugferdProfiles::PROFILE_XRECHNUNG_3);

        if ($complete) {
            $document
                ->setDocumentBuyerReference('04011000-12345-67')
                // ⚠️ Name OR department, never both: both are BT-41, and CII-SR-465 ( an error in the XRechnung scenario
                // since VAMPIRE PHP 1.3.0, as in the KoSIT configuration ) rejects a contact that carries the two.
                ->setDocumentSellerContact('Max Mustermann (TEST)', null, '+49 89 0000000', null, 'invoice@example.com')
                ->setDocumentSellerCommunication('EM', 'invoice@example.com')
                ->setDocumentBuyerCommunication('EM', 'buyer@example.com')
                ->addDocumentPaymentMeanToCreditTransfer('DE02120300000000202051', 'Example Supplier GmbH (TEST)');
        }

        return $document;
    }

    /** A blank one-page PDF made with FPDF, without embedded XML. */
    public static function blankPdf(): string
    {
        $pdf = new \FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, 'Test invoice R-2026-0001 (TEST)');

        return $pdf->Output('S');
    }

    /** A Factur-X / ZUGFeRD PDF: the blank PDF with the XML of the builder embedded. */
    public static function facturXPdf(int $profile = ZugferdProfiles::PROFILE_EN16931, bool $wrongGrandTotal = false): string
    {
        return ZugferdDocumentPdfBuilder::fromPdfString(self::builder($profile, $wrongGrandTotal), self::blankPdf())->generateDocument()->downloadString();
    }
}
