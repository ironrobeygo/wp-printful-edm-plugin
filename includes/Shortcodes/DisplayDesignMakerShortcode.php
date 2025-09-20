<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/PrintfulApiServices.php';
require_once PRINTFUL_CATALOG_PLUGIN_PATH . 'includes/Services/EDMAuthentication.php';

class DisplayDesignMakerShortcode {
	private $api;
	private $edm;
	private bool $registered = false;

	public function __construct(){
		$this->api = new PrintfulApi();
		$this->edm = new EDMAuthentication();
	}

	public function register(){
		if($this->registered) return;
		add_shortcode('printful_design_maker',                      array($this, 'render'));
		add_action('wp_ajax_get_edm_token',                         array($this, 'get_edm_token'));
		add_action('wp_ajax_nopriv_get_edm_token',                  array($this, 'get_edm_token'));

		$this->registered = true;
	}

	public function render($atts = []){
		$atts = shortcode_atts(array(
			'title' => 'Design Your Product',
			'product_id' => '',
			'design_id'  => '',
			'back_url' => ''
		), $atts);

		$back_url = '';
		if (!empty($atts['back_url'])) {
			$back_url = $atts['back_url'];
		} elseif (isset($_GET['back'])) {
			$back_url = wp_unslash((string) $_GET['back']);
		} else {
			$ref = wp_get_referer();
			if ($ref) {
				$site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
				$ref_host  = wp_parse_url($ref, PHP_URL_HOST);
				if ($site_host && $ref_host && strtolower($site_host) === strtolower($ref_host)) {
					$back_url = $ref;
				}
			}
			if (!$back_url) {
				$catalog_page_id = (int) get_option('pf_catalog_page_id', 0); // define this option if you like
				if ($catalog_page_id) {
					$back_url = get_permalink($catalog_page_id);
				}
			}
		}

		if (!$back_url) {
			$back_url = home_url('/');
		}
		$back_url = wp_validate_redirect($back_url, home_url('/'));

		$prefill = array(
			'design_name'         => '',
			'external_product_id' => '',
			'template_id'         => 0,
			'variant_id'          => 0,
		);

		if (empty($atts['product_id']) && isset($_GET['product_id'])) {
			$atts['product_id'] = sanitize_text_field($_GET['product_id']);
		}
		if (empty($atts['design_id']) && isset($_GET['design_id'])) {
			$atts['design_id'] = sanitize_text_field($_GET['design_id']);
			$row = $this->fetch_design_by_id((int) $atts['design_id'], true);
			if (! $row) {
				return '<p>Error: Design not found or you do not have access.</p>';
			}

			if (empty($atts['product_id']) && ! empty($row['product_id'])) {
				$atts['product_id'] = (string) $row['product_id'];
			}

			$prefill['design_name']         = (string) ($row['design_name'] ?? '');
			$prefill['external_product_id'] = (string) ($row['external_product_id'] ?? '');
			$prefill['template_id']         = (int)    ($row['template_id'] ?? 0);
			$prefill['variant_id']          = (int)    ($row['variant_id'] ?? 0);
		}

		$store_id = get_option('printful_store_id', '');

		if (empty($store_id)) {
			return '<div class="printful-error"><p>Error: Printful Store ID not configured. Please check plugin settings.</p></div>';
		}

		if (empty($atts['product_id']) && empty($atts['design_id'])) {
			return '<p>Error: No product ID or design ID specified. Please provide a product_id or design_id parameter.</p>';
		}

		$unique_id = 'printful_edm_' . uniqid();

		$variants_for_js = [];
		if (!empty($atts['product_id'])) {
			$resp = $this->api->request("v2/catalog-products/{$atts['product_id']}", 'GET');
			foreach (($resp['data']['variants'] ?? []) as $v) {
				$variants_for_js[] = [
					'id'    => (int) ($v['id'] ?? 0),
					'color' => (string) ($v['color'] ?? ''),
					'size'  => (string) ($v['size'] ?? ''),
				];
			}
		}

		$store_id   	= (int) get_option('printful_store_id');
		$design_name 	= $prefill['design_name'] ?: 'My Design';

		ob_start();
		?>
		<div id="design-maker-container"
			data-unique-id="<?php echo esc_attr($unique_id); ?>"
			data-product-id="<?php echo esc_attr($atts['product_id']); ?>"
			data-design-id="<?php echo esc_attr($atts['design_id']); ?>"
			data-external-product-id="<?php echo esc_attr($prefill['external_product_id']); ?>"
			data-store-id="<?php echo esc_attr($store_id); ?>"
			data-current-user-id="<?php echo (int) get_current_user_id(); ?>"
			data-variants='<?php echo wp_json_encode($variants_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>'>
			<div class="design-maker-header">
				<div class="design-info">
					<h2><?php echo $atts['title']; ?></h2>
					<input type="text" id="design-name-<?php echo $unique_id; ?>" class="design-name-input" placeholder="Enter design name..." value="<?php echo esc_attr($design_name); ?>" />
				</div>
				<div class="pf-toolbar" style="display:flex;align-items:center;gap:12px;justify-content:space-between;margin:8px 0;">
					<div class="pf-price" style="margin-top: 26px;">
						<span class="pf-price-label" style="opacity:.75;margin-right:6px;">Price:</span>
						<span id="pf-live-price">—</span>
					</div>
					<div class="design-actions">
						<button type="button" id="edm-save-btn" class="pf-btn pf-btn--primary">Save Design</button>
						<button type="button" onclick="location.href = '<?php echo esc_url($back_url); ?>';" class="pf-btn pf-btn--secondary">Back to Catalog Page</button>
					</div>
				</div>

			</div>

			<div id="printful-edm-container" class="edm-container">
				<div class="edm-loading">
					<p>Loading Printful Design Maker...</p>
					<div class="spinner"></div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function get_edm_token()
	{
		check_ajax_referer('printful_nonce', 'nonce');

		error_log('AJAX get_edm_token called');

		$product_id          = isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : '';
		$external_product_id = isset($_POST['external_product_id']) ? sanitize_text_field($_POST['external_product_id']) : '';

		if ($external_product_id === '') {
			$external_product_id = 'u' . (get_current_user_id() ?: 0) . ':p' . ($product_id ?: '0') . ':ts:' . time();
		}

		if (empty($product_id) && empty($external_product_id)) {
			wp_send_json_error(array('error' => 'missing_ids', 'message' => 'Product ID or External Product ID is required'));
			return;
		}

		$nonce_token = $this->edm->get_nonce_token($product_id, null, $external_product_id);

		error_log('EDM Nonce Token: ' . var_export($nonce_token, true));

		if (is_string($nonce_token) && $nonce_token !== '') {
			wp_send_json_success(array(
				'nonce'               => $nonce_token,               // <-- string, top-level
				'external_product_id' => $external_product_id,
				'product_id'          => $product_id,
			));
		} else {
			wp_send_json_error(array('error' => 'api_failed', 'message' => 'Unable to get EDM token'));
		}
	}

	private function fetch_design_by_id($design_id, $require_owner = true)
	{
		global $wpdb;
		$table     = $wpdb->prefix . 'printful_designs';
		$design_id = absint($design_id);
		if (! $design_id) {
			return null;
		}

		if ($require_owner && is_user_logged_in()) {
			$uid = get_current_user_id();
			$sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND user_id = %d LIMIT 1", $design_id, $uid);
		} else {
			$sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $design_id);
		}
		$row = $wpdb->get_row($sql, ARRAY_A);
		return $row ?: null;
	}
}
