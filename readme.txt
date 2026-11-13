=== Payment Matcher for Fio Bank and WooCommerce ===
Contributors: jakubkasparek
Tags: woocommerce, fio, bank transfer, payment, qr
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Marks WooCommerce bank-transfer orders as paid automatically using the Fio banka API. Manual assignment, reminders, QR payment code.

== Description ==

Payment Matcher for Fio Bank downloads incoming payments from your Fio banka account every 15 minutes and matches them to WooCommerce orders by variable symbol (order number). When the amount is right, the order moves to Processing and the customer receives the usual WooCommerce e-mail.

= What it does =

* **Automatic matching** by variable symbol – also finds the order number in the message for recipient and ignores leading zeros. Works with sequential order number plugins.
* **Live status on the thank-you page** – “Waiting for your bank transfer” switches to “Payment received” by itself, usually within a minute.
* **Refunds** – an outgoing payment with the order’s variable symbol is recorded on the order, only when money for that order actually arrived.
* **Underpayments and overpayments** – partial payments add up; you get an e-mail for anything that needs attention.
* **Observing mode** – start safely: the plugin only lists what it would do, changes nothing.
* **Manual assignment** – assign any payment to an order with one click, with a suggestion when the variable symbol is missing (same amount and sender name).
* **Restore and mark paid** – when money arrives for an order that was already cancelled.
* **Payment box in the order** – all payments for the order and how long it has been waiting.
* **Token health alerts** – an e-mail and an admin notice when the Fio token expires or checks stop running.
* **Daily summary** e-mail at 7:00.
* **Payment reminder** – a standard WooCommerce e-mail sent to the customer after a set number of days without payment.
* **Token expiry reminder** – set the token’s expiry date and get an e-mail and an admin notice 14 days before.
* **Personal data** – privacy policy text, WordPress personal data export and erasure, automatic deletion of old payments (12 months by default).
* **QR payment (QR Platba)** – optional QR code on the thank-you page and in order e-mails. Generated on your server, no external service.

= Requirements =

* A Fio banka account and a read-only API token (Fio internet banking → Settings → API → Add token, type “Account monitoring”).
* WooCommerce 8.0+, HPOS compatible.
* The PHP GD extension for the QR code image in e-mails (the thank-you page uses SVG and works without it).

= External services =

This plugin connects to the **Fio banka API** (`https://fioapi.fio.cz/`) to read the movements of your own account, using the token you enter. The request is made from your server every 15 minutes and when you click “Check now”. No other data is sent.

* Fio banka API documentation: https://www.fio.cz/bankovni-sluzby/api-bankovnictvi
* Terms of use (electronic account management): https://www.fio.cz/docs/cz/VSP_IB.pdf
* Privacy policy (Informační memorandum): https://www.fio.cz/docs/cz/fio_informacni_memorandum.pdf

The plugin is not affiliated with or endorsed by Fio banka, a.s.

== Installation ==

1. Upload the plugin and activate it.
2. Go to **WooCommerce → Fio payments → Settings** and paste your Fio API token.
3. Leave the mode on **Observing only** for a few days and check the results.
4. Switch to **Live**.

You can also define the token in `wp-config.php`: `define( 'PMFIO_FIO_TOKEN', '…' );`

== Frequently Asked Questions ==

= How do I get the Fio API token? =

1. Log in to Fio internet banking (ib.fio.cz) and open Settings → API.
2. Choose the account that receives payments for orders and click “Add new token”. Name it, e.g. after the website.
3. Choose the type “Account monitoring only” (read-only).
4. Set the validity to 180 days and turn on automatic renewal – every login to internet banking then extends it.
5. Confirm with SMS or Smartbanking. The token works about 5 minutes later.
6. Click “Show full token” and copy all 64 characters to WooCommerce → Fio payments → Settings.

The same guide is shown in the plugin settings under “How to get the token”. Fio’s official guide with a screenshot of the API settings is on pages 4–5 of https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf (in Czech).

= Can I share the token with another Fio plugin? =

It works, because both only read the account, but Fio allows one request per 30 seconds per token. When they collide, one check is skipped and the next one catches up; the plugin does not raise an alert for that. A separate token per plugin and per website is cleaner.

= What happens to payments seen in observing mode when I switch to live? =

They are marked as “seen” and are not processed again, so you do not get a burst of e-mails for orders you already handled by hand.

= Does it send the payment reminder to old orders? =

No. Only orders created after you switched to live mode get a reminder, and each order gets at most one.

== Screenshots ==

1. Payments: overview, filters, manual assignment with a suggested order.
2. Settings: connection, matching, notifications and personal data.
3. QR payment settings with a live preview.
4. Thank-you page: live payment status and the QR code.
5. Order screen: payments from Fio for the order.

== Hooks ==

* `pmfio_payment_matched` ( WC_Order $order, string $result, array $movement ) – an incoming payment was assigned to an order.
* `pmfio_refund_matched` ( WC_Order $order, float $amount, array $movement ) – an outgoing payment was recorded as a refund.
* `pmfio_status_poll_interval` – how often the thank-you page asks for the status, in milliseconds (default 15000).
* `pmfio_order_number_meta_key`, `pmfio_find_order` – customise how the variable symbol is matched to an order.

== Changelog ==

= 1.5.0 =
* New: live payment status on the thank-you page.
* New: refunds (outgoing payments) are recorded on the order. Other outgoing payments are stored without personal data and hidden.
* New: actions pmfio_payment_matched and pmfio_refund_matched for integrations.

= 1.4.0 =
* New: token expiry date with an e-mail and admin notice 14 days ahead.
* New: privacy policy text, personal data exporter and eraser, automatic deletion of old payments.

= 1.3.0 =
* Renamed to Payment Matcher for Fio Bank and WooCommerce (slug payment-matcher-for-fio-bank, prefix pmfio).
* Stricter input sanitizing and output escaping.

= 1.2.0 =
* New: QR payment code (QR Platba) on the thank-you page and in order e-mails.
* New: redesigned admin page with overview, filters and tabs.
* Translations: English source strings, Czech translation included.

= 1.1.0 =
* New: manual assignment, suggestions without variable symbol, restore cancelled orders, order meta box, health alerts, daily summary, payment reminder.

= 1.0.0 =
* First version.
