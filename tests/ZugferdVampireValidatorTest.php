<?php

declare(strict_types=1);

namespace InvoicePress\VampireZugferd\Tests;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Validator;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdKositValidator;
use horstoeko\zugferd\ZugferdProfiles;
use InvoicePress\VampireZugferd\ZugferdVampireValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ZugferdVampireValidatorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/';

    /** @return array<string, array{int}> */
    public static function profiles(): array
    {
        return [
            'BASIC WL' => [ZugferdProfiles::PROFILE_BASICWL],
            'BASIC' => [ZugferdProfiles::PROFILE_BASIC],
            'EN 16931' => [ZugferdProfiles::PROFILE_EN16931],
            'EXTENDED' => [ZugferdProfiles::PROFILE_EXTENDED],
        ];
    }

    #[DataProvider('profiles')]
    public function testAValidInvoiceOfEachProfileIsValid(int $profile): void
    {
        $validator = ZugferdVampireValidator::fromZugferdDocument(InvoiceFactory::builder($profile))->validate();

        self::assertTrue($validator->hasNoProcessErrors(), implode("\n", $validator->getProcessErrors()));
        self::assertTrue($validator->hasNoValidationErrors(), implode("\n", $validator->getValidationErrors()));
        self::assertFalse($validator->hasValidationErrors());
    }

    #[DataProvider('profiles')]
    public function testAWrongGrandTotalIsAnError(int $profile): void
    {
        $validator = ZugferdVampireValidator::fromZugferdDocument(InvoiceFactory::builder($profile, true))->validate();

        self::assertTrue($validator->hasNoProcessErrors());
        self::assertTrue($validator->hasValidationErrors());
        self::assertMatchesRegularExpression('/\[BR-(FXEXT-)?CO-15\]/', implode("\n", $validator->getValidationErrors()));
    }

    public function testACompleteXRechnungIsValid(): void
    {
        $validator = ZugferdVampireValidator::fromZugferdDocument(InvoiceFactory::xrechnung())->validate();

        self::assertTrue($validator->hasNoValidationErrors(), implode("\n", $validator->getValidationErrors()));
        self::assertStringContainsString('XRechnung-CII@', implode(' ', $validator->getProcessOutput()));
    }

    /**
     * The rules of the German CIUS are reported one by one. ZugferdKositValidator 1.0.133 reads only the steps val-xsd,
     * val-sch.1 and val-xml of the KoSIT report and reports these rules as one line "Validation error".
     */
    public function testTheRulesOfXRechnungAreReportedOneByOne(): void
    {
        $errors = implode("\n", ZugferdVampireValidator::fromZugferdDocument(InvoiceFactory::xrechnung(false))->validate()->getValidationErrors());

        foreach (['BR-DE-1', 'BR-DE-2', 'BR-DE-15', 'PEPPOL-EN16931-R010', 'PEPPOL-EN16931-R020'] as $rule) {
            self::assertStringContainsString('[' . $rule . ']', $errors);
        }
    }

    /** The main rule: the verdict of the package equals the verdict of VAMPIRE PHP called directly. */
    public function testTheVerdictEqualsADirectCallOfVampire(): void
    {
        $direct = self::directValidator();
        $documents = [InvoiceFactory::xrechnung(), InvoiceFactory::xrechnung(false), InvoiceFactory::builder(), InvoiceFactory::builder(ZugferdProfiles::PROFILE_EN16931, true)];

        foreach ($documents as $document) {
            $xml = $document->serializeAsXml();
            $result = $direct->validateString($xml);
            $validator = ZugferdVampireValidator::fromString($xml)->validate();

            self::assertSame($result->isAccepted(), $validator->hasNoValidationErrors());
            self::assertCount(count(array_filter($result->messages(), static fn ($m) => $m->flag === Flag::Fatal)), $validator->getValidationErrors());
        }

        foreach (glob(self::FIXTURES . '*.xml') as $file) {
            $result = $direct->validateFile($file);
            $validator = ZugferdVampireValidator::fromFile($file)->validate();

            self::assertSame($result->isAccepted(), $validator->hasNoValidationErrors(), basename($file));
        }
    }

    public function testTheShippedXRechnungFixtures(): void
    {
        self::assertTrue(ZugferdVampireValidator::fromFile(self::FIXTURES . 'xrechnung-ubl-valid.xml')->validate()->hasNoValidationErrors());
        self::assertTrue(ZugferdVampireValidator::fromFile(self::FIXTURES . 'xrechnung-cii-valid.xml')->validate()->hasNoValidationErrors());

        $invalid = ZugferdVampireValidator::fromFile(self::FIXTURES . 'xrechnung-cii-invalid.xml')->validate();

        self::assertTrue($invalid->hasValidationErrors());
        self::assertTrue($invalid->hasNoProcessErrors());
        self::assertStringContainsString('[BR-CO-10]', implode("\n", $invalid->getValidationErrors()));
        self::assertTrue(ZugferdVampireValidator::fromFile(self::FIXTURES . 'xrechnung-ubl-invalid.xml')->validate()->hasValidationErrors());
    }

    public function testAReaderGivesTheSameVerdictAsTheString(): void
    {
        foreach ([InvoiceFactory::xrechnung(), InvoiceFactory::builder(ZugferdProfiles::PROFILE_EN16931, true)] as $builder) {
            $xml = $builder->getContent();
            $reader = ZugferdDocumentReader::readAndGuessFromContent($xml);

            self::assertSame(
                ZugferdVampireValidator::fromString($xml)->validate()->hasNoValidationErrors(),
                ZugferdVampireValidator::fromZugferdDocument($reader)->validate()->hasNoValidationErrors()
            );
        }
    }

    public function testAPdfWithEmbeddedXmlIsCheckedAsContentAndAsFile(): void
    {
        $pdf = InvoiceFactory::facturXPdf();

        self::assertTrue(ZugferdVampireValidator::fromString($pdf)->validate()->hasNoValidationErrors());

        $file = tempnam(sys_get_temp_dir(), 'vz') . '.pdf';
        file_put_contents($file, InvoiceFactory::facturXPdf(ZugferdProfiles::PROFILE_EN16931, true));

        try {
            $validator = ZugferdVampireValidator::fromFile($file)->validate();
            self::assertTrue($validator->hasNoProcessErrors());
            self::assertTrue($validator->hasValidationErrors());
        } finally {
            unlink($file);
        }
    }

    /** @return array<string, array{string}> */
    public static function documentsWithoutVerdict(): array
    {
        return [
            'empty' => [''],
            'not XML' => ['this is not xml <'],
            'no matching rule set' => ['<?xml version="1.0"?><FatturaElettronica xmlns="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2"/>'],
            'PDF without XML' => [InvoiceFactory::blankPdf()],
        ];
    }

    /** A run without verdict is a process error, never a validation error and never a valid document. */
    #[DataProvider('documentsWithoutVerdict')]
    public function testADocumentWithoutVerdictIsAProcessError(string $document): void
    {
        $validator = ZugferdVampireValidator::fromString($document)->validate();

        self::assertTrue($validator->hasProcessErrors());
        self::assertFalse($validator->hasNoValidationErrors());
        self::assertSame([], $validator->getValidationErrors());
    }

    public function testNoDocumentAndAMissingFileAreProcessErrors(): void
    {
        self::assertTrue((new ZugferdVampireValidator())->validate()->hasProcessErrors());
        self::assertTrue(ZugferdVampireValidator::fromFile(self::FIXTURES . 'does-not-exist.xml')->validate()->hasProcessErrors());
        self::assertFalse((new ZugferdVampireValidator())->hasNoValidationErrors(), 'not validated yet');
    }

    public function testThePositiveReportIsOffByDefault(): void
    {
        $xml = InvoiceFactory::builder()->getContent();

        self::assertNull(ZugferdVampireValidator::fromString($xml)->validate()->getPositiveReport());

        $report = ZugferdVampireValidator::fromString($xml)->enablePositiveReport()->validate()->getPositiveReport();

        self::assertIsArray($report);
        self::assertSame(hash('sha256', $xml), $report['document']['sha256'] ?? null);
        self::assertStringContainsString('"ruleReport"', ZugferdVampireValidator::fromString($xml)->enablePositiveReport()->validate()->getJsonReport());
    }

    public function testTheKositReportNamesVampire(): void
    {
        $report = ZugferdVampireValidator::fromString(InvoiceFactory::builder()->getContent())->validate()->getKositReport();

        self::assertStringContainsString('<rep:report', $report);
        self::assertStringContainsString('valid="true"', $report);
        self::assertStringContainsString('vampire-php', $report);
    }

    /** Code written for ZugferdKositValidator keeps running: every public method of it exists here with the same name. */
    public function testEveryPublicMethodOfTheKositValidatorExists(): void
    {
        $ours = new ReflectionClass(ZugferdVampireValidator::class);

        foreach ((new ReflectionClass(ZugferdKositValidator::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertTrue($ours->hasMethod($method->getName()), $method->getName());
            self::assertSame($method->getNumberOfRequiredParameters(), $ours->getMethod($method->getName())->getNumberOfRequiredParameters(), $method->getName());
        }

        $validator = (new ZugferdVampireValidator(InvoiceFactory::builder()))
            ->setBaseDirectory(sys_get_temp_dir())
            ->disableCleanup()
            ->enableRemoteMode()
            ->setRemoteModeHost('localhost')
            ->setRemoteModePort(8080)
            ->validate();

        self::assertTrue($validator->hasNoValidationErrors());
        self::assertSame('', $validator->getRemoteModeUrl());
    }

    private static function directValidator(): Validator
    {
        $base = dirname((string) (new ReflectionClass(Validator::class))->getFileName(), 2);

        return new Validator(scenarioResolver: ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json'), baseDir: $base, lang: 'en');
    }
}
