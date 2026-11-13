# Tonyapps_AbandonedCart — notes for AI agents and developers

Read this before changing the module. Workspace-wide rules are in ../AGENTS.md.

## Status

- Built 2026-10-23; package `tonyapps/module-abandoned-cart` 0.1.0.
- 2026-10-30 review fixes done; 2026-11-09 email redesign, Promotion Block and guest-email capture done; 2026-11-11 default template ID renamed for the Email Designer (see Change history).
- Scope rule: core already keeps the cart (`quote`) and lists abandoned carts under **Reports > Marketing > Abandoned Carts** but never emails anyone. This module adds the emails and nothing else.

## Architecture

| Feature | How |
|---|---|
| Who qualifies | Active quote with items and an email. Guests get an email on the quote once they type it at checkout (see Guest email below). |
| Schedule | `Send After (hours)`, e.g. `1,24,72` sends up to three reminders, each counted from the last cart change (`quote.updated_at`). If cron was down and several are overdue, only the latest one is sent (`Model\ReminderSchedule::dueStep()`; `parseDelays()` drops junk/duplicates/non-positive values and sorts). |
| Restore link | `abandonedcart/cart/restore/token/…` (`Controller\Cart\Restore`). GET changes nothing: the page (`restore.phtml`) POSTs itself back straight away (script, with a button fallback), so shoppers land in their cart while mail scanners, which don't run scripts, can't apply the coupon or reset the reminder schedule. The POST carries a form key and goes through core's CSRF check. Guest carts go straight back into the session (`replaceQuote`) with the coupon applied. Customer carts ask the shopper to sign in (before-auth URL = the restore link, so they come back to it); an email link never logs anyone in. Once the cart's owner is signed in, the coupon is applied to their cart. A signed-in shopper who opens a guest cart's link gets a notice instead of a cart swap. |
| Coupon | Optional. One code per cart from a cart price rule with *Use Auto Generation* ticked (`Model\Config\Source\CouponRule` lists only those), added to reminder #N (default 3) or any later one (`>=`). One coupon per email: other carts with the same email reuse that code. Expired/used-up codes are logged at info on restore; the cart is still restored. |
| Unsubscribe | `abandonedcart/cart/unsubscribe/token/…` (`Controller\Cart\Unsubscribe`, `CsrfAwareActionInterface`) shows a confirm button (GET changes nothing); the POST opts that address out of all future reminders. Only this POST accepts no form key, for RFC 8058 one-click. Emails carry `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click`. Recovered rows keep their status. |
| Recovery | `Observer\MarkRecovered` on `sales_model_service_quote_submit_success` marks a reminded cart `recovered`. |
| Already ordered | Carts whose email placed an order after the cart last changed (e.g. from another cart) are not reminded. |
| Opt-out check | Candidate selection skips emails in `tonyapps_abandonedcart_optout`. |

- Cron: `tonyapps_abandonedcart_reminders` (`Model\ReminderSender::send`), `*/15 * * * *`, group `default`, up to 500 carts per run (`BATCH_SIZE`). Each send runs in frontend store emulation; a failure is logged per quote and the run continues.
- Candidate query: `Model\ResourceModel\Reminder::getCandidates()`.
- Email items: `items` (array of cards), FreeGift lines (item option `tonyapps_free_gift`) say *Free gift* instead of $0.00.
- Guest email: `Plugin\SaveGuestEmailOnCart`, after-plugin on `Magento\Customer\Api\AccountManagementInterface::isEmailAvailable`, declared in `etc/webapi_rest/di.xml` only.
- Layout/templates: `abandonedcart_cart_restore.xml` + `restore.phtml`, `abandonedcart_cart_unsubscribe.xml` + `unsubscribe.phtml`, email `view/frontend/email/reminder.html`.
- Data patch `Setup\Patch\Data\CopyUnsubscribedEmails` copies existing status-2 emails into the opt-out table.
- Soft link to Tonyapps_FreeGift (item option name only, no dependency).

### Guest email (2026-11-09)

Luma's checkout keeps the guest's email in the browser until the order is placed, so a guest cart had no email and was never reminded. `Plugin\SaveGuestEmailOnCart` saves it: checkout always calls `POST /V1/customers/isEmailAvailable` once the address is valid, even with *Enable Guest Checkout Login* off, and that request carries the shopper's session. Only the session's own active guest cart with items is updated, only where reminders are enabled, and the answer of the email check is never changed. No extra JavaScript or request. Privacy: the email is stored before the order, which the store's privacy notice should mention.

### Email template (2026-11-09 redesign)

Card per cart line (product image, name linking to the product, qty, row total; Tonyapps_FreeGift lines say *Free gift*), cart subtotal, optional Promotion Block box, coupon in a highlighted box, **Return to your cart** button, footer with "Stop cart reminders".

Template variables: `items`, `subtotal`, `storeName`, `promoBlockId`, `couponCode`, `restoreUrl`, `unsubscribeUrl`. `itemsHtml` is still passed for templates customised in the admin before the redesign.

