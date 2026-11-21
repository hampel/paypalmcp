# CLAUDE.md

Guidance for a session working in this repository.

## What this is

`paypalmcp` is a Laravel Zero 13 CLI (PHP 8.3+) that runs a read-only MCP server over stdio. Its
tools answer questions about PayPal business accounts — transactions, payers, fees, balances,
subscriptions — through `hampel/paypal-api` and `hampel/paypal-api-laravel`, so an operator's
agent can answer them without holding a PayPal login.

**`README.md` is the operator's document; this file is why the code is the way it is.**
`CLAUDE.local.md`, gitignored, records where it runs, which accounts it reads and what is
undecided. **Released as 0.1.0, 2026-11-21**; the structure follows `saasumcp`, which followed `xfmcp`. *Releasing*
below is the procedure.

## Commands

```bash
php paypalmcp app:config            # resolved configuration, and the .env actually read
php paypalmcp app:validate          # inventory, state directory, and each app, asked of PayPal
php paypalmcp mcp:start paypal      # the server; speaks JSON-RPC on stdin and stdout

composer check                      # phpstan, then pest
composer build                      # release phar into builds/, refusing dev dependencies
```

**`app:validate` is the command to extend** when a tool needs a permission no probe asks for, or
the server gains a new dependency on its environment: a probe is one request through the gateway,
so it is counted, and a permission missing from it is found at a deploy rather than in the middle
of someone's question.

## Read-only comes from the code, because the credential can move money

**A REST app's token carries every feature the app has**, and paypal-api measured thirty scopes on
a sandbox app on 2026-09-15, refunds and payouts among them. Nothing on PayPal's side stops it
moving money. `paypal-api` wraps no operation that writes, so a server that only calls it cannot
either.

- **Never add a tool that sends a raw request** — `Client::connection()` or `endpoint()` with a
  class of our own. `ServerTest` greps the tools for both.
- **A write would be a scope change to paypal-api first**, which that package's own `CLAUDE.md`
  refuses on the same grounds. Not a flag on a tool here.

## Every request crosses one counted transport

**`App\PayPal\MeteredClient` is bound under `PayPalServiceProvider::HTTP_CLIENT`**, wrapping the
package's own `PendingRequestClient` so `Http::fake()` still sees every request. paypal-api has no
request budget of its own — saasu-api's `withRequestBudget()` has no counterpart, because PayPal
rations nothing — so the count is kept at the one seam the token request crosses too.

- **`Gateway::call()` opens a meter with the most a call may send and closes it after**, and the
  meter refuses any request made outside one. A tool that built its own client would spend outside
  the daily budget; `MeterTest` proves such a request is refused before it is sent.
- **The refusals are plain `RuntimeException`s, not `ClientExceptionInterface`.** paypal-api
  catches that interface and reports it as PayPal being unreachable.
- **`AppServiceProvider` must stay listed before `PayPalServiceProvider`** in `config/app.php`:
  the package binds its transport with `singletonIf()`, so the first binding wins.
- **`Gateway::LOGIN_ALLOWANCE` is three**: a token when none is cached, and on a 401 a new token
  plus the one resend paypal-api makes.

## The budget bounds a conversation, not a quota

**PayPal publishes no rate limit** and sends no rate-limit header. The budget exists because a
search over three years is about 37 requests, and an agent asked to check a list of names would
repeat that per name on a credential that could refund. `DailyBudget` is saasumcp's: persisted in
the state directory's cache under a lock, a rolling 24 hours in hourly buckets, reserve then
settle. **Keyed by `PayPalAccount::identity()`** — the environment and a hash of the client id —
so renaming an inventory key does not reset it, and the inventory refuses two keys for one app.

## History is read in UTC calendar months and kept once closed

**PayPal searches 31 days at most, keeps three years, and has no payer, email or name filter.** So
`TransactionHistory` walks months newest first and the tools filter here — event code, currency
and status included, so every question about a month shares one cached copy of it.

- **A calendar month, not a 31-day window from the range's start**, so the same month is the same
  search whatever range it falls in. A closed month at a range's edge is read whole and clipped.
- **Closed is `CLOSED_AFTER_HOURS` (72) past the month's end**, far past PayPal's documented three
  hours, measured at about two. A closed month is kept `CACHE_DAYS` (30); an open one never.
- **A walk stops at a month boundary when its allowance runs out**, and does not start a month
  whose `total_pages` will not fit, so what it covered is a range it can state.
- **`Present::coverage()` goes beside every history answer.** PayPal silently cuts a search's end
  back to its last refresh; the page's `end_date` is the only place that says how far it got, and
  "nothing found" without it would sometimes mean "not reported yet".

