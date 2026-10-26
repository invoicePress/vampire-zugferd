<?php

declare(strict_types=1);

/**
 * This file is part of invoicepress/vampire-zugferd.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace InvoicePress\VampireZugferd;

use EinvoiceValidator\Result\Result;
use horstoeko\zugferd\ZugferdDocument;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use Throwable;

/**
 * Validates a horstoeko/zugferd document with VAMPIRE PHP, in pure PHP and without Java.
 *
 * The class is used exactly like `horstoeko\zugferd\ZugferdKositValidator`: the same constructor, the same static
 * constructors, `setDocument()`, `validate()` and the same queries. Code that calls those methods changes one class name.
 *
 * ```php
 * $validator = (new ZugferdVampireValidator($document))->validate();
 * $validator->getValidationErrors();
 * ```
 *
 * ⚠️ A SUBCLASS OF ZugferdKositValidator WAS CONSIDERED AND REJECTED. It would inherit fourteen public methods for the
 * download of the Java application and for the remote daemon, and its document and message bag are `private`, out of
 * reach of a subclass. Code that declares a parameter of the type `ZugferdKositValidator` therefore changes that type
 * as well; code that only calls the methods changes the `new`.
 *
 * ⚠️ THE KOSIT-SPECIFIC SETTERS EXIST AND DO NOTHING. `setBaseDirectory()`, the download and file name setters, the
 * cleanup switches and the remote mode are accepted and return the instance, so that existing code runs unchanged.
 * VAMPIRE downloads nothing, starts no process and talks to no host.
 *
 * ⚠️ WHAT GETS CHECKED IS WHAT THE DOCUMENT GIVES. A string is checked as it is. A ZugferdDocumentReader or Builder is
 * checked as `serializeAsXml()` writes it, as ZugferdKositValidator does. For a reader that is a NEW serialization of the
 * object model, not the bytes that were read: an element the model does not know is gone, and the checksum in the positive
 * validation report belongs to the new XML. To check the original bytes, pass the file with `fromFile()` or its content
 * as a string.
 */
class ZugferdVampireValidator
{
    /** @var null|string|ZugferdDocument */
    private $document;

    /** Whether the positive validation report is produced; off by default. */
    private bool $positiveReport = false;

    private ?VampireRun $run = null;

    /** Process errors that arise before VAMPIRE runs, for example an unreadable file. */
    private array $pendingProcessErrors = [];

    /**
     * @param null|string|ZugferdDocument $document the XML ( or the content of a ZUGFeRD / Factur-X PDF ), a reader or a builder
     */
    public function __construct($document = null)
    {
        $this->setDocument($document);
    }

    /** The same as `ZugferdKositValidator::fromString()`. */
    public static function fromString(string $document): self
    {
        return new self($document);
    }

    /** The same as `ZugferdKositValidator::fromZugferdDocument()`. */
    public static function fromZugferdDocument(ZugferdDocument $zugferdDocument): self
    {
        return new self($zugferdDocument);
    }

    /**
     * A path to an XML file or to a ZUGFeRD / Factur-X PDF with embedded XML. Not in ZugferdKositValidator.
     *
     * The file is read when `validate()` runs; a missing or unreadable file becomes a process error then.
     */
    public static function fromFile(string $filename): self
    {
        return (new self())->setDocumentFromFile($filename);
    }

    /**
     * @param string|ZugferdDocument $document
     */
    public function setDocument($document): self
    {
        // Same behaviour as ZugferdKositValidator: anything else is ignored silently.
        if (!is_string($document) && !($document instanceof ZugferdDocument)) {
            return $this;
        }

        $this->document = $document;
        $this->pendingProcessErrors = [];

        return $this;
    }

    /** Not in ZugferdKositValidator: read the document from an XML or PDF file. */
    public function setDocumentFromFile(string $filename): self
    {
        $this->document = null;
        $this->pendingProcessErrors = [];

        $content = is_file($filename) && is_readable($filename) ? file_get_contents($filename) : false;

        if (false === $content) {
            $this->pendingProcessErrors[] = sprintf('The file %s does not exist or cannot be read', $filename);

            return $this;
        }

        $this->document = $content;

        return $this;
    }

    /**
     * Switch the positive validation report on: VAMPIRE then lists every rule of every rule set it used, with its result.
     * Read it with `getPositiveReport()` or as part of `getJsonReport()`.
     */
    public function enablePositiveReport(): self
    {
        $this->positiveReport = true;

        return $this;
    }

    public function disablePositiveReport(): self
    {
        $this->positiveReport = false;

        return $this;
    }

    /** Perform the validation. */
    public function validate(): self
    {
        $this->run = null;

        if ($this->pendingProcessErrors !== []) {
            $this->run = new VampireRun();
            $this->run->processErrors = $this->pendingProcessErrors;

            return $this;
        }

        if (null === $this->document) {
            $this->run = new VampireRun();
            $this->run->processErrors[] = 'You must specify an instance of the ZugferdDocument class';

            return $this;
        }

        try {
            $xml = $this->documentContent();
        } catch (Throwable $throwable) {
            $this->run = new VampireRun();
            $this->run->processErrors[] = $throwable->getMessage();

            return $this;
        }

        $this->run = VampireRun::run($xml, $this->positiveReport);

        return $this;
    }

