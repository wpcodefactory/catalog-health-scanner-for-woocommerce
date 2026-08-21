=== WPFactory Catalog Health Scanner for WooCommerce ===
Contributors: wpcodefactory
Tags: woocommerce, products, audit, catalog, inventory
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.0.0
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Scan your WooCommerce catalog and find the products that are silently costing you sales.

== Description ==

Every store accumulates broken product data through normal operation: imports that map columns incorrectly, platform migrations, bulk edits applied to the wrong selection, plugins installed and removed. None of it produces an error message. The products just quietly stop working.

Catalog Health Scanner checks every product and variation against a library of 70 checks and turns each finding into plain language: "this product cannot be bought right now", "you lose money on every unit sold", "customers paid and got a broken download".

**Highlights**

* **70 checks across 10 categories**, from unpurchasable variations and fake discounts to duplicate SKUs, broken downloads, price outliers, feed readiness, and AI-visibility gaps (missing structured data, placeholder content, duplicate descriptions, missing attributes, alt text, reviews, and FAQ content).
* **Health score** out of 100, per category and overall, with a trend line across scans.
* **Applicability engine**: check groups that don't apply to your store (weights on a flat-rate store, GTINs without feeds) are auto-detected, skipped, and excluded from the score — with the reasoning shown and overridable. Changing applicability recalculates historic scores so the trend stays comparable.
* **Scan profiles**: Revenue Blockers, Pre-launch, Post-migration, Feed Readiness, Inventory Audit, Full Scan, plus custom profiles you can edit and duplicate.
* **Safe, previewed fixes**: every fix shows the exact before/after per product first, is reversible with one-click undo, and is logged.
* **Permanent per-product ignore** for false positives, with a reviewable ignore list — ignore selected, ignore all matching, restore all.
* **Multi-select everywhere**: tick individual products (shift-click for ranges), select the whole page, or act on every matching issue at once.
* **CSV export** of any filtered issue list.
* Sortable issue-count column on the products list and a Catalog Health panel on the product edit screen.
* No frontend output, no cart or checkout involvement, no third-party services.

= Free vs Pro =

The free version scans everything and hides nothing: all 70 checks, every profile, the full dashboard, the health score and trend, ignore/restore, CSV export, and fixing products one at a time — each fix previewed and undoable.

