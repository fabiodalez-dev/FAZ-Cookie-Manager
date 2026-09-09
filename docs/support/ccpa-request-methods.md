# CCPA request methods in a generated policy

The bundled English rights section already describes email requests to limit sensitive-information use without identity verification. Request channels are editable through **Cookie Policy → Policy text**; a separate contact-field schema is not required.

The default email wording needs review for each business. Under [CCPA §1798.130(a)(1)](https://cppa.ca.gov/pdf/20260101_ccpa_statute.pdf), the email-only exception applies to exclusively online businesses with a direct consumer relationship. Otherwise, designated channels must include a toll-free number and a website method. The [CPPA guidance](https://cppa.ca.gov/faq.html) also explains website links and methods for exercising an applicable right to limit.

1. Select California and the language in Policy text, then load the sections.
2. Replace the rights section with the business's actual request instructions, including its website URL and telephone channel where required. Preserve the distinction between verified know/delete/correct requests and opt-out/limit requests.
3. Save and review the rendered policy. Repeat for every published language; overrides are intentionally isolated by language and jurisdiction.

The editor now surfaces these assumptions. The regression test saves website and telephone instructions through the real admin UI, verifies them in the rendered CCPA policy and checks that the GDPR version stays unchanged.
