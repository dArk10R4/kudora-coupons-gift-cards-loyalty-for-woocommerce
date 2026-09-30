=== Kudora – Coupons, Gift Cards & Loyalty for WooCommerce ===
Contributors: nihad1122
Tags: coupons, gift cards, loyalty points, discount rules, bogo
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart coupons and discount rules, gift cards and a loyalty program for WooCommerce. Every feature is free.

== Description ==

Kudora brings three things most stores end up buying separately — **discount rules & smart coupons**, **gift cards** and **loyalty points & rewards** — together in one plugin, with nothing locked behind a paid version. It works with the classic cart and checkout and with the Cart and Checkout blocks, and supports WooCommerce's High-Performance Order Storage.

Turn off any module you do not need under WooCommerce → Settings → Kudora.

= Smart coupons & discount rules =

* **Product discounts** — percent off, amount off or a fixed price for chosen products, categories or tags. Shown as sale prices in the shop and in the cart.
* **Bulk / tiered pricing** — quantity ranges (per line, per product or across products) with an optional pricing table on product pages.
* **Cart discounts** — percent or amount off, limited to products you choose, with an optional maximum.
* **Buy X get Y (BOGO)** — free, percent off or amount off; same or different products; repeat; cheapest or most expensive item; optional automatic "add the free product to the cart".
* **Bundle prices** — "any 3 for $25".
* **Free shipping rules**.
* **Conditions** for every rule and coupon: cart subtotal and quantity, products, categories, tags, coupons used, logged in, user role, email or domain, first order, number of orders, total spent, previously bought products or categories, billing/shipping country, day of the week, time of day, dates, loyalty tier and points balance.
* Priority, exclusive rules, schedules, usage limits, "not with coupons".
* **Promotion notices** — "Add $12.00 more to get free shipping!".
* **Smart coupons** on top of WooCommerce coupons: start date, weekday and time-of-day schedule, conditions, **auto-apply**, **coupon links** (`?coupon=CODE`), Buy X get Y coupons, free-product coupons, maximum discount for percentage coupons, status and times-used columns, and a usage history on each coupon.
* **Coupon generator** — create hundreds of unique codes from a template coupon (optionally one per email) and download them as CSV.

= Gift cards =

* Turn any simple or variable product into a gift card: preset amounts, **custom amounts** (min/max) or variations as denominations.
* Customers add the **recipient, sender, a message, a delivery date** and pick a **design**, with a live preview of the card.
* Codes are created only after payment and emailed to the recipient — immediately or on the chosen date — with a **PDF** attachment and a printable page with a **QR code**. The buyer is told when it was delivered.
* Customers redeem the code in a gift card field or simply in the **coupon field**, in the classic checkout and the Checkout block. **Partial use and several cards per order** are supported; the card pays after tax and can cover shipping.
* Balances are updated in one atomic database step, so the same money can never be spent twice.
* Cancelled or failed orders give the money back to the card; admins can **refund to the card** or issue **store credit** from the order screen.
* **Expiry dates**, restrictions to products or categories, **physical cards**, and payment methods allowed for buying gift cards. Gift cards never pay for gift cards.
* **Balance check** block and shortcode, and a **Gift cards** tab in My Account where customers can also save a card to their account.
* Admin: codes with balance, spent and expiry; full history of every card; manual and **bulk creation**; balance adjustments; **designs** (colour or image); **sales** report; outstanding balance; **CSV import and export**.

= Loyalty points & rewards =