## Payers: name, email, account id and country, never address or phone

**Decided as the handover recommended, and confirmed by Simon on 2026-11-21.**
Matching a payment to a person is the point, so those four go out. `Present::redact()` drops
`shipping_info`, `auction_info` and every `payer_info` field but five **before a row is cached**,
so the state directory never holds an address either. **Allowlists, not denylists**: a section
PayPal adds later is dropped until someone decides it may go out. A subscription's subscriber is
cut to email, payer id and name the same way.

## Stdio only, and stdout is the protocol

**`bootstrap/ai.php` calls `Mcp::local()` and must never call `Mcp::web()`.**
`tests/Feature/ServerTest.php` asserts the registered servers are exactly `['paypal']`.
`App\Exceptions\Handler` sends a rendered exception to stderr, and `bootstrap/app.php` catches a
malformed `.env` before the handler exists; both are from `xfmcp`. `.github/mcp-smoke.sh` sends
`initialize` and a `tools/call` to `list-accounts`, because `initialize` alone still succeeds when
every tool call is broken.

**`ValidationServiceProvider` and `TranslationServiceProvider` are listed in `config/app.php`
because laravel/mcp 1.2 needs them** — without them every tool call answers "Something went
wrong", found by saasumcp and xfmcp on 2026-11-21.

**Nothing is logged.** There is no `config/logging.php` and no `Log::` call: the answers are
payers' names and money, and a log would be another place for them to rest.

## A failure is a tool error with a sentence in it

**A tool returns `Response::error()`, never an exception and never an empty result.**
`Gateway::explain()` turns each paypal-api exception into a sentence naming whose problem it is:
the app, a missing feature, a missing record, PayPal, or the budget. PayPal's own text is passed
on from `ErrorDetail::describe()` with its debug id, capped at 400 characters — **never the
exception's message**, which quotes the request URI.

## The inventory holds the secrets

**`accounts.toml` holds each account's `client_id` and `secret`**, as saasumcp's holds logins and
xfmcp's holds keys. Nothing read from it is quoted back; `app:validate` fails it at anything wider
than 0600, or root-owned and 0640; `AccountInventory` re-reads it when it changes.
**`environment` is required, not defaulted**, unlike paypal-api-laravel's sandbox default: there a
missing variable must not point at real payers, here a missing line is a typo in a file that names
both kinds.

## Things learnt elsewhere that apply here

- **`config.platform.php` is pinned to 8.3.0.** A lock made under 8.5 otherwise takes
  dependencies 8.3 cannot load (`saasusync`, 2026-11-19).
- **Never set `permission` on the file cache store.** Laravel applies it to directories, and 0600
  makes them impossible to enter; `StateDirectory` creates the parent 0700 instead.
- **The environment file never overrides a variable already set**, so a client's `--env` wins.
  `bootstrap/app.php` loads it immutably, as saasumcp measured it must.
- **A method on a tool must not be called `name()`**: `Primitive::name()` is the tool's name.
- **The clock is Carbon's** in `TransactionHistory` and the tools, so tests travel with
  `Carbon::setTestNow()`. `DailyBudget` takes a closure instead.

## Releasing

CI tests on the floor and the newest PHP the constraint admits, compiles on every push, and
publishes a release only on a tag.

1. **Move `## [Unreleased]` in the CHANGELOG to `## [<tag>]` first.** The release job takes that
   section as the notes; a mismatch falls back to generated notes silently.
2. **Tag, then build.** `app:build` compiles the version in as a literal, and the release job
   fails if `--version` or the server's `serverInfo.version` differs from the tag. The version is
   `git describe --tags --dirty` (`App\Support\Version`).
3. **The maintainer pushes the tag**; CI publishes `paypalmcp-<version>` and `SHA256SUMS`.
4. **Pin an install to the published `SHA256SUMS`, never to a local build**: box stamps the phar,
   so the same commit does not compile to the same bytes.

## Conventions

- PSR-12 through Pint's defaults; `vendor/bin/pint` before committing.
- Configuration through `config()`, never `env()` outside `config/`, and never in
  `config/app.php`, which `app:build` compiles as a literal.
- `PayPalServiceProvider` is listed by hand; Laravel Zero ignores package discovery.
  `tests/Feature/NoNetworkTest.php` fakes nothing to prove the fakes intercept.
- `tests/Fixtures/` are paypal-api's own redacted sandbox captures; see its `CLAUDE.md`.
- No committed file names a real business, account, host or path.
