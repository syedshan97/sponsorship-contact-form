<?php
/**
 * Plugin Name:     Sponsor Episodes for WooCommerce
 * Description:     Dynamic per‑episode sponsorship: link/article, display ads, podcast & video options with real‑time pricing, TOS, Elementor post‑purchase form injection.
 * Version:         1.6
 * Author:          Stonefly
 * Text Domain:     sponsor-episodes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SEP_Plugin {

    /** @var int[] Product IDs where sponsorship applies */
    private $targets = [ 24818 ]; // ← Edit your product IDs here

    /** @var SEP_Plugin */
    private static $instance = null;

    /** Singleton init */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->init_hooks();
        }
        return self::$instance;
    }

    /** Hook registrations */
    private function init_hooks() {
        // Front‑end form & assets
        add_action( 'wp_enqueue_scripts',                   [ $this, 'enqueue_assets' ] );
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_front_form' ] );

        // Cart & checkout data
        add_filter( 'woocommerce_add_cart_item_data',               [ $this, 'add_cart_item_data' ], 10, 2 );
        add_action( 'woocommerce_before_calculate_totals',          [ $this, 'apply_price' ],       20 );
        add_filter( 'woocommerce_get_item_data',                    [ $this, 'display_cart_meta' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item',   [ $this, 'save_order_meta' ],   10, 4 );

        // Redirect & button label
        add_filter( 'woocommerce_product_single_add_to_cart_text',  [ $this, 'button_text' ] );
        add_filter( 'woocommerce_add_to_cart_redirect',             [ $this, 'redirect_to_checkout' ] );

        // Elementor form injection on Thank‑You page
        add_action( 'woocommerce_thankyou',                         [ $this, 'render_elementor_test' ], 30 );

        // Styled thank‑you message (optional)
        add_filter( 'woocommerce_thankyou_order_received_text',     [ $this, 'thankyou_message' ], 10, 2 );
        add_filter( 'woocommerce_before_pay_form',                  [ $this, 'thankyou_message' ], 10, 2 );
		
		//ajax hook for booking
		add_action( 'wp_ajax_sep_get_reserved_ranges',       [ $this, 'ajax_get_reserved_ranges' ] );
        add_action( 'wp_ajax_nopriv_sep_get_reserved_ranges',[ $this, 'ajax_get_reserved_ranges' ] );
    }

    /** Are we on one of our target product pages? */
    private function is_target_page() {
        return is_product() && in_array( get_the_ID(), $this->targets, true );
    }

    /** Are we on a cart item from our targets? */
    private function is_target_cart_item( $product_id ) {
        return in_array( $product_id, $this->targets, true );
    }



	/**
 * Enqueue our single JS file everywhere (so it runs on both product & thank-you pages),
 * and localize only when on the product page.
 */
public function enqueue_assets() {
    // 1) Flatpickr core (needed for the date‑range pickers)
    wp_enqueue_script( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], null, true );
    wp_enqueue_style ( 'flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css' );

    // 2) Main plugin JS (always load, so it runs everywhere)
    wp_enqueue_script(
        'sep-js',
        plugin_dir_url( __FILE__ ) . 'sponsor-episodes.js',
        [ 'jquery', 'flatpickr' ],
        '1.2',
        true
    );

    // 3) Prepare the data object for JS
    $data = [
        'ajax_url' => admin_url( 'admin-ajax.php' ),    // for sep_get_reserved_ranges
    ];

    // 4) On product pages, also pass your sponsorship options
    if ( $this->is_target_page() ) {
        $data['options'] = $this->get_options_list();
    }

    // 5) Localize into SEP_DATA
    wp_localize_script( 'sep-js', 'SEP_DATA', $data );
}


	
    /** Define all sponsorship options */
    private function get_options_list() {
        return [
            'link_backlink'  => ['label'=>'Backlink in Existing Article',  'group'=>'Link & Article Sponsorships','price'=>100],
            'link_guest'     => ['label'=>'Guest Post (You Provide)',      'group'=>'Link & Article Sponsorships','price'=>150],
            'link_sponsored' => ['label'=>'Sponsored Article (You Provide)','group'=>'Link & Article Sponsorships','price'=>200],
            'link_pinned'    => ['label'=>'30‑Day Pinned Article',          'group'=>'Link & Article Sponsorships','price'=>500],
            'ad_home'        => ['label'=>'Homepage Banner Ad',            'group'=>'Display Ad Options',         'price'=>300],
            'ad_side'        => ['label'=>'Side Banner Ad',                'group'=>'Display Ad Options',         'price'=>150],
            'pod_snippet'    => ['label'=>'Sponsored Snippet in Podcast',  'group'=>'Podcast Sponsorships',       'price'=>1000],
            'pod_full'       => ['label'=>'Full Sponsored Podcast Episode','group'=>'Podcast Sponsorships',       'price'=>1500],
            'vid_snippet'    => ['label'=>'YouTube Ad Snippet',            'group'=>'Video Sponsorships',         'price'=>400],
            'vid_full'       => ['label'=>'Full Sponsored YouTube Video',  'group'=>'Video Sponsorships',         'price'=>800],
            'vid_bundle'     => ['label'=>'YouTube Short + Full‑Length',   'group'=>'Video Sponsorships',         'price'=>1000],
            'vid_comm'       => ['label'=>'YouTube Community Post',        'group'=>'Video Sponsorships',         'price'=>150],
        ];
    }

    /** Render the front‐end checkbox groups */
public function render_front_form() {
    if ( ! $this->is_target_page() ) {
        return;
    }

    $groups = [];
    foreach ( $this->get_options_list() as $key => $opt ) {
        $groups[ $opt['group'] ][] = [
            'key'   => $key,
            'label' => $opt['label'],
            'price' => $opt['price'],
        ];
    }

    echo '<div id="sep-wrapper">';
    foreach ( $groups as $heading => $items ) {
        echo '<div class="sep-group"><h3>' . esc_html( $heading ) . '</h3>';

        foreach ( $items as $it ) {
            // 1) Primary checkbox
            printf(
                '<p><label><input type="checkbox" name="sep_opts[]" value="%1$s" data-price="%2$d" /> %3$s — $%2$d</label></p>',
                esc_attr( $it['key'] ),
                esc_attr( $it['price'] ),
                esc_html( $it['label'] )
            );

            // 2) If this is one of the two banner slots, append a single hidden date‑range input
			if ( in_array( $it['key'], [ 'ad_home', 'ad_side' ], true ) ) {
    // range picker + note + image
    printf(
        '<p class="sep-ad-range-wrapper" style="display:none;">
           <input type="text"
                  name="sep_ad_range[%1$s]"
                  class="sep-ad-range"
                  data-slot="%1$s"
                  placeholder="Select reservation range…"
                  readonly>
           <br/><small class="sep-note"><em>%2$s</em></small>
           <br/><img src="%3$s" alt="%4$s" class="sep-ad-preview" style="max-width:100%%;height:auto;margin-top:15px;">
         </p>',
        esc_attr( $it['key'] ),
        // note text
        ( 'ad_home' === $it['key']
            ? 'Note: The homepage banner ad is available with flexible weekly scheduling. '
              .'The minimum purchase is 1 week, billed at $300. You can extend beyond the '
              .'initial week, with additional days billed at $300 ÷ 7 per day.'
            : 'Note: The side banner ad is also available with flexible weekly scheduling. '
              .'The minimum purchase is 1 week, billed at $150. Additional days beyond '
              .'the first week are billed at $150 ÷ 7 per day.'
        ),
        // image src
        ( 'ad_home' === $it['key']
            ? '/wp-content/uploads/2025/08/Home-Banner-Ad.png'
            : '/wp-content/uploads/2025/08/Side-Banner-Ad-O.png'
        ),
        // alt text
        ( 'ad_home' === $it['key'] ? 'Homepage Banner Example' : 'Sidebar Banner Example' )
    );
}


            // 3) If this is the 30‑Day Pinned Article option, append four hidden date‑range inputs
            if ( 'link_pinned' === $it['key'] ) {
                for ( $i = 1; $i <= 4; $i++ ) {
                    printf(
                        '<p class="sep-ad-range-wrapper" style="display:none;">
                           <label style="display:block; margin-bottom:4px;">Book Slot %2$d:</label>
                           <input type="text"
                                  name="sep_ad_range[%1$s_%2$d]"
                                  class="sep-ad-range"
                                  data-slot="%1$s_%2$d"
                                  placeholder="Select reservation range…"
                                  readonly>
                         </p>',
                        esc_attr( $it['key'] ),
                        $i
                    );
                }
            }
        }

        echo '</div>';
    }

    echo '<div id="sep-total">Total: $0</div>';
    echo '<p><label><input type="checkbox" id="sep-tos" /> I agree to the <a href="/terms">Terms of Service</a></label></p>';
    echo '</div>';
}


	

    /** Add selected opts & price to cart */
public function add_cart_item_data( $cart_item_data, $product_id ) {
    // Only process our target products and when options are selected
    if ( ! $this->is_target_cart_item( $product_id ) || empty( $_POST['sep_opts'] ) ) {
        return $cart_item_data;
    }

    // 1) Existing checkbox logic: sanitize and sum flat prices,
    //    skipping the two banners and the pinned‐article checkbox itself
    $chosen = array_map( 'sanitize_text_field', (array) $_POST['sep_opts'] );
    $total  = 0;
    foreach ( $chosen as $key ) {
        if ( in_array( $key, [ 'ad_home', 'ad_side', 'link_pinned' ], true ) ) {
            continue;
        }
        $total += $this->get_options_list()[ $key ]['price'] ?? 0;
    }

    // Persist chosen options & initial total
    $cart_item_data['sep_opts']  = $chosen;
    $cart_item_data['sep_price'] = $total;

    // 2) Capture any date ranges (banner slots + pinned slots 1–4)
    if ( ! empty( $_POST['sep_ad_range'] ) && is_array( $_POST['sep_ad_range'] ) ) {
        foreach ( $_POST['sep_ad_range'] as $slot => $val ) {
            // Expect format "YYYY-MM-DD — YYYY-MM-DD"
            $parts = preg_split( '/\s*—\s*/u', sanitize_text_field( $val ) );
            if ( count( $parts ) === 2 ) {
                list( $from, $to ) = $parts;
                $cart_item_data[ "sep_{$slot}_range" ] = [
                    'from' => $from,
                    'to'   => $to,
                ];
            }
        }
    }

    // 3) Homepage Banner Ad prorated (7-day base, $42.85/day beyond)
    if ( ! empty( $cart_item_data['sep_ad_home_range'] ) ) {
        $r       = $cart_item_data['sep_ad_home_range'];
        $from_ts = strtotime( $r['from'] );
        $to_ts   = strtotime( $r['to'] );
        $days    = ( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) + 1;
        $base    = 300;
        $per_day = 42.85;
        $extra   = max( 0, $days - 7 ) * $per_day;
        $total  += round( $base + $extra, 2 );
    }

    // 4) Side Banner Ad prorated (7-day base, $21.42/day beyond)
    if ( ! empty( $cart_item_data['sep_ad_side_range'] ) ) {
        $r2       = $cart_item_data['sep_ad_side_range'];
        $f2_ts    = strtotime( $r2['from'] );
        $t2_ts    = strtotime( $r2['to'] );
        $d2       = ( ( $t2_ts - $f2_ts ) / DAY_IN_SECONDS ) + 1;
        $base2    = 150;
        $per_day2 = 21.42;
        $extra2   = max( 0, $d2 - 7 ) * $per_day2;
        $total  += round( $base2 + $extra2, 2 );
    }

    // 5) 30‑Day Pinned Article prorated for slots 1–4
    foreach ( range( 1, 4 ) as $i ) {
        $key = "sep_link_pinned_{$i}_range";
        if ( ! empty( $cart_item_data[ $key ] ) ) {
            $r3     = $cart_item_data[ $key ];
            $f3     = strtotime( $r3['from'] );
            $t3     = strtotime( $r3['to'] );
            $days30 = ( ( $t3 - $f3 ) / DAY_IN_SECONDS ) + 1;
            $base30 = 500;
            $per30  = 500 / 30;
            $extra3 = max( 0, $days30 - 30 ) * $per30;
            $total += round( $base30 + $extra3, 2 );
        }
    }

    // 6) Persist the updated total back into cart item
    $cart_item_data['sep_price'] = $total;

    return $cart_item_data;
}




    /** Restore cart data from session */
   
	public function load_cart_item_data( $item, $values ) {
    // Existing: restore sep_opts & sep_price
    if ( isset( $values['sep_opts'] ) ) {
        $item['sep_opts'] = $values['sep_opts'];
    }
    if ( isset( $values['sep_price'] ) ) {
        $item['sep_price'] = $values['sep_price'];
    }

    // NEW: restore banner and pinned‑article ranges
    foreach ( [
        'ad_home',
        'ad_side',
        'link_pinned_1',
        'link_pinned_2',
        'link_pinned_3',
        'link_pinned_4',
    ] as $slot ) {
        $key = "sep_{$slot}_range";
        if ( isset( $values[ $key ] ) ) {
            $item[ $key ] = $values[ $key ];
        }
    }

    return $item;
}



    /** Override line‐item price */
    public function apply_price( $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            if ( isset( $item['sep_price'] ) ) {
                $item['data']->set_price( floatval( $item['sep_price'] ) );
            }
        }
    }

    /** Display meta in Cart & Checkout */
