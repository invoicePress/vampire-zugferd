# Changelog

## 1.0.1

- Works with VAMPIRE PHP 1.3.0. The test invoice for XRechnung no longer gives its seller contact both a name and a
  department, which CII-SR-465 rejects since that version.
- The repository no longer carries internal working documents.

## 1.0.0

- First version: a validator class for horstoeko that checks with VAMPIRE PHP instead of the KoSIT validator, with the
  same public methods, the report in the KoSIT format, the full JSON report and the positive validation report on request.