[WPFactory Catalog Health Scanner for WooCommerce Pro](https://wpfactory.com/item/wpfactory-catalog-health-scanner-for-woocommerce/) unlocks the automation on top:

* **Bulk fixing** — apply a previewed fix to every affected product in one click, including "Fix all quick wins" from the dashboard.
* **Scheduled scans** with an email digest that only arrives when there is something new, plus immediate critical alerts.
* **Auto-fix after scheduled scans** for the unambiguous, fully reversible quick wins.
* **PDF audit report** with a styled cover and full findings breakdown, white-labeled with your agency name and logo.
* **Scan comparison** — exactly what was fixed, what regressed, and what is new between any two scans.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen.
3. Answer the six setup questions (all pre-answered from your store's configuration).
4. Run your first scan from "Catalog Health".

== Frequently Asked Questions ==

= Will a fix change my products without asking? =

No. Every fix shows you the exact before and after value for every product it would touch, before anything is written. Applied fixes are logged and can be undone in one click for 30 days (configurable). Nothing runs unattended unless you explicitly enable auto-fix after scheduled scans (Pro), and even that is restricted to fully reversible fixes and logged the same way.

= A check flagged something that is intentional in my store. =

Ignore it. Ignored issues stop counting against your score and stay out of future scans for that product, and the Settings screen keeps a reviewable list you can restore from at any time. Whole check groups that don't apply to your store (weights on a flat-rate store, for example) are detected automatically and can also be toggled manually.

= Can I rescan only what changed? =

Yes. After your first scan the dashboard offers a changes-only rescan, labelled with the number of products edited since the last scan. It visits just those products, which on a large catalog takes seconds rather than minutes. Because it deliberately skips the rest of the catalog, its score is marked as partial and the products it did not visit keep their existing findings.

= Does the plugin slow down my storefront? =

No. It has no frontend output and hooks nothing into the cart or checkout. Scans run in the admin (or via cron in Pro) in small batches with pause and resume, so even large catalogs scan without timeouts.

= Does it send my data anywhere? =

No. Scanning, scoring, fixes, and reports all run entirely on your own server. There are no third-party services and no phone-home.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes, compatibility is declared and tested with WooCommerce's custom order tables.

= What exactly does Pro add? =

Bulk fixing (apply a previewed fix to all affected products at once), scheduled scans with an email digest and critical alerts, optional auto-fix after scheduled scans, the white-label PDF audit report, and scan comparison. Everything else — all 70 checks included — is in the free version, and Pro features are visible (locked) in the free UI so you can see what you would get.

== Screenshots ==

1. Dashboard: health score, trend, category scorecard, and the critical issues banner after a scan.
2. Purchasability: an issue group expanded to the affected products, with multi-select, fix, and ignore actions.
3. Content: findings sorted by severity, including structured data and AI-visibility checks.
4. Shipping: missing weight and dimension findings with per-product actions.
5. Scan profiles: built-in audits plus a custom profile builder with every check listed.
6. Settings: applicability per check group, with what auto-detect currently says about your store.
7. History: every scan with score, duration, and PDF report, plus scan comparison.
8. Setup wizard: a few store questions, each pre-answered from your actual configuration.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Changelog ==

= 1.0.0 - 01/08/2026 =
* Initial release.
* Dev - 70 checks across 10 categories.
* Dev - Applicability auto-detection with setup wizard; historic score recalculation on configuration change.
* Dev - Batch scanner with pause/resume.
* Dev - Previewable, reversible, logged bulk fixes.
* Dev - Scheduled scans, email digest, immediate critical alerts.
* Dev - Scan comparison view (fixed / regressed / new).
* Dev - PDF audit report with white-label branding; CSV export.
* Dev - List filters (severity, category, type, new since last scan); sortable products-list column.
* Dev - Multi-select with shift-click ranges; bulk ignore (selection or all matching) and bulk restore.
* Dev - Critical alert deep-links to the category the criticals are actually in, with a per-category breakdown.
* Dev - Every screen (dashboard, categories, history, profiles, settings, setup) shares one page shell and tab bar.
* Dev - PDF report logo accepts PNG, WebP, and GIF; non-JPEG sources are transcoded through GD.
* Dev - Applicability groups registered for product reviews and FAQ content.
* Fix - Products skipped by the grace period were counted as scanned, so the first scan after an import could report a healthy score having checked nothing. Skipped products are now counted separately, declared on the dashboard, and the grace period defaults to off.
* Fix - A scan that skipped any product no longer auto-resolves issues, so a skipped product is never reported as fixed.
* Fix - `tax_class_invalid` and `shipping_class_deleted` could never fire, because WooCommerce normalises both values away when it reads a product. Both now read the underlying data.
* Fix - The title-encoding repair used ISO-8859-1 where mojibake originates in Windows-1252, so "Fix all" reported nothing to do on titles the check had flagged. It now peels up to three encoding layers.
* Fix - The variation-SKU fix checked the parent's SKU rather than the variations', and never did anything.
* Fix - Undo for stock-status and tax-class fixes silently failed, because WooCommerce re-normalises those fields on save.
* Fix - Ignored issues are now declared next to the score and on the PDF cover, instead of a fully-ignored catalog reading as 100% healthy.
* Dev - Issue groups without an automatic fix now name their action ("View 7 products") instead of a generic "Review".
* Dev - Free/Pro split: bulk fixing, fix-all quick wins, scheduled scans + digest + alerts + auto-fix, the PDF report and white label, and scan comparison are Pro; the free build shows each of them locked in place with an upgrade path, enforced server-side.
* WC tested up to: 10.9.