/**
 * Display cart & checkout line‐item meta, including prorated reservation costs.
 *
 * @param array $meta Existing meta for this item.
 * @param array $item Cart item data.
 * @return array Modified meta.
 */
public function display_cart_meta( $meta, $item ) {
    // 1) Flat‐fee options (non‑date slots)
    if ( ! empty( $item['sep_opts'] ) ) {
        foreach ( $item['sep_opts'] as $key ) {
            // Skip date‑driven slots here
            if ( in_array( $key, [ 'ad_home','ad_side','link_pinned' ], true ) ) {
                continue;
            }
            $opt   = $this->get_options_list()[ $key ] ?? null;
            if ( ! $opt ) {
                continue;
            }
            $meta[] = [
                'key'   => $opt['label'],
                'value' => '$' . number_format_i18n( $opt['price'], 2 ),
            ];
        }
    }

    // Helper: format a YYYY-MM-DD date to MM-DD-YYYY
    $format_date = function( $d ) {
        $dt = DateTime::createFromFormat( 'Y-m-d', $d );
        return $dt ? $dt->format('m-d-Y') : $d;
    };

    // 2) Banners and pinned slots: compute days, cost & display range + days
    $slots = [
        'ad_home'        => [ 'label'=>'Homepage Banner Ad',        'min'=>7,  'base'=>300 ],
        'ad_side'        => [ 'label'=>'Side Banner Ad',            'min'=>7,  'base'=>150 ],
        'link_pinned_1'  => [ 'label'=>'30‑Day Pinned Slot 1',       'min'=>30, 'base'=>500 ],
        'link_pinned_2'  => [ 'label'=>'30‑Day Pinned Slot 2',       'min'=>30, 'base'=>500 ],
        'link_pinned_3'  => [ 'label'=>'30‑Day Pinned Slot 3',       'min'=>30, 'base'=>500 ],
        'link_pinned_4'  => [ 'label'=>'30‑Day Pinned Slot 4',       'min'=>30, 'base'=>500 ],
    ];

    foreach ( $slots as $slot_key => $cfg ) {
        $meta_key = "sep_{$slot_key}_range";

        if ( empty( $item[ $meta_key ] ) || ! is_array( $item[ $meta_key ] ) ) {
            continue;
        }

        $from = $item[ $meta_key ]['from'];
        $to   = $item[ $meta_key ]['to'];

        // compute inclusive day count
        $start_ts = strtotime( $from );
        $end_ts   = strtotime( $to );
        $days      = floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;

        // prorated cost
        $base    = $cfg['base'];
        $min     = $cfg['min'];
        $per_day = round( $base / $min, 2 );
        $extra   = max( 0, $days - $min );
        $cost    = round( $base + ( $extra * $per_day ), 2 );

        // formatted range + days
        $range_str = sprintf(
            '%s — %s (%d %s)',
            $format_date( $from ),
            $format_date( $to ),
            $days,
            _n( 'Day', 'Days', $days, 'sponsor-episodes' )
        );

        // append to meta
        $meta[] = [
            'key'   => $cfg['label'] . ' Reservation',
            'value' => sprintf( '%s: $%s', $range_str, number_format_i18n( $cost, 2 ) ),
        ];
    }

    return $meta;
}



    /** Save full meta to the order */
  
	/**
 * Save sponsorship options & reservation details into the order line item.
 */
