<?php

/**
 * Validate an XML invoice or a ZUGFeRD / Factur-X PDF and save the positive validation report.
 *
 *   php examples/validate.php invoice.pdf [report.json]
 */

declare(strict_types=1);

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;
use InvoicePress\VampireZugferd\ZugferdVampireValidator;

require __DIR__ . '/../vendor/autoload.php';

$file = $argv[1] ?? exit("Usage: php examples/validate.php <invoice.xml|invoice.pdf> [report.json]\n");

// Read the invoice with horstoeko/zugferd, as an application would ...
$document = str_ends_with(strtolower($file), '.pdf')
    ? ZugferdDocumentPdfReader::readAndGuessFromFile($file)
    : ZugferdDocumentReader::readAndGuessFromFile($file);

// ... and check it with VAMPIRE. These three lines are all it takes.
$validator = ZugferdVampireValidator::fromZugferdDocument($document)->enablePositiveReport()->validate();
echo $validator->hasNoValidationErrors() ? "Valid.\n" : implode("\n", [...$validator->getProcessErrors(), ...$validator->getValidationErrors()]) . "\n";
file_put_contents($argv[2] ?? 'report.json', $validator->getJsonReport());

exit($validator->hasNoValidationErrors() ? 0 : 1);
