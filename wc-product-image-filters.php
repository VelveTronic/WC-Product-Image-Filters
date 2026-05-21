<?php
/**
 * Plugin Name: WC Product Image Filters
 * Description: Add fixed CSS filters to selected WooCommerce product images with an Ajax product selector and live admin preview.
 * Version: 2.1.3
 * Author: VelveTronic
 * Text Domain: wc-product-image-filters
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Product_Image_Filters {
    const OPTION_KEY = 'wcpif_rules';
    const AJAX_NONCE = 'wcpif_admin_nonce';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_head', [$this, 'output_filter_css'], 99);
        add_filter('woocommerce_product_get_image', [$this, 'filter_product_image_html'], 20, 6);
        add_filter('post_thumbnail_html', [$this, 'filter_post_thumbnail_html'], 20, 5);
        add_action('wp_ajax_wcpif_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_wcpif_product_preview', [$this, 'ajax_product_preview']);
        add_action('wp_ajax_wcpif_search_categories', [$this, 'ajax_search_categories']);
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Product Image Filters',
            'Image Filters',
            'manage_woocommerce',
            'wc-product-image-filters',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('wcpif_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize_rules'],
            'default' => []
        ]);
    }

    public function enqueue_admin_assets($hook) {
        if (false === strpos((string) $hook, 'wc-product-image-filters')) {
            return;
        }

        wp_enqueue_script('jquery');

        if (wp_style_is('woocommerce_admin_styles', 'registered')) {
            wp_enqueue_style('woocommerce_admin_styles');
        }
    }

    public function sanitize_rules($input) {
        $rules = [];

        if (!is_array($input)) {
            return $rules;
        }

        foreach ($input as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $product_ids = $this->normalize_ids($rule['product_ids'] ?? '');
            $category_ids = $this->normalize_ids($rule['category_ids'] ?? '');

            if (empty(trim($product_ids)) && empty(trim($category_ids))) {
                continue;
            }

            $rules[] = [
                'product_ids' => $product_ids,
                'category_ids' => $category_ids,
                'brightness'  => $this->sanitize_number($rule['brightness'] ?? 1, 0, 3, 1),
                'contrast'    => $this->sanitize_number($rule['contrast'] ?? 1, 0, 3, 1),
                'saturate'    => $this->sanitize_number($rule['saturate'] ?? 1, 0, 3, 1),
                'grayscale'   => $this->sanitize_number($rule['grayscale'] ?? 0, 0, 1, 0),
                'blur'        => $this->sanitize_number($rule['blur'] ?? 0, 0, 20, 0),
                'main_image_only' => !empty($rule['main_image_only']) ? 1 : 0,
            ];
        }

        // Bust cached category→product ID mappings so output_filter_css picks up changes.
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wcpif_cat_pids_%' OR option_name LIKE '_transient_timeout_wcpif_cat_pids_%'");

        return $rules;
    }

    private function sanitize_number($value, $min, $max, $default) {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            return $default;
        }
        $value = (float) $value;
        return max($min, min($max, $value));
    }

    private function normalize_ids($value) {
        if (is_array($value)) {
            $value = implode(',', array_filter($value, 'is_scalar'));
        }

        $ids = array_filter(array_map('absint', preg_split('/[\s,]+/', (string) $value)));
        $ids = array_values(array_unique($ids));

        return implode(',', $ids);
    }

    private function get_rule_ids($rule, $key) {
        if (!is_array($rule) || empty($rule[$key])) {
            return [];
        }

        return array_filter(array_map('absint', preg_split('/[\s,]+/', (string) $rule[$key])));
    }

    private function build_filter_value($rule) {
        if (!is_array($rule)) {
            $rule = [];
        }

        $brightness = $this->sanitize_number($rule['brightness'] ?? 1, 0, 3, 1);
        $contrast = $this->sanitize_number($rule['contrast'] ?? 1, 0, 3, 1);
        $saturate = $this->sanitize_number($rule['saturate'] ?? 1, 0, 3, 1);
        $grayscale = $this->sanitize_number($rule['grayscale'] ?? 0, 0, 1, 0);
        $blur = $this->sanitize_number($rule['blur'] ?? 0, 0, 20, 0);

        return sprintf(
            'brightness(%s) contrast(%s) saturate(%s) grayscale(%s) blur(%spx)',
            $this->format_css_number($brightness),
            $this->format_css_number($contrast),
            $this->format_css_number($saturate),
            $this->format_css_number($grayscale),
            $this->format_css_number($blur)
        );
    }

    private function format_css_number($value) {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function get_product_label($product_id) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        if (!$product) {
            return sprintf('#%d', absint($product_id));
        }

        return sprintf('%s (#%d)', $product->get_name(), $product->get_id());
    }

    private function get_product_preview_data($product_id) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        if (!$product) {
            return [
                'id' => absint($product_id),
                'text' => sprintf('#%d', absint($product_id)),
                'image' => wc_placeholder_img_src('woocommerce_thumbnail'),
            ];
        }

        $image_id = $product->get_image_id();
        $image = $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : wc_placeholder_img_src('woocommerce_thumbnail');

        return [
            'id' => $product->get_id(),
            'text' => $this->get_product_label($product->get_id()),
            'image' => $image,
        ];
    }

    private function get_rules_for_display() {
        $rules = get_option(self::OPTION_KEY, []);
        $rules = is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];

        if (empty($rules)) {
            $rules = [[
                'product_ids'     => '',
                'category_ids'    => '',
                'brightness'      => 1.1,
                'contrast'        => 1.05,
                'saturate'        => 1,
                'grayscale'       => 0,
                'blur'            => 0,
                'main_image_only' => 0,
            ]];
        }

        return $rules;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $rules = $this->get_rules_for_display();
        ?>
        <div class="wrap">
            <h1>WooCommerce Product Image Filters</h1>
            <p>Apply fixed CSS image filters to selected WooCommerce products. Works on product archives, single product pages, WooCommerce blocks, and common theme product cards.</p>

            <form method="post" action="options.php">
                <?php settings_fields('wcpif_settings_group'); ?>

                <table class="widefat striped" id="wcpif-rules-table">
                    <thead>
                        <tr>
                            <th style="width: 34%;">Products / Categories</th>
                            <th style="width: 160px;">Live Preview</th>
                            <th>Brightness</th>
                            <th>Contrast</th>
                            <th>Saturate</th>
                            <th>Grayscale</th>
                            <th>Blur px</th>
                            <th style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rules as $index => $rule): ?>
                            <?php $this->render_rule_row($index, $rule); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button button-secondary" id="wcpif-add-rule">+ Add Rule</button>
                </p>

                <?php submit_button('Save Filters'); ?>
            </form>

            <hr>
            <h2>Recommended values</h2>
            <p><strong>Bright commercial look:</strong> brightness 1.12, contrast 1.06, saturate 1.05</p>
            <p><strong>Luxury soft look:</strong> brightness 1.06, contrast 1.08, saturate 0.9</p>
            <p><strong>Black &amp; white:</strong> grayscale 1</p>
        </div>
        <?php
        $this->render_admin_css();
        $this->render_admin_script();
    }

    private function render_rule_row($index, $rule) {
        $k = esc_attr(self::OPTION_KEY);
        $i = esc_attr($index);
        ?>
        <tr>
            <td>
                <div class="wcpif-product-control" data-placeholder="Search product name or enter Product ID">
                    <input type="hidden" class="wcpif-product-ids" name="<?php echo $k; ?>[<?php echo $i; ?>][product_ids]" value="<?php echo esc_attr($rule['product_ids'] ?? ''); ?>" />
                    <div class="wcpif-selected-products" data-placeholder="No products selected yet">
                    <?php foreach ($this->get_rule_ids($rule, 'product_ids') as $product_id): ?>
                        <span class="wcpif-product-chip" data-id="<?php echo esc_attr($product_id); ?>">
                            <?php echo esc_html($this->get_product_label($product_id)); ?>
                            <button type="button" class="wcpif-remove-product" aria-label="Remove product">&times;</button>
                        </span>
                    <?php endforeach; ?>
                    </div>
                    <input type="text" class="wcpif-product-search" placeholder="Search product name or enter Product ID" autocomplete="off" />
                    <div class="wcpif-product-results" hidden></div>
                </div>
                <div class="wcpif-category-control" data-placeholder="No categories selected yet">
                    <input type="hidden" class="wcpif-category-ids" name="<?php echo $k; ?>[<?php echo $i; ?>][category_ids]" value="<?php echo esc_attr($rule['category_ids'] ?? ''); ?>" />
                    <div class="wcpif-selected-categories" data-placeholder="No categories selected yet">
                    <?php foreach ($this->get_rule_ids($rule, 'category_ids') as $category_id):
                        $term = get_term($category_id, 'product_cat');
                        if (!$term || is_wp_error($term)) {
                            continue;
                        }
                    ?>
                        <span class="wcpif-category-chip" data-id="<?php echo esc_attr($category_id); ?>">
                            <?php echo esc_html($term->name . " (#{$term->term_id})"); ?>
                            <button type="button" class="wcpif-remove-category" aria-label="Remove category">&times;</button>
                        </span>
                    <?php endforeach; ?>
                    </div>
                    <input type="text" class="wcpif-category-search" placeholder="Search product category" autocomplete="off" />
                    <div class="wcpif-category-results" hidden></div>
                </div>
                <p class="description">Search by product name/SKU/ID, and/or select product categories.</p>
            </td>
            <td>
                <div class="wcpif-preview" aria-live="polite">
                    <img src="<?php echo esc_url(wc_placeholder_img_src('woocommerce_thumbnail')); ?>" alt="" />
                </div>
                <label class="wcpif-scope-toggle" title="Apply filter to main product image only, skipping single product gallery images.">
                    <input type="checkbox" name="<?php echo $k; ?>[<?php echo $i; ?>][main_image_only]" value="1" <?php checked(!empty($rule['main_image_only'])); ?> />
                    Main image only
                </label>
            </td>
            <td><input type="number" step="0.01" min="0" max="3" name="<?php echo $k; ?>[<?php echo $i; ?>][brightness]" value="<?php echo esc_attr($rule['brightness'] ?? 1); ?>" /></td>
            <td><input type="number" step="0.01" min="0" max="3" name="<?php echo $k; ?>[<?php echo $i; ?>][contrast]" value="<?php echo esc_attr($rule['contrast'] ?? 1); ?>" /></td>
            <td><input type="number" step="0.01" min="0" max="3" name="<?php echo $k; ?>[<?php echo $i; ?>][saturate]" value="<?php echo esc_attr($rule['saturate'] ?? 1); ?>" /></td>
            <td><input type="number" step="0.01" min="0" max="1" name="<?php echo $k; ?>[<?php echo $i; ?>][grayscale]" value="<?php echo esc_attr($rule['grayscale'] ?? 0); ?>" /></td>
            <td><input type="number" step="0.1" min="0" max="20" name="<?php echo $k; ?>[<?php echo $i; ?>][blur]" value="<?php echo esc_attr($rule['blur'] ?? 0); ?>" /></td>
            <td><button type="button" class="button wcpif-remove-rule">Remove</button></td>
        </tr>
        <?php
    }

    private function render_admin_css() {
        ?>
        <style>
            .woocommerce_page_wc-product-image-filters .wrap {
                max-width: 100%;
                overflow-x: hidden;
            }

            .woocommerce_page_wc-product-image-filters #wcpif-rules-table {
                table-layout: fixed;
            }

            #wcpif-rules-table td {
                vertical-align: top;
            }

            #wcpif-rules-table th,
            #wcpif-rules-table td {
                padding: 14px 16px;
            }

            #wcpif-rules-table input[type="number"] {
                width: 100px;
            }

            .wcpif-scope-toggle {
                display: flex;
                align-items: flex-start;
                gap: 5px;
                margin-top: 8px;
                font-size: 12px;
                color: #50575e;
                cursor: pointer;
            }

            .wcpif-scope-toggle input[type="checkbox"] {
                margin-top: 2px;
                flex-shrink: 0;
            }

            .wcpif-product-control,
            .wcpif-category-control {
                position: relative;
                max-width: 720px;
                width: 100% !important;
                min-width: 320px;
            }

            .wcpif-selected-products,
            .wcpif-selected-categories {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 6px;
                min-height: 50px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                background: #fff;
                padding: 8px;
                box-sizing: border-box;
            }

            .wcpif-product-control:focus-within .wcpif-selected-products,
            .wcpif-category-control:focus-within .wcpif-selected-categories,
            .wcpif-product-search:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
                outline: 2px solid transparent;
            }

            .wcpif-product-search,
            .wcpif-category-search {
                width: 100% !important;
                min-height: 42px;
                margin-top: 8px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                background: #fff;
                color: #1d2327;
                font-size: 14px;
                line-height: 1.4;
                padding: 8px 10px;
                box-sizing: border-box;
            }

            .wcpif-product-chip,
            .wcpif-category-chip {
                display: inline-flex;
                align-items: center;
                max-width: 100%;
                gap: 6px;
                padding: 5px 9px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                background: #f6f7f7;
                color: #1d2327;
                font-size: 13px;
                line-height: 1.5;
            }

            .wcpif-remove-product,
            .wcpif-remove-category {
                width: auto;
                height: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                border: 0;
                background: transparent;
                color: #50575e;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                line-height: 1;
            }

            .wcpif-product-results,
            .wcpif-category-results {
                position: absolute;
                top: calc(100% + 4px);
                left: 0;
                right: 0;
                z-index: 100000;
                max-height: 240px;
                overflow-y: auto;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                background: #fff;
                box-shadow: 0 8px 18px rgba(0, 0, 0, 0.16);
            }

            .wcpif-product-result,
            .wcpif-category-result {
                width: 100%;
                min-height: 38px;
                padding: 9px 11px;
                border: 0;
                background: #fff;
                color: #1d2327;
                cursor: pointer;
                font-size: 14px;
                line-height: 1.35;
                text-align: left;
            }

            .wcpif-product-result:hover,
            .wcpif-product-result:focus,
            .wcpif-category-result:hover,
            .wcpif-category-result:focus {
                background: #2271b1;
                color: #fff;
                outline: none;
            }

            .wcpif-product-result.is-empty,
            .wcpif-category-result.is-empty {
                cursor: default;
                background: #fff;
                color: #646970;
            }

            .wcpif-product-control.is-empty .wcpif-selected-products::before,
            .wcpif-category-control.is-empty .wcpif-selected-categories::before {
                color: #646970;
                content: attr(data-placeholder);
            }

            .wcpif-preview {
                width: 120px;
                aspect-ratio: 1 / 1;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                background: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
            }

            .wcpif-preview img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
        </style>
        <?php
    }

    private function render_admin_script() {
        ?>
        <script>
        (function($){
            const tableBody = document.querySelector('#wcpif-rules-table tbody');
            const addBtn = document.querySelector('#wcpif-add-rule');
            const optionKey = '<?php echo esc_js(self::OPTION_KEY); ?>';
            const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            const nonce = '<?php echo esc_js(wp_create_nonce(self::AJAX_NONCE)); ?>';
            const SEARCH_DEBOUNCE_MS = 250;

            function getIndex() {
                return tableBody.querySelectorAll('tr').length + Date.now();
            }

            function parseNumber(value, fallback) {
                const parsed = parseFloat(String(value || '').replace(',', '.'));
                return Number.isFinite(parsed) ? parsed : fallback;
            }

            function buildFilter(row) {
                return [
                    `brightness(${parseNumber(row.querySelector('[name*="[brightness]"]').value, 1)})`,
                    `contrast(${parseNumber(row.querySelector('[name*="[contrast]"]').value, 1)})`,
                    `saturate(${parseNumber(row.querySelector('[name*="[saturate]"]').value, 1)})`,
                    `grayscale(${parseNumber(row.querySelector('[name*="[grayscale]"]').value, 0)})`,
                    `blur(${parseNumber(row.querySelector('[name*="[blur]"]').value, 0)}px)`
                ].join(' ');
            }

            function updatePreview(row) {
                const image = row.querySelector('.wcpif-preview img');
                if (image) {
                    image.style.filter = buildFilter(row);
                }
            }

            function loadPreviewImage(row) {
                const ids = getSelectedIds(row);
                const image = row.querySelector('.wcpif-preview img');
                const selected = ids.length ? ids[0] : '';

                if (!selected || !image) {
                    image.src = '<?php echo esc_js(wc_placeholder_img_src('woocommerce_thumbnail')); ?>';
                    updatePreview(row);
                    return;
                }

                $.get(ajaxUrl, {
                    action: 'wcpif_product_preview',
                    nonce: nonce,
                    product_id: selected
                }).done(function(response) {
                    if (response && response.success && response.data.image) {
                        image.src = response.data.image;
                    }
                    updatePreview(row);
                }).fail(function() {
                    updatePreview(row);
                });
            }

            function getSelectedIds(row) {
                const hidden = row.querySelector('.wcpif-product-ids');
                return (hidden && hidden.value ? hidden.value.split(',') : [])
                    .map(id => id.trim())
                    .filter(Boolean);
            }

            function closeResults(control, type) {
                const results = control.querySelector(`.wcpif-${type}-results`);
                results.hidden = true;
                results.innerHTML = '';
            }

            function syncIds(control, type) {
                const hidden = control.querySelector(`.wcpif-${type}-ids`);
                const ids = Array.from(control.querySelectorAll(`.wcpif-${type}-chip`))
                    .map(chip => chip.dataset.id)
                    .filter(Boolean);
                hidden.value = Array.from(new Set(ids)).join(',');
                control.classList.toggle('is-empty', ids.length === 0);
                if (type === 'product') loadPreviewImage(control.closest('tr'));
            }

            function renderResults(control, type, items, term) {
                const results = control.querySelector(`.wcpif-${type}-results`);
                const selected = new Set(
                    Array.from(control.querySelectorAll(`.wcpif-${type}-chip`)).map(c => c.dataset.id)
                );
                let filtered = (items || []).filter(item => !selected.has(String(item.id)));

                results.innerHTML = '';

                if (!filtered.length && type === 'product' && /^\d+$/.test(term)) {
                    filtered = [{ id: term, text: `Product ID #${term}` }];
                }

                if (!filtered.length) {
                    const empty = document.createElement('div');
                    empty.className = `wcpif-${type}-result is-empty`;
                    empty.textContent = term ? `No ${type}s found` : 'Please enter 1 or more characters';
                    results.appendChild(empty);
                    results.hidden = false;
                    return;
                }

                filtered.forEach(function(item) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `wcpif-${type}-result`;
                    btn.dataset.id = item.id;
                    btn.dataset.text = item.text;
                    btn.textContent = item.text;
                    results.appendChild(btn);
                });
                results.hidden = false;
            }

            function addItem(control, type, id, text) {
                if (!id || control.querySelector(`.wcpif-${type}-chip[data-id="${CSS.escape(String(id))}"]`)) {
                    return;
                }

                const chip = document.createElement('span');
                chip.className = `wcpif-${type}-chip`;
                chip.dataset.id = id;
                chip.append(document.createTextNode(text || (type === 'product' ? `Product ID #${id}` : id)));

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = `wcpif-remove-${type}`;
                remove.setAttribute('aria-label', `Remove ${type}`);
                remove.innerHTML = '&times;';
                chip.append(remove);

                control.querySelector(`.wcpif-selected-${type}s`).appendChild(chip);
                control.querySelector(`.wcpif-${type}-search`).value = '';
                closeResults(control, type);
                syncIds(control, type);
            }

            function initSearch(context, type) {
                (context || document).querySelectorAll(`.wcpif-${type}-control`).forEach(function(control) {
                    if (control.dataset.wcpifReady) return;
                    control.dataset.wcpifReady = '1';
                    control.classList.toggle('is-empty', !control.querySelector(`.wcpif-${type}-chip`));
                    if (type === 'product') loadPreviewImage(control.closest('tr'));
                });
            }

            addBtn.addEventListener('click', function(){
                const i = getIndex();
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>
                        <div class="wcpif-product-control">
                            <input type="hidden" class="wcpif-product-ids" name="${optionKey}[${i}][product_ids]" value="" />
                            <div class="wcpif-selected-products" data-placeholder="No products selected yet"></div>
                            <input type="text" class="wcpif-product-search" placeholder="Search product name or enter Product ID" autocomplete="off" />
                            <div class="wcpif-product-results" hidden></div>
                        </div>
                        <div class="wcpif-category-control" data-placeholder="No categories selected yet">
                            <input type="hidden" class="wcpif-category-ids" name="${optionKey}[${i}][category_ids]" value="" />
                            <div class="wcpif-selected-categories" data-placeholder="No categories selected yet"></div>
                            <input type="text" class="wcpif-category-search" placeholder="Search product category" autocomplete="off" />
                            <div class="wcpif-category-results" hidden></div>
                        </div>
                        <p class="description">Search by product name/SKU/ID, and/or select product categories.</p>
                    </td>
                    <td>
                        <div class="wcpif-preview" aria-live="polite"><img src="<?php echo esc_js(wc_placeholder_img_src('woocommerce_thumbnail')); ?>" alt="" /></div>
                        <label class="wcpif-scope-toggle" title="Apply filter to main product image only, skipping single product gallery images.">
                            <input type="checkbox" name="${optionKey}[${i}][main_image_only]" value="1" />
                            Main image only
                        </label>
                    </td>
                    <td><input type="number" step="0.01" min="0" max="3" name="${optionKey}[${i}][brightness]" value="1.1" /></td>
                    <td><input type="number" step="0.01" min="0" max="3" name="${optionKey}[${i}][contrast]" value="1.05" /></td>
                    <td><input type="number" step="0.01" min="0" max="3" name="${optionKey}[${i}][saturate]" value="1" /></td>
                    <td><input type="number" step="0.01" min="0" max="1" name="${optionKey}[${i}][grayscale]" value="0" /></td>
                    <td><input type="number" step="0.1" min="0" max="20" name="${optionKey}[${i}][blur]" value="0" /></td>
                    <td><button type="button" class="button wcpif-remove-rule">Remove</button></td>
                `;
                tableBody.appendChild(row);
                initSearch(row, 'product');
                initSearch(row, 'category');
                updatePreview(row);
            });

            document.addEventListener('click', function(e){
                if (e.target.classList.contains('wcpif-remove-rule')) {
                    const rows = tableBody.querySelectorAll('tr');
                    if (rows.length > 1) {
                        e.target.closest('tr').remove();
                    }
                }

                if (e.target.classList.contains('wcpif-remove-product')) {
                    const control = e.target.closest('.wcpif-product-control');
                    e.target.closest('.wcpif-product-chip').remove();
                    syncIds(control, 'product');
                }

                if (e.target.classList.contains('wcpif-remove-category')) {
                    const control = e.target.closest('.wcpif-category-control');
                    e.target.closest('.wcpif-category-chip').remove();
                    syncIds(control, 'category');
                }

                if (e.target.classList.contains('wcpif-product-result') && !e.target.classList.contains('is-empty')) {
                    addItem(e.target.closest('.wcpif-product-control'), 'product', e.target.dataset.id, e.target.dataset.text);
                }

                if (e.target.classList.contains('wcpif-category-result') && !e.target.classList.contains('is-empty')) {
                    addItem(e.target.closest('.wcpif-category-control'), 'category', e.target.dataset.id, e.target.dataset.text);
                }

                if (!e.target.closest('.wcpif-product-control')) {
                    document.querySelectorAll('.wcpif-product-control').forEach(c => closeResults(c, 'product'));
                }

                if (!e.target.closest('.wcpif-category-control')) {
                    document.querySelectorAll('.wcpif-category-control').forEach(c => closeResults(c, 'category'));
                }
            });

            document.addEventListener('input', function(e) {
                if (e.target.matches('#wcpif-rules-table input[type="number"]')) {
                    updatePreview(e.target.closest('tr'));
                }

                if (e.target.classList.contains('wcpif-product-search')) {
                    const control = e.target.closest('.wcpif-product-control');
                    const term = e.target.value.trim();

                    if (!term) {
                        renderResults(control, 'product', [], term);
                        return;
                    }

                    window.clearTimeout(control.wcpifSearchTimer);
                    control.wcpifSearchTimer = window.setTimeout(function() {
                        $.get(ajaxUrl, {
                            action: 'wcpif_search_products',
                            nonce: nonce,
                            term: term
                        }).done(function(response) {
                            renderResults(control, 'product', response && response.success ? response.data.results : [], term);
                        }).fail(function() {
                            renderResults(control, 'product', [], term);
                        });
                    }, SEARCH_DEBOUNCE_MS);
                }

                if (e.target.classList.contains('wcpif-category-search')) {
                    const control = e.target.closest('.wcpif-category-control');
                    const term = e.target.value.trim();

                    if (!term) {
                        renderResults(control, 'category', [], term);
                        return;
                    }

                    window.clearTimeout(control.wcpifSearchTimer);
                    control.wcpifSearchTimer = window.setTimeout(function() {
                        $.get(ajaxUrl, { action: 'wcpif_search_categories', nonce: nonce, term: term })
                            .done(function(response) {
                                renderResults(control, 'category', response && response.success ? response.data.results : [], term);
                            }).fail(function() {
                                renderResults(control, 'category', [], term);
                            });
                    }, SEARCH_DEBOUNCE_MS);
                }
            });

            document.addEventListener('keydown', function(e) {
                if (!e.target.classList.contains('wcpif-product-search') || e.key !== 'Enter') {
                    return;
                }

                e.preventDefault();
                const control = e.target.closest('.wcpif-product-control');
                const first = control.querySelector('.wcpif-product-result:not(.is-empty)');
                const term = e.target.value.trim();

                if (first) {
                    addItem(control, 'product', first.dataset.id, first.dataset.text);
                } else if (/^\d+$/.test(term)) {
                    addItem(control, 'product', term, `Product ID #${term}`);
                }
            });

            initSearch(document, 'product');
            initSearch(document, 'category');
            tableBody.querySelectorAll('tr').forEach(updatePreview);
        })(jQuery);
        </script>
        <?php
    }

    public function ajax_search_products() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        check_ajax_referer(self::AJAX_NONCE, 'nonce');

        $term = isset($_GET['term']) ? wc_clean(wp_unslash($_GET['term'])) : '';
        $results = [];

        if ('' === trim($term)) {
            wp_send_json_success(['results' => $results]);
        }

        if (ctype_digit($term)) {
            $product = wc_get_product(absint($term));
            if ($product) {
                $results[] = [
                    'id' => $product->get_id(),
                    'text' => $this->get_product_label($product->get_id()),
                ];
            }
        }

        if (class_exists('WC_Data_Store')) {
            $data_store = WC_Data_Store::load('product');
            $ids = $data_store->search_products($term, '', true, false, 20);

            foreach ($ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $results[$product->get_id()] = [
                        'id' => $product->get_id(),
                        'text' => $this->get_product_label($product->get_id()),
                    ];
                }
            }
        } else {
            $products = wc_get_products([
                'status' => ['publish', 'private', 'draft'],
                'limit' => 20,
                's' => $term,
                'return' => 'objects',
            ]);

            foreach ($products as $product) {
                $results[$product->get_id()] = [
                    'id' => $product->get_id(),
                    'text' => $this->get_product_label($product->get_id()),
                ];
            }
        }

        wp_send_json_success(['results' => array_values($results)]);
    }

    public function ajax_search_categories() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        check_ajax_referer(self::AJAX_NONCE, 'nonce');

        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $results = [];

        if ('' === trim($term)) {
            wp_send_json_success(['results' => $results]);
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'search' => $term,
            'number' => 20,
        ]);

        if (!is_wp_error($terms)) {
            foreach ($terms as $item) {
                $results[] = [
                    'id' => $item->term_id,
                    'text' => sprintf('%s (#%d)', $item->name, $item->term_id),
                ];
            }
        }

        wp_send_json_success(['results' => $results]);
    }

    public function ajax_product_preview() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        check_ajax_referer(self::AJAX_NONCE, 'nonce');

        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        wp_send_json_success($this->get_product_preview_data($product_id));
    }

    public function output_filter_css() {
        if (is_admin()) {
            return;
        }

        $rules = get_option(self::OPTION_KEY, []);
        if (empty($rules) || !is_array($rules)) {
            return;
        }

        $css_blocks = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $product_ids  = $this->get_rule_ids($rule, 'product_ids');
            $category_ids = $this->get_rule_ids($rule, 'category_ids');

            if (!empty($category_ids)) {
                $product_ids = array_values(array_unique(array_merge(
                    $product_ids,
                    $this->get_product_ids_by_category_ids($category_ids)
                )));
            }

            if (empty($product_ids)) {
                continue;
            }

            $selectors = [];
            $main_only = $this->is_main_image_only_rule($rule);
            foreach ($product_ids as $id) {
                $selectors = array_merge(
                    $selectors,
                    $this->get_product_image_selectors($id, $main_only)
                );
            }

            $css_blocks[] = implode(",\n", $selectors)
                . " {\n  filter: " . esc_html($this->build_filter_value($rule)) . " !important;\n}";
        }

        if (!empty($css_blocks)) {
            echo "\n<style id='wcpif-dynamic-css'>\n" . implode("\n", $css_blocks) . "\n</style>\n";
        }
    }

    private function get_product_ids_by_category_ids(array $category_ids) {
        if (empty($category_ids)) {
            return [];
        }

        sort($category_ids);
        $cache_key = 'wcpif_cat_pids_' . md5(implode(',', $category_ids));
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category_ids,
            ]],
        ]);

        $ids = array_map('absint', $query->posts);
        set_transient($cache_key, $ids, HOUR_IN_SECONDS);
        return $ids;
    }

    public function filter_product_image_html($html, $product, $size = null, $attr = [], $placeholder = true, $image = null) {
        if (is_admin() || !$product || !is_a($product, 'WC_Product')) {
            return $html;
        }

        return $this->apply_filter_to_image_html($html, $product->get_id());
    }

    public function filter_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (is_admin() || 'product' !== get_post_type($post_id)) {
            return $html;
        }

        return $this->apply_filter_to_image_html($html, $post_id);
    }

    private function is_main_image_only_rule($rule) {
        return is_array($rule) && !empty($rule['main_image_only']);
    }

    private function get_product_image_selectors($product_id, $main_image_only = false) {
        $id = absint($product_id);

        if ($main_image_only) {
            return [
                ".post-{$id} img.wp-post-image",
                ".product.post-{$id} img.wp-post-image",
                ".woocommerce ul.products li.post-{$id} img",
                ".woocommerce ul.products li.product.post-{$id} img",
                "body.single-product.postid-{$id} .woocommerce-product-gallery img.wp-post-image",
                "body.single-product.postid-{$id} .woocommerce-product-gallery .woocommerce-product-gallery__image:first-child img",
                ".wc-block-grid__product.post-{$id} img",
                ".wc-block-grid__product[data-product-id=\"{$id}\"] img",
                ".wc-block-product.post-{$id} img",
                ".wc-block-product[data-wc-product-id=\"{$id}\"] img",
            ];
        }

        return [
            ".post-{$id} img.wp-post-image",
            ".product.post-{$id} img",
            ".woocommerce ul.products li.post-{$id} img",
            ".woocommerce ul.products li.product.post-{$id} img",
            "body.single-product.postid-{$id} .woocommerce-product-gallery img",
            ".wc-block-grid__product.post-{$id} img",
            ".wc-block-grid__product[data-product-id=\"{$id}\"] img",
            ".wc-block-product.post-{$id} img",
            ".wc-block-product[data-wc-product-id=\"{$id}\"] img",
            "[data-product-id=\"{$id}\"] img",
            "[data-wc-product-id=\"{$id}\"] img",
        ];
    }

    private function apply_filter_to_image_html($html, $product_id) {
        $rule = $this->get_rule_for_product($product_id);
        if (!$rule) {
            return $html;
        }

        $filter        = $this->build_filter_value($rule);
        $product_class = 'wcpif-product-' . absint($product_id);
        $main_only     = $this->is_main_image_only_rule($rule);

        if (class_exists('WP_HTML_Tag_Processor')) {
            return $this->apply_filter_via_html_processor($html, $filter, $product_class, $main_only);
        }

        return $this->apply_filter_via_regex($html, $filter, $product_class, $main_only);
    }

    private function apply_filter_via_html_processor($html, $filter, $product_class, $main_only) {
        $processor = new WP_HTML_Tag_Processor($html);
        while ($processor->next_tag('img')) {
            $processor->add_class('wcpif-filtered-image');
            $processor->add_class($product_class);
            $style = (string) $processor->get_attribute('style');
            $processor->set_attribute('style', trim($style . '; filter: ' . $filter . ' !important;', '; '));
            if ($main_only) {
                break;
            }
        }
        return $processor->get_updated_html();
    }

    private function apply_filter_via_regex($html, $filter, $product_class, $main_only) {
        $class = 'wcpif-filtered-image ' . $product_class;
        return preg_replace_callback('/<img\b([^>]*)>/i', function($matches) use ($filter, $class) {
            $tag = $matches[0];

            if (preg_match('/\bclass=("|\')(.*?)\1/i', $tag)) {
                $tag = preg_replace('/\bclass=("|\')(.*?)\1/i', 'class=$1$2 ' . esc_attr($class) . '$1', $tag, 1);
            } else {
                $tag = preg_replace('/<img\b/i', '<img class="' . esc_attr($class) . '"', $tag, 1);
            }

            if (preg_match('/\bstyle=("|\')(.*?)\1/i', $tag)) {
                $tag = preg_replace('/\bstyle=("|\')(.*?)\1/i', 'style=$1$2; filter: ' . esc_attr($filter) . ' !important;$1', $tag, 1);
            } else {
                $tag = preg_replace('/<img\b/i', '<img style="filter: ' . esc_attr($filter) . ' !important;"', $tag, 1);
            }

            return $tag;
        }, $html, $main_only ? 1 : -1);
    }

    private function get_rule_for_product($product_id) {
        $rules = get_option(self::OPTION_KEY, []);

        if (empty($rules) || !is_array($rules)) {
            return null;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $product_ids = $this->get_rule_ids($rule, 'product_ids');
            if (in_array(absint($product_id), $product_ids, true)) {
                return $rule;
            }

            $category_ids = $this->get_rule_ids($rule, 'category_ids');
            if (!empty($category_ids) && has_term($category_ids, 'product_cat', $product_id)) {
                return $rule;
            }
        }

        return null;
    }
}

new WC_Product_Image_Filters();
