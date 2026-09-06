# Report URI CSP reporting for Magento

> **Beta.** This works and is tested, but it has not yet run on a production store for long
> enough to prove it. It changes the CSP, `Report-To` and `Reporting-Endpoints` response headers
> on every page, so **try it on staging first**. Please open an issue if anything surprises you.

Magento 2 ships a Content Security Policy that is turned on and reports nowhere.

Every install from 2.3.5 onwards enables `Magento_Csp`, which sends
`Content-Security-Policy-Report-Only` on the storefront and in the Admin. Every visitor's
browser builds violation reports for that policy and then finds no endpoint to deliver them
to, because the reporting endpoint is a separate setting and its default is empty.

Four fields fix that, spread across three config groups. This module reduces them to one.

## What it does

Paste the reporting address from your Setup page once. On save the module writes all four
native fields:

| Config path | Endpoint |
| --- | --- |
| `csp/mode/storefront/report_uri` | `reportOnly` |
| `csp/mode/admin/report_uri` | `reportOnly` |
| `csp/mode/storefront_checkout_index_index/report_uri` | `enforce` |
| `csp/mode/admin_sales_order_create_index/report_uri` | `enforce` |

The split is not a setting. Whether a page enforces is something Magento already records, and
the disposition in the endpoint has to agree with it or violations arrive tagged as the wrong
kind. Each field is resolved against that page's own `report_only`, falling back to its area
default - the same rule `ConfigManager` uses when it renders the header. Change a page to
restrict mode and its endpoint follows on the next save, without being asked.

The whole address is taken rather than assembled from a subdomain, because two of its segments
are account-specific: the path is `/r/d/` for a personal account and `/r/t/` for a team, and
the host is your account's own. Both are already correct on the address your Setup page shows,
so pasting it whole removes a question you could answer wrongly - and a reporting endpoint that
is merely wrong looks exactly like one that works, because the browser never tells your site
its reports went nowhere.

Values are written to the same config rows the native fields use, so those fields keep showing
what is in force and stay editable afterwards. Nothing is hidden or locked.

Clearing the address removes the endpoints again — but only the ones this module wrote. A
field you set by hand is left alone.

## Install

```bash
composer require report-uri/magento2-csp-reporting
bin/magento module:enable ReportUri_CspReporting
bin/magento setup:upgrade
bin/magento cache:flush
```

Then go to **Stores > Configuration > Security > Content Security Policy**, paste an address
into the **Report URI** group, and save.

