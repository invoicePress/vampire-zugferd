<?php

declare(strict_types=1);

/**
 * This file is part of invoicepress/vampire-zugferd.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace InvoicePress\VampireZugferd;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Report\KositXmlReport;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Result\Message;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use EinvoiceValidator\Validator;
use ReflectionClass;
use Throwable;

/**
 * One validation run with VAMPIRE PHP, sorted into the four kinds of message a horstoeko validator knows.
 *
 * VAMPIRE chooses the rule sets itself, from the specification identifier in the document: a ZUGFeRD / Factur-X file is
 * checked against its profile, an XRechnung against XRechnung, a Peppol BIS file against Peppol. This class never names a
 * scenario, so it cannot check a document against the wrong rules.
 *
 * ⚠️ A RUN THAT REACHED NO VERDICT IS A PROCESS ERROR, NEVER A VALIDATION ERROR AND NEVER A VALID DOCUMENT. Four cases end
 * here: the document is empty or not well-formed XML, no scenario of VAMPIRE matches it ( a FatturaPA file, for example ),
 * the rule sets of the matching scenario are not installed, or VAMPIRE throws. VAMPIRE reports the first two as `fatal`
 * findings of its own; passed on as validation errors they would call a document "invalid" that was never checked against
 * any rule.
 *
 * @internal
 */
final class VampireRun
{
    /** @var array<int, Validator> one engine per process and switch, because loading the scenarios takes time */
    private static array $validators = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $information = [];

    /** @var list<string> */
    public array $processErrors = [];

    /** @var list<string> */
    public array $processOutput = [];

    /**
     * Every finding with its fields, for a caller that wants more than the text.
     *
     * @var list<array{kind: string, text: string, data: array<string, mixed>}>
     */
    public array $findings = [];

    /** Whether VAMPIRE reached a verdict; false for every process error. */
    public bool $executed = false;

    public ?Result $result = null;

    public string $xml = '';

    /**
     * @param string $xml           the invoice XML
     * @param bool   $positiveReport whether VAMPIRE lists every rule with its result ( the positive validation report )
     */
    public static function run(string $xml, bool $positiveReport): self
    {
        $run = new self();
        $run->xml = $xml;

        if (trim($xml) === '') {
            // ⚠️ VAMPIRE PHP 1.2.0 throws a ValueError from DOMDocument::loadXML() for an empty string.
            $run->processErrors[] = 'The document is empty';

            return $run;
        }

        try {
            $result = self::validator($positiveReport)->validateString($xml);
        } catch (Throwable $throwable) {
            $run->processErrors[] = sprintf('VAMPIRE PHP failed: %s', $throwable->getMessage());

            return $run;
        }

        $run->result = $result;
        $run->processOutput[] = sprintf(
            '%s %s, scenario %s, rule sets %s',
            Validator::ENGINE_NAME,
            Validator::VERSION,
            $result->scenarioName() ?? '-',
            $result->rulesets() !== [] ? implode(', ', $result->rulesets()) : '-'
        );

        if ($result->stageStatus(Stage::Wellformed) === StageStatus::Failed) {
            foreach ($result->messages() as $message) {
                if ($message->stage === Stage::Wellformed) {
                    $run->processErrors[] = sprintf('The document is not well-formed XML: %s', $message->text);
                }
            }

            return $run;
        }

        foreach ($result->messages() as $message) {
            if ($message->ruleset === 'scenario') {
                // ⚠️ The text of this finding is German in VAMPIRE PHP 1.2.0 whatever the language; the bridge says it itself.
                $run->processErrors[] = 'No rule set of VAMPIRE matches this document. It is not an EN 16931 invoice in UBL or CII, or its specification identifier ( BT-24 ) is unknown';

                return $run;
            }
        }

        if (!$result->hasVerdict()) {
            $run->processErrors[] = sprintf('The rule sets for this document are not installed: %s', implode(', ', $result->missingRulesets()));

            return $run;
        }

        foreach ($result->messages() as $message) {
            $text = self::format($message);

            $kind = match ($message->flag) {
                Flag::Fatal => 'error',
                Flag::Warning => 'warning',
                Flag::Information => 'information',
            };

            match ($kind) {
                'error' => $run->errors[] = $text,
                'warning' => $run->warnings[] = $text,
                'information' => $run->information[] = $text,
            };

            $run->findings[] = [
                'kind' => $kind,
                'text' => $text,
                'data' => [
                    'engine' => Validator::ENGINE_NAME . ' ' . Validator::VERSION,
                    'rule' => $message->ruleId,
                    'rulesets' => $message->origins(),
                    'stage' => $message->stage->value,
                    'location' => $message->location,
                    'line' => $message->line,
                    'bt' => $message->bt,
                ],
            ];
        }

        $run->executed = true;

        return $run;
    }

    /**
     * "<rule set>: [<rule>] <text>", the shape "<resource name>: <message>" of the KoSIT validators of horstoeko.
     *
     * The rule sets of EN 16931 already start their texts with "[BR-CO-15]-"; the rule is added only where the text does not
     * carry it, so that no message names its rule twice.
     */
    private static function format(Message $message): string
    {
        $text = trim($message->text);

        if ($message->ruleId !== null && $message->ruleId !== '' && !str_starts_with($text, '[')) {
            $text = sprintf('[%s] %s', $message->ruleId, $text);
        }

        return sprintf('%s: %s', implode(', ', $message->origins()), $text);
    }

    /**
     * The report in the format of the KoSIT validator ( VARL ), as XML. It names VAMPIRE PHP as the engine, never the KoSIT
     * validator. Empty when VAMPIRE did not run.
     */
    public function kositReport(): string
    {
        return $this->result !== null ? (new KositXmlReport('invoicepress/vampire-php', Validator::VERSION))->render($this->result, $this->xml) : '';
    }

    /**
     * The full report of VAMPIRE as JSON. With the positive validation report switched on, its section `ruleReport` lists
     * every rule of every rule set used, with `passed`, `failed`, `not-applicable` or `not-checked`, the SHA-256 of the
     * checked bytes and the time of the check. Empty when VAMPIRE did not run.
     */
    public function jsonReport(): string
    {
        return $this->result !== null ? (new JsonReport())->toJson($this->result) : '';
    }

    /**
     * The positive validation report alone, or null when it was not switched on or VAMPIRE did not run.
     *
     * @return null|array<string, mixed>
     */
    public function positiveReport(): ?array
    {
        $ruleReport = $this->result?->ruleReport();

        return $ruleReport !== null && $this->result !== null ? $ruleReport->toArray($this->result) : null;
    }

    private static function validator(bool $positiveReport): Validator
    {
        $key = (int) $positiveReport;

        if (!isset(self::$validators[$key])) {
            // ⚠️ The scenarios are found next to the installed engine, wherever Composer put it; no path is configured.
            $base = dirname((string) (new ReflectionClass(Validator::class))->getFileName(), 2);

            self::$validators[$key] = new Validator(
                scenarioResolver: ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json'),
                baseDir: $base,
                lang: 'en',
                ruleReport: $positiveReport,
            );
        }

        return self::$validators[$key];
    }
}