public function save_order_meta( $line_item, $cart_key, $values ) {
    // 1) Flat‑fee options (unchanged)
    if ( ! empty( $values['sep_opts'] ) ) {
        // Save the raw keys (hidden)
        $line_item->add_meta_data( '_sep_opts', $values['sep_opts'], true );

        // Save each flat‑fee label + price
        foreach ( $values['sep_opts'] as $opt_key ) {
			if ( in_array( $opt_key, [ 'ad_home', 'ad_side', 'link_pinned' ], true ) ) {
            continue;
        }
            if ( $opt = $this->get_options_list()[ $opt_key ] ?? null ) {
                $line_item->add_meta_data(
                    $opt['label'],
                    '$' . number_format_i18n( $opt['price'], 2 ),
                    true
                );
            }
        }
    }

    // Helper to format YYYY‑MM‑DD → MM‑DD‑YYYY
    $format_date = function( $d ) {
        $dt = DateTime::createFromFormat( 'Y-m-d', $d );
        return $dt ? $dt->format('m-d-Y') : $d;
    };

    // Slots config: slot_key => [ label, minDays, basePrice ]
    $slots = [
        'ad_home'        => [ 'Homepage Banner Ad',     7,  300 ],
        'ad_side'        => [ 'Side Banner Ad',         7,  150 ],
        'link_pinned_1'  => [ '30‑Day Pinned Slot 1',  30, 500 ],
        'link_pinned_2'  => [ '30‑Day Pinned Slot 2',  30, 500 ],
        'link_pinned_3'  => [ '30‑Day Pinned Slot 3',  30, 500 ],
        'link_pinned_4'  => [ '30‑Day Pinned Slot 4',  30, 500 ],
    ];

    foreach ( $slots as $slot_key => list( $label, $minDays, $base ) ) {
        $meta_key = "sep_{$slot_key}_range";

        if ( empty( $values[ $meta_key ] ) || ! is_array( $values[ $meta_key ] ) ) {
            continue;
        }

        $from = $values[ $meta_key ]['from'];
        $to   = $values[ $meta_key ]['to'];

        // inclusive days
        $days = floor( ( strtotime($to) - strtotime($from) ) / DAY_IN_SECONDS ) + 1;

        // prorated cost
        $perDay = round( $base / $minDays, 2 );
        $extra  = max( 0, $days - $minDays );
        $cost   = round( $base + ( $extra * $perDay ), 2 );

        // formatted string
        $range_str = sprintf(
            '%s — %s (%d %s): $%s',
            $format_date( $from ),
            $format_date( $to ),
            $days,
            _n( 'Day', 'Days', $days, 'sponsor-episodes' ),
            number_format_i18n( $cost, 2 )
        );

        // store raw array (for AJAX/calendar if needed)
        $line_item->add_meta_data( $meta_key, $values[ $meta_key ], true );

        // store the human label + formatted range & cost
        $line_item->add_meta_data(
            $label . ' Reservation',
            $range_str,
            true
        );
    }
}


    /** Change Add to Cart text */
    public function button_text() {
        return $this->is_target_page() ? __( 'Buy Now', 'sponsor-episodes' ) : null;
    }

    /** Redirect immediately to checkout */
    public function redirect_to_checkout( $url ) {
        if ( isset( $_REQUEST['add-to-cart'] ) && in_array( intval($_REQUEST['add-to-cart']), $this->targets, true ) ) {
            return wc_get_checkout_url();
        }
        return $url;
    }

    /**
     * Inject Elementor form shortcode on Thank You and expose raw keys to JS.
     *
     * @param int $order_id
     */
    public function render_elementor_test( $order_id ) {
        if ( ! function_exists( 'do_shortcode' ) ) {
            return;
        }
        // 1) Render the Elementor Form (ID 4390)
        echo do_shortcode( '[elementor-template id="24838"]' );

        // 2) Pull raw _sep_opts
        $p = [];
        $order = wc_get_order( absint( $order_id ) );
        if ( $order ) {
            foreach ( $order->get_items() as $item ) {
                $keys = $item->get_meta( '_sep_opts', true );
                if ( is_array( $keys ) ) {
                    $p = array_merge( $p, $keys );
                }
            }
        }
        $p = array_values( array_unique( $p ) );

        // 3) Expose to JS
         printf(
        '<script>
           window.sepPurchased     = %s;
           window.sepOrderId       = %d;
           window.sepCustomerName  = %s;
           window.sepCustomerEmail = %s;
         </script>',
        wp_json_encode( $p, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT ),
        $order_id,
        wp_json_encode( $order ? trim( $order->get_billing_first_name() . " " . $order->get_billing_last_name() ) : '' ),
        wp_json_encode( $order ? $order->get_billing_email() : '' )
    );
    }


	/**
 * Append a styled thank‑you block to the default text.
 */
