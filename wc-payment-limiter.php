<?php
/**
 * Plugin Name: WooCommerce Order Payment Limiter
 * Description: Automatically cancels WooCommerce orders after a configurable number of failed payment attempts. Supports official WooCommerce Stripe and Payment Plugins for Stripe WooCommerce.
 * Version: 1.0.0
 * Author: OpenWPClub
 * Author URI: https://openwpclub.com/
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-payment-limiter
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_PAYMENT_LIMITER_VERSION', '1.0.0');
define('WC_PAYMENT_LIMITER_PLUGIN_FILE', __FILE__);
define('WC_PAYMENT_LIMITER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_PAYMENT_LIMITER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class WC_Payment_Limiter {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_options();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init_plugin'));
        add_action('init', array($this, 'load_textdomain'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin after WooCommerce is loaded
     */
    public function init_plugin() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        $this->init_payment_hooks();
        
        if (is_admin()) {
            $this->init_admin();
        }
    }
    
    /**
     * Initialize payment failure hooks
     */
    private function init_payment_hooks() {
        // Official WooCommerce Stripe Gateway
        add_action('wc_gateway_stripe_process_payment_error', array($this, 'handle_stripe_payment_failure'), 10, 2);
        
        // Payment Plugins for Stripe WooCommerce
        add_action('wc_stripe_process_charge_failed', array($this, 'handle_payment_plugins_stripe_failure'), 10, 2);
        
        // Generic WooCommerce payment failure (fallback)
        add_action('woocommerce_payment_failed', array($this, 'handle_generic_payment_failure'), 10, 1);
    }
    
    /**
     * Initialize admin functionality
     */
    private function init_admin() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));
    }
    
    /**
     * Load plugin options
     */
    private function load_options() {
        $defaults = array(
            'max_attempts' => 3,
            'enable_logging' => true,
            'cancel_status' => 'cancelled',
            'custom_note' => '',
            'reset_attempts_on_success' => true
        );
        
        $this->options = get_option('wc_payment_limiter_options', $defaults);
        $this->options = wp_parse_args($this->options, $defaults);
    }
    
    /**
     * Handle Official WooCommerce Stripe Gateway payment failure
     */
    public function handle_stripe_payment_failure($error, $order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        
        $this->process_payment_failure($order, 'stripe_official', $error);
    }
    
    /**
     * Handle Payment Plugins for Stripe WooCommerce failure
     */
    public function handle_payment_plugins_stripe_failure($charge, $order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        
        $error_message = '';
        if (is_object($charge) && isset($charge->failure_message)) {
            $error_message = $charge->failure_message;
        } elseif (is_array($charge) && isset($charge['failure_message'])) {
            $error_message = $charge['failure_message'];
        }
        
        $this->process_payment_failure($order, 'stripe_payment_plugins', $error_message);
    }
    
    /**
     * Handle generic WooCommerce payment failure (fallback)
     */
    public function handle_generic_payment_failure($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $payment_method = $order->get_payment_method();
        
        // Only process if it's a Stripe-related gateway and not already handled
        if (strpos($payment_method, 'stripe') !== false) {
            $last_handled = $order->get_meta('_payment_limiter_last_handled', true);
            $current_time = time();
            
            // Avoid duplicate processing (within 30 seconds)
            if ($last_handled && ($current_time - $last_handled) < 30) {
                return;
            }
            
            $this->process_payment_failure($order, 'stripe_generic', '');
            $order->update_meta_data('_payment_limiter_last_handled', $current_time);
            $order->save_meta_data();
        }
    }
    
    /**
     * Process payment failure and handle attempt tracking
     */
    private function process_payment_failure($order, $gateway_type, $error_message = '') {
        $order_id = $order->get_id();
        $max_attempts = intval($this->options['max_attempts']);
        
        if ($max_attempts <= 0) {
            return; // Feature disabled
        }
        
        // Get current attempt count for this gateway type
        $meta_key = "_payment_attempts_{$gateway_type}";
        $attempt_count = $order->get_meta($meta_key, true);
        $attempt_count = $attempt_count ? intval($attempt_count) + 1 : 1;
        
        // Update attempt count
        $order->update_meta_data($meta_key, $attempt_count);
        $order->update_meta_data('_payment_limiter_last_gateway', $gateway_type);
        $order->save_meta_data();
        
        // Log the attempt
        $this->log_payment_attempt($order_id, $gateway_type, $attempt_count, $error_message);
        
        // Add order note for this attempt
        $note = sprintf(
            __('Payment attempt %d of %d failed (%s). Error: %s', 'wc-payment-limiter'),
            $attempt_count,
            $max_attempts,
            $this->get_gateway_display_name($gateway_type),
            $error_message ?: __('Unknown error', 'wc-payment-limiter')
        );
        $order->add_order_note($note);
        
        // Cancel order if max attempts reached
        if ($attempt_count >= $max_attempts) {
            $this->cancel_order_after_max_attempts($order, $gateway_type, $attempt_count);
        }
    }
    
    /**
     * Cancel order after maximum attempts reached
     */
    private function cancel_order_after_max_attempts($order, $gateway_type, $attempt_count) {
        $cancel_status = $this->options['cancel_status'];
        $custom_note = $this->options['custom_note'];
        
        $cancel_note = $custom_note ?: sprintf(
            __('Order automatically cancelled after %d failed payment attempts using %s gateway.', 'wc-payment-limiter'),
            $attempt_count,
            $this->get_gateway_display_name($gateway_type)
        );
        
        // Update order status
        $order->update_status($cancel_status, $cancel_note);
        
        // Log the cancellation
        $this->log_order_cancellation($order->get_id(), $gateway_type, $attempt_count);
        
        // Fire action hook for extensibility
        do_action('wc_payment_limiter_order_cancelled', $order, $gateway_type, $attempt_count);
    }
    
    /**
     * Reset payment attempts on successful payment
     */
    public function reset_payment_attempts($order_id) {
        if (!$this->options['reset_attempts_on_success']) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Remove all attempt counters
        $gateway_types = array('stripe_official', 'stripe_payment_plugins', 'stripe_generic');
        foreach ($gateway_types as $gateway_type) {
            $order->delete_meta_data("_payment_attempts_{$gateway_type}");
        }
        
        $order->delete_meta_data('_payment_limiter_last_gateway');
        $order->delete_meta_data('_payment_limiter_last_handled');
        $order->save_meta_data();
        
        $this->log_message("Payment attempts reset for order #{$order_id} after successful payment.");
    }
    
    /**
     * Get display name for gateway type
     */
    private function get_gateway_display_name($gateway_type) {
        $names = array(
            'stripe_official' => __('Official WooCommerce Stripe', 'wc-payment-limiter'),
            'stripe_payment_plugins' => __('Payment Plugins for Stripe', 'wc-payment-limiter'),
            'stripe_generic' => __('Stripe Gateway', 'wc-payment-limiter')
        );
        
        return isset($names[$gateway_type]) ? $names[$gateway_type] : $gateway_type;
    }
    
    /**
     * Log payment attempt
     */
    private function log_payment_attempt($order_id, $gateway_type, $attempt_count, $error_message) {
        if (!$this->options['enable_logging']) {
            return;
        }
        
        $message = sprintf(
            'Order #%d: Payment attempt %d failed using %s. Error: %s',
            $order_id,
            $attempt_count,
            $gateway_type,
            $error_message ?: 'Unknown error'
        );
        
        $this->log_message($message);
    }
    
    /**
     * Log order cancellation
     */
    private function log_order_cancellation($order_id, $gateway_type, $attempt_count) {
        if (!$this->options['enable_logging']) {
            return;
        }
        
        $message = sprintf(
            'Order #%d: Automatically cancelled after %d failed payment attempts using %s',
            $order_id,
            $attempt_count,
            $gateway_type
        );
        
        $this->log_message($message, 'notice');
    }
    
    /**
     * Log message
     */
    private function log_message($message, $level = 'info') {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'wc-payment-limiter'));
        } else {
            error_log("WC Payment Limiter: {$message}");
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Payment Limiter', 'wc-payment-limiter'),
            __('Payment Limiter', 'wc-payment-limiter'),
            'manage_woocommerce',
            'wc-payment-limiter',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wc_payment_limiter_options', 'wc_payment_limiter_options', array($this, 'validate_options'));
    }
    
    /**
     * Validate options
     */
    public function validate_options($input) {
        $validated = array();
        
        $validated['max_attempts'] = max(0, intval($input['max_attempts']));
        $validated['enable_logging'] = !empty($input['enable_logging']);
        $validated['cancel_status'] = sanitize_text_field($input['cancel_status']);
        $validated['custom_note'] = sanitize_textarea_field($input['custom_note']);
        $validated['reset_attempts_on_success'] = !empty($input['reset_attempts_on_success']);
        
        return $validated;
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->options = get_option('wc_payment_limiter_options');
        }
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Payment Limiter Settings', 'wc-payment-limiter'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('wc_payment_limiter_options'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="max_attempts"><?php _e('Maximum Payment Attempts', 'wc-payment-limiter'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="max_attempts" name="wc_payment_limiter_options[max_attempts]" 
                                   value="<?php echo esc_attr($this->options['max_attempts']); ?>" min="0" max="10" />
                            <p class="description">
                                <?php _e('Set to 0 to disable automatic order cancellation. Maximum recommended: 10.', 'wc-payment-limiter'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="cancel_status"><?php _e('Order Status After Cancellation', 'wc-payment-limiter'); ?></label>
                        </th>
                        <td>
                            <select id="cancel_status" name="wc_payment_limiter_options[cancel_status]">
                                <?php
                                $statuses = wc_get_order_statuses();
                                foreach ($statuses as $status => $label) {
                                    $status = str_replace('wc-', '', $status);
                                    echo '<option value="' . esc_attr($status) . '"' . selected($this->options['cancel_status'], $status, false) . '>' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="custom_note"><?php _e('Custom Cancellation Note', 'wc-payment-limiter'); ?></label>
                        </th>
                        <td>
                            <textarea id="custom_note" name="wc_payment_limiter_options[custom_note]" 
                                      rows="3" cols="50"><?php echo esc_textarea($this->options['custom_note']); ?></textarea>
                            <p class="description">
                                <?php _e('Leave empty to use the default cancellation message.', 'wc-payment-limiter'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Additional Settings', 'wc-payment-limiter'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="wc_payment_limiter_options[enable_logging]" 
                                           value="1" <?php checked($this->options['enable_logging']); ?> />
                                    <?php _e('Enable detailed logging', 'wc-payment-limiter'); ?>
                                </label>
                                <br><br>
                                <label>
                                    <input type="checkbox" name="wc_payment_limiter_options[reset_attempts_on_success]" 
                                           value="1" <?php checked($this->options['reset_attempts_on_success']); ?> />
                                    <?php _e('Reset attempt counter on successful payment', 'wc-payment-limiter'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Supported Payment Gateways', 'wc-payment-limiter'); ?></h2>
            <ul>
                <li><strong><?php _e('Official WooCommerce Stripe Gateway', 'wc-payment-limiter'); ?></strong> - 
                    <?php echo $this->is_plugin_active('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php') ? 
                        '<span style="color: green;">' . __('Active', 'wc-payment-limiter') . '</span>' : 
                        '<span style="color: red;">' . __('Not Active', 'wc-payment-limiter') . '</span>'; ?>
                </li>
                <li><strong><?php _e('Payment Plugins for Stripe WooCommerce', 'wc-payment-limiter'); ?></strong> - 
                    <?php echo $this->is_plugin_active('woo-stripe-payment/stripe-payments-woocommerce.php') ? 
                        '<span style="color: green;">' . __('Active', 'wc-payment-limiter') . '</span>' : 
                        '<span style="color: red;">' . __('Not Active', 'wc-payment-limiter') . '</span>'; ?>
                </li>
            </ul>
            
            <?php if ($this->options['enable_logging']): ?>
            <hr>
            <h2><?php _e('Recent Log Entries', 'wc-payment-limiter'); ?></h2>
            <p>
                <em><?php _e('Check WooCommerce > Status > Logs for detailed payment limiter logs.', 'wc-payment-limiter'); ?></em>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Check if plugin is active
     */
    private function is_plugin_active($plugin_path) {
        return is_plugin_active($plugin_path);
    }
    
    /**
     * Add action links
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-payment-limiter') . '">' . __('Settings', 'wc-payment-limiter') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Load textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-payment-limiter', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Activation hook
     */
    public function activate() {
        // Set default options
        if (!get_option('wc_payment_limiter_options')) {
            $defaults = array(
                'max_attempts' => 3,
                'enable_logging' => true,
                'cancel_status' => 'cancelled',
                'custom_note' => '',
                'reset_attempts_on_success' => true
            );
            update_option('wc_payment_limiter_options', $defaults);
        }
        
        // Hook to reset attempts on successful payment
        add_action('woocommerce_payment_complete', array($this, 'reset_payment_attempts'));
        add_action('woocommerce_order_status_completed', array($this, 'reset_payment_attempts'));
    }
    
    /**
     * Deactivation hook
     */
    public function deactivate() {
        // Remove hooks
        remove_action('woocommerce_payment_complete', array($this, 'reset_payment_attempts'));
        remove_action('woocommerce_order_status_completed', array($this, 'reset_payment_attempts'));
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php _e('WooCommerce Payment Limiter requires WooCommerce to be installed and active.', 'wc-payment-limiter'); ?>
            </p>
        </div>
        <?php
    }
}

/**
 * Initialize the plugin
 */
function wc_payment_limiter_init() {
    return WC_Payment_Limiter::get_instance();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', 'wc_payment_limiter_init');

/**
 * Check for WooCommerce on activation
 */
register_activation_hook(__FILE__, function() {
    if (!class_exists('WooCommerce')) {
        wp_die(
            __('This plugin requires WooCommerce to be installed and active.', 'wc-payment-limiter'),
            __('Plugin Activation Error', 'wc-payment-limiter'),
            array('back_link' => true)
        );
    }
});