* Points per currency spent, on products after or before discounts, or on the order total; points are given when the order is paid or completed.
* **Earning rules**: award extra points or multiply points for chosen products when conditions match.
* **Unlimited tiers** with badge colours and earning multipliers. Tiers can follow lifetime points (spending keeps the tier) or the current balance.
* Bonus points for **creating an account, the first order, product reviews (verified buyers) and birthdays**.
* **Pay with points** at checkout — the customer chooses how many, or the best discount is applied automatically — with a point value, a maximum discount, a minimum and a maximum number of points per order, and excluded products.
* **Rewards catalog**: customers turn points into a coupon for an amount off, a percentage (with a cap), free shipping or a **free product**, with tier requirements and per-customer limits.
* **Referral program**: every customer gets a link; friends get a first-order discount and the referrer gets points or a coupon. Self-referrals, repeat customers, small orders and more than a monthly cap are refused.
* **Points expiry** (oldest points are used first) with a reminder email.
* Refunds and cancellations take back earned points proportionally and return points that were spent.
* My Account "Points & rewards" page with the balance, tier progress ("collect 250 more points to become Gold"), history, rewards and referral link; a loyalty card block; "earn X points" on product, cart and checkout pages.
* Admin: customers and balances with manual adjustments, logs with filters, statistics (earned, redeemed, redemption rate, active customers) and CSV export, points import.

= Emails =

Gift card, gift card delivered, points earned, tier upgraded, points expiring, reward claimed and referral reward — all editable under WooCommerce → Settings → Emails, with templates you can override in your theme.

= Privacy =

Kudora registers a personal data exporter and eraser. Erasing removes names, emails, messages and birthdays but keeps gift card balances and points history, which are financial records.

== Installation ==

1. Install and activate WooCommerce.
2. Install Kudora from Plugins → Add New, or upload the plugin folder to `/wp-content/plugins/`, and activate it.
3. Go to WooCommerce → Settings → Kudora to choose the modules and settings.
4. Create discount rules under Marketing → Discount rules, gift card products by ticking "Gift card" on a product, and loyalty rules, tiers and rewards under Marketing → Loyalty.

== Frequently Asked Questions ==

= Is anything paid or limited? =

No. There is no paid version. Every feature described here is included.

= Does it work with the Cart and Checkout blocks? =

Yes. The gift card field, points, labels and promotion notices appear in the block cart and checkout, and all discounts use WooCommerce's own coupon and totals system, so they behave the same in the classic checkout, the blocks and the Store API.

= Are gift cards taxed? =

Gift card products are set to "not taxable" when you create them, because tax is charged when the card is spent. When a customer pays with a gift card it acts like a payment after tax, so it can also cover tax and shipping.

= Can a customer use a gift card and a coupon together? =

Yes. They can also use several gift cards and part of a card's balance. Discount rules keep working next to "individual use" coupons.

= What happens to gift cards and points if an order is cancelled or refunded? =

Money paid with a gift card goes back to the card when an order is cancelled or failed (and on full refunds, if you enable it). Points spent on a cancelled order come back; points earned on a refunded order are taken back proportionally. Gift cards bought in a cancelled order are disabled if they were not used yet.

= Where are the shortcodes? =

* `[kudora_gift_card_balance]` — gift card balance check (also a block).
* `[kudora_points]` — the customer's loyalty card (also a block).
* `[kudora_referral_link]` — the customer's referral link.

= Will I lose gift cards if I delete the plugin? =

Not unless you ask for it: data is kept on uninstall unless "Delete data on uninstall" is switched on in the settings.

== Screenshots ==

1. Gift card product page with amount, recipient, message, delivery date and design preview.
2. Block checkout with a gift card and loyalty points applied.
3. Discount rule editor with conditions.
4. Gift card admin with balances and history.
5. My Account → Points & rewards with tier progress and rewards.
6. Loyalty logs with statistics.

== Changelog ==

= 1.0.0 =
* First release: smart coupons & discount rules, gift cards, loyalty points, tiers, rewards and referrals.

== Third-party libraries ==

* tFPDF 1.33 by Ian Back and Tycho Veltmeijer — LGPL — used to create PDF gift cards (`includes/vendor/tfpdf`).
* DejaVu fonts — Bitstream Vera / DejaVu license — embedded in PDF gift cards (`includes/vendor/tfpdf/font/unifont`).
* QR Code generator by Kazuhiko Arase — MIT — used for QR codes on printable gift cards and PDFs (`includes/vendor/qrcode`).