**Promotion Block** (Email group, per store view): a CMS block from **Content > Blocks** (`Model\Config\Source\PromoBlock`), shown in a box between the cart and the coupon, e.g. a current promotion with a banner and a link. Edited with the WYSIWYG editor and rendered with `{{block id=$promoBlockId}}`; empty means no box.

## Data model

- `tonyapps_abandonedcart_reminder`: one row per reminded quote. Columns `entity_id`, `quote_id` (unique, FK `quote.entity_id` ON DELETE CASCADE), `email`, `store_id`, `sent_count`, `status` (0 active, 1 recovered, 2 unsubscribed), `token` (unique, 64), `coupon_code`, `created_at`, `last_sent_at`, `recovered_at`; index (`email`, `status`).
- `tonyapps_abandonedcart_optout`: `email` (PK), `created_at`. Opt-outs are keyed by email because reminder rows are deleted with their quote by core's quote cleanup, so a status-2 row alone was forgotten after about 30 days.

## Configuration and ACL

Section `tonyapps_abandonedcart` (**Stores > Configuration > Sales > Abandoned Cart Reminders**), ACL `Tonyapps_AbandonedCart::config`.

| Path | Default | Scope |
|---|---|---|
| `general/enabled` | 0 (off) | store |
| `general/delays` | `1,24,72` | global |
| `general/max_age_days` | 7 | global. Keeps the first run after switching on from emailing every old cart. |
| `email/identity` | `sales` | store |
| `email/template` | `tonyapps_abandonedcart_email_template` | store |
| `email/promo_block` | empty | store |
| `coupon/rule_id` | 0 (none) | store. Rule's *Uses per Coupon* should be 1. |
| `coupon/step` | 3 | store. 1 = first reminder. |

Email template ID: `tonyapps_abandonedcart_email_template` (file `reminder.html`), formerly `tonyapps_abandonedcart_reminder`.

## Decisions and constraints

- An email link never logs anyone in; GET on restore/unsubscribe never changes state (mail scanners).
- Only the latest overdue reminder is sent after a cron outage.
- Batch ceiling: 500 carts per 15-minute run (48k/day); raise or page if a store outgrows it (`ponytail:` note in `ReminderSender`).
- `sales_order` has no `customer_email` index; the "already ordered" probe is bounded by the `created_at` index (`ponytail:` note in `ResourceModel\Reminder`).
- Delays and max age are global; the rest is per store.

## Known limitations and open points

- No admin screen for recovery numbers; use SQL (Operations).
- Guest email capture depends on checkout calling `isEmailAvailable` over REST (Luma does).
- Hyvä not checked.

## Tests

```sh
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Tonyapps/AbandonedCart/Test/Unit
```

Unit tests cover Restore, PromoBlock source, ReminderSchedule, ReminderSender, Reminder resource, SaveGuestEmailOnCart, CopyUnsubscribedEmails, and the reminder email / restore templates. Also covered elsewhere in the workspace: `../EmailDesigner/Test/Integration/Model/ModuleEmailsTest.php` (template setting) and Playwright `../e2e/tests/abandoned-cart.spec.js` (helper `../e2e/abandoned.php`).

## Operations

```sh
bin/magento module:enable Tonyapps_AbandonedCart
bin/magento setup:upgrade
```

Recovery numbers:

```sql
SELECT status, COUNT(*) FROM tonyapps_abandonedcart_reminder GROUP BY status;
```

## Change history

- **2026-11-13 coupon for customer carts**: the coupon was applied only to guest carts. The cart's owner now gets it too: signed in already, it is applied at once; signed out, the sign-in page sends them back to the restore link (before-auth URL), which applies it. Tests in `RestoreTest`.
- **2026-11-11 template ID rename**: the default template ID is `tonyapps_abandonedcart_email_template` (was `tonyapps_abandonedcart_reminder`). The template setting lists the default under an ID made from its config path, so the old ID made the setting fail with "Email template is not defined" and no other template could be picked (blocking Email Designer designs). Theme overrides keep working (they go by file name).
- **2026-11-09 guest email**: `Plugin\SaveGuestEmailOnCart` (see Architecture).
- **2026-11-09 email redesign**: product cards, subtotal, highlighted coupon, button; new variables `items`, `subtotal`, `storeName`; `itemsHtml` kept; Promotion Block setting. Screenshot in `docs/emails/reminder-with-coupon.png` (Luma, 2026-11-09).
- **2026-10-30 review fixes**:
  - Opt-outs stored by email in `tonyapps_abandonedcart_optout`, checked by candidate selection; a data patch copies existing status-2 emails.
  - Restore page's POST carries a form key and goes through core's CSRF check; only Unsubscribe still accepts a POST without a form key (RFC 8058 one-click).
  - Coupon added to the coupon reminder or any later one (`>=`), so a cron outage no longer skips it; one coupon per email, reused across that email's carts.
  - Carts whose email placed an order after the cart last changed are no longer reminded.
  - A signed-in shopper who opens a guest cart's link sees a notice instead of being sent to their own cart without one.
