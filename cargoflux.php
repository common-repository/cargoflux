<?php
/*
 * Plugin Name: Cargoflux
 * Plugin URI: https://cargoflux.com
 * Description: Integrate with the Cargoflux order management and shipping system
 * Version: 1.3.4
 * Author: Cargoflux ApS
 * License: GPLv2 or later
 */

 if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

 if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

  require_once(plugin_dir_path(__FILE__).'cargoflux-api.php');

  function cargoflux_rates_init() {

    if ( ! class_exists( "Cargoflux_WC_Rates") ) {

      class Cargoflux_WC_Rates extends WC_Shipping_Method {

        const PRODUCTS_CACHE_KEY = 'cf_products';
        /**
         * Constructor for cargoflux shipping class
         *
         * @access public
         * @return void
         */
        public function __construct() {
          $this->id                 = 'cargoflux_rates'; // Id for your shipping method. Should be unique.
          $this->method_title       = __( 'Cargoflux Rates' );  // Title shown in admin
          $this->method_description = __( 'Configure rates from Cargoflux. Be sure to first enter a valid api key in the Cargoflux tab.' ); // Description shown in admin
          $this->title              = "Cargoflux Rates"; // This can be added as an setting but for this example its forced.]
          $this->init();
        }

        /**
         * Init your settings
         *
         * @access public
         * @return void
         */
        function init() {
          // Load the settings API
          $this->init_settings(); // This is part of the settings API. Loads settings you previously init.
          $this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
          $this->init_settings(); // This is part of the settings API. Loads settings you previously init.

          // Save settings in admin if you have any defined
          add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        /**
         * Refresh carrier products when loading options html
         */
        public function get_admin_options_html() {
          delete_transient(self::PRODUCTS_CACHE_KEY);
          $this->init_form_fields();

          $all_fields = $this->get_form_fields();
          $general_fields = Array();
          foreach ($all_fields as $k => $v) {
            if (!preg_match('/^\d/', $k)) {
              $general_fields[$k] = $v;
            }
          }
          $settings_html = $this->generate_settings_html( $general_fields, false );

          $shipping_zones = \WC_Shipping_Zones::get_zones();

          $product_settings_html = '<table class="cargoflux-rates wc-shipping-zone-methods widefat"><tr>';
          $product_settings_html .= '<th></th>';
          foreach ($shipping_zones as $zone) {
            $product_settings_html .= "<th class='zone_name' colspan='4'>{$zone['zone_name']}</th>";
          }
          $product_settings_html .= '</tr><tr><th></th>';
          foreach ($shipping_zones as $zone) {

            $product_settings_html .= "<th>Enable</th>";
            $product_settings_html .= "<th>Pricing</th>";
            $product_settings_html .= "<th>Flat price</th>";
            $product_settings_html .= "<th>Free threshold</th>";
          }
          $product_settings_html .= '</tr>';
          foreach ($this->get_carrier_products() as $cp) {
            $product_settings_html .= '<tr>';
            $product_settings_html .= "<td>{$cp['name']}</td>";
            foreach ($shipping_zones as $zone) {
              $opt_base_name = "{$zone['id']}_{$cp['code']}_";
              $input_base_name = "woocommerce_cargoflux_rates_{$opt_base_name}";
              $enabled_checked = $this->settings[$opt_base_name.'enable'] == 'yes' ? 'checked="checked"' : '';
              $product_settings_html .=
                "<td><input type='checkbox' name='{$input_base_name}enable' {$enabled_checked}</td>";
              $pricing = $this->settings[$opt_base_name.'pricing'];
              $product_settings_html .=
                "<td><select name='{$input_base_name}pricing'}'>
                <option ".($pricing == 'cargoflux' ? 'selected' : '')." value='cargoflux'>Cargoflux</option>
                <option ".($pricing == 'flat' ? 'selected' : '')." value='flat'>Flat</option>
                </select>
                </td>";
              $product_settings_html .=
                "<td><input type='number' name='{$input_base_name}flat_price' value='{$this->settings[$opt_base_name.'flat_price']}'/></td>";
              $product_settings_html .=
                "<td><input type='number' name='{$input_base_name}free_shipping_threshold' value='{$this->settings[$opt_base_name.'free_shipping_threshold']}'/></td>";
            }
            $product_settings_html .= '</tr>';
          }
          $product_settings_html .= '</table>';

          return '<table class="form-table">' . $settings_html . '</table>' . $product_settings_html;
        }

        /**
         * Get carrier products from cargoflux. Use transient system for caching
         */
        public function get_carrier_products() {
          $cached_products = get_transient(self::PRODUCTS_CACHE_KEY);
          if ($cached_products) {
            $carrier_products = $cached_products;
          } else {
            if ($api = $this->get_api()) {
              $carrier_products = $api->get_carrier_products();
              set_transient(self::PRODUCTS_CACHE_KEY, $carrier_products);
            } else {
              $carrier_products = array();
            }
          }
          return $carrier_products;
        }

        function init_form_fields() {
          $this->form_fields = array(
            'enabled' => array(
              'title' => __('Enabled', 'woocommerce'),
              'type' => 'checkbox',
              'description' => __('Enable shipping rates from Cargoflux.', 'woocommerce'),
              'default' => false
            ),
            'api_key' => array(
              'title' => __('API Key', 'woocommerce'),
              'type' => 'text',
              'description' => __('This key corresponds to a Cargoflux account.', 'woocommerce'),
              'default' => ''
            ),
            'individual_packing_classes' => array(
              'title' => __('Individual packing classes', 'woocommerce'),
              'type' => 'text',
              'description' => __('Enter the slugs of packing classes where each product should be packaged individually. Separate by "|"', 'woocommerce'),
              'default' => ''
            )
          );

          if (is_plugin_active('woocommerce-multilingual/wpml-woocommerce.php')) {
            $this->form_fields['convert_currency'] = array(
              'title' => '[WPML] Convert to local currency',
              'type' => 'checkbox',
              'description' => 'Specify if Cargoflux cost prices should be converted to local currencies according to WPML',
              'default' => false
            );
          }

          $shipping_zones = \WC_Shipping_Zones::get_zones();
          foreach ($shipping_zones as $zone) {
            foreach ($this->get_carrier_products() as $cp) {
              $this->form_fields[$zone['id'].'_'.$cp['code']."_enable"] = array(
                'title' => $zone['zone_name'].' - '.$cp['name']. ': Enable',
                'type' => 'checkbox',
                'default' => false
              );

              $this->form_fields[$zone['id'].'_'.$cp['code']."_pricing"] = array(
                'title' => $zone['zone_name'].' - '.$cp['name'].': Price model',
                'type' => 'select',
                'options' => array(
                  'cargoflux' => 'Use price from Cargoflux',
                  'flat' => 'Specify flat price'
                ),
                'default' => 'cargoflux'
              );

              $this->form_fields[$zone['id'].'_'.$cp['code']."_flat_price"] = array(
                'title' => $zone['zone_name'].' - '.$cp['name'].': Flat price',
                'type' => 'number',
                'default' => ''
              );

              $this->form_fields[$zone['id'].'_'.$cp['code']."_free_shipping_threshold"] = array(
                'title' => $zone['zone_name'].' - '.$cp['name'].': Free shipping threshold',
                'type' => 'number',
                'default' => ''
              );
            }
          }
        }

        private function get_api() {
          $api_key = $this->settings['api_key'];
          if ($api_key) {
            $api = new Cargoflux_CargofluxApi($api_key);
            return $api;
          }
          return false;
        }

        /**
         * calculate_shipping function.
         *
         * @access public
         * @param array $package
         * @return void
         */
        public function calculate_shipping( $package = array() ) {
          if ($api = $this->get_api()) {
            $rates = $api->get_rates($package, $this->settings, $this->get_carrier_products());
            foreach ($rates as $rate) {
              if (is_plugin_active('woocommerce-multilingual/wpml-woocommerce.php')
                  && $this->settings['convert_currency']!='yes') {
                $cache_key = $rate['id'];
                wp_cache_set($cache_key, $rate['cost'], 'converted_shipping_cost');
              }
              $this->add_rate($rate);
            }
          }
        }

        public function book_shipment($order) {
          if ($api = $this->get_api()) {
            $api->book_shipment($order, $this->settings);
          }
        }
      }
    }
  }

  add_action( 'woocommerce_shipping_init', 'cargoflux_rates_init' );

  function cargoflux_add_rates( $methods ) {
    $methods['cargoflux_rates'] = 'Cargoflux_WC_Rates';
    return $methods;
  }

  add_filter( 'woocommerce_shipping_methods', 'cargoflux_add_rates' );

  function cargoflux_sa_wc_after_order_complete( $order_id ) {
    if ($order = wc_get_order($order_id)) {
      $shipping = new Cargoflux_WC_Rates();
      $shipping->book_shipment($order);
    }
    return;
  }

  add_action( 'woocommerce_order_status_completed', 'cargoflux_sa_wc_after_order_complete'  );

  function cargoflux_callback( object $req ) {
    $parameters = $req->get_params();
    $data = json_decode($req->get_body(), true);

    if ($order = wc_get_order($parameters['order_id'])) {
      if ($order->get_meta('_cargoflux_shipment_id') == $data['unique_shipment_id'] &&
          $order->get_meta('_cargoflux_token') == $parameters['token']) {
        $note = null;
        if ($data['status'] == 'booked') {
          $note = 'Booked shipment with awb <a target="_blank" href="'.$data['track_and_trace_url'].'">'.$data['awb'].'</a>';
          if (array_key_exists('awb_asset_url', $data)) {
            $note = $note."\n".
                    '<a target="_blank" href="'.$data['awb_asset_url'].'">Label</a>';
          }
        } else {
          $note = 'Shipment booking status: '.$data['status'];
        }
        $order->add_order_note($note);
        wp_send_json_success();
        return;
      }
    }
    wp_send_json_error(null, 403);
  }
  add_action( 'rest_api_init', function () {
    register_rest_route( 'cargoflux', '/callback/', array(
      'methods'  => 'POST',
      'callback' => 'cargoflux_callback',
      'permission_callback' => '__return_true'
    ) );
  } );

  function cargoflux_register_backend_css() {
    wp_register_style("cargoflux_css", plugins_url("cargoflux/css/cargoflux.css"));
    wp_enqueue_style('cargoflux_css');
  }

  // Enqueue Backend scripts
  add_action('admin_enqueue_scripts', 'cargoflux_register_backend_css');
}
