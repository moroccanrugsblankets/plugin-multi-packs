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
	private const OPTION_KEY = 'wc_multi_packs_settings';
	private const META_KEY = '_wc_multi_packs_groups';
	private const NONCE_ACTION = 'wc_multi_packs_add_to_cart';
	private const NONCE_NAME = 'wc_multi_packs_nonce';

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
				echo '<p>' . esc_html__('Configure default pack values and optional storefront custom code.', 'plugin-multi-packs') . '</p>';
			},
			'wc_multi_packs'
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

	public function register_product_meta_box(): void {
		add_meta_box(
			'wc-multi-packs',
			__('Gestion des Packs', 'plugin-multi-packs'),
			[$this, 'render_product_meta_box'],
			'product',
			'normal',
			'default'
		);
	}

	public function render_product_meta_box(\WP_Post $post): void {
		wp_nonce_field('wc_multi_packs_save_meta_box', 'wc_multi_packs_meta_box_nonce');

		$groups        = $this->get_product_groups($post->ID);
		$global_tiers  = $this->get_settings()['default_tiers'];
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
		<div class="wc-multi-packs-admin" data-default-tiers="<?php echo esc_attr($global_tiers); ?>">
			<p><?php esc_html_e('Add one or more pack groups. Each row can use a BOGO discount or a fixed pack total.', 'plugin-multi-packs'); ?></p>
			<div class="wc-multi-packs-admin__groups" data-groups>
				<?php foreach ($groups as $group_index => $group) : ?>
					<?php $this->render_group_editor($group_index, $group); ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" data-add-group><?php esc_html_e('Ajouter un groupe', 'plugin-multi-packs'); ?></button>
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

	private function render_group_editor(int|string $group_index, array $group, bool $is_template = false): void {
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
				<h2 class="hndle"><?php esc_html_e('Groupe de packs', 'plugin-multi-packs'); ?></h2>
				<div class="handle-actions">
					<button type="button" class="button-link-delete" data-remove-group><?php esc_html_e('Supprimer', 'plugin-multi-packs'); ?></button>
				</div>
			</div>
			<div class="inside">
				<p>
					<label>
						<strong><?php esc_html_e('Titre du groupe', 'plugin-multi-packs'); ?></strong><br />
						<input type="text" class="widefat" name="wc_multi_packs_groups[<?php echo esc_attr((string) $group_index); ?>][group_title]" value="<?php echo esc_attr((string) $group['group_title']); ?>" />
					</label>
				</p>
				<p>
					<label>
						<strong><?php esc_html_e('Paliers spécifiques (optionnel)', 'plugin-multi-packs'); ?></strong><br />
						<input type="text" class="widefat" name="wc_multi_packs_groups[<?php echo esc_attr((string) $group_index); ?>][tiers_override]" value="<?php echo esc_attr((string) $group['tiers_override']); ?>" placeholder="<?php esc_attr_e('Ex: 6, 12, 24', 'plugin-multi-packs'); ?>" />
					</label>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Conditionnement', 'plugin-multi-packs'); ?></th>
							<th><?php esc_html_e('Unités / lot', 'plugin-multi-packs'); ?></th>
							<th><?php esc_html_e('Mode', 'plugin-multi-packs'); ?></th>
							<th><?php esc_html_e('BOGO / Prix fixe', 'plugin-multi-packs'); ?></th>
							<th><?php esc_html_e('Action', 'plugin-multi-packs'); ?></th>
						</tr>
					</thead>
					<tbody data-lines data-group-index="<?php echo esc_attr((string) $group_index); ?>">
						<?php foreach ($lines as $line_index => $line) : ?>
							<?php $this->render_line_editor($group_index, $line_index, $line, $is_template); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="button" class="button" data-add-line data-group-index="<?php echo esc_attr((string) $group_index); ?>"><?php esc_html_e('Ajouter une ligne', 'plugin-multi-packs'); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	private function render_line_editor(int|string $group_index, int|string $line_index, array $line, bool $is_template = false): void {
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
		$name = 'wc_multi_packs_groups[' . $group_index . '][lines][' . $line_index . ']';
		?>
		<tr class="wc-multi-packs-admin__line">
			<td>
				<input type="text" class="widefat" name="<?php echo esc_attr($name); ?>[pack_label]" value="<?php echo esc_attr((string) $line['pack_label']); ?>" placeholder="<?php esc_attr_e('190gr x 6', 'plugin-multi-packs'); ?>" />
			</td>
			<td>
				<input type="number" class="small-text" min="1" step="1" name="<?php echo esc_attr($name); ?>[units_per_pack]" value="<?php echo esc_attr((string) $line['units_per_pack']); ?>" />
			</td>
			<td>
				<select name="<?php echo esc_attr($name); ?>[mode]" data-pack-mode>
					<option value="bogo" <?php selected('bogo', $line['mode']); ?>><?php esc_html_e('BOGO', 'plugin-multi-packs'); ?></option>
					<option value="fixed" <?php selected('fixed', $line['mode']); ?>><?php esc_html_e('Prix fixe', 'plugin-multi-packs'); ?></option>
				</select>
			</td>
			<td>
				<div class="wc-multi-packs-admin__mode-fields" data-mode-fields>
					<span data-mode="bogo">
						<input type="number" class="small-text" min="0" step="1" name="<?php echo esc_attr($name); ?>[bogo_buy]" value="<?php echo esc_attr((string) $line['bogo_buy']); ?>" placeholder="<?php esc_attr_e('Achetés', 'plugin-multi-packs'); ?>" />
						+
						<input type="number" class="small-text" min="0" step="1" name="<?php echo esc_attr($name); ?>[bogo_free]" value="<?php echo esc_attr((string) $line['bogo_free']); ?>" placeholder="<?php esc_attr_e('Offerts', 'plugin-multi-packs'); ?>" />
					</span>
					<span data-mode="fixed">
						<input type="number" class="small-text" min="0" step="0.01" name="<?php echo esc_attr($name); ?>[fixed_price]" value="<?php echo esc_attr((string) $line['fixed_price']); ?>" placeholder="<?php esc_attr_e('Prix du lot', 'plugin-multi-packs'); ?>" />
					</span>
				</div>
			</td>
			<td>
				<button type="button" class="button-link-delete" data-remove-line><?php esc_html_e('Supprimer', 'plugin-multi-packs'); ?></button>
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
		wp_register_script('wc-multi-packs', WC_MULTI_PACKS_URL . 'assets/js/wc-multi-packs.js', [], '1.0.0', true);
		wp_enqueue_style('wc-multi-packs');
		wp_enqueue_script('wc-multi-packs');

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

		?>
		<div class="wc-multi-packs">
			<h3 class="wc-multi-packs__title"><?php esc_html_e('Commander par lots', 'plugin-multi-packs'); ?></h3>
			<?php foreach ($groups as $group_index => $group) : ?>
				<div class="wc-multi-packs__group">
					<?php if (! empty($group['group_title'])) : ?>
						<h4 class="wc-multi-packs__group-title"><?php echo esc_html($group['group_title']); ?></h4>
					<?php endif; ?>
					<table class="shop_table wc-multi-packs__table">
						<thead>
							<tr>
								<th><?php esc_html_e('Qté', 'plugin-multi-packs'); ?></th>
								<th><?php esc_html_e('Cond.', 'plugin-multi-packs'); ?></th>
								<th><?php esc_html_e('Prix', 'plugin-multi-packs'); ?></th>
								<th><?php esc_html_e('Ajout', 'plugin-multi-packs'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($group['lines'] as $line_index => $line) : ?>
								<?php
								$price_html = $this->get_pack_price_html($product, $line);
								if ('' === $price_html) {
									continue;
								}
								?>
								<tr>
									<td>
										<div class="quantity wc-multi-packs__quantity" data-pack-quantity>
											<button type="button" class="button wc-multi-packs__qty-button" data-pack-decrement>-</button>
											<input type="number" min="1" step="1" value="1" class="input-text qty text" name="wc_multi_packs_display_quantity_<?php echo esc_attr((string) $group_index . '_' . (string) $line_index); ?>" />
											<button type="button" class="button wc-multi-packs__qty-button" data-pack-increment>+</button>
										</div>
									</td>
									<td><?php echo esc_html((string) $line['pack_label']); ?></td>
									<td><?php echo wp_kses_post($price_html); ?></td>
									<td>
										<form method="post" class="wc-multi-packs__form">
											<input type="hidden" name="wc_multi_packs_product_id" value="<?php echo esc_attr((string) $product->get_id()); ?>" />
											<input type="hidden" name="wc_multi_packs_group_index" value="<?php echo esc_attr((string) $group_index); ?>" />
											<input type="hidden" name="wc_multi_packs_line_index" value="<?php echo esc_attr((string) $line_index); ?>" />
											<input type="hidden" name="wc_multi_packs_quantity" value="1" data-pack-quantity-input />
											<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
											<button type="submit" class="button alt"><?php esc_html_e('AJOUTER', 'plugin-multi-packs'); ?></button>
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

		$units_per_pack = max(1, (int) $line['units_per_pack']);
		$unit_quantity  = $pack_qty * $units_per_pack;

		if (! $product->has_enough_stock($unit_quantity)) {
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
				'base_unit_price'   => (float) $product->get_price('edit'),
				'unique_key'        => wp_generate_uuid4(),
			],
		];

		$added = WC()->cart->add_to_cart($product_id, $unit_quantity, 0, [], $cart_item_data);

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

	private function get_pack_price_html(\WC_Product $product, array $line): string {
		$units_per_pack = max(1, (int) ($line['units_per_pack'] ?? 1));
		$base_price     = (float) wc_get_price_to_display($product);
		$mode           = (string) ($line['mode'] ?? 'bogo');

		if ('fixed' === $mode) {
			$fixed_price = max(0, (float) ($line['fixed_price'] ?? 0));
			return wc_price($fixed_price);
		}

		$buy  = max(0, (int) ($line['bogo_buy'] ?? 0));
		$free = max(0, (int) ($line['bogo_free'] ?? 0));

		$total_price = $base_price * $units_per_pack;

		if ($buy > 0 && $free > 0) {
			$cycle_units = $buy + $free;
			$cycles      = intdiv($units_per_pack, $cycle_units);
			$free_units  = $cycles * $free;
			$total_price = $base_price * max(0, $units_per_pack - $free_units);

			return sprintf(
				'%1$s <small class="wc-multi-packs__discount-note">%2$s</small>',
				wc_price($total_price),
				esc_html(sprintf(__('BOGO %1$d + %2$d', 'plugin-multi-packs'), $buy, $free))
			);
		}

		return wc_price($total_price);
	}

	private function sanitize_groups(mixed $groups): array {
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

					if ('' === $pack_label || $units_per_pack < 1) {
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
			'custom_css'    => '',
			'custom_js'     => '',
		];
	}

	private function get_product_groups(int $product_id): array {
		$groups = get_post_meta($product_id, self::META_KEY, true);

		return is_array($groups) ? $groups : [];
	}

	private function product_has_packs(int $product_id): bool {
		return [] !== $this->get_product_groups($product_id);
	}

	private function get_admin_script(): string {
		return <<<JS
(function(){const groupsContainer=document.querySelector('[data-groups]');if(!groupsContainer){return;}const groupTemplate=document.getElementById('tmpl-wc-multi-packs-group');const lineTemplate=document.getElementById('tmpl-wc-multi-packs-line');const refreshModes=(scope)=>{scope.querySelectorAll('[data-pack-mode]').forEach((select)=>{const wrapper=select.closest('tr').querySelector('[data-mode-fields]');if(!wrapper){return;}wrapper.querySelectorAll('[data-mode]').forEach((node)=>{node.style.display=node.getAttribute('data-mode')===select.value?'inline-flex':'none';});});};const nextGroupIndex=()=>groupsContainer.querySelectorAll('.wc-multi-packs-admin__group').length;const nextLineIndex=(tbody)=>tbody.querySelectorAll('.wc-multi-packs-admin__line').length;document.addEventListener('click',(event)=>{const addGroup=event.target.closest('[data-add-group]');if(addGroup&&groupTemplate){event.preventDefault();groupsContainer.insertAdjacentHTML('beforeend',groupTemplate.innerHTML.replaceAll('__group_index__',String(nextGroupIndex())));refreshModes(groupsContainer);return;}const addLine=event.target.closest('[data-add-line]');if(addLine&&lineTemplate){event.preventDefault();const groupIndex=addLine.getAttribute('data-group-index');const tbody=addLine.closest('.inside').querySelector('[data-lines]');if(!tbody){return;}tbody.insertAdjacentHTML('beforeend',lineTemplate.innerHTML.replaceAll('__group_index__',String(groupIndex)).replaceAll('__line_index__',String(nextLineIndex(tbody))));refreshModes(tbody);return;}const removeGroup=event.target.closest('[data-remove-group]');if(removeGroup){event.preventDefault();removeGroup.closest('.wc-multi-packs-admin__group')?.remove();return;}const removeLine=event.target.closest('[data-remove-line]');if(removeLine){event.preventDefault();const tbody=removeLine.closest('tbody');removeLine.closest('tr')?.remove();if(tbody&&tbody.children.length===0&&lineTemplate){const groupIndex=tbody.getAttribute('data-group-index')||'0';tbody.insertAdjacentHTML('beforeend',lineTemplate.innerHTML.replaceAll('__group_index__',groupIndex).replaceAll('__line_index__','0'));}refreshModes(groupsContainer);}});document.addEventListener('change',(event)=>{if(event.target.matches('[data-pack-mode]')){refreshModes(event.target.closest('tr'));}});refreshModes(groupsContainer);})(); 
JS;
	}

	private function get_admin_style(): string {
		return '.wc-multi-packs-admin__mode-fields span{display:inline-flex;gap:6px;align-items:center}.wc-multi-packs-admin__group{margin-bottom:16px}.wc-multi-packs-admin__group .inside{padding-top:12px}';
	}
}