    /** @return array<int, string> */
    public function getValidationErrors(): array
    {
        return $this->run?->errors ?? [];
    }

    /**
     * True only if VAMPIRE reached a verdict and found no error.
     *
     * ⚠️ False when VAMPIRE never checked the document ( a process error ), as in ZugferdKositValidator 1.0.133: no
     * statement about the document can be made then. `hasProcessErrors()` tells the two cases apart.
     */
    public function hasNoValidationErrors(): bool
    {
        if (null === $this->run || !$this->run->executed) {
            return false;
        }

        return [] === $this->getValidationErrors();
    }

    public function hasValidationErrors(): bool
    {
        return !$this->hasNoValidationErrors();
    }

    /** @return array<int, string> */
    public function getValidationWarnings(): array
    {
        return $this->run?->warnings ?? [];
    }

    public function hasNoValidationWarnings(): bool
    {
        return [] === $this->getValidationWarnings();
    }

    public function hasValidationWarnings(): bool
    {
        return !$this->hasNoValidationWarnings();
    }

    /** @return array<int, string> */
    public function getValidationInformation(): array
    {
        return $this->run?->information ?? [];
    }

    public function hasNoValidationInformation(): bool
    {
        return [] === $this->getValidationInformation();
    }

    public function hasValidationInformation(): bool
    {
        return !$this->hasNoValidationInformation();
    }

    /** @return array<int, string> the reasons why no verdict was reached */
    public function getProcessErrors(): array
    {
        return $this->run?->processErrors ?? [];
    }

    public function hasNoProcessErrors(): bool
    {
        return [] === $this->getProcessErrors();
    }

    public function hasProcessErrors(): bool
    {
        return !$this->hasNoProcessErrors();
    }

    /** @return array<int, string> one line with engine, scenario and rule sets */
    public function getProcessOutput(): array
    {
        return $this->run?->processOutput ?? [];
    }

    /** Not in ZugferdKositValidator: the report in the format of the KoSIT validator, naming VAMPIRE PHP as the engine. */
    public function getKositReport(): string
    {
        return $this->run?->kositReport() ?? '';
    }

    /** Not in ZugferdKositValidator: the full report of VAMPIRE as JSON, with the positive validation report if switched on. */
    public function getJsonReport(): string
    {
        return $this->run?->jsonReport() ?? '';
    }

    /**
     * Not in ZugferdKositValidator: the positive validation report, or null if it was not switched on or no verdict was reached.
     *
     * @return null|array<string, mixed>
     */
    public function getPositiveReport(): ?array
    {
        return $this->run?->positiveReport();
    }

    /** Not in ZugferdKositValidator: the result object of VAMPIRE PHP, for everything the queries above leave out. */
    public function getResult(): ?Result
    {
        return $this->run?->result;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Accepted for compatibility with ZugferdKositValidator. VAMPIRE needs none of this; every method returns the instance.
    // ---------------------------------------------------------------------------------------------------------------

    public function setBaseDirectory(string $newBaseDirectory): self
    {
        return $this;
    }

    public function setValidatorDownloadUrl(string $newValidatorDownloadUrl): self
    {
        return $this;
    }

    public function setValidatorScenarioDownloadUrl(string $newValidatorScenarioDownloadUrl): self
    {
        return $this;
    }

    public function setValidatorAppZipFilename(string $newValidatorAppZipFilename): self
    {
        return $this;
    }

    public function setValidatorScenarioZipFilename(string $newValidatorScenarioZipFilename): self
    {
        return $this;
    }

    public function setValidatorAppJarFilename(string $newValidatorAppJarFilename): self
    {
        return $this;
    }

    public function setValidatorAppScenarioFilename(string $newValidatorAppScenarioFilename): self
    {
        return $this;
    }

    public function disableCleanup(): self
    {
        return $this;
    }

    public function enableCleanup(): self
    {
        return $this;
    }

    public function disableRemoteMode(): self
    {
        return $this;
    }

    public function enableRemoteMode(): self
    {
        return $this;
    }

    public function setRemoteModeHost(string $remoteModeHost): self
    {
        return $this;
    }

    public function setRemoteModePort(int $remoteModePort): self
    {
        return $this;
    }

    /** Always empty: VAMPIRE talks to no host. */
    public function getRemoteModeUrl(): string
    {
        return '';
    }

    /**
     * The XML to check.
     *
     * ⚠️ A STRING MAY BE THE CONTENT OF A PDF. ZugferdKositValidator would hand PDF bytes to the KoSIT validator as XML and
     * get a parse error; here the embedded XML is taken out first. A PDF without embedded invoice XML throws, and validate()
     * turns that into a process error.
     */
    private function documentContent(): string
    {
        if (is_string($this->document)) {
            if (str_starts_with(ltrim(substr($this->document, 0, 1024)), '%PDF-')) {
                $xml = ZugferdDocumentPdfReader::getXmlFromContent($this->document);

                if (trim($xml) === '') {
                    throw new \RuntimeException('The PDF contains no embedded invoice XML');
                }

                return $xml;
            }

            return $this->document;
        }

        return $this->document->serializeAsXml();
    }
}
