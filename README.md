# Tonyapps_AbandonedCart

Reminder emails for carts left behind, with a one-click "return to your cart" link, an optional
single-use coupon, and recovery tracking.

Core already keeps the cart (`quote`) and lists abandoned carts under **Reports > Marketing > Abandoned Carts**,
but it never emails anyone. This module adds the emails and nothing else.

| Feature | How |
|---|---|
| Who qualifies | Active quote with items and an email. Guests get an email on the quote once they type it at checkout (OneStepCheckout saves it at the shipping step). |
| Schedule | `Send After (hours)`, e.g. `1,24,72` sends up to three reminders, each counted from the last cart change. If cron was down and several are overdue, only the latest one is sent. |
| Restore link | `abandonedcart/cart/restore/token/…`. Guest carts go straight back into the session, with the coupon applied. Customer carts ask the shopper to sign in; an email link never logs anyone in. |
| Coupon | Optional. One code per cart from a cart price rule with *Use Auto Generation* ticked, added to reminder #N (default: the 3rd). |
| Restore link | `abandonedcart/cart/restore/token/…` changes nothing on GET: the page POSTs itself back straight away (script, with a button fallback), so shoppers land in their cart as before while mail scanners, which don't run scripts, can't apply the coupon or reset the reminder schedule. |
| Unsubscribe | `abandonedcart/cart/unsubscribe/token/…` shows a confirm button (GET changes nothing, so mail scanners can't opt people out); the POST opts that address out of all future reminders. Emails carry `List-Unsubscribe` + `List-Unsubscribe-Post` for one-click unsubscribe in Gmail/Apple Mail. Recovered rows keep their status. |
| Recovery | Observer on `sales_model_service_quote_submit_success` marks a reminded cart `recovered`. |

State lives in `tonyapps_abandonedcart_reminder`, one row per reminded quote (status 0 active, 1 recovered, 2 unsubscribed).

## Cron

* `tonyapps_abandonedcart_reminders`: every 15 min, up to 500 carts per run.

## Configuration

**Stores > Configuration > Sales > Abandoned Cart Reminders**. **Off by default.** Enable, sender,
template and coupon can be set per store. The delays and max cart age are global.

`Ignore Carts Older Than` (default 7 days) keeps the first run after you switch the module on from
emailing every old cart in the database.

## Recovery numbers

There's no admin screen yet. Use SQL:

```sql
SELECT status, COUNT(*) FROM tonyapps_abandonedcart_reminder GROUP BY status;
```

## 2026-10-30 review fixes

- Opt-outs are stored by email in `tonyapps_abandonedcart_optout`, which candidate selection checks. Reminder rows are deleted with their quote by core's quote cleanup, so a status-2 row alone was forgotten after about 30 days. A data patch copies the existing status-2 emails.
- The Restore page's POST carries a form key and goes through core's CSRF check. Only Unsubscribe still accepts a POST without a form key, for RFC 8058 one-click.
- The coupon is added to the coupon reminder or any later one (`>=`), so a cron outage no longer skips it. An email gets one coupon: other carts with the same email reuse that code instead of generating another.
- Carts whose email placed an order after the cart last changed (from another cart, for example) are no longer reminded.
- A signed-in shopper who opens a guest cart's link sees a notice instead of being sent to their own cart without one.

## Install

```
bin/magento module:enable Tonyapps_AbandonedCart
bin/magento setup:upgrade
```

## Tests

```
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Tonyapps/AbandonedCart/Test/Unit
```
