<?php

declare(strict_types=1);

namespace InvoicePress\VampireZugferd\Tests;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use EinvoiceValidator\Validator;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use InvoicePress\VampireZugferd\ZugferdVampireValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * The verdict of the package against a direct call of VAMPIRE PHP, over a folder of invoices.
 *
 * Runs only when the environment variable VAMPIRE_EXAMPLES names a folder; the invoices are not part of this repository.
 * Every *.xml and *.pdf below the folder is checked, also files that are no invoice at all: for them both sides must agree
 * that no verdict was reached.
 */
final class CorpusParityTest extends TestCase
{
    public function testEveryFileGetsTheVerdictOfADirectCall(): void
    {
        $dir = (string) getenv('VAMPIRE_EXAMPLES');

        if ($dir === '' || !is_dir($dir)) {
            self::markTestSkipped('Set VAMPIRE_EXAMPLES to a folder of invoices to run this test.');
        }

        $base = dirname((string) (new ReflectionClass(Validator::class))->getFileName(), 2);
        $direct = new Validator(scenarioResolver: ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json'), baseDir: $base, lang: 'en');
        $files = 0;
        $differences = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!preg_match('/\.(xml|pdf)$/i', $file->getFilename())) {
                continue;
            }

            $path = $file->getPathname();
            $files++;

            try {
                $xml = str_ends_with(strtolower($path), '.pdf') ? ZugferdDocumentPdfReader::getXmlFromFile($path) : (string) file_get_contents($path);
                $result = trim($xml) !== '' ? $direct->validateString($xml) : null;
            } catch (Throwable) {
                $result = null;
            }

            $expected = 'none';

            if ($result !== null && $result->hasVerdict() && $result->scenarioName() !== null && $result->stageStatus(Stage::Wellformed) !== StageStatus::Failed) {
                $expected = count(array_filter($result->messages(), static fn ($m) => $m->flag === Flag::Fatal));
            }

            $validator = ZugferdVampireValidator::fromFile($path)->validate();
            $actual = $validator->hasProcessErrors() ? 'none' : count($validator->getValidationErrors());

            if ($expected !== $actual) {
                $differences[] = sprintf('%s: direct %s, package %s', $path, $expected, $actual);
            }
        }

        fwrite(STDERR, sprintf("\nCorpus: %d files, %d differences\n", $files, count($differences)));

        self::assertSame([], $differences);
    }
}