public function thankyou_message( $text, $order ) {
    // Merchant name (uppercased)
    $merchant = 'Daily Security Review';
    // Order total formatted by WooCommerce
    $amount   = $order->get_formatted_order_total();

    // Inline CSS for this block
    $style = '
    <style>
      .sep-thankyou { text-align: center; margin: 2em 0; }
      .sep-thankyou .sep-check-icon {
        font-size: 3rem;
        color: #28a745;
        line-height: 1;
        margin-bottom: 0.5em;
      }
      .sep-thankyou h2 {
        margin: 0.2em 0;
        font-size: 1.6em;
      }
      .sep-thankyou .sep-subtext {
        color: #666;
        margin-bottom: 1em;
      }
      .sep-thankyou .sep-receipt {
        display: flex;
        padding: 1em 1.5em;
        background: #f5f5f5;
        border-top: 1px solid #ddd;
        position: relative;
        font-family: monospace;
        text-transform: uppercase;
        letter-spacing: 1px;
      }
      .sep-thankyou .sep-merchant {
        font-weight: bold;
      }
      .sep-thankyou .sep-amount {
        position: absolute;
        right: 1.5em;
        top: 50%;
        transform: translateY(-50%);
        font-weight: bold;
      }
      .woocommerce-thankyou-order-received, h1.entry-title {
        display: none;
      }
    </style>';

    // Build the HTML
    $html  = $style;
    $html .= '<div class="sep-thankyou">';
    $html .= '  <div class="sep-check-icon"><i style="border: 2px solid; padding: 15px; border-radius: 50%;" class="fas fa-check"></i></div>';
    $html .= '  <h2>' . esc_html__( 'Thanks for your payment', 'sponsor-episodes' ) . '</h2>';
    $html .= '  <p class="sep-subtext">'
           . sprintf(
               /* translators: 1: merchant name */
               esc_html__( 'A payment to %1$s will appear on your statement.', 'sponsor-episodes' ),
               esc_html( $merchant )
             )
           . '</p>';
    $html .= '  <div class="sep-receipt">';
    $html .= '    <span class="sep-merchant">' . esc_html( $merchant ) . '</span>';
    $html .= '    <span class="sep-amount">'  . wp_kses_post( $amount )  . '</span>';
    $html .= '  </div>';
    $html .= '</div>';

    return $text . $html;
}
	
	/**
 * Return all booked date‑ranges for a given slot.
 */
  public function ajax_get_reserved_ranges() {
    $slot = sanitize_text_field( $_POST['slot'] ?? '' );

    // Allow banners or ANY link_pinned_N (N=1–4)
    $allowed = [ 'ad_home', 'ad_side' ];
    if ( ! in_array( $slot, $allowed, true ) 
         && strpos( $slot, 'link_pinned_' ) !== 0
    ) {
        wp_send_json_error();
    }

    $ranges = [];
    $orders = wc_get_orders([
        'limit'  => -1,
        'status' => [ 'processing', 'completed' ],
    ]);

    foreach ( $orders as $order ) {
        foreach ( $order->get_items() as $item ) {
            // Meta key matches exactly sep_{slot}_range
            $meta = $item->get_meta( "sep_{$slot}_range", true );
            if ( is_array( $meta ) && ! empty( $meta['from'] ) && ! empty( $meta['to'] ) ) {
                $ranges[] = [ 'from' => $meta['from'], 'to' => $meta['to'] ];
            }
        }
    }

    wp_send_json_success( $ranges );
}


	
}

// Initialize the plugin
SEP_Plugin::instance();
