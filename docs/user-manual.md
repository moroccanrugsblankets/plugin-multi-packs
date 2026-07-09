# WooCommerce Multi-Pack Wholesale Manager — User Manual

## Table of Contents

1. [Overview](#overview)
2. [Installation](#installation)
3. [Concepts](#concepts)
4. [Global Pack Settings](#global-pack-settings)
   - [Default Pack Groups](#default-pack-groups)
   - [Default Pack Tiers](#default-pack-tiers)
   - [Custom CSS / Custom JS](#custom-css--custom-js)
5. [Configuring a Pack Group](#configuring-a-pack-group)
   - [Group Title](#group-title)
   - [Packaging Field (Conditioning)](#packaging-field-conditioning)
   - [Units / Pack](#units--pack)
   - [Mode: BOGO vs Fixed Price](#mode-bogo-vs-fixed-price)
6. [Per-Product Pack Configuration](#per-product-pack-configuration)
   - [Inheriting Global Settings](#inheriting-global-settings)
   - [Overriding Global Settings](#overriding-global-settings)
   - [Disabling Pack Tables for a Product](#disabling-pack-tables-for-a-product)
7. [How the Storefront Looks](#how-the-storefront-looks)
8. [Cart Behaviour](#cart-behaviour)
9. [Pricing Logic Reference](#pricing-logic-reference)
10. [Examples](#examples)
    - [Example A — Lot of 6 (BOGO 5+1)](#example-a--lot-of-6-bogo-51)
    - [Example B — Lot of 12 (BOGO 10+2)](#example-b--lot-of-12-bogo-102)
    - [Example C — Fixed-price bundle](#example-c--fixed-price-bundle)
11. [Troubleshooting](#troubleshooting)

---

## Overview

**WooCommerce Multi-Pack Wholesale Manager** lets you display configurable wholesale pack tables on WooCommerce product pages. Customers can choose how many packs to order and add them directly to the cart, with automatic BOGO (Buy-X-Get-Y-Free) or fixed-price discounts applied.

Key features:

- **Global pack groups** that apply to every product on the site by default.
- **Per-product override** — configure custom pack groups on any individual product.
- **Disable switch** to hide the pack table on specific products.
- **Two pricing modes**: BOGO discount or a flat fixed price per pack.
- Per-unit price displayed under each total for instant cost comparison.

---

## Installation

1. Upload the `plugin-multi-packs` folder to `/wp-content/plugins/`.
2. Activate the plugin from **WP Admin → Plugins**.
3. WooCommerce must be installed and activated (it is a hard requirement).

---

## Concepts

| Term | Meaning |
|---|---|
| **Pack group** | A titled section on the product page (e.g. "Buy a pack of 6"). Each group can have multiple pack lines (one per product variant/weight). |
| **Pack line** | A single row in a group: one specific conditioning (e.g. "190gr × 6") with its own pricing rule. |
| **Conditioning / Packaging** | The combination of the product variant weight and the pack quantity, e.g. *190 gr × 6*. |
| **BOGO** | Buy X, Get Y Free. The customer physically receives `buy + free` units but only pays for `buy` units. |
| **Fixed price** | A flat total price for the whole pack, regardless of the unit price. |
| **Global groups** | Pack groups stored in the plugin settings page and shown on **all** products (unless overridden or disabled per product). |

---

## Global Pack Settings

Navigate to **WooCommerce → Multi-Packs** in the WordPress admin menu.

### Default Pack Groups

This is the most important section. Every pack group you configure here will appear automatically on **every product page** on the site.

Use this when you want the same pack structure for all products — for example:

- **Pack of 6** (5 bought + 1 free) for all products.
- **Pack of 12** (10 bought + 2 free) for all products.

You can add as many groups as needed with the **Add group** button, and add as many pack lines per group as needed with the **Add line** button.

> **Tip:** Set a descriptive group title such as `Buy a pack of 6 (5 bought + 1 free)` — that text is displayed as a heading on the product page above the pack table.

### Default Pack Tiers

A legacy convenience field. Enter pack sizes separated by commas or one per line (e.g. `6, 12, 24`). This value is available to custom JS if you want to drive additional storefront behaviour. It does not affect pack group display.

### Custom CSS / Custom JS

Both fields are injected **only** on product pages that display a pack table. Use them to style or enhance the pack UI without touching your theme files.

---

## Configuring a Pack Group

### Group Title

Shown as a heading (`<h4>`) above the pack table on the product page.

**Recommended format** (to match the style shown in the screenshot):

```
Buy a pack of 6 (5 bought + 1 free)
Buy a pack of 12 (10 bought + 2 free)
```

Leave empty to display the table without a heading.

### Packaging Field (Conditioning)

This field represents the **combination of the product variant's weight and the pack quantity**.

Formula: `[Variant weight] × [Pack quantity]`

Examples:

| Variant | Pack qty | Packaging label to enter |
|---|---|---|
| 130 gr | 12 | `130gr × 12` |
| 190 gr | 6 | `190gr × 6` |
| 800 gr | 6 | `800gr × 6` |
| 450 gr | 12 | `450gr × 12` |

> **Important:** This is a free-text label — enter exactly what you want customers to see in the "Cond." column of the pack table.

### Units / Pack

The total number of individual product units included in one pack. This number must match the quantity implied by your packaging label.

- `190gr × 6` → Units/pack = **6**
- `130gr × 12` → Units/pack = **12**

This value is used for:
- Adding the correct quantity to the cart.
- Calculating the per-unit price shown in the Price column.
- BOGO free-unit calculation.

### Mode: BOGO vs Fixed Price

#### BOGO (Buy X Get Y Free)

Enter two values:
- **Bought** — the number of units the customer pays for in each cycle.
- **Free** — the number of units they receive at no extra cost in each cycle.

The plugin automatically calculates how many free units fit into the pack and reduces the total price accordingly.

**Example** — Pack of 6, BOGO 5+1:
- Units/pack = 6
- Bought = 5, Free = 1
- Total paid units = 5 (one full BOGO cycle in a pack of 6)
- Pack price = unit price × 5

#### Fixed Price

Enter a flat price for the entire pack. The per-unit price displayed below it is calculated automatically: `fixed_price ÷ units_per_pack`.

---

## Per-Product Pack Configuration

Open any product in **WP Admin → Products** and scroll to the **Pack Management** meta box.

### Inheriting Global Settings

If you have configured global pack groups and do **not** add any custom groups for this product, the product will automatically display the global pack table. A blue notice confirms: *"Global pack settings are active for this product."*

### Overriding Global Settings

To apply a different pack structure to a specific product:

1. Add one or more pack groups using the **Add group** button inside the **Pack Management** meta box.
2. Save the product.

The per-product groups completely **replace** the global groups for this product. The global groups are no longer shown.

> **Use case:** Most products share a "Pack of 6 (5+1)" and "Pack of 12 (10+2)" deal globally, but one premium product has a special "Pack of 3 (2+1)" deal — configure that in the product's meta box.

### Disabling Pack Tables for a Product

Tick **"Disable pack tables for this product"** in the Pack Management meta box and save. No pack table will be rendered for this product, even if global groups are active.

> **Use case:** A digital product or a product sold individually that should never show wholesale options.

---

## How the Storefront Looks

On the product page, below the standard Add-to-Cart button, the plugin renders one section per pack group:

```
──────────────────────────────────────────────────────────
Buy a pack of 6 (5 bought + 1 free)

 Qty  │  Cond.    │  Price                    │
──────│───────────│───────────────────────────│
[−][1][+] │ 190gr × 6  │ 15,00 €               │ [ADD]
          │            │ (i.e. 2,50 € / unit)  │
[−][1][+] │ 800gr × 6  │ 55,50 €               │ [ADD]
          │            │ (i.e. 9,25 € / unit)  │
──────────────────────────────────────────────────────────
Buy a pack of 12 (10 bought + 2 free)
...
```

- The **Qty** stepper controls how many packs to add (default: 1).
- The **Cond.** column shows the packaging label.
- The **Price** column shows the total pack price and, below it, the per-unit price.
- The **ADD** button adds the chosen quantity of packs to the cart.

---

## Cart Behaviour

When a pack is added to the cart:

- The product quantity displayed is `packs × units_per_pack` (e.g. 2 packs of 6 = 12 units).
- The per-unit price is adjusted automatically so the line total reflects the correct pack discount.
- The cart item detail shows the pack label and the number of packs ordered.
- The quantity input in the cart is locked (displays as plain text) to prevent customers from changing it outside the pack context.

---

## Pricing Logic Reference

### BOGO

```
cycle_units  = bogo_buy + bogo_free
full_cycles  = floor(total_units / cycle_units)
free_units   = full_cycles × bogo_free
paid_units   = total_units − free_units
total_price  = unit_price × paid_units
```

### Fixed Price

```
total_price  = fixed_price × number_of_packs
unit_price   = fixed_price ÷ units_per_pack   (display only)
```

---

## Examples

### Example A — Lot of 6 (BOGO 5+1)

| Field | Value |
|---|---|
| Group title | `Buy a pack of 6 (5 bought + 1 free)` |
| Packaging | `190gr × 6` |
| Units / pack | `6` |
| Mode | BOGO |
| Bought | `5` |
| Free | `1` |

**Result:** A pack of 6 × 190 gr costs the same as 5 individual units. One unit is free.

---

### Example B — Lot of 12 (BOGO 10+2)

| Field | Value |
|---|---|
| Group title | `Buy a pack of 12 (10 bought + 2 free)` |
| Packaging | `130gr × 12` |
| Units / pack | `12` |
| Mode | BOGO |
| Bought | `10` |
| Free | `2` |

**Result:** A pack of 12 × 130 gr costs the same as 10 individual units. Two units are free.

---

### Example C — Fixed-price bundle

| Field | Value |
|---|---|
| Group title | `Tasting pack` |
| Packaging | `Assorted × 6` |
| Units / pack | `6` |
| Mode | Fixed price |
| Pack price | `18.00` |

**Result:** The pack is sold for a flat 18.00 € regardless of individual unit prices. The per-unit price displayed is 3.00 €.

---

## Troubleshooting

| Symptom | Possible cause | Solution |
|---|---|---|
| Pack table not showing on a product | "Disable pack tables" is checked, or no global groups are configured | Uncheck the disable option, or add global groups in WooCommerce → Multi-Packs |
| Pack table shows global packs instead of custom packs | Per-product groups were not saved correctly | Ensure at least one complete line (Packaging + Units/pack filled) is present and save the product |
| Price shows €0.00 | Unit price of the product is 0, or fixed price field left empty | Set the product price, or enter a value in the Fixed price field |
| "Selected pack is unavailable" error on add-to-cart | The saved pack index is out of sync after a group was removed | Re-save the product after making structural changes to groups |
| Per-unit price is not displayed | The pack line has no units_per_pack value or it is 0 | Enter a positive integer in the Units/pack field |