Copy any address from the [Setup page](https://report-uri.com/account/setup/) of your account.
All of these work, because each carries the same account details and the module derives the
rest:

- the CSP address, on any of its three dispositions — `.../r/d/csp/reportOnly`,
  `.../r/d/csp/enforce`, `.../r/d/csp/wizard`
- the Reporting API address — `.../a/d/g`
- the whole `Report-To:` header line, if that is the block you copied

Whatever you paste is normalised on save, so the field shows back the address actually written
to the four fields.

## It also repairs Magento's Reporting API headers

Magento builds its `Report-To` header out of the CSP report URI, and adds `report-to` to the
policy alongside `report-uri`. That assumes one address serves both mechanisms.

For Report URI it does not. The CSP endpoint takes `application/csp-report`; the Reporting API
endpoint is a different path and takes `application/reports+json`. Each rejects the other's
format. And a browser that supports the Reporting API prefers `report-to` and ignores
`report-uri` — so on a stock Magento store with the fields filled in correctly, Chrome's
reports are posted somewhere that will not accept them, while Firefox and Safari keep working
off `report-uri`. The store looks configured and silently loses a large share of its reports.

This module repoints `Report-To` at the Reporting API address, adds the modern
`Reporting-Endpoints` header, and names the group `default` in both — matching Report URI's own
setup documentation — rewriting the policy's `report-to` directive to match. Both headers are
set, because Network Error Logging is only deliverable over `Report-To`.

If your report URI belongs to another collector, nothing is changed — a collector that serves
both mechanisms from one address is left exactly as Magento configured it.

## Setting it across a portfolio

There is no shipped default for the address, because any value in a module's `config.xml` would
be somebody else's account. To set your own across many stores, put it in the deployment config
instead:

```bash
bin/magento config:set --lock-config csp/reporturi/address "https://abc123.report-uri.com/r/d/csp/reportOnly"
```

That writes it into `app/etc/config.php`, where it can be committed and deployed, and the Admin
field shows it as locked. The four endpoints are still derived and written normally — the
backend model runs on this path too.

If you edit `app/etc/config.php` by hand, run `bin/magento app:config:import` before using
`config:set` again; Magento refuses until the file and the database agree.

## Two optional reporting keywords

Both are added to `script-src`, and neither changes what the browser allows to execute. That
was verified against Chromium 149 under an enforcing policy: `'unsafe-inline'` keeps working
alongside both, while a genuine hash source in the same position disables it. The distinction
matters here, because Magento's checkout enforces from 2.4.7.

**Include a sample of the blocked script** — `'report-sample'`, **on by default**. Violation
reports carry the first 40 characters of whatever was blocked instead of only the name of the
directive it broke. No extra reports are sent, so there is no extra volume.

**Collect script integrity metadata** - `'report-sha256'`, **on by default**. Enables
[CSP Integrity](https://docs.report-uri.com/setup/csp-integrity/): the browser reports a
fingerprint for every script on every page load, not only on violations, which builds a full
inventory of what runs on your store. The volume follows your traffic rather than your problems
and counts against your Report URI quota accordingly.

## What it deliberately does not do

No report viewer, no policy builder, no `csp_whitelist.xml` management. Those duplicate the
platform or the service. Beyond the two reporting keywords above — neither of which changes
what a browser will execute — the module does not touch the content of your policy. It collects
nothing and sends nothing anywhere except the endpoint you configure.

## Tests

```bash
vendor/bin/phpunit -c vendor/report-uri/magento2-csp-reporting/phpunit.xml.dist
```

The config resolves Magento's bootstrap by walking up from the module, so the same command works
whether it was installed by Composer or dropped into `app/code` - those sit at different depths,
and a fixed relative path works in one and silently fails in the other.

55 unit tests over the parts where a wrong answer would be silent: the address parser, the
disposition each field resolves to, which endpoints clearing is allowed to remove, and the
Reporting API repointing. Magento's own `dev/tests/unit` run picks them up too, but that suite
carries thousands of unrelated warnings; the config above keeps a failure here readable.

## Handling of what you paste

The value of this field reaches three response headers, so it is treated as an injection
surface rather than as configuration.

**Nothing pasted is ever echoed.** Every URL the module writes is rebuilt with `sprintf()` from
captured groups whose character classes cannot express a delimiter, a quote, a space or a
control character - the token is `[a-z0-9]{4,32}`, the host must end in `report-uri.com`, the
account scope is a single `d` or `t`, and the disposition is one of the module's own constants.
Whatever goes in, what comes out is a URL of exactly that shape.

On top of that, input carrying a CR, LF, NUL or any other control character is refused before
matching begins, and anything over 2 KB is refused outright. The matching pattern is
deliberately unanchored so that pasting a whole `Report-To:` header works, which is precisely
why it cannot be the only thing standing between the field and a header.

Worth knowing: Magento's own four Report URI fields have no server-side validation - their
`validate-url` rule is client-side only - so a control character written directly into one of
them takes the storefront down with a 500. Setting them through this module is the safer path.

## Compatibility

Magento Open Source and Adobe Commerce 2.4.4+, and Mage-OS. Admin-only: it adds no frontend
output, so it is theme-agnostic and needs no Hyvä compatibility layer.

## Links

- [Magento CSP reporting](https://report-uri.com/solutions/magento)
- [Adobe Commerce and Magento setup guide](https://docs.report-uri.com/platforms/adobe-commerce/)

## Licence

MIT. See `LICENSE`.
