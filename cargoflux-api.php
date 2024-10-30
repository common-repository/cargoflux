<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Cargoflux_CargofluxApi {

  private $api_key;

  function __construct($api_key) {
    $this->api_key = $api_key;
  }

  /**
   * Attempt to book shipment in Cargoflux for given order. Report status in order notes
   */
  public function book_shipment($order, $settings) {
    foreach ($order->get_items('shipping') as $_ => $item) {
      $line_items = $order->get_items('line_item');
      $package = array('contents' => array());
      foreach ($line_items as $line_item) {
        $package['contents'][$line_item->get_data()['id']] = $line_item->get_data();
      }
      $dims = $this->package_dimensions($package, $settings);
      $billing = $order->get_data()['billing'];
      $recipient = $order->get_data()['shipping'];

      $attention = ($recipient['first_name'] ?? '').' '.($recipient['last_name'] ?? '');
      $company_name = $recipient['company'];
      if (empty($company_name)) {
        $company_name = $attention;
      }

      $token = md5(strval(rand()));

      if ($item['method_id'] == 'cargoflux_rates') {
        $body = array(
          'callback_url' => $this->callback_host().'/wp-json/cargoflux/callback?order_id='.$order->get_data()['id'].'&token='.$token,
          'default_sender' => true,
          'shipment' => array(
            'shipping_date' => date('Y-m-d'),
            'product_code' => $item->get_meta('product_code'),
            'parcelshop_id' => $item->get_meta('parcelshop_id'),
            'package_dimensions' => $dims,
          ),
          'recipient' => array (
            'company_name' => $company_name,
            'attention' => $attention,
            'country_code' => $recipient['country'],
            'state_code' => $recipient['state'],
            'zip_code' => $recipient['postcode'],
            'address_line1' => $recipient['address_1'],
            'address_line2' => $recipient['address_2'],
            'city' => $recipient['city'],
            'email' => $recipient['email'] ?? $billing['email'],
            'phone_number' => $recipient['phone'] ?? $billing['phone']
          )
        );
        $response_code = 0;
        $response_body = "Unknown error";
        $response = $this->post('/api/v1/customers/shipments', $body, $response_code, $response_body);
        if (!$response) {
          $response_data = json_decode($response_body, true);
          $error_msg = '';
          if (gettype($response_data) == 'array' && array_key_exists('errors', $response_data)) {
            foreach ($response_data['errors'] as $err) {
              if (array_key_exists('description', $err)) {
                $error_msg = $error_msg.$err['description']."\n";
              }
            }
          }
          if ($error_msg == '') {
            $error_msg = $response_body;
          }
          $failed_message = "Failed booking shipment with Cargoflux ($response_code)\n\n".
                            $error_msg."\n".
                            'Request: '.json_encode($body)."\n";
          $order->add_order_note($failed_message);
        } else {
          $order->add_order_note("Created new shipment with Cargoflux\n".
                                 "New shipment identifier: ".$response['unique_shipment_id']);
          $order->update_meta_data('_cargoflux_shipment_id', $response['unique_shipment_id']);
          $order->update_meta_data('_cargoflux_token', $token);
          $order->save();
        }
      }
    }
  }

  /**
   * Fetch all available carrier products
   */
  public function get_carrier_products() {
    $response = $this->get('/api/v1/customers/carrier_products');

    if ($response) {
      $products = array_map(function($value) {
        return array(
          'name' => $value['product_name'],
          'code' => $value['product_code'],
          'supports_parcelshop' => $value['supports_parcelshop'] && $value['supports_parcelshop_lookup']
        );
      }, $response);
      return array_filter($products, function($product) {
        return $product['code'] != null;
      });
    } else {
      return [];
    }
  }

  /**
   * Get rates for a checkout session
   */
  public function get_rates($package, $settings, $known_carrier_products) {

    $zone_id = $this->get_shipping_zone_id($package);
    if (!$zone_id) {
      return Array();
    }

    $recipient = $package['destination'];
    $body = array(
      'default_sender' => true,
      'recipient' => array(
        'country_code' => $recipient['country'],
        'state_code' => $recipient['state'],
        'zip_code' => $recipient['postcode'],
        'address_line1' => $recipient['address'],
        'address_line2' => $recipient['address_2'],
        'city' => $recipient['city']
      ),
      'package_dimensions' => $this->package_dimensions($package, $settings)
    );
    $response = $this->post('/api/v1/customers/shipments/prices.json', $body);
    if ($response) {
      $rates = array_map(function($value) {
        return array(
          'id' => $value['product_code'],
          'label' => $value['name'],
          'cost' => floatval($value['price_amount']),
          'meta_data' => array(
            'product_code' => $value['product_code']
          )
        );
        }, $response);


      $rates = array_filter($rates, function($rate) USE($settings, $zone_id) {
        return !is_null($rate['cost']) && array_key_exists($zone_id.'_'.$rate['id'].'_enable', $settings)
                        && $settings[$zone_id.'_'.$rate['id'].'_enable'] == 'yes';
      });

      $rates = array_map(function($rate) USE($settings, $package, $zone_id) {
        if ($settings[$zone_id.'_'.$rate['id'].'_pricing'] == 'flat' && $settings[$zone_id.'_'.$rate['id'].'_flat_price'] > 0) {
          $rate['cost'] = floatval($settings[$zone_id.'_'.$rate['id'].'_flat_price']);
        }
        $free_shipping_th = $settings[$zone_id.'_'.$rate['id'].'_free_shipping_threshold'];
        if ($free_shipping_th && $free_shipping_th > 0 && $free_shipping_th <= $package['cart_subtotal']) {
          $rate['cost'] = 0;
        }
        return $rate;
      }, $rates);

      $parcelshop_support = array();
      foreach ($known_carrier_products as $cp) {
        if ($cp['supports_parcelshop']) {
          array_push($parcelshop_support,  $cp['code']);
        }
      }

      $rates_tmp = $rates;
      $rates = [];
      foreach ($rates_tmp as $rate) {
        if (in_array($rate['id'], $parcelshop_support)) {
          $parcelshops = $this->get('/api/v1/customers/parcelshops', array(
              'product_code' => $rate['id'],
              'address_line' => $recipient['address'],
              'zip_code' => $recipient['postcode'],
              'city' => $recipient['city'],
              'country_code' => $recipient['country'],
              'amount' => 3
          ));
          if ($parcelshops && count($parcelshops) > 0) {
            foreach($parcelshops as $parcelshop) {
              array_push($rates, array(
                'id' => $rate['id'].'$'.$parcelshop['id'],
                'label' => $rate['label'].': '.$parcelshop['name'].', '.$parcelshop['address_line']. ', '.$parcelshop['zip_code'].', '.$parcelshop['city'],
                'cost' => $rate['cost'],
                'meta_data' => array(
                  'product_code' => $rate['id'],
                  'parcelshop_id' => $parcelshop['id']
                )
              ));
            }
          } else {
            array_push($rates, $rate);
          }
        } else {
          array_push($rates, $rate);
        }
      }

      return $rates;
    } else {
      return [];
    }
  }

  /**
   * Return the id of the first zone found with country matching recipient
   */
  private function get_shipping_zone_id($package) {
    $country_code = $package['destination']['country'];
    $shipping_zones = \WC_Shipping_Zones::get_zones();
    foreach ($shipping_zones as $zone) {
      foreach ($zone['zone_locations'] as $location) {
        if ($location->type == 'country' && $location->code == $country_code) {
          return $zone['id'];
        }
      }
    }
    return null;
  }

  /**
   * kg conversion factor
   */
  private function weight_unit_factor($unit) {
    switch ($unit) {
    case 'g':
      return 1000;
    case 'lbs':
      return 0.453592;
    case 'oz':
      return 0.0283495;
    }
    return 1;
  }

  /**
   * cm conversion factor
   */
  private function dim_unit_factor($unit) {
    switch ($unit) {
      case 'm':
        return 100;
      case 'mm':
        return 0.1;
      case 'in':
        return 2.54;
      case 'yd':
        return 91.44;
    }
    return 1;
  }

  /**
   * Return cargoflux format package dimensions for one group of items
   */
  private function group_dimensions($group) {
    $total_weight = 0;
    $total_volume = 0;
    $largest_dim_primary = 0;
    $largest_dim_secondary = 0;

    foreach ($group as $line) {
      $product_dims = $line['dims'];
      if ($product_dims[0] > $largest_dim_primary) {
        $largest_dim_primary = $product_dims[0];
        if ($product_dims[1] > $largest_dim_secondary) {
          $largest_dim_secondary = $product_dims[1];
        }
      } elseif ($product_dims[1] > $largest_dim_secondary) {
        $largest_dim_secondary = $product_dims[1];
      }

      $total_weight += (double) $line['weight'] * (double) $line['qty'];
      $total_volume += (double) $product_dims[0] * (double) $product_dims[1] * (double) $product_dims[2] * (double) $line['qty'];
    }

    $weight_unit = get_option('woocommerce_weight_unit');
    $dim_unit = get_option('woocommerce_dimensions_unit');

    $weight_factor = $this->weight_unit_factor($weight_unit);
    $dim_factor = $this->dim_unit_factor($dim_unit);

    $total_weight_kg = max(0.001, $total_weight * $weight_factor);
    $total_volume_cm3 = $total_volume * pow($dim_factor, 3);
    $cubic_dim = pow($total_volume_cm3, 1/3) + 1;
    $length = max(1, (int) max($cubic_dim, $largest_dim_primary * $dim_factor));
    $width = max(1, (int) max($cubic_dim, $largest_dim_secondary * $dim_factor));
    $height = max(1, (int) $cubic_dim);
    return array(
      'amount' => strval(1),
      'height' => strval($height),
      'width' => strval($width),
      'length' => strval($length),
      'weight' => strval($total_weight_kg)
    );
  }

  /**
   * Return cargoflux format package dimensions for entire shipment content
   */
  private function package_dimensions($package, $settings) {
    // Group lines according to packing classes
    $groups = array();
    foreach ($package['contents'] as $_ => $line) {
      $product = null;
      if ((int) $line['variation_id'] > 0) {
        $product = wc_get_product( $line['variation_id'] );
      }
      if (!$product) {
        $product = wc_get_product( $line['product_id'] );
      }

      $shipping_class = $product->get_shipping_class() || 'default';
      $product_dims = array($product->get_length(), $product->get_width(), $product->get_height());
      rsort($product_dims);

      if (!array_key_exists($shipping_class, $groups)) {
        $groups[$shipping_class] = array();
      }
      array_push($groups[$shipping_class], array('dims' => $product_dims, 'qty' => $line['quantity'], 'weight' => $product->get_weight()));
    }

    // Package each packing class separately
    $dimensions = array();
    $individual_packing_classes = explode('|', str_replace(' ', '', $settings['individual_packing_classes']));
    foreach ($groups as $class => $group) {
      // If the package class is configured as "individual_packing_class", package each item of the same lines separately
      if (in_array($class, $individual_packing_classes)) {
        foreach ($group as $line) {
          $line_1 = $line;
          $line_1['qty'] = 1;
          for ($i=0; $i<$line['qty']; $i++) {
            array_push($dimensions, $this->group_dimensions(array($line_1)));
          }
        }
      } else {
        array_push($dimensions, $this->group_dimensions($group));
      }
    }

    return $dimensions;
  }


  /**
   * Perform post request
   */
  private function post($endpoint, $body, & $return_code=null, & $return_body=null) {
    return $this->request($endpoint, 'POST', json_encode($body), $return_code, $return_body);
  }

  /**
   * Perform get request
   */
  private function get($endpoint, $body=null) {
    return $this->request($endpoint, 'GET', $body);
  }

  /**
   * Common http request method
   */
  private function request($endpoint, $method, $body=null, & $return_code=null, & $return_body=null) {

    $url = "{$this->api_host()}{$endpoint}";
    $args =  array( 'headers' => $this->headers(), 'body' => $body );
    $response = null;
    try {
      if ($method == 'POST') {
        $response = wp_remote_post($url, $args);
      } elseif ($method == 'GET') {
        $response = wp_remote_get($url, $args);
      }
      if (!(gettype($response) == 'array' && $response['response']['code'] == 200)) {
        throw new Exception('API returned non-success');
      }
      return json_decode($response['body'], true);
    } catch (Exception $e) {
      error_log("Failed Cargoflux request: {$e->getMessage()}");
      error_log("Failing request URL: $url");
      error_log("Failing request body: $body");
      if (!is_null($response)) {
        $response_str = print_r($response, true);
        error_log("Failing response: $response_str");
      }
      if (gettype($response) == 'array') {
        if (!is_null($return_code)) {
          $return_code = $response['response']['code'];
        }
        if (!is_null($return_body)) {
          $return_body = $response['body'];
        }
      }
      return false;
    }
  }

  private function api_host() {
    return $this->get_env('CARGOFLUX_API_HOST', 'https://api.cargoflux.com');
  }

  private function callback_host() {
    return $this->get_env('CARGOFLUX_CALLBACK_HOST', get_site_url());
  }

  /**
   * Replacement for global getenv that may return "1" instead of null
   * on non-existing keys
   */
  private function get_env($key, $default=false) {
    if (array_key_exists($key, $_ENV)) {
      return $_ENV[$key];
    }
    return $default;
  }

  private function headers() {
    return array(
        'Content-Type' => 'application/json',
        'Access-Token' => $this->api_key
    );
  }
}
