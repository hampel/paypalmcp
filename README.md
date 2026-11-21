# paypalmcp — PayPal lookups over MCP

A read-only [MCP](https://modelcontextprotocol.io/) server, over stdio, that lets an agent answer
questions about one or more PayPal business accounts: what a person paid and when, a transaction
by its id, fees and totals over a period, balances, and a subscription's state. It is for an
operator's agent that needs those answers without holding a PayPal login itself.

**Nothing it does can move money or change an account, and every request it sends is counted.**
Both are the point of it; see *Safety*.

## Requirements

PHP 8.3 or later. A PayPal REST app for each account, made for this server alone, with
Transaction Search and Subscriptions ticked.

## Installation

Download `paypalmcp-<version>` and `SHA256SUMS` from the release, verify, and install the binary:

```bash
sha256sum --ignore-missing -c SHA256SUMS
install -m 0755 paypalmcp-<version> /usr/local/bin/paypalmcp
```

From a checkout, `composer install` and run `php paypalmcp` in place of `paypalmcp`.

## Configuration

Three things: an environment file, an account inventory, and a state directory.

**The environment file** says where the state lives, and holds no credential. `.env.example` is
the template and says where the file is looked for. A variable already set, exported or passed
by the MCP client, wins over the same key in the file.

```dotenv
PAYPALMCP_STATE_PATH=/var/lib/paypalmcp
APP_TIMEZONE=Australia/Sydney
```

`APP_TIMEZONE` is the zone a bare date such as `2026-03-01` is read in, as the whole of that day.
Times PayPal reports keep their own offset.

**The account inventory** names the accounts and holds the REST app for each.
`accounts.toml.example` is the template; by default it is `accounts.toml` in the state directory.
It holds client secrets, so `app:validate` fails it at anything wider than 0600, or root-owned
and 0640 for a group.

```toml
[acme]
name = "Acme Example Pty Ltd"
environment = "live"
client_id = "..."
secret = "..."
# daily_budget = 500
```

The table name is the key every tool takes. `environment` is required: `live` or `sandbox`.

**The state directory** is created 0700 on first use. It holds cached PayPal tokens, each
account's request count, and the months of transactions already read, payers' names and emails
among them. Set it absolutely: a compiled binary otherwise uses the working directory, which the
MCP client chooses.

```bash
paypalmcp app:config       # what is configured, and the .env actually read
paypalmcp app:validate     # checks it, and asks PayPal for what the tools need
```

`app:validate` sends four requests to each account, counted against its budget. `--offline`
skips them; `--strict` exits 2 on a warning.

| | all fine | warnings only | any failure |
|---|---|---|---|
| `app:validate` | 0 | 0 | 1 |
| `app:validate --strict` | 0 | 2 | 1 |

1 means failed, with the flag or without it: not the monitoring-plugin numbering, where 1 is a
warning.

## REST apps

**Give each account its own app, made for this server alone**, from the Apps & Credentials page
of PayPal's developer dashboard, so it can be revoked without touching anything else and its
token cache is its own.

**Tick Transaction Search and Subscriptions on it, and nothing else.** Every transaction and
balance tool needs Transaction Search; `get-subscription` needs Subscriptions, which Transaction
Search does not grant. `account-access` and `app:validate` read from the token whether each is
there.

**A change to an existing app's features waits for its token to expire**, up to nine hours:
PayPal hands back the same token until then, so forgetting the cached one does not help. A new
app's first token has its features at once.

**An app's token can do more than its ticked features say.** With only those two ticked, a sandbox
app's token still carried refund and payment scopes (2026-11-21): PayPal has no read-only app. What keeps this server read-only is that it has no code that writes; see *Safety*.

## Registering the server

With Claude Code:

```bash
claude mcp add paypal -- paypalmcp mcp:start paypal
```

Any MCP client that starts a stdio server works the same way: the command is
`paypalmcp mcp:start paypal`. If the environment file is not in one of the places looked for, set
`PAYPALMCP_ENV` in the environment the client starts it with.

## Tools

| tool | answers | costs |
|---|---|---|
| `list-accounts` | the configured accounts and their budgets | nothing |
| `account-access` | whether the app is accepted, and has the features the tools need | a token, or nothing |
| `search-transactions` | rows in a range, by event code, currency or status | about 1 a month |
| `find-payer` | rows whose payer matches an email, account id or name | about 1 a month |
| `transaction-totals` | count, gross, fees and net by currency and event code | about 1 a month |
| `get-transaction` | one transaction, with its conversion rows | 1 or 2 with a date, 1 a month without |
| `balances` | each currency's balance, as of PayPal's last refresh | 1 |
| `get-subscription` | a subscription's state and its recent payments | 2 |

A call costs one more when no token is cached. Every answer carries `budget`: what the call sent
and what is left.

### Coverage

**PayPal's reporting lags, and it cuts a search back to its last refresh without saying so.** A
payment takes up to three hours to appear. So every answer built from transaction history carries
`coverage`: the range asked for, the range actually read, and a sentence saying why they differ.

### Months

**PayPal searches at most 31 days at a time, keeps three years, and cannot search by person.** So
the server reads history one UTC calendar month at a time, newest first, and filters here. A month
that ended more than three days ago is kept for 30 days, so asking about it again costs nothing.
`max_requests` caps a call; when it runs out the answer says how far back it reached.

### Payers

**A row goes out with the payer's name, PayPal login email, PayPal account id and country.** Their
phone number and every postal address are withheld, from answers and from the cache. The email is
the payer's PayPal login, which is often not the address they gave the seller; `custom_field` is
the seller's own reference where one was set.

## Safety

**Read-only in the code, because the credential is not.** paypal-api wraps no operation that moves
money or changes an account, and this server calls nothing else and has no tool that sends a raw
request.

**Each account has a budget over any 24 hours**, 500 requests unless set otherwise, kept in the
state directory and shared by every server process. PayPal publishes no rate limit; the budget
bounds what one conversation can send. A call sets aside the most it might send before it starts;
one that does not fit is refused without asking PayPal. Every request crosses one transport that
counts it, and a request from anywhere else is refused.

## Development

```bash
composer check            # phpstan, then pest
composer build            # the release phar, into builds/
```

## Licence

MIT. See [LICENSE](LICENSE).
