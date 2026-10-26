**Title:** ZugferdKositValidator ignores the validation step `val-sch.2`, so XRechnung rule violations are not reported

**Version:** horstoeko/zugferd v1.0.133

**What happens**

When the KoSIT validator rejects an XRechnung only because of an XRechnung-specific rule (for example `BR-DE-15`), the
message bag of `ZugferdKositValidator` contains no rule message at all. The only error is the generic line

    Validation error. One ore more files were rejected

so the developer cannot see which rule failed.

**Why**

With the official XRechnung configuration, the KoSIT report (VARL) contains four validation steps inside
`rep:scenarioMatched`:

| Step id | Content |
|---|---|
| `val-xml` | well-formedness |
| `val-xsd` | XML schema |
| `val-sch.1` | Schematron rules of EN 16931 |
| `val-sch.2` | Schematron rules of XRechnung (`BR-DE-*`, `BR-DEX-*`, `BR-TMP-*`) |

`parseValidatorXmlReportByDomDocument()` in `src/ZugferdKositValidator.php` only reads three of them
(lines 1140–1144 in v1.0.133):

```php
$resultAreas = [
    'val-xsd',
    'val-sch.1',
    'val-xml',
];
```

Messages from `val-sch.2` are therefore never added to the message bag.

**Expected**

All messages of the report are reported, including those of `val-sch.2`.

**Suggested change**

Read every `rep:validationStepResult` of the matched scenario instead of a fixed list, for example:

```php
$queryResult = $domXPath->query('//rep:report/rep:scenarioMatched/rep:validationStepResult/@id');
$resultAreas = [];
foreach ($queryResult ?: [] as $idNode) {
    $resultAreas[] = $idNode->nodeValue;
}
```

or, as a minimal fix, add `'val-sch.2'` to the list.

**Note**

`horstoeko/invoicesuite` reads all four steps and reports the XRechnung messages correctly.

While at it: the generic message has a typo, "One ore more" should be "One or more" (line 1249).
