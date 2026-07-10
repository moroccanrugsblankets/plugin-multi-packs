<?php
/**
 * Main plugin class.
 *
 * @package PluginMultiPacks
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

final class WC_Multi_Packs_Plugin {
	private const OPTION_KEY      = 'wc_multi_packs_settings';
	private const META_KEY        = '_wc_multi_packs_groups';
	private const META_DISABLED   = '_wc_multi_packs_disabled';
	private const NONCE_ACTION    = 'wc_multi_packs_add_to_cart';
	private const NONCE_NAME      = 'wc_multi_packs_nonce';

	private static ?self $instance = null;

	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'register_settings_page']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('add_meta_boxes', [$this, 'register_product_meta_box']);
		add_action('save_post_product', [$this, 'save_product_meta_box']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_front_assets']);
		add_action('woocommerce_after_add_to_cart_form', [$this, 'render_pack_table']);
		add_action('wp_loaded', [$this, 'handle_pack_add_to_cart']);
		add_action('woocommerce_before_calculate_totals', [$this, 'apply_pack_pricing'], 20);
		add_filter('woocommerce_get_item_data', [$this, 'render_cart_item_data'], 10, 2);
		add_filter('woocommerce_cart_item_quantity', [$this, 'render_locked_cart_quantity'], 10, 3);
		add_filter('woocommerce_widget_cart_item_quantity', [$this, 'render_widget_cart_item_quantity'], 10, 3);
		add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_pack_add_to_cart'], 10, 3);
	}

	public function register_settings_page(): void {
		add_submenu_page(
			'woocommerce',
			__('Multi-Pack Wholesale Manager', 'plugin-multi-packs'),
			__('Multi-Packs', 'plugin-multi-packs'),
			'manage_woocommerce',
			'wc-multi-packs',
			[$this, 'render_settings_page']
		);
	}

	public function register_settings(): void {
		register_setting(
			'wc_multi_packs',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [$this, 'sanitize_settings'],
				'default'           => $this->get_default_settings(),
			]
		);

		add_settings_section(
			'wc_multi_packs_main',
			__('Global pack settings', 'plugin-multi-packs'),
			static function (): void {
				echo '<p>' . esc_html__('Configure default pack groups applied to all products. Per-product groups override these settings.', 'plugin-multi-packs') . '</p>';
			},
			'wc_multi_packs'
		);

		add_settings_field(
			'global_groups',
			__('Default pack groups', 'plugin-multi-packs'),
			[$this, 'render_global_groups_field'],
			'wc_multi_packs',
			'wc_multi_packs_main'
		);

		add_settings_field(
			'default_tiers',
			__('Default pack tiers', 'plugin-multi-packs'),
			[$this, 'render_default_tiers_field'],
			'wc_multi_packs',
			'wc_multi_packs_main'
		);

		add_settings_field(
			'custom_css',
			__('Custom CSS', 'plugin-multi-packs'),
			[$this, 'render_custom_css_field'],
			'wc_multi_packs',
			'wc_multi_packs_main'
		);

		add_settings_field(
			'custom_js',
			__('Custom JS', 'plugin-multi-packs'),
			[$this, 'render_custom_js_field'],
			'wc_multi_packs',
			'wc_multi_packs_main'
		);
	}

	public function sanitize_settings(mixed $settings): array {
		$settings = is_array($settings) ? $settings : [];

		return [
			'default_tiers' => $this->sanitize_tiers_text($settings['default_tiers'] ?? ''),
			'global_groups' => $this->sanitize_groups($settings['global_groups'] ?? [], true),
			'custom_css'    => sanitize_textarea_field(wp_unslash((string) ($settings['custom_css'] ?? ''))),
			'custom_js'     => sanitize_textarea_field(wp_unslash((string) ($settings['custom_js'] ?? ''))),
		];
	}

	public function render_settings_page(): void {
		if (! current_user_can('manage_woocommerce')) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('WooCommerce Multi-Pack Wholesale Manager', 'plugin-multi-packs'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('wc_multi_packs');
				do_settings_sections('wc_multi_packs');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_default_tiers_field(): void {
		$settings = $this->get_settings();
		?>
		<textarea class="large-text code" rows="5" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_tiers]"><?php echo esc_textarea($settings['default_tiers']); ?></textarea>
		<p class="description"><?php esc_html_e('One value per line or a comma-separated list, for example: 6, 12, 24', 'plugin-multi-packs'); ?></p>
		<?php
	}

	public function render_custom_css_field(): void {
		$settings = $this->get_settings();
		?>
		<textarea class="large-text code" rows="8" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_css]"><?php echo esc_textarea($settings['custom_css']); ?></textarea>
		<p class="description"><?php esc_html_e('Loaded only on product pages containing configured packs.', 'plugin-multi-packs'); ?></p>
		<?php
	}

	public function render_custom_js_field(): void {
		$settings = $this->get_settings();
		?>
		<textarea class="large-text code" rows="8" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_js]"><?php echo esc_textarea($settings['custom_js']); ?></textarea>
		<p class="description"><?php esc_html_e('Loaded only on product pages containing configured packs.', 'plugin-multi-packs'); ?></p>
		<?php
	}

	public function render_global_groups_field(): void {
		$settings      = $this->get_settings();
		$groups        = $settings['global_groups'] ?? [];
		$name_base     = self::OPTION_KEY . '[global_groups]';
		$line_template = [
			'pack_label'     => '',
			'units_per_pack' => '',
			'mode'           => 'bogo',
			'bogo_buy'       => '',
			'bogo_free'      => '',
			'fixed_price'    => '',
		];

		if ([] === $groups) {
			$groups = [
				[
					'group_title'    => '',
					'tiers_override' => '',
					'lines'          => [$line_template],
				],
			];
		}
		?>
		<div class="wc-multi-packs-admin" data-name-base="<?php echo esc_attr($name_base); ?>">
			<p class="description"><?php esc_html_e('These groups are displayed on every product page. Products with their own groups configured below will use those instead.', 'plugin-multi-packs'); ?></p>
			<div class="wc-multi-packs-admin__groups" data-groups>
				<?php foreach ($groups as $group_index => $group) : ?>
					<?php $this->render_group_editor($group_index, $group, false, $name_base, true); ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" data-add-group><?php esc_html_e('Add group', 'plugin-multi-packs'); ?></button>
			</p>
		</div>
		<script type="text/html" id="tmpl-wc-multi-packs-group">
			<?php $this->render_group_editor('__group_index__', ['group_title' => '', 'tiers_override' => '', 'lines' => [$line_template]], true, 'wc_multi_packs_groups', true); ?>
		</script>
		<script type="text/html" id="tmpl-wc-multi-packs-line">
			<?php $this->render_line_editor('__group_index__', '__line_index__', $line_template, true, 'wc_multi_packs_groups', true); ?>
		</script>
		<?php
	}

	public function register_product_meta_box(): void {
		add_meta_box(
			'wc-multi-packs',
			__('Pack Management', 'plugin-multi-packs'),
			[$this, 'render_product_meta_box'],
			'product',
			'normal',
			'default'
		);
	}

	public function render_product_meta_box(\WP_Post $post): void {
		wp_nonce_field('wc_multi_packs_save_meta_box', 'wc_multi_packs_meta_box_nonce');

		$groups           = $this->get_raw_product_groups($post->ID);
		$is_disabled      = (bool) get_post_meta($post->ID, self::META_DISABLED, true);
		$has_custom_groups = [] !== $groups;
		$global_tiers     = $this->get_settings()['default_tiers'];
		$line_template    = [
			'pack_label'     => '',
			'units_per_pack' => '',
			'mode'           => 'bogo',
			'bogo_buy'       => '',
			'bogo_free'      => '',
			'fixed_price'    => '',
		];
		if ([] === $groups) {
			$groups = [
				[
					'group_title'    => '',
					'tiers_override' => '',
					'lines'          => [$line_template],
				],
			];
		}
		?>
		<div class="wc-multi-packs-admin" data-name-base="wc_multi_packs_groups" data-default-tiers="<?php echo esc_attr($global_tiers); ?>">
			<?php if (! $has_custom_groups && ! $is_disabled) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e('Global pack settings are active for this product. Add custom groups below to override them, or check the box to disable pack tables entirely.', 'plugin-multi-packs'); ?></p>
				</div>
			<?php endif; ?>
			<p>
				<label>
					<input type="checkbox" name="wc_multi_packs_disabled" value="1" <?php checked($is_disabled); ?> />
					<strong><?php esc_html_e('Disable pack tables for this product', 'plugin-multi-packs'); ?></strong>
				</label>
			</p>
			<p><?php esc_html_e('Add one or more pack groups. Each row can use a BOGO discount or a fixed pack total. Leave all groups empty to use global defaults.', 'plugin-multi-packs'); ?></p>
			<div class="wc-multi-packs-admin__groups" data-groups>
				<?php foreach ($groups as $group_index => $group) : ?>
					<?php $this->render_group_editor($group_index, $group, false, 'wc_multi_packs_groups'); ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" data-add-group><?php esc_html_e('Add group', 'plugin-multi-packs'); ?></button>
			</p>
		</div>
		<script type="text/html" id="tmpl-wc-multi-packs-group">
			<?php $this->render_group_editor('__group_index__', ['group_title' => '', 'tiers_override' => '', 'lines' => [$line_template]], true); ?>
		</script>
		<script type="text/html" id="tmpl-wc-multi-packs-line">
			<?php $this->render_line_editor('__group_index__', '__line_index__', $line_template, true); ?>
		</script>
		<?php
	}

	private function render_group_editor(int|string $group_index, array $group, bool $is_template = false, string $name_base = 'wc_multi_packs_groups', bool $is_global = false): void {
		if ($is_template) {
			$name_base   = '__name_base__';
			$group_index = '__group_index__';
		}

		$group = wp_parse_args(
			$group,
			[
				'group_title'    => '',
				'tiers_override' => '',
				'lines'          => [],
			]
		);
		$lines = is_array($group['lines']) && [] !== $group['lines'] ? $group['lines'] : [[]];
		?>
		<div class="wc-multi-packs-admin__group postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e('Pack group', 'plugin-multi-packs'); ?></h2>
				<div class="handle-actions">
					<button type="button" class="button-link-delete" data-remove-group><?php esc_html_e('Remove', 'plugin-multi-packs'); ?></button>
				</div>
			</div>
			<div class="inside">
				<p>
					<label>
						<strong><?php esc_html_e('Group title', 'plugin-multi-packs'); ?></strong><br />
						<input type="text" class="widefat" name="<?php echo esc_attr($name_base . '[' . $group_index . '][group_title]'); ?>" value="<?php echo esc_attr((string) $group['group_title']); ?>" placeholder="<?php esc_attr_e('e.g. Buy a pack of 6 (5 bought + 1 free)', 'plugin-multi-packs'); ?>" />
					</label>
				</p>
				<p>
					<label>
						<strong><?php esc_html_e('Specific tiers (optional)', 'plugin-multi-packs'); ?></strong><br />
						<input type="text" class="widefat" name="<?php echo esc_attr($name_base . '[' . $group_index . '][tiers_override]'); ?>" value="<?php echo esc_attr((string) $group['tiers_override']); ?>" placeholder="<?php esc_attr_e('Example: 6, 12, 24', 'plugin-multi-packs'); ?>" />
					</label>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<?php if (! $is_global) : ?>
								<th><?php esc_html_e('Packaging', 'plugin-multi-packs'); ?></th>
								<th><?php esc_html_e('Units / pack', 'plugin-multi-packs'); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e('Mode', 'plugin-multi-packs'); ?></th>
							<th><?php esc_html_e('BOGO / Fixed price', 'plugin-multi-packs'); ?></th>
							<th><?php esc_html_e('Action', 'plugin-multi-packs'); ?></th>
						</tr>
					</thead>
					<tbody data-lines data-group-index="<?php echo esc_attr((string) $group_index); ?>">
						<?php foreach ($lines as $line_index => $line) : ?>
							<?php $this->render_line_editor($group_index, $line_index, $line, $is_template, $name_base, $is_global); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="button" class="button" data-add-line data-group-index="<?php echo esc_attr((string) $group_index); ?>"><?php esc_html_e('Add line', 'plugin-multi-packs'); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	private function render_line_editor(int|string $group_index, int|string $line_index, array $line, bool $is_template = false, string $name_base = 'wc_multi_packs_groups', bool $is_global = false): void {
		if ($is_template) {
			$name_base   = '__name_base__';
			$group_index = '__group_index__';
			$line_index  = '__line_index__';
		}

		$line = wp_parse_args(
			$line,
			[
				'pack_label'     => '',
				'units_per_pack' => '',
				'mode'           => 'bogo',
				'bogo_buy'       => '',
				'bogo_free'      => '',
				'fixed_price'    => '',
			]
		);
		$name = $name_base . '[' . $group_index . '][lines][' . $line_index . ']';
		?>
		<tr class="wc-multi-packs-admin__line">
			<?php if (! $is_global) : ?>
				<td>
					<input type="text" class="widefat" name="<?php echo esc_attr($name); ?>[pack_label]" value="<?php echo esc_attr((string) $line['pack_label']); ?>" placeholder="<?php esc_attr_e('190gr x 6', 'plugin-multi-packs'); ?>" />
				</td>
				<td>
					<input type="number" class="small-text" min="1" step="1" name="<?php echo esc_attr($name); ?>[units_per_pack]" value="<?php echo esc_attr((string) $line['units_per_pack']); ?>" />
				</td>
			<?php endif; ?>
			<td>
				<select name="<?php echo esc_attr($name); ?>[mode]" data-pack-mode>
					<option value="bogo" <?php selected('bogo', $line['mode']); ?>><?php esc_html_e('BOGO', 'plugin-multi-packs'); ?></option>
					<option value="fixed" <?php selected('fixed', $line['mode']); ?>><?php esc_html_e('Fixed price', 'plugin-multi-packs'); ?></option>
				</select>
			</td>
			<td>
				<div class="wc-multi-packs-admin__mode-fields" data-mode-fields>
					<span data-mode="bogo">
						<input type="number" class="small-text" min="0" step="1" name="<?php echo esc_attr($name); ?>[bogo_buy]" value="<?php echo esc_attr((string) $line['bogo_buy']); ?>" placeholder="<?php esc_attr_e('Bought', 'plugin-multi-packs'); ?>" />
						+
						<input type="number" class="small-text" min="0" step="1" name="<?php echo esc_attr($name); ?>[bogo_free]" value="<?php echo esc_attr((string) $line['bogo_free']); ?>" placeholder="<?php esc_attr_e('Free', 'plugin-multi-packs'); ?>" />
					</span>
					<span data-mode="fixed">
						<input type="number" class="small-text" min="0" step="0.01" name="<?php echo esc_attr($name); ?>[fixed_price]" value="<?php echo esc_attr((string) $line['fixed_price']); ?>" placeholder="<?php esc_attr_e('Pack price', 'plugin-multi-packs'); ?>" />
					</span>
				</div>
			</td>
			<td>
				<button type="button" class="button-link-delete" data-remove-line><?php esc_html_e('Remove', 'plugin-multi-packs'); ?></button>
			</td>
		</tr>
		<?php
	}

	public function save_product_meta_box(int $post_id): void {
		if (! isset($_POST['wc_multi_packs_meta_box_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_multi_packs_meta_box_nonce'])), 'wc_multi_packs_save_meta_box')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		// Save the "disabled" flag.
		if (! empty($_POST['wc_multi_packs_disabled'])) {
			update_post_meta($post_id, self::META_DISABLED, '1');
		} else {
			delete_post_meta($post_id, self::META_DISABLED);
		}

		$raw_groups = $_POST['wc_multi_packs_groups'] ?? [];
		$groups     = $this->sanitize_groups($raw_groups);

		if ([] === $groups) {
			delete_post_meta($post_id, self::META_KEY);
			return;
		}

		update_post_meta($post_id, self::META_KEY, $groups);
	}

	public function enqueue_admin_assets(string $hook): void {
		$screen = get_current_screen();

		if (! $screen instanceof \WP_Screen) {
			return;
		}

		if ('product' === $screen->post_type && 'post' === $screen->base) {
			wp_register_script('wc-multi-packs-admin', '', [], '1.0.0', true);
			wp_enqueue_script('wc-multi-packs-admin');
			wp_add_inline_script('wc-multi-packs-admin', $this->get_admin_script());
			wp_register_style('wc-multi-packs-admin', false, [], '1.0.0');
			wp_enqueue_style('wc-multi-packs-admin');
			wp_add_inline_style('wc-multi-packs-admin', $this->get_admin_style());
		}

		if ('woocommerce_page_wc-multi-packs' === $hook) {
			wp_register_script('wc-multi-packs-admin', '', [], '1.0.0', true);
			wp_enqueue_script('wc-multi-packs-admin');
			wp_add_inline_script('wc-multi-packs-admin', $this->get_admin_script());
			wp_register_style('wc-multi-packs-admin', false, [], '1.0.0');
			wp_enqueue_style('wc-multi-packs-admin');
			wp_add_inline_style('wc-multi-packs-admin', $this->get_admin_style());
		}
	}

	public function enqueue_front_assets(): void {
		if (! function_exists('is_product') || ! is_product()) {
			return;
		}

		$product_id = get_the_ID();
		if (! $product_id || ! $this->product_has_packs((int) $product_id)) {
			return;
		}

		wp_register_style('wc-multi-packs', WC_MULTI_PACKS_URL . 'assets/css/wc-multi-packs.css', [], '1.0.0');
		wp_register_script('wc-multi-packs', '', [], '1.0.0', true);
		wp_enqueue_style('wc-multi-packs');
		wp_enqueue_script('wc-multi-packs');

		wp_localize_script(
			'wc-multi-packs',
			'wcMultiPacksData',
			[
				'currencySymbol' => get_woocommerce_currency_symbol(),
				'currencyPos'    => get_option('woocommerce_currency_pos', 'left'),
				'numDecimals'    => (string) wc_get_price_decimals(),
				'decimalSep'     => wc_get_price_decimal_separator(),
				'thousandSep'    => wc_get_price_thousand_separator(),
				/* translators: %s: formatted unit price */
				'ieLabel'        => __('(i.e. %s / unit)', 'plugin-multi-packs'),
			]
		);

		wp_add_inline_script('wc-multi-packs', $this->get_front_script());

		$settings = $this->get_settings();
		if ('' !== trim($settings['custom_css'])) {
			wp_add_inline_style('wc-multi-packs', $settings['custom_css']);
		}

		if ('' !== trim($settings['custom_js'])) {
			wp_add_inline_script('wc-multi-packs', $settings['custom_js']);
		}
	}

	public function render_pack_table(): void {
		if (! function_exists('wc_get_product')) {
			return;
		}

		global $product;

		if (! $product instanceof \WC_Product) {
			return;
		}

		$groups = $this->get_product_groups($product->get_id());
		if ([] === $groups) {
			return;
		}

		$is_variable = $product instanceof \WC_Product_Variable;

		// Collect variation attribute input names so they can be injected into every form.
		$variation_attribute_keys = [];
		if ($is_variable) {
			foreach (array_keys($product->get_variation_attributes()) as $attr_name) {
				$variation_attribute_keys[] = 'attribute_' . sanitize_title($attr_name);
			}
		}

		?>
		<div class="wc-multi-packs<?php echo $is_variable ? ' wc-multi-packs--variable' : ''; ?>"<?php echo $is_variable ? ' data-variable="1"' : ''; ?>>
			<h3 class="wc-multi-packs__title"><?php esc_html_e('Order by packs', 'plugin-multi-packs'); ?></h3>
			<?php foreach ($groups as $group_index => $group) : ?>
				<div class="wc-multi-packs__group">
					<?php if (! empty($group['group_title'])) : ?>
						<h4 class="wc-multi-packs__group-title"><?php echo esc_html($group['group_title']); ?></h4>
					<?php endif; ?>
					<table class="shop_table wc-multi-packs__table">
						<thead>
							<tr>
								<th><?php esc_html_e('Qty', 'plugin-multi-packs'); ?></th>
								<th><?php esc_html_e('Pack', 'plugin-multi-packs'); ?></th>
								<th><?php esc_html_e('Price', 'plugin-multi-packs'); ?></th>
								<th><?php esc_html_e('Add', 'plugin-multi-packs'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($group['lines'] as $line_index => $line) : ?>
								<?php
								$price_html = $this->get_pack_price_html($product, $line);
								// For variable products the row is always rendered (hidden) so JS can update it on variant selection.
								if ('' === $price_html && ! $is_variable) {
									continue;
								}
								?>
								<tr
									data-pack-line="1"
									data-units-per-pack="<?php echo esc_attr((string) max(1, (int) ($line['units_per_pack'] ?? 1))); ?>"
									data-mode="<?php echo esc_attr((string) ($line['mode'] ?? 'bogo')); ?>"
									data-bogo-buy="<?php echo esc_attr((string) max(0, (int) ($line['bogo_buy'] ?? 0))); ?>"
									data-bogo-free="<?php echo esc_attr((string) max(0, (int) ($line['bogo_free'] ?? 0))); ?>"
									data-fixed-price="<?php echo esc_attr((string) max(0.0, (float) ($line['fixed_price'] ?? 0))); ?>"
									data-base-pack-label="<?php echo esc_attr((string) $line['pack_label']); ?>"
								>
									<td>
										<div class="quantity wc-multi-packs__quantity" data-pack-quantity>
											<button type="button" class="button wc-multi-packs__qty-button" data-pack-decrement>-</button>
											<input type="number" min="1" step="1" value="1" class="input-text qty text" name="wc_multi_packs_display_quantity_<?php echo esc_attr((string) $group_index . '_' . (string) $line_index); ?>" />
											<button type="button" class="button wc-multi-packs__qty-button" data-pack-increment>+</button>
										</div>
									</td>
									<td data-pack-label><?php echo esc_html((string) $line['pack_label']); ?></td>
									<td data-pack-price><?php echo wp_kses_post($price_html); ?></td>
									<td>
										<form method="post" class="wc-multi-packs__form">
											<input type="hidden" name="wc_multi_packs_product_id" value="<?php echo esc_attr((string) $product->get_id()); ?>" />
											<input type="hidden" name="wc_multi_packs_group_index" value="<?php echo esc_attr((string) $group_index); ?>" />
											<input type="hidden" name="wc_multi_packs_line_index" value="<?php echo esc_attr((string) $line_index); ?>" />
											<input type="hidden" name="wc_multi_packs_quantity" value="1" data-pack-quantity-input />
											<?php if ($is_variable) : ?>
												<input type="hidden" name="wc_multi_packs_variation_id" value="0" data-pack-variation-id />
												<?php foreach ($variation_attribute_keys as $attr_key) : ?>
													<input type="hidden" name="wc_multi_packs_variation_attrs[<?php echo esc_attr($attr_key); ?>]" value="" data-pack-variation-attr="<?php echo esc_attr($attr_key); ?>" />
												<?php endforeach; ?>
											<?php endif; ?>
											<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
											<button type="submit" class="button alt"><?php esc_html_e('ADD', 'plugin-multi-packs'); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function validate_pack_add_to_cart(bool $passed, int $product_id, int $quantity): bool {
		if (! isset($_POST['wc_multi_packs_product_id'])) {
			return $passed;
		}

		$post_product_id = absint(wp_unslash($_POST['wc_multi_packs_product_id']));

		if ($post_product_id !== $product_id) {
			return $passed;
		}

		if ($quantity < 1) {
			wc_add_notice(__('Invalid pack quantity.', 'plugin-multi-packs'), 'error');
			return false;
		}

		return $passed;
	}

	public function handle_pack_add_to_cart(): void {
		if (! isset($_POST['wc_multi_packs_product_id'], $_POST[self::NONCE_NAME])) {
			return;
		}

		if (! function_exists('WC')) {
			return;
		}

		$nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
		if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			return;
		}

		$product_id  = absint(wp_unslash($_POST['wc_multi_packs_product_id']));
		$group_index = absint(wp_unslash($_POST['wc_multi_packs_group_index'] ?? 0));
		$line_index  = absint(wp_unslash($_POST['wc_multi_packs_line_index'] ?? 0));
		$pack_qty    = max(1, absint(wp_unslash($_POST['wc_multi_packs_quantity'] ?? 1)));
		$groups      = $this->get_product_groups($product_id);
		$line        = $groups[$group_index]['lines'][$line_index] ?? null;

		if (! is_array($line)) {
			wc_add_notice(__('Selected pack is unavailable.', 'plugin-multi-packs'), 'error');
			return;
		}

		$product = wc_get_product($product_id);
		if (! $product instanceof \WC_Product || ! $product->is_purchasable()) {
			wc_add_notice(__('This product cannot be purchased right now.', 'plugin-multi-packs'), 'error');
			return;
		}

		// Resolve variation for variable products.
		$variation_id    = absint(wp_unslash($_POST['wc_multi_packs_variation_id'] ?? 0));
		$variation_attrs = [];
		if ($variation_id > 0) {
			$raw_attrs = isset($_POST['wc_multi_packs_variation_attrs']) && is_array($_POST['wc_multi_packs_variation_attrs'])
				? $_POST['wc_multi_packs_variation_attrs'] : [];
			foreach ($raw_attrs as $k => $v) {
				$clean_key = sanitize_key(wp_unslash((string) $k));
				if ('' !== $clean_key) {
					$variation_attrs[$clean_key] = sanitize_text_field(wp_unslash((string) $v));
				}
			}
		}

		if ($product instanceof \WC_Product_Variable && 0 === $variation_id) {
			wc_add_notice(__('Please select a product variant before adding to cart.', 'plugin-multi-packs'), 'error');
			return;
		}

		// Use the variation's own price and stock when applicable.
		$variation_product = $variation_id > 0 ? (wc_get_product($variation_id) ?: $product) : $product;

		$units_per_pack = max(1, (int) $line['units_per_pack']);
		$unit_quantity  = $pack_qty * $units_per_pack;

		if (! $variation_product->has_enough_stock($unit_quantity)) {
			wc_add_notice(__('Not enough stock available for this pack.', 'plugin-multi-packs'), 'error');
			return;
		}

		$cart_item_data = [
			'wc_multi_pack' => [
				'group_title'       => (string) ($groups[$group_index]['group_title'] ?? ''),
				'pack_label'        => (string) $line['pack_label'],
				'units_per_pack'    => $units_per_pack,
				'requested_packs'   => $pack_qty,
				'mode'              => (string) $line['mode'],
				'bogo_buy'          => (int) $line['bogo_buy'],
				'bogo_free'         => (int) $line['bogo_free'],
				'fixed_price'       => (float) $line['fixed_price'],
				'base_unit_price'   => (float) $variation_product->get_price('edit'),
				'unique_key'        => wp_generate_uuid4(),
			],
		];

		$added = WC()->cart->add_to_cart($product_id, $unit_quantity, $variation_id, $variation_attrs, $cart_item_data);

		if ($added) {
			wc_add_notice(__('Pack added to cart.', 'plugin-multi-packs'), 'success');
			$redirect_url = wp_get_referer();
			if (! $redirect_url) {
				$redirect_url = get_permalink($product_id) ?: wc_get_cart_url();
			}
			wp_safe_redirect($redirect_url);
			exit;
		}

		wc_add_notice(__('Unable to add this pack to the cart.', 'plugin-multi-packs'), 'error');
	}

	public function apply_pack_pricing(\WC_Cart $cart): void {
		if (is_admin() && ! defined('DOING_AJAX')) {
			return;
		}

		foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
			if (! isset($cart_item['wc_multi_pack'], $cart_item['data']) || ! $cart_item['data'] instanceof \WC_Product) {
				continue;
			}

			$pack = $cart_item['wc_multi_pack'];
			$base_unit_price = isset($pack['base_unit_price']) ? (float) $pack['base_unit_price'] : (float) $cart_item['data']->get_regular_price();
			if ($base_unit_price <= 0) {
				$base_unit_price = (float) $cart_item['data']->get_price('edit');
			}

			$quantity       = max(1, (int) $cart_item['quantity']);
			$units_per_pack = max(1, (int) ($pack['units_per_pack'] ?? 1));
			$pack_count     = max(1, (int) ceil($quantity / $units_per_pack));
			$mode           = (string) ($pack['mode'] ?? 'bogo');

			if ('fixed' === $mode) {
				$fixed_price = max(0, (float) ($pack['fixed_price'] ?? 0));
				$total_price = $fixed_price * $pack_count;
				$unit_price  = $quantity > 0 ? $total_price / $quantity : $base_unit_price;
				$cart_item['data']->set_price($unit_price);
				$cart->cart_contents[ $cart_item_key ]['wc_multi_pack']['requested_packs'] = $pack_count;
				continue;
			}

			$buy              = max(0, (int) ($pack['bogo_buy'] ?? 0));
			$free             = max(0, (int) ($pack['bogo_free'] ?? 0));
			$discounted_total = $base_unit_price * $quantity;

			if ($buy > 0 && $free > 0) {
				$cycle_units     = $buy + $free;
				$cycles          = intdiv($quantity, $cycle_units);
				$free_units      = $cycles * $free;
				$discounted_total = $base_unit_price * max(0, $quantity - $free_units);
			}

			$unit_price = $quantity > 0 ? $discounted_total / $quantity : $base_unit_price;
			$cart_item['data']->set_price($unit_price);
			$cart->cart_contents[ $cart_item_key ]['wc_multi_pack']['requested_packs'] = $pack_count;
		}
	}

	public function render_cart_item_data(array $item_data, array $cart_item): array {
		if (! isset($cart_item['wc_multi_pack'])) {
			return $item_data;
		}

		$pack = $cart_item['wc_multi_pack'];

		if (! empty($pack['group_title'])) {
			$item_data[] = [
				'key'   => __('Pack group', 'plugin-multi-packs'),
				'value' => wc_clean((string) $pack['group_title']),
			];
		}

		$item_data[] = [
			'key'   => __('Pack', 'plugin-multi-packs'),
			'value' => wc_clean((string) ($pack['pack_label'] ?? '')),
		];

		$item_data[] = [
			'key'   => __('Requested packs', 'plugin-multi-packs'),
			'value' => (string) max(1, (int) ($pack['requested_packs'] ?? 1)),
		];

		return $item_data;
	}

	public function render_locked_cart_quantity(string $product_quantity, string $cart_item_key, array $cart_item): string {
		if (! isset($cart_item['wc_multi_pack'])) {
			return $product_quantity;
		}

		$pack = $cart_item['wc_multi_pack'];
		$requested_packs = max(1, (int) ($pack['requested_packs'] ?? 1));
		$units_per_pack  = max(1, (int) ($pack['units_per_pack'] ?? 1));

		return sprintf(
			'%1$s<br /><small>%2$s</small>',
			esc_html((string) $cart_item['quantity']),
			esc_html(sprintf(__('%1$d pack(s) × %2$d units', 'plugin-multi-packs'), $requested_packs, $units_per_pack))
		);
	}

	public function render_widget_cart_item_quantity(string $product_quantity, array $cart_item, string $cart_item_key): string {
		if (! isset($cart_item['wc_multi_pack'])) {
			return $product_quantity;
		}

		$pack = $cart_item['wc_multi_pack'];
		$mode = (string) ($pack['mode'] ?? 'bogo');

		if ('bogo' !== $mode) {
			return $product_quantity;
		}

		$buy  = max(0, (int) ($pack['bogo_buy'] ?? 0));
		$free = max(0, (int) ($pack['bogo_free'] ?? 0));

		if ($buy <= 0 || $free <= 0) {
			return $product_quantity;
		}

		$quantity        = max(1, (int) $cart_item['quantity']);
		$cycle_units     = $buy + $free;
		$cycles          = intdiv($quantity, $cycle_units);
		$free_units      = $cycles * $free;
		$paid_units      = $quantity - $free_units;

		$product = isset($cart_item['data']) && $cart_item['data'] instanceof \WC_Product ? $cart_item['data'] : null;

		$base_unit_price = isset($pack['base_unit_price']) ? (float) $pack['base_unit_price'] : 0.0;
		if ($base_unit_price <= 0 && $product) {
			$base_unit_price = (float) $product->get_regular_price();
		}
		if ($base_unit_price <= 0 && $product) {
			$base_unit_price = (float) $product->get_price('edit');
		}

		$display_price = $product
			? wc_get_price_to_display($product, ['price' => $base_unit_price])
			: $base_unit_price;

		return sprintf(
			'<span class="quantity">%1$s &times; %2$s<br /><small>%3$s</small></span>',
			esc_html((string) $paid_units),
			wc_price($display_price),
			esc_html(sprintf(
				/* translators: %d: number of free units */
				_n('%d offert', '%d offerts', $free_units, 'plugin-multi-packs'),
				$free_units
			))
		);
	}

	private function get_pack_price_html(\WC_Product $product, array $line): string {
		$units_per_pack = max(1, (int) ($line['units_per_pack'] ?? 1));
		$base_price     = (float) wc_get_price_to_display($product);
		$mode           = (string) ($line['mode'] ?? 'bogo');

		if ('fixed' === $mode) {
			$fixed_price    = max(0, (float) ($line['fixed_price'] ?? 0));
			$unit_price     = $units_per_pack > 0 ? $fixed_price / $units_per_pack : 0;

			return sprintf(
				'%1$s <small class="wc-multi-packs__unit-price">%2$s</small>',
				wc_price($fixed_price),
				/* translators: %s: formatted unit price */
				esc_html(sprintf(__('(i.e. %s / unit)', 'plugin-multi-packs'), strip_tags(wc_price($unit_price))))
			);
		}

		$buy  = max(0, (int) ($line['bogo_buy'] ?? 0));
		$free = max(0, (int) ($line['bogo_free'] ?? 0));

		$total_price = $base_price * $units_per_pack;

		if ($buy > 0 && $free > 0) {
			$cycle_units = $buy + $free;
			$cycles      = intdiv($units_per_pack, $cycle_units);
			$free_units  = $cycles * $free;
			$total_price = $base_price * max(0, $units_per_pack - $free_units);
			$unit_price  = $units_per_pack > 0 ? $total_price / $units_per_pack : 0;

			return sprintf(
				'%1$s <small class="wc-multi-packs__unit-price">%2$s</small>',
				wc_price($total_price),
				/* translators: %s: formatted unit price */
				esc_html(sprintf(__('(i.e. %s / unit)', 'plugin-multi-packs'), strip_tags(wc_price($unit_price))))
			);
		}

		$unit_price = $units_per_pack > 0 ? $total_price / $units_per_pack : $base_price;

		return sprintf(
			'%1$s <small class="wc-multi-packs__unit-price">%2$s</small>',
			wc_price($total_price),
			/* translators: %s: formatted unit price */
			esc_html(sprintf(__('(i.e. %s / unit)', 'plugin-multi-packs'), strip_tags(wc_price($unit_price))))
		);
	}

	private function sanitize_groups(mixed $groups, bool $is_global = false): array {
		if (! is_array($groups)) {
			return [];
		}

		$sanitized = [];

		foreach ($groups as $group) {
			if (! is_array($group)) {
				continue;
			}

			$group_title    = sanitize_text_field(wp_unslash((string) ($group['group_title'] ?? '')));
			$tiers_override = $this->sanitize_tiers_text($group['tiers_override'] ?? '');
			$lines          = [];

			if (isset($group['lines']) && is_array($group['lines'])) {
				foreach ($group['lines'] as $line) {
					if (! is_array($line)) {
						continue;
					}

					$pack_label     = sanitize_text_field(wp_unslash((string) ($line['pack_label'] ?? '')));
					$units_per_pack = max(0, absint($line['units_per_pack'] ?? 0));
					$mode           = 'fixed' === ($line['mode'] ?? '') ? 'fixed' : 'bogo';
					$bogo_buy       = max(0, absint($line['bogo_buy'] ?? 0));
					$bogo_free      = max(0, absint($line['bogo_free'] ?? 0));
					$fixed_price    = wc_format_decimal(wp_unslash((string) ($line['fixed_price'] ?? '')));

					if (! $is_global && ('' === $pack_label || $units_per_pack < 1)) {
						continue;
					}

					$lines[] = [
						'pack_label'     => $pack_label,
						'units_per_pack' => $units_per_pack,
						'mode'           => $mode,
						'bogo_buy'       => $bogo_buy,
						'bogo_free'      => $bogo_free,
						'fixed_price'    => (float) $fixed_price,
					];
				}
			}

			if ([] === $lines) {
				continue;
			}

			$sanitized[] = [
				'group_title'    => $group_title,
				'tiers_override' => $tiers_override,
				'lines'          => $lines,
			];
		}

		return $sanitized;
	}

	private function sanitize_tiers_text(mixed $tiers): string {
		$tiers = sanitize_textarea_field(wp_unslash((string) $tiers));
		$tiers = preg_replace('/[^0-9,\n\r ]+/', '', $tiers);

		return is_string($tiers) ? trim($tiers) : '';
	}

	private function get_settings(): array {
		return wp_parse_args(
			get_option(self::OPTION_KEY, []),
			$this->get_default_settings()
		);
	}

	private function get_default_settings(): array {
		return [
			'default_tiers' => "6\n12\n24",
			'global_groups' => [],
			'custom_css'    => '',
			'custom_js'     => '',
		];
	}

	private function get_raw_product_groups(int $product_id): array {
		$groups = get_post_meta($product_id, self::META_KEY, true);

		return is_array($groups) ? $groups : [];
	}

	private function get_product_groups(int $product_id): array {
		// If packs are explicitly disabled for this product, return nothing.
		if (get_post_meta($product_id, self::META_DISABLED, true)) {
			return [];
		}

		// Use per-product groups when set; otherwise fall back to global groups.
		$groups = $this->get_raw_product_groups($product_id);
		if ([] !== $groups) {
			return $groups;
		}

		$global_groups = $this->get_settings()['global_groups'] ?? [];
		if ([] === $global_groups) {
			return [];
		}

		return $this->expand_global_groups($global_groups);
	}

	private function expand_global_groups(array $global_groups): array {
		$settings      = $this->get_settings();
		$default_tiers = $this->parse_tiers($settings['default_tiers']);
		$expanded      = [];

		foreach ($global_groups as $group) {
			$tiers_text = trim((string) ($group['tiers_override'] ?? ''));
			$tiers      = '' !== $tiers_text ? $this->parse_tiers($tiers_text) : $default_tiers;

			if ([] === $tiers) {
				continue;
			}

			$bogo_lines     = is_array($group['lines']) ? $group['lines'] : [];
			$expanded_lines = [];

			foreach ($tiers as $tier) {
				foreach ($bogo_lines as $bogo_line) {
					$expanded_lines[] = [
						'pack_label'     => 'x' . $tier,
						'units_per_pack' => $tier,
						'mode'           => (string) ($bogo_line['mode'] ?? 'bogo'),
						'bogo_buy'       => (int) ($bogo_line['bogo_buy'] ?? 0),
						'bogo_free'      => (int) ($bogo_line['bogo_free'] ?? 0),
						'fixed_price'    => (float) ($bogo_line['fixed_price'] ?? 0.0),
					];
				}
			}

			if ([] !== $expanded_lines) {
				$expanded[] = [
					'group_title'    => (string) ($group['group_title'] ?? ''),
					'tiers_override' => (string) ($group['tiers_override'] ?? ''),
					'lines'          => $expanded_lines,
				];
			}
		}

		return $expanded;
	}

	private function parse_tiers(string $tiers_text): array {
		$values = preg_split('/[\s,]+/', $tiers_text, -1, PREG_SPLIT_NO_EMPTY);
		$result = [];

		foreach ($values as $v) {
			$n = (int) $v;
			if ($n > 0) {
				$result[] = $n;
			}
		}

		return $result;
	}

	private function product_has_packs(int $product_id): bool {
		return [] !== $this->get_product_groups($product_id);
	}

	private function get_admin_script(): string {
		return <<<JS
(function(){const groupTemplate=document.getElementById('tmpl-wc-multi-packs-group');const lineTemplate=document.getElementById('tmpl-wc-multi-packs-line');const refreshModes=(scope)=>{scope.querySelectorAll('[data-pack-mode]').forEach((select)=>{const wrapper=select.closest('tr').querySelector('[data-mode-fields]');if(!wrapper){return;}wrapper.querySelectorAll('[data-mode]').forEach((node)=>{node.style.display=node.getAttribute('data-mode')===select.value?'inline-flex':'none';});});};const nextGroupIndex=(container)=>container.querySelectorAll('.wc-multi-packs-admin__group').length;const nextLineIndex=(tbody)=>tbody.querySelectorAll('.wc-multi-packs-admin__line').length;const getNameBase=(el)=>{const admin=el.closest('.wc-multi-packs-admin');return(admin&&admin.dataset.nameBase)?admin.dataset.nameBase:'wc_multi_packs_groups';};document.addEventListener('click',(event)=>{const addGroup=event.target.closest('[data-add-group]');if(addGroup&&groupTemplate){event.preventDefault();const admin=addGroup.closest('.wc-multi-packs-admin');if(!admin){return;}const container=admin.querySelector('[data-groups]');if(!container){return;}const nameBase=getNameBase(addGroup);container.insertAdjacentHTML('beforeend',groupTemplate.innerHTML.replaceAll('__name_base__',nameBase).replaceAll('__group_index__',String(nextGroupIndex(container))));refreshModes(container);return;}const addLine=event.target.closest('[data-add-line]');if(addLine&&lineTemplate){event.preventDefault();const groupIndex=addLine.getAttribute('data-group-index');const tbody=addLine.closest('.inside').querySelector('[data-lines]');if(!tbody){return;}const nameBase=getNameBase(addLine);tbody.insertAdjacentHTML('beforeend',lineTemplate.innerHTML.replaceAll('__name_base__',nameBase).replaceAll('__group_index__',String(groupIndex)).replaceAll('__line_index__',String(nextLineIndex(tbody))));refreshModes(tbody);return;}const removeGroup=event.target.closest('[data-remove-group]');if(removeGroup){event.preventDefault();removeGroup.closest('.wc-multi-packs-admin__group')?.remove();return;}const removeLine=event.target.closest('[data-remove-line]');if(removeLine){event.preventDefault();const tbody=removeLine.closest('tbody');removeLine.closest('tr')?.remove();if(tbody&&tbody.children.length===0&&lineTemplate){const groupIndex=tbody.getAttribute('data-group-index')||'0';const nameBase=getNameBase(tbody);tbody.insertAdjacentHTML('beforeend',lineTemplate.innerHTML.replaceAll('__name_base__',nameBase).replaceAll('__group_index__',groupIndex).replaceAll('__line_index__','0'));}refreshModes(removeLine.closest('.wc-multi-packs-admin')||document);}});document.addEventListener('change',(event)=>{if(event.target.matches('[data-pack-mode]')){refreshModes(event.target.closest('tr'));}});document.querySelectorAll('[data-groups]').forEach((container)=>{refreshModes(container);});})(); 
JS;
	}

	private function get_admin_style(): string {
		return '.wc-multi-packs-admin__mode-fields span{display:inline-flex;gap:6px;align-items:center}.wc-multi-packs-admin__group{margin-bottom:16px}.wc-multi-packs-admin__group .inside{padding-top:12px}';
	}

	private function get_front_script(): string {
		return "document.addEventListener('click',function(event){const button=event.target.closest('[data-pack-increment],[data-pack-decrement]');if(!button){return;}const wrapper=button.closest('[data-pack-quantity]');const input=wrapper?wrapper.querySelector(\"input[type='number']\"):null;if(!input){return;}const current=Math.max(1,parseInt(input.value||'1',10));input.value=button.hasAttribute('data-pack-increment')?current+1:Math.max(1,current-1);input.dispatchEvent(new Event('input',{bubbles:true}));});document.addEventListener('input',function(event){const input=event.target.closest(\".wc-multi-packs__quantity input[type='number']\");if(!input){return;}const row=input.closest('tr');const target=row?row.querySelector('[data-pack-quantity-input]'):null;if(target){target.value=Math.max(1,parseInt(input.value||'1',10));}});" . $this->get_variable_product_script();
	}

	private function get_variable_product_script(): string {
		return <<<'JS'
(function(){var wrap=document.querySelector('.wc-multi-packs[data-variable]');if(!wrap){return;}var vform=document.querySelector('.variations_form');if(!vform||typeof jQuery==='undefined'){return;}var d=typeof wcMultiPacksData!=='undefined'?wcMultiPacksData:{};function fmtPrice(n){var nd=parseInt(d.numDecimals||'2',10);var fixed=(+n).toFixed(nd);var parts=fixed.split('.');var ts=d.thousandSep||'';if(ts){parts[0]=parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,ts);}var num=nd>0?parts.join(d.decimalSep||'.'):parts[0];var sym=d.currencySymbol||'';var pos=d.currencyPos||'left';if(pos==='left')return sym+num;if(pos==='right')return num+sym;if(pos==='left_space')return sym+'\u00a0'+num;return num+'\u00a0'+sym;}function buildPrice(total,perUnit){var lbl=(d.ieLabel||'(i.e. %s / unit)').replace('%s',fmtPrice(perUnit));return fmtPrice(total)+' <small class="wc-multi-packs__unit-price">'+lbl+'</small>';}function calcRow(unitPrice,row){var n=Math.max(1,parseInt(row.dataset.unitsPerPack||'1',10));var mode=row.dataset.mode||'bogo';if(mode==='fixed'){var fp=parseFloat(row.dataset.fixedPrice)||0;return buildPrice(fp,n>0?fp/n:0);}var buy=parseInt(row.dataset.bogoBuy||'0',10);var free=parseInt(row.dataset.bogoFree||'0',10);var total=unitPrice*n;if(buy>0&&free>0){var cy=buy+free;var cycles=Math.floor(n/cy);total=unitPrice*Math.max(0,n-(cycles*free));}return buildPrice(total,n>0?total/n:unitPrice);}jQuery(vform).on('found_variation',function(e,v){wrap.classList.remove('wc-multi-packs--variable');var label='';document.querySelectorAll('.variations select').forEach(function(s){var o=s.options[s.selectedIndex];if(o&&o.value){label+=(label?' ':'')+o.text;}});var price=parseFloat(v.display_price)||0;var vid=parseInt(v.variation_id,10)||0;var attrs=v.attributes||{};wrap.querySelectorAll('[data-pack-line]').forEach(function(row){var lc=row.querySelector('[data-pack-label]');if(lc){var base=row.dataset.basePackLabel||'';lc.textContent=label?(label+' '+base):base;}var pc=row.querySelector('[data-pack-price]');if(pc){pc.innerHTML=calcRow(price,row);}var vidIn=row.querySelector('[data-pack-variation-id]');if(vidIn){vidIn.value=String(vid);}row.querySelectorAll('[data-pack-variation-attr]').forEach(function(inp){var k=inp.getAttribute('data-pack-variation-attr');inp.value=(k&&attrs[k])?attrs[k]:'';});});});jQuery(vform).on('reset_data',function(){wrap.classList.add('wc-multi-packs--variable');});})();
JS;
	}
}
