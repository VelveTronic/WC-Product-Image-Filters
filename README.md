# WC Product Image Filters

WC Product Image Filters is a lightweight WooCommerce plugin for applying permanent CSS `filter` effects to individual product images.

It lets store managers select products from a visual admin panel, adjust image filter values, preview the effect in real time, and apply the result across product archives, single product pages, WooCommerce blocks, and common theme product cards.

## Features

- Per-product permanent CSS image filters
- Product selection by product name, SKU, or Product ID
- Ajax product search in the WooCommerce admin area
- Multiple products per filter rule
- Live admin preview while editing filter values
- Supports brightness, contrast, saturation, grayscale, and blur
- Frontend support for product archives and single product galleries
- Optional per-rule scope to filter only the main product image and skip gallery images
- Additional compatibility selectors for WooCommerce blocks and theme product cards
- Safe value sanitization, including comma decimal input such as `1,05`

## Requirements

- WordPress
- WooCommerce
- A theme or block layout that outputs product images using standard WooCommerce or WordPress product markup

## Installation

1. Download the plugin zip file.
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload `wc-product-image-filters.zip`.
4. Activate **WC Product Image Filters**.
5. Go to **WooCommerce > Image Filters**.

## Usage

1. Open **WooCommerce > Image Filters**.
2. Use the product search field to find products by name, SKU, or Product ID.
3. Add one or more products to a rule.
4. Adjust the filter values:
   - **Brightness**
   - **Contrast**
   - **Saturate**
   - **Grayscale**
   - **Blur**
5. Enable **Main image only** when the filter should skip the single product gallery images.
6. Review the live preview.
7. Save the filters.

The selected products will keep the configured image filter effect on supported frontend views.

## Manual Upgrade Notes

WordPress matches manually uploaded plugin zips by the installed plugin folder, not only by the plugin name.

If the plugin was installed from GitHub's **Download ZIP** source archive, the active folder may be named `WC-Product-Image-Filters-main`. Upgrade with GitHub's **Download ZIP** from the same branch, or use a package whose top-level folder is also `WC-Product-Image-Filters-main`, so WordPress recognizes the upload as an upgrade and offers to replace the existing plugin.

If WordPress already created a duplicate inactive copy, delete the inactive duplicate first, then upload the matching zip again.

## Recommended Values

- Bright commercial look: `brightness 1.12`, `contrast 1.06`, `saturate 1.05`
- Luxury soft look: `brightness 1.06`, `contrast 1.08`, `saturate 0.9`
- Black and white: `grayscale 1`

## Compatibility Notes

The plugin applies filters in two ways:

- It injects frontend CSS selectors for product-specific markup.
- It also filters WooCommerce and WordPress product image HTML when available.

This combination improves compatibility with classic WooCommerce templates, single product galleries, WooCommerce blocks, and many custom theme product cards.

## Version

Current version: `2.1.3`

## Author

VelveTronic
