# Payment Matcher for Fio Bank

Marks WooCommerce bank-transfer orders as paid automatically using the [Fio banka API](https://www.fio.cz/bankovni-sluzby/api-bankovnictvi). See [readme.txt](readme.txt) for the full feature list.

## Development

- Unit tests (no WordPress needed): `php tests/harness.php`
- Translations: `wp i18n make-pot . languages/payment-matcher-for-fio-bank.pot`, then `python3 languages/build-cs.py && python3 languages/build-sk.py` (add new strings to their dictionaries) and run `wp i18n make-mo languages && wp i18n make-php languages`.
- `tests/` is not part of the release ZIP.

## How it works

1. Every 15 minutes (Action Scheduler or the server cron address) the plugin downloads movements for the last N days of every connected account (one token per account) from `https://fioapi.fio.cz/v1/rest/periods/{token}/{from}/{to}/transactions.json`.
2. Each incoming movement is stored once (by Fio movement ID) in `{prefix}pmfio_movements`.
3. The variable symbol (or a number in the message) is matched to the order number.
4. Amount and currency are checked. Partial payments add up.

License: GPLv2 or later.
