<?php
/**
 * Plugin Name: DFPay Gateway (Site A)
 * Version: 1.0.2
 */
if (!defined('ABSPATH')) { exit; }
define('DFPAY_PROXY_CREATE_URL', 'https://digifarsh.net/wp-json/dfpay/v1/create');
define('DFPAY_ALLOW_CLOCK_SKEW', 300);

// Create assets directory and JS file on plugin activation
register_activation_hook(__FILE__, 'dfpay_create_assets');
function dfpay_create_assets() {
    $dir = plugin_dir_path(__FILE__) . 'assets';
    if (!file_exists($dir)) { 
        wp_mkdir_p($dir); 
    }
    $path = $dir . '/dfpay-blocks.js';
    
    $js_content = "jQuery(function($) {
        const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
        const { getSetting } = window.wc.wcSettings;
        
        const settings = getSetting('dfpay_gateway_data', {});
        
        const Label = () => {
            return wp.element.createElement('span', null, settings.title || 'DFPay');
        };
        
        const Content = () => {
            return wp.element.createElement('div', null, settings.description || 'پرداخت امن.');
        };
        
        const canMakePayment = () => {
            return true;
        };
        
        const DFPayMethod = {
            name: 'dfpay_gateway',
            label: wp.element.createElement(Label),
            content: wp.element.createElement(Content),
            edit: wp.element.createElement(Content),
            canMakePayment: canMakePayment,
            ariaLabel: settings.title || 'DFPay',
            supports: {
                features: settings.supports || ['products']
            }
        };
        
        registerPaymentMethod(DFPayMethod);
    });";
    
    file_put_contents($path, $js_content);
}

// Also create on init to ensure it exists
add_action('init', function() {
    $dir = plugin_dir_path(__FILE__) . 'assets';
    $path = $dir . '/dfpay-blocks.js';
    
    if (!file_exists($path)) {
        dfpay_create_assets();
    }
});

// Register payment gateway
add_filter('woocommerce_payment_gateways', function($gateways){
    $gateways[] = 'WC_Gateway_DFPay';
    return $gateways;
});

add_action('plugins_loaded', function(){
    if (!class_exists('WC_Payment_Gateway')) return;
    
    class WC_Gateway_DFPay extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'dfpay_gateway';
            $this->method_title = 'DFPay';
            $this->method_description = 'Redirects to proxy gateway.';
            $this->has_fields = false;
            $this->supports = ['products'];
            $this->init_form_fields();
            $this->init_settings();
            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }
        
        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'فعال',
                    'type' => 'checkbox',
                    'label' => 'فعال‌سازی DFPay',
                    'default' => 'yes'
                ],
                'title' => [
                    'title' => 'عنوان درگاه',
                    'type' => 'text',
                    'default' => 'پرداخت اینترنتی',
                ],
                'description' => [
                    'title' => 'توضیح',
                    'type' => 'textarea',
                    'default' => 'پرداخت امن.',
                ]
            ];
        }
        
        public function process_payment($order_id) {
            if (!defined('ZIBAL_SHARED_SECRET')) {
                wc_add_notice('Secret تعریف نشده است.', 'error');
                return ['result'=>'failure'];
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice('سفارش یافت نشد.', 'error');
                return ['result'=>'failure'];
            }
            
            $amount = (int) round($order->get_total());
            $payload = [
                'order_id' => (string)$order_id,
                'amount' => $amount,
            ];
            
            $json = wp_json_encode($payload);
            $ts = (string) time();
            $sig = base64_encode(hash_hmac('sha256', $ts . '.' . $json, ZIBAL_SHARED_SECRET, true));
            
            $res = wp_remote_post(DFPAY_PROXY_CREATE_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-DF-Timestamp' => $ts,
                    'X-DF-Signature' => $sig,
                ],
                'body' => $json,
                'timeout' => 20,
            ]);
            
            if (is_wp_error($res)) {
                wc_add_notice('خطا در اتصال به پراکسی.', 'error');
                return ['result'=>'failure'];
            }
            
            $code = wp_remote_retrieve_response_code($res);
            $body = json_decode(wp_remote_retrieve_body($res), true);
            
            if ($code !== 200 || empty($body['ok'])) {
                $raw = wp_remote_retrieve_body($res);
                $msg = !empty($body['err']) ? $body['err'] : ('status='.$code.' raw='.$raw);
                wc_add_notice('عدم موفقیت ایجاد تراکنش: ' . $msg, 'error');
                return ['result'=>'failure'];
            }
            
            $pay_url = $body['pay_url'];
            return ['result'=>'success','redirect'=>$pay_url];
        }
    }
});

