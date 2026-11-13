# Builds languages/payment-matcher-for-fio-bank-cs_CZ.po from the POT and the dictionary below.
# Run: python3 languages/build-cs.py  (then wp i18n make-mo languages && wp i18n make-php languages)
import re
import os

HERE = os.path.dirname(os.path.abspath(__file__))

T = {
    'Downloads incoming payments from the Fio banka API and marks bank-transfer orders as paid by variable symbol. Manual assignment, payment reminders, daily summary, token health alerts and an optional QR payment code (Czech QR Platba).':
        'Stahuje příchozí platby přes API Fio banky a podle variabilního symbolu označí objednávky na převod jako zaplacené. Ruční přiřazení, připomínky platby, denní přehled, hlídání tokenu a volitelná QR platba.',
    'Settings': 'Nastavení',
    'Fio payments': 'Fio platby',
    'Paid': 'Zaplaceno',
    'Paid, overpayment': 'Zaplaceno, přeplatek',
    'Underpayment': 'Nedoplatek',
    'To review': 'Ke kontrole',
    'No order': 'Bez objednávky',
    'Would mark paid': 'Spároval bych',
    'Would mark paid (overpayment)': 'Spároval bych (přeplatek)',
    'Underpayment (observing)': 'Nedoplatek (jen sleduje)',
    'To review (observing)': 'Ke kontrole (jen sleduje)',
    'No order (observing)': 'Bez objednávky (jen sleduje)',
    'Seen while observing': 'Viděno při sledování',
    'You do not have permission to do this.': 'Na tuto akci nemáte oprávnění.',
    'The IBAN is not valid, nothing was saved.': 'IBAN není platný, nic se neuložilo.',
    'Saved.': 'Uloženo.',
    'Enter an order number.': 'Zadejte číslo objednávky.',
    'Fio payment matching is not working:': 'Párování plateb z Fio nefunguje:',
    'Open Fio payments': 'Otevřít Fio platby',
    ('%d day', '%d days'): ('%d den', '%d dny', '%d dní'),
    'No Fio payment yet.': 'Zatím žádná platba z Fio.',
    'Waiting for payment: %s': 'Čeká na platbu: %s',
    'Reminder sent %s': 'Připomínka odeslána %s',
    'Restore and mark paid': 'Obnovit a zaplatit',
    'Suggested: %s': 'Návrh: %s',
    'order no.': 'č. objednávky',
    'Order number': 'Číslo objednávky',
    'Assign': 'Přiřadit',
    'Payments': 'Platby',
    'QR payment': 'QR platba',
    'Live': 'Ostrý provoz',
    'Observing only': 'Jen sleduje',
    'Connect your Fio account': 'Připojte účet u Fio banky',
    'Create a read-only token in Fio internet banking (Settings → API → Add token, type “Account monitoring”, with automatic renewal) and paste it in Settings.':
        'V internetovém bankovnictví Fio vytvořte token jen pro čtení (Nastavení → API → Přidat token, typ „Sledování účtu“, s automatickým prodlužováním) a vložte ho do Nastavení.',
    'Open settings': 'Otevřít nastavení',
    'Last check': 'Poslední kontrola',
    'not yet': 'zatím ne',
    'next at %s': 'další v %s',
    'Check now': 'Zkontrolovat teď',
    'Paid in the last 7 days': 'Zaplaceno za 7 dní',
    ('%d payment', '%d payments'): ('%d platba', '%d platby', '%d plateb'),
    'last 30 days': 'za posledních 30 dní',
    'Waiting > 3 days': 'Čeká déle než 3 dny',
    'unpaid bank transfers': 'nezaplacené převody',
    'Observing only: payments are downloaded and listed below, but orders are not changed automatically and nothing is sent. Manual “Assign” always applies for real.':
        'Režim jen sleduje: platby se stahují a zapisují do tabulky níže, ale objednávky se samy nemění a nic se neposílá. Ruční „Přiřadit“ platí vždy naostro.',
    'All': 'Vše',
    'Date': 'Datum',
    'Amount': 'Částka',
    'VS': 'VS',
    'From': 'Od',
    'Order': 'Objednávka',
    'Result': 'Výsledek',
    'Actions': 'Akce',
    'No incoming payments yet.': 'Zatím žádné příchozí platby.',
    'Connection': 'Připojení',
    'Fio API token': 'Token Fio API',
    'Set in wp-config.php (constant PMFIO_FIO_TOKEN).': 'Nastaveno ve wp-config.php (konstanta PMFIO_FIO_TOKEN).',
    'saved – paste a new one to replace it': 'uložený – pro změnu vložte nový',
    'paste the token': 'vložte token',
    'Token saved.': 'Token je uložený.',
    'delete': 'smazat',
    'Fio internet banking → Settings → API → Add token, type “Account monitoring”, with automatic renewal. Each website needs its own token.':
        'Internetové bankovnictví Fio → Nastavení → API → Přidat token, typ „Sledování účtu“, se zapnutým automatickým prodlužováním. Každý web potřebuje vlastní token.',
    'Downloads and lists payments, changes nothing.': 'Stahuje a vypisuje platby, nic nemění.',
    'Paid orders move to “Processing” and the customer gets the usual e-mail.': 'Zaplacené objednávky přejdou do stavu „Zpracovává se“ a zákazník dostane běžný e-mail.',
    'Mode': 'Režim',
    'Matching': 'Párování',
    'Payment methods': 'Platební metody',
    'Comma-separated payment method IDs. Active: %s': 'ID platebních metod oddělená čárkou. Aktivní: %s',
    'Order statuses': 'Stavy objednávky',
    'Only orders in these statuses are marked as paid (without “wc-”).': 'Jako zaplacené se označí jen objednávky v těchto stavech (bez „wc-“).',
    'Order number meta key': 'Meta klíč čísla objednávky',
    'For sequential order number plugins (usually %s). If nothing is found, the order ID is tried.': 'Pro pluginy na pořadová čísla (obvykle %s). Když nic nenajde, zkusí se ID objednávky.',
    'Days to look back': 'Kolik dní zpět',
    'Notifications': 'Upozornění',
    'Notification e-mail': 'E-mail pro upozornění',
    'Gets underpayments, overpayments, payments to review and an alert when matching stops working.': 'Přijdou sem nedoplatky, přeplatky, platby ke kontrole a upozornění, když párování přestane fungovat.',
    'Daily summary': 'Denní přehled',
    'Every morning at 7:00 send payments from the last 24 hours and orders waiting for payment for more than 3 days':
        'Každé ráno v 7:00 poslat platby za posledních 24 hodin a objednávky, které čekají na platbu déle než 3 dny',
    'Payment reminder': 'Připomínka platby',
    'days after the order': 'dní po objednávce',
    'Live mode only, and only for orders created after switching to live. Turn on and edit the text in %s.':
        'Jen v ostrém provozu a jen u objednávek vytvořených po jeho zapnutí. Zapnutí a text e-mailu: %s.',
    'WooCommerce → Settings → Emails → Payment reminder': 'WooCommerce → Nastavení → E-maily → Připomínka platby',
    'Save settings': 'Uložit nastavení',
    'Order %s': 'Objednávka %s',
    'QR payment (QR Platba)': 'QR platba',
    'A QR code with the account, amount and variable symbol for unpaid bank-transfer orders. Czech and Slovak banking apps fill in the payment automatically. Generated on your server, no external service.':
        'QR kód s účtem, částkou a variabilním symbolem u nezaplacených objednávek na převod. České a slovenské bankovní aplikace platbu vyplní samy. Generuje se na vašem serveru, bez cizí služby.',
    'Thank-you page after the order': 'Děkovná stránka po objednávce',
    'Order e-mails to the customer (including the payment reminder)': 'E-maily objednávky zákazníkovi (včetně připomínky platby)',
    'Show QR code': 'Zobrazit QR kód',
    'Turn off if another plugin or your theme already shows a QR code, so customers do not see two.': 'Vypněte, pokud QR kód už ukazuje jiný plugin nebo šablona, ať zákazník nevidí dva.',
    'Empty = taken from the bank-transfer account in WooCommerce: %s': 'Prázdné = převezme se účet pro bankovní převod ve WooCommerce: %s',
    'Empty = taken from the bank-transfer account in WooCommerce (Settings → Payments → Direct bank transfer).': 'Prázdné = převezme se účet pro bankovní převod ve WooCommerce (Nastavení → Platby → Bankovní převod).',
    'IBAN': 'IBAN',
    'Message for recipient': 'Zpráva pro příjemce',
    'Up to 60 characters, without diacritics in the bank. You can use %s.': 'Nejvýš 60 znaků, v bance bez diakritiky. Lze použít %s.',
    'Preview': 'Náhled',
    'Sample order. Try scanning it in your banking app, but do not confirm the payment.': 'Ukázková objednávka. Zkuste kód naskenovat v bankovní aplikaci, platbu ale nepotvrzujte.',
    'No valid account. Fill in the IBAN or the bank-transfer account in WooCommerce.': 'Chybí platný účet. Vyplňte IBAN nebo účet pro bankovní převod ve WooCommerce.',
    'Save QR settings': 'Uložit nastavení QR',
    'The Fio API token is missing.': 'Chybí token Fio API.',
    'Fio allows one request per 30 seconds, please try again in a moment.': 'Fio dovolí jeden dotaz za 30 sekund, zkuste to prosím za chvíli.',
    'Connection to Fio failed: %s': 'Spojení s Fio selhalo: %s',
    'Fio returned an unexpected response.': 'Fio vrátilo neočekávanou odpověď.',
    'Fio: requests too frequent (limit is one request per 30 seconds per token).': 'Fio: příliš časté dotazy (limit je jeden dotaz za 30 sekund na token).',
    'Fio: the token does not exist or has expired. Extend it or create a new one in Fio internet banking (Settings → API).': 'Fio: token neexistuje nebo vypršel. Prodlužte ho nebo vytvořte nový v internetovém bankovnictví (Nastavení → API).',
    'Fio: data older than 90 days requires authorisation in internet banking.': 'Fio: data starší 90 dní vyžadují autorizaci v internetovém bankovnictví.',
    'Fio: too many movements, reduce the number of days to look back.': 'Fio: příliš mnoho pohybů, snižte počet dní zpět.',
    'Fio: malformed request (404).': 'Fio: chybný dotaz (404).',
    'Fio: unexpected HTTP status %d.': 'Fio: neočekávaný HTTP stav %d.',
    'Sent to the customer when no payment arrives for a bank-transfer order within the set number of days (Payment Matcher for Fio Bank). The number of days is set in WooCommerce → Fio payments.':
        'Posílá se zákazníkovi, když k objednávce na převod do nastaveného počtu dní nedorazí platba (Fio párování plateb). Počet dní se nastavuje ve WooCommerce → Fio platby.',
    'Payment reminder for order {order_number}': 'Připomínka platby objednávky {order_number}',
    'Waiting for your payment': 'Čekáme na platbu',
    'Hello, we have not received the payment for your order {order_number} yet. If you have already paid, it may simply not have arrived yet – in that case please ignore this e-mail. Otherwise you will find the payment details below.':
        'Dobrý den, k Vaší objednávce {order_number} jsme zatím neobdrželi platbu. Pokud jste už platili, platba se možná ještě nepropsala – v tom případě tento e-mail prosím ignorujte. Jinak níže najdete údaje k platbě.',
    'Order:': 'Objednávka:',
    'Amount:': 'Částka:',
    'Introduction': 'Úvodní text',
    'The order and payment details are always shown below this text. You can use {order_number}.': 'Pod textem se vždy zobrazí objednávka a údaje k platbě. Lze použít {order_number}.',
    'Payment not found.': 'Platba nenalezena.',
    'This payment is already assigned to an order.': 'Platba už je přiřazená k objednávce.',
    'Order %s does not exist.': 'Objednávka %s neexistuje.',
    'Order %s is cancelled. Use “Restore and mark paid”.': 'Objednávka %s je zrušená. Použijte „Obnovit a zaplatit“.',
    'Order %1$s cannot be marked as paid (status “%2$s”, payment method %3$s, currency %4$s).': 'Objednávku %1$s nejde označit jako zaplacenou (stav „%2$s“, platební metoda %3$s, měna %4$s).',
    '(assigned manually by %s)': '(přiřadil ručně %s)',
    'Payment assigned to order %1$s: %2$s.': 'Platba přiřazena k objednávce %1$s: %2$s.',
    'This order cannot be restored.': 'Objednávku nejde obnovit.',
    'Restored, the payment arrived after the order was cancelled (%s).': 'Obnoveno, platba přišla po zrušení objednávky (%s).',
    'paid': 'zaplaceno',
    'paid with overpayment': 'zaplaceno s přeplatkem',
    'partial payment': 'částečná platba',
    'Fio movement %1$s from %2$s, %3$s.': 'Fio pohyb %1$s ze dne %2$s, %3$s.',
    'unknown sender': 'neznámý odesílatel',
    'Bank transfer received: %1$s. %2$s': 'Platba převodem přišla: %1$s. %2$s',
    'Bank transfer received with an overpayment of %1$s: %2$s. %3$s': 'Platba převodem přišla s přeplatkem %1$s: %2$s. %3$s',
    'Overpayment on order': 'Přeplatek u objednávky',
    '%s more than the order total was received. The order is marked as paid.': 'Přišlo o %s víc, než je cena objednávky. Objednávka je označená jako zaplacená.',
    'Partial payment received: %1$s (%2$s of %3$s in total). %4$s': 'Přišla částečná platba %1$s (celkem %2$s z %3$s). %4$s',
    'Underpayment on order': 'Nedoplatek u objednávky',
    'So far %1$s of %2$s has been received. The order status has not changed.': 'Zatím přišlo %1$s z %2$s. Stav objednávky se nezměnil.',
    'A payment of %1$s arrived, but the order is “%2$s” or uses another payment method or currency, so nothing was changed. %3$s':
        'Přišla platba %1$s, ale objednávka je ve stavu „%2$s“ nebo má jinou platební metodu či měnu, proto se nic nezměnilo. %3$s',
    'You can restore the order with “Restore and mark paid” on the Fio payments page.': 'Objednávku můžete obnovit tlačítkem „Obnovit a zaplatit“ na stránce Fio platby.',
    'Payment to review': 'Platba ke kontrole',
    'A payment arrived for an order that cannot be marked as paid automatically (status “%1$s”, payment method %2$s). Please check it manually.':
        'Přišla platba k objednávce, kterou nejde automaticky označit jako zaplacenou (stav „%1$s“, platební metoda %2$s). Zkontrolujte ji prosím ručně.',
    'Payment:': 'Platba:',
    'From:': 'Od:',
    'Message:': 'Zpráva:',
    'All Fio payments': 'Všechny platby z Fio',
    'Pay by QR code': 'Zaplaťte QR kódem',
    'Scan the code in your banking app, the amount and variable symbol will fill in automatically.': 'Naskenujte kód v bankovní aplikaci, částka i variabilní symbol se vyplní samy.',
    '%1$s · variable symbol %2$s': '%1$s · variabilní symbol %2$s',
    'Downloaded %1$d movements, %2$d new.': 'Staženo pohybů: %1$d, nových: %2$d.',
    'Reminders sent: %d.': 'Odeslané připomínky: %d.',
    'No payment check has run since %s. The task scheduler (WP-Cron / Action Scheduler) is probably not running.': 'Kontrola plateb neproběhla od %s. Nejspíš neběží plánovač úloh (WP-Cron / Action Scheduler).',
    'No payment check has run yet.': 'Kontrola plateb zatím ani jednou neproběhla.',
    'Automatic Fio payment matching on %s is not working right now:': 'Automatické párování plateb z Fio na webu %s teď nefunguje:',
    'Until this is fixed, paid orders will not be marked automatically. If the token expired: Fio internet banking → Settings → API → extend the token (or create a new one and paste it on the Fio payments page).':
        'Dokud se to neopraví, zaplacené objednávky se samy neoznačí. Pokud vypršel token: internetové bankovnictví Fio → Nastavení → API → prodloužit token (nebo vytvořit nový a vložit ho na stránce Fio platby).',
    'Fio payment matching is not working': 'Párování plateb z Fio nefunguje',
    'order %s': 'objednávka %s',
    'suggestion: order %s': 'návrh: objednávka %s',
    'no order': 'bez objednávky',
    'Fio payments in the last 24 hours:': 'Platby z Fio za posledních 24 hodin:',
    'Matched and paid': 'Spárováno a zaplaceno',
    'Other incoming payments': 'Ostatní příchozí platby',
    'Waiting for payment for more than 3 days': 'Čekají na platbu déle než 3 dny',
    'Fio payments – summary %s': 'Platby z Fio – přehled %s',
    'Payment reminder sent to the customer.': 'Zákazníkovi odeslána připomínka platby.',
    'The Fio API token expired on %s.': 'Token Fio API vypršel %s.',
    'The Fio API token expires on %s.': 'Token Fio API vyprší %s.',
    'Log in to Fio internet banking to extend it (automatic renewal), or create a new token, then update the date.': 'Přihlaste se do internetového bankovnictví Fio, tím se token prodlouží (při automatickém prodlužování), nebo vytvořte nový a pak upravte datum.',
    'Token valid until': 'Token platí do',
    'Shown in Fio internet banking next to the token. Filled in as +180 days when you save a new token. You get an e-mail and an admin notice 14 days before it expires.': 'Najdete ho v internetovém bankovnictví Fio u tokenu. Při uložení nového tokenu se vyplní +180 dní. 14 dní předem přijde e-mail a upozornění v administraci.',
    'Personal data': 'Osobní údaje',
    'Payer name, account and message are stored with each payment. 0 = keep forever, otherwise at least 3 months. Customers’ requests are handled in %1$s and %2$s, a suggested text for the privacy policy is in %3$s.': 'U každé platby se ukládá jméno plátce, účet a zpráva. 0 = uchovávat napořád, jinak nejméně 3 měsíce. Žádosti zákazníků vyřídíte v nástrojích %1$s a %2$s, návrh textu do zásad ochrany osobních údajů najdete v %3$s.',
    'Export Personal Data': 'Export osobních údajů',
    'Erase Personal Data': 'Výmaz osobních údajů',
    'Privacy Policy Guide': 'průvodci zásadami ochrany osobních údajů',
    'Keep payments for': 'Uchovávat platby',
    'months': 'měsíců',
    'Suggested text for your privacy policy. Adjust it to how you actually use the data.': 'Navrhovaný text do zásad ochrany osobních údajů. Upravte ho podle toho, jak údaje skutečně používáte.',
    'Suggested text:': 'Navrhovaný text:',
    'When you pay by bank transfer, we receive from our bank (Fio banka, a.s.) the details of your payment: the amount, date, variable symbol, the sender’s name and account number and the message for recipient. We use them only to match the payment to your order and to deal with underpayments, overpayments and refunds.': 'Při platbě převodem nám naše banka (Fio banka, a.s.) předá údaje o platbě: částku, datum, variabilní symbol, jméno a číslo účtu odesílatele a zprávu pro příjemce. Používáme je jen ke spárování platby s objednávkou a k vyřízení nedoplatků, přeplatků a vrácení peněz.',
    ('We keep these details for %d month and then delete them automatically.', 'We keep these details for %d months and then delete them automatically.'): ('Tyto údaje uchováváme %d měsíc a pak je automaticky smažeme.', 'Tyto údaje uchováváme %d měsíce a pak je automaticky smažeme.', 'Tyto údaje uchováváme %d měsíců a pak je automaticky smažeme.'),
    'Bank transfers (Fio)': 'Platby převodem (Fio)',
    'Sender': 'Odesílatel',
    'Account': 'Účet',
    'Message': 'Zpráva',
    'Payer name, account and message were removed from Fio payments. Amounts and dates are kept for bookkeeping.': 'Z plateb Fio bylo odstraněno jméno plátce, účet a zpráva. Částky a data zůstávají kvůli účetnictví.',
    'The Fio API token used on %1$s expires on %2$s. After that, payments will no longer be matched automatically.': 'Token Fio API používaný na webu %1$s vyprší %2$s. Potom se platby přestanou automaticky párovat.',
    'If the token has automatic renewal, simply log in to Fio internet banking and it will be extended by 180 days. Otherwise extend it or create a new one in Settings → API. Then update the expiry date on the Fio payments settings page.': 'Pokud má token automatické prodlužování, stačí se přihlásit do internetového bankovnictví Fio a prodlouží se o 180 dní. Jinak ho prodlužte nebo vytvořte nový v Nastavení → API. Potom upravte datum platnosti v nastavení Fio plateb.',
    'Fio API token expires on %s': 'Token Fio API vyprší %s',
    'Refunded': 'Vráceno',
    'Refund (observing)': 'Vratka (jen sleduje)',
    'Refunded by bank transfer: %1$s (Fio movement %2$s from %3$s, to %4$s).': 'Vráceno převodem: %1$s (Fio pohyb %2$s ze dne %3$s, příjemce %4$s).',
    'unknown recipient': 'neznámý příjemce',
    'Payment received, thank you! We are processing your order.': 'Platba dorazila, děkujeme! Objednávku zpracováváme.',
    'Waiting for your bank transfer. This page updates by itself when the payment arrives.': 'Čekáme na Vaši platbu převodem. Jakmile dorazí, stránka se sama aktualizuje.',
    'Log in to Fio internet banking (ib.fio.cz).': 'Přihlaste se do internetového bankovnictví Fio (ib.fio.cz).',
    'Open Settings → API.': 'Otevřete Nastavení → API.',
    'Choose the account that receives payments for orders and click “Add new token”. Enter a name, e.g. the website address.': 'Vyberte účet, na který chodí platby za objednávky, a klikněte na „Přidat nový token“. Zadejte název, třeba adresu webu.',
    'Choose the type “Account monitoring only” (read-only). The plugin never needs to send payments.': 'Zvolte typ „Pouze sledovat účet“ (jen pro čtení). Plugin nikdy nepotřebuje platby odesílat.',
    'Set the validity to the maximum (180 days) and turn on automatic renewal – every login to internet banking then extends it.': 'Nastavte platnost na maximum (180 dní) a zapněte automatické prodlužování – každé přihlášení do bankovnictví ji pak prodlouží.',
    'Confirm with SMS or the Smartbanking app. The token works about 5 minutes after confirmation.': 'Potvrďte SMS nebo v aplikaci Smartbanking. Token začne fungovat asi 5 minut po potvrzení.',
    'Click “Show full token”, copy all 64 characters, paste them in the plugin settings and save.': 'Klikněte na „Zobrazit celý token“, zkopírujte všech 64 znaků, vložte je do nastavení pluginu a uložte.',
    'How to get the token': 'Jak získat token',
    'Keep the token private: anyone who has it can read the account’s movements. You can delete it in internet banking at any time.': 'Token nikomu neposílejte: kdo ho má, vidí pohyby na účtu. V internetovém bankovnictví ho můžete kdykoli smazat.',
    'Each website needs its own token.': 'Každý web potřebuje vlastní token.',
    'Official Fio guide with a screenshot (PDF, in Czech, pages 4–5)': 'Oficiální návod Fio se screenshotem (PDF, strany 4–5)',
    'Paid earlier': 'Zaplaceno dříve',
    'Paid earlier (observing)': 'Zaplaceno dříve (jen sleduje)',
    'Re-evaluation is only available while observing.': 'Znovu vyhodnotit jde jen v režimu jen sleduje.',
    'Evaluate again': 'Vyhodnotit znovu',
    'Choose at least one payment method and one order status, nothing was saved.': 'Vyberte aspoň jednu platební metodu a jeden stav objednávky, nic se neuložilo.',
    'turned off in WooCommerce': 've WooCommerce vypnutá',
    'not installed': 'není nainstalovaná',
    'Which orders to match': 'Které objednávky párovat',
    'Usually only bank transfer. The plugin marks an order as paid only if the customer chose one of these.': 'Obvykle jen platba převodem. Plugin označí objednávku jako zaplacenou, jen pokud zákazník zvolil jednu z vybraných metod.',
    'Order status': 'Stav objednávky',
    'Orders in these statuses are waiting for the money.': 'Objednávky v těchto stavech čekají na peníze.',
    'Advanced settings': 'Pokročilé nastavení',
    'Other statuses': 'Další stavy',
    'Only if your shop uses its own status for orders waiting for payment.': 'Jen pokud e-shop používá pro objednávky čekající na platbu vlastní stav.',
    'Where the order number is stored': 'Kde je uložené číslo objednávky',
    'Leave as it is. Only for plugins that give orders their own numbers (usually %s). The variable symbol is also compared with the order ID.': 'Nechte, jak je. Týká se jen pluginů, které objednávkám dávají vlastní čísla (obvykle %s). Variabilní symbol se porovnává i s ID objednávky.',
    'Look for payments from the last': 'Hledat platby za posledních',
    'days': 'dní',
    '3 days are enough for everyday use. More only when comparing with older payments.': 'Pro běžný provoz stačí 3 dny. Víc jen při porovnávání se staršími platbami.',
    'A new cron address was created. Update it in your hosting’s cron.': 'Vznikla nová adresa pro cron. Upravte ji v cronu hostingu.',
    'Last called by cron: %s': 'Cron naposledy zavolal: %s',
    'Not called by cron yet. Until then the WordPress scheduler runs the checks.': 'Cron zatím nevolal. Do té doby kontroly spouští plánovač WordPressu.',
    'Create a new address': 'Vytvořit novou adresu',
    'Automatic checks': 'Automatická kontrola',
    'Most reliable: let your hosting’s cron open this address every 15 minutes (e.g. Blueboard → Cron). The daily summary runs through it too. Without it, the WordPress scheduler is used, which runs only when someone visits the site. Keep the address private.': 'Nejspolehlivější je nechat cron hostingu otevírat tuto adresu každých 15 minut (např. Blueboard → Cron). Spouští i denní přehled. Bez něj se použije plánovač WordPressu, který běží jen při návštěvě webu. Adresu nikomu neposílejte.',
    'Automatic checks are not running – set up cron': 'Automatická kontrola neběží – nastavte cron',
    'runs from the server cron': 'běží přes cron serveru',
    'none yet': 'zatím žádné',
    'last 30 days – open the list': 'za 30 dní – otevřít seznam',
    'all fine': 'vše v pořádku',
    'Assign to %s': 'Přiřadit k %s',
    'Another order…': 'Jiná objednávka…',
    'Assign…': 'Přiřadit…',
}

