# Test fixtures

| File | Source | Licence |
|---|---|---|
| `xrechnung-ubl-valid.xml` | `01.01a-INVOICE_ubl.xml` of the XRechnung test suite of KoSIT, <https://github.com/itplr-kosit/xrechnung-testsuite> | Apache License 2.0 |
| `xrechnung-cii-valid.xml` | `01.01a-INVOICE_uncefact.xml` of the same test suite | Apache License 2.0 |
| `xrechnung-cii-invalid.xml` | `xrechnung-cii-valid.xml` with one line total changed from 314.96 to 314.86, so that rule BR-CO-10 fails | Apache License 2.0, modified |
| `xrechnung-ubl-invalid.xml` | `01.02a-INVOICE_ubl.xml` of the same test suite, modified so that a business rule fails | Apache License 2.0, modified |

All other test invoices are made at test time with the builder of horstoeko/zugferd from invented data
( `tests/InvoiceFactory.php` ).
