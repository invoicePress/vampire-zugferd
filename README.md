# VAMPIRE for horstoeko/zugferd

Validate the e-invoices you create or read with [horstoeko/zugferd](https://github.com/horstoeko/zugferd) against the
official rule sets of EN 16931, XRechnung, Factur-X / ZUGFeRD and Peppol BIS Billing, in pure PHP.

`ZugferdVampireValidator` is used exactly like `ZugferdKositValidator` of horstoeko/zugferd. It checks with
[VAMPIRE PHP](https://github.com/invoicepress/vampire-php), so it needs no Java, downloads nothing and starts no process.
Changing one class name is enough.

## Requirements

- PHP 8.2 or newer. horstoeko/zugferd itself runs from PHP 7.3, but VAMPIRE PHP needs 8.2; on an older PHP, Composer
  refuses the installation.
- The PHP extensions `dom`, `libxml` and `bcmath`.
- horstoeko/zugferd 1.0.130 or newer.

This package cannot be installed next to horstoeko/invoicesuite, because invoicesuite declares a conflict with
horstoeko/zugferd. For invoicesuite there is [invoicepress/vampire-invoicesuite](https://github.com/invoicepress/vampire-invoicesuite).

## Installation

```bash
composer require invoicepress/vampire-zugferd
```

## Usage

Create an invoice with the builder, check it, save the positive validation report:

```php
$document  = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_XRECHNUNG_3); // ... fill in your invoice
$validator = ZugferdVampireValidator::fromZugferdDocument($document)->enablePositiveReport()->validate();
print_r($validator->getValidationErrors());
file_put_contents('report.json', $validator->getJsonReport());
```

`examples/validate.php` does the same for an XML file or a ZUGFeRD / Factur-X PDF:

```bash
php examples/validate.php invoice.pdf report.json
```

### What you can pass in

| Input | How |
|---|---|
| a `ZugferdDocumentBuilder` | `new ZugferdVampireValidator($builder)`, `fromZugferdDocument($builder)` or `setDocument($builder)` |
| a `ZugferdDocumentReader` | the same; also the reader that `ZugferdDocumentPdfReader::readAndGuessFromFile()` returns |
| XML as a string | `fromString($xml)` |
| the content of a ZUGFeRD / Factur-X PDF | `fromString($pdf)`; the embedded XML is taken out first |
| a file, XML or PDF | `fromFile($path)` |

A reader or a builder is checked as its `serializeAsXml()` writes it, as `ZugferdKositValidator` does. For a reader
this is a new serialization of the object model, not the bytes that were read. To check the original bytes, pass the
file with `fromFile()`.

### What you get back

| Method | Content |
|---|---|
| `getValidationErrors()`, `getValidationWarnings()`, `getValidationInformation()` | the findings as strings of the form `rule set: [rule] text`, like the KoSIT validator class |
| `hasNoValidationErrors()` | true only if VAMPIRE reached a verdict and found no error |
| `getProcessErrors()` | why no verdict was reached: empty or unreadable document, a PDF without invoice XML, no matching rule set, missing rule sets |
| `getProcessOutput()` | one line with engine, scenario and rule sets |
| `getKositReport()` | the report in the XML format of the KoSIT validator, naming VAMPIRE PHP as the engine |
| `getJsonReport()` | the full report of VAMPIRE as JSON |
| `getPositiveReport()` | with `enablePositiveReport()`: every rule of every rule set used, with `passed`, `failed`, `not-applicable` or `not-checked`, the SHA-256 of the checked bytes and the time of the check; off by default |
| `getResult()` | the result object of VAMPIRE PHP |

A document that VAMPIRE could not check is never reported as valid: `hasNoValidationErrors()` is false and
`hasProcessErrors()` is true.

### Which rules are checked

VAMPIRE reads the specification identifier ( BT-24 ) of the invoice and checks every rule set that belongs to it. With
VAMPIRE PHP 1.2.0, the invoices made with the builder of horstoeko/zugferd were checked against these rule sets:

| Profile of horstoeko/zugferd | Rule sets of VAMPIRE PHP 1.2.0 |
|---|---|
| `PROFILE_BASICWL` | Factur-X / ZUGFeRD BASIC WL ( `FACTURX-BASICWL-CII@1.09.2` ) |
| `PROFILE_BASIC` | Factur-X / ZUGFeRD BASIC ( `FACTURX-BASIC-CII@1.09.2` ) |
| `PROFILE_EN16931` | EN 16931 CII ( `EN16931-CII@1.3.16` ) |
| `PROFILE_EXTENDED` | Factur-X / ZUGFeRD EXTENDED ( `FACTURX-EXTENDED-CII@1.09.2` ) |
| `PROFILE_XRECHNUNG_3` | EN 16931 CII and XRechnung 3.0.2 CII ( `XRechnung-CII@3.0.2` ) |
| `PROFILE_MINIMUM` | Factur-X / ZUGFeRD MINIMUM ( `FACTURX-MINIMUM-CII@1.09.2` ) |

An XML string may also be a UBL invoice, for example XRechnung UBL or Peppol BIS Billing 3.0; VAMPIRE checks it against
its rule sets as well.

## Migrating from the KoSIT validator

```diff
- $validator = new ZugferdKositValidator($document);
+ $validator = new ZugferdVampireValidator($document);
```

Every public method of `ZugferdKositValidator` exists with the same name and the same parameters. The methods for the
download, the Java application, the cleanup and the remote mode are accepted and do nothing, so that existing code runs
unchanged. If your code declares a parameter of the type `ZugferdKositValidator`, change that type as well; the class does
not extend it.

There is one difference you will notice with XRechnung: `ZugferdKositValidator` 1.0.133 reads only the steps `val-xsd`,
`val-sch.1` and `val-xml` of the KoSIT report. The rules of the German CIUS are in the step `val-sch.2`, so a rejected
XRechnung gets the single message "Validation error. One ore more files were rejected". This class lists every failed rule,
for example `XRechnung-CII@3.0.2: [BR-DE-15] Das Element "Buyer reference" (BT-10) muss übermittelt werden.`

## Tests

```bash
composer install
vendor/bin/phpunit
```

The tests build invoices of every profile with horstoeko/zugferd and compare the verdict of this class with a direct call
of VAMPIRE PHP. With the environment variable `VAMPIRE_EXAMPLES` set to a folder of invoices, the test `CorpusParityTest`
does the same for every XML and PDF file below it.

## Licence

This package is published under the MIT licence, like horstoeko/zugferd. It contains no code of horstoeko/zugferd and no
code of VAMPIRE PHP; Composer installs both as dependencies. VAMPIRE PHP is published under the GNU General Public License,
version 2 or later, and its rule sets carry the licences of their sources ( see `NOTICE` in VAMPIRE PHP ). Which
obligations follow for your application depends on how you distribute it; this note is not legal advice.

## Links

- VAMPIRE in the browser and the documentation: <https://vampire.invoicepress.de>
- VAMPIRE PHP: <https://github.com/invoicepress/vampire-php>
- For horstoeko/invoicesuite: <https://github.com/invoicepress/vampire-invoicesuite>
