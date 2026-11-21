# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- An MCP server over stdio, `paypalmcp mcp:start paypal`, with read-only tools: `list-accounts`,
  `account-access`, `search-transactions`, `get-transaction`, `find-payer`, `transaction-totals`,
  `balances` and `get-subscription`.
- A request budget per account over any 24 hours, 500 unless the account sets its own, shared by
  every server process, enforced at the one transport every request crosses.
- Transaction history read a UTC calendar month at a time, newest first, with closed months kept
  for 30 days, and a `coverage` block on every answer saying how far PayPal's reporting reached.
- Payers shown by name, PayPal login email, account id and country; phone numbers and addresses
  withheld from answers and from the cache.
- `app:config` and `app:validate`, which checks each account's app has Transaction Search and
  Subscriptions, and asks PayPal for a search, the balances and a subscription read.