// REST API for confirmation
add_action('rest_api_init', function () {
    register_rest_route('dfpay/v1', '/confirm', [
        'methods'  => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            if (!defined('ZIBAL_SHARED_SECRET')) {
                return new \WP_REST_Response(['ok'=>false,'err'=>'secret undefined'], 500);
            }
            
            $raw = $req->get_body();
            $ts  = $req->get_header('x-df-timestamp');
            $sig = $req->get_header('x-df-signature');
            
            if (!$raw || !$ts || !$sig) {
                return new \WP_REST_Response(['ok'=>false,'err'=>'missing headers/body'], 400);
            }
            
            if (abs(time() - intval($ts)) > DFPAY_ALLOW_CLOCK_SKEW) {
                return new \WP_REST_Response(['ok'=>false,'err'=>'timestamp skew'], 400);
            }
            
            $calc = base64_encode(hash_hmac('sha256', $ts . '.' . $raw, ZIBAL_SHARED_SECRET, true));
            if (!hash_equals($calc, $sig)) {
                return new \WP_REST_Response(['ok'=>false,'err'=>'bad signature'], 403);
            }
            
            $data = json_decode($raw, true);
            $order_id = isset($data['order_id']) ? intval($data['order_id']) : 0;
            $paid     = !empty($data['paid']);
            $track_id = isset($data['track_id']) ? sanitize_text_field($data['track_id']) : '';
            $txn_id   = isset($data['transaction_id']) ? sanitize_text_field($data['transaction_id']) : '';
            
            if (!$order_id) {
                return new \WP_REST_Response(['ok'=>false,'err'=>'bad order_id'], 400);
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return new \WP_REST_Response(['ok'=>false,'err'=>'order not found'], 404);
            }
            
            if ($paid) {
                if ($txn_id) {
                    $order->payment_complete($txn_id);
                } else {
                    $order->payment_complete();
                }
                $order->add_order_note(sprintf('DFPay: Paid. track_id=%s, txn=%s', $track_id, $txn_id));
                $order->update_status('processing');
            } else {
                $order->update_status('failed', 'DFPay: Payment failed.');
            }
            
            return new \WP_REST_Response(['ok'=>true], 200);
        }
    ]);
});

// Blocks integration
add_action('woocommerce_blocks_loaded', function() {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    class DFPay_Blocks_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        protected $name = 'dfpay_gateway';
        
        public function initialize() {
            $this->settings = get_option('woocommerce_dfpay_gateway_settings', []);
        }
        
        public function is_active() {
            return filter_var($this->get_setting('enabled', 'no'), FILTER_VALIDATE_BOOLEAN);
        }
        
        public function get_payment_method_script_handles() {
            wp_register_script(
                'dfpay-gateway-blocks',
                plugins_url('assets/dfpay-blocks.js', __FILE__),
                ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
                '1.0.2',
                true
            );
            
            return ['dfpay-gateway-blocks'];
        }
        
        public function get_payment_method_data() {
            return [
                'title' => $this->get_setting('title', 'پرداخت اینترنتی'),
                'description' => $this->get_setting('description', 'پرداخت امن.'),
                'supports' => $this->get_supported_features(),
                'gatewayId' => $this->name
            ];
        }
    }
    
    // Register the block method
    add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
        $registry->register(new DFPay_Blocks_Method());
    });
});

// Enqueue scripts
add_action('wp_enqueue_scripts', function(){
    if (function_exists('is_checkout') && (is_checkout() || has_block('woocommerce/checkout'))) {
        wp_enqueue_script(
            'dfpay-gateway-blocks',
            plugins_url('assets/dfpay-blocks.js', __FILE__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            '1.0.2',
            true
        );
        
        // Localize settings for JavaScript
        $settings = get_option('woocommerce_dfpay_gateway_settings', []);
        wp_localize_script('dfpay-gateway-blocks', 'dfpay_gateway_data', [
            'title' => $settings['title'] ?? 'پرداخت اینترنتی',
            'description' => $settings['description'] ?? 'پرداخت امن.',
            'supports' => ['products']
        ]);
    }
});