NBSP = '\u00a0'


def typo(s):
    # Non-breaking space after one-letter Czech prepositions/conjunctions.
    s = re.sub(r'(?<![\w])([vkszouaiVKSZOUAI]) ', lambda m: m.group(1) + NBSP, s)
    # Number and the following word (14 dní, 3 měsíce, 180 dní) stay together.
    return re.sub(r'(?<=\d) (?=[^\W\d])', NBSP, s)


def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


pot = open(os.path.join(HERE, 'payment-matcher-for-fio-bank.pot'), encoding='utf-8').read()
blocks = pot.split('\n\n')
out = [
    'msgid ""\nmsgstr ""\n'
    '"Project-Id-Version: Payment Matcher for Fio Bank and WooCommerce\\n"\n'
    '"Language: cs_CZ\\n"\n'
    '"MIME-Version: 1.0\\n"\n'
    '"Content-Type: text/plain; charset=UTF-8\\n"\n'
    '"Content-Transfer-Encoding: 8bit\\n"\n'
    '"Plural-Forms: nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;\\n"\n'
    '"X-Domain: payment-matcher-for-fio-bank\\n"'
]
missing = []
for b in blocks[1:]:
    m = re.search(r'^msgid "((?:[^"\\]|\\.)*)"$', b, re.M)
    if not m:
        continue
    msgid = m.group(1).replace('\\"', '"').replace('\\\\', '\\')
    p = re.search(r'^msgid_plural "((?:[^"\\]|\\.)*)"$', b, re.M)
    head = '\n'.join(l for l in b.split('\n') if l.startswith('#'))
    if p:
        plural = p.group(1).replace('\\"', '"')
        tr = T.get((msgid, plural))
        if not tr:
            missing.append(msgid)
            continue
        body = 'msgid "%s"\nmsgid_plural "%s"\n' % (esc(msgid), esc(plural)) + '\n'.join('msgstr[%d] "%s"' % (i, esc(typo(t))) for i, t in enumerate(tr))
    else:
        tr = T.get(msgid)
        if tr is None:
            if msgid.startswith('http') or msgid in ('Jakub Kašpárek', 'Payment Matcher for Fio Bank and WooCommerce'):
                continue
            missing.append(msgid)
            continue
        body = 'msgid "%s"\nmsgstr "%s"' % (esc(msgid), esc(typo(tr)))
    out.append((head + '\n' if head else '') + body)

open(os.path.join(HERE, 'payment-matcher-for-fio-bank-cs_CZ.po'), 'w', encoding='utf-8').write('\n\n'.join(out) + '\n')
print('translated:', len(out) - 1, 'missing:', len(missing))
for x in missing:
    print('  MISSING:', x)
