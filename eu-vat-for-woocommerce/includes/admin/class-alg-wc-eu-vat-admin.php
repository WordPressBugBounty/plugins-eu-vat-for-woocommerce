<?php
/**
 * EU VAT for WooCommerce - Admin Class
 *
 * @version 3.2.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Alg_WC_EU_VAT_Admin' ) ) :

class Alg_WC_EU_VAT_Admin {

	/**
	 * Constructor.
	 *
	 * @version 2.11.7
	 * @since   1.0.0
	 */
	function __construct() {

		// Admin order edit
		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'add_to_admin_order_display' ), PHP_INT_MAX );

		// Admin order edit - "Load billing address" button
		add_filter( 'woocommerce_ajax_get_customer_details', array( $this, 'add_to_ajax_get_customer_details' ), PHP_INT_MAX, 3 );

		// EU VAT number summary on order edit page
		if ( 'yes' === get_option( 'alg_wc_eu_vat_add_order_edit_metabox', 'no' ) ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
			// "Validate VAT and remove taxes" button
			add_action( 'admin_init', array( $this, 'validate_vat_and_maybe_remove_taxes' ), PHP_INT_MAX );
		}

		// Reports
		add_filter( 'woocommerce_admin_reports', array( $this, 'add_eu_vat_reports' ), PHP_INT_MAX );

		// Admin orders list
		if ( 'yes' === get_option( 'alg_wc_eu_vat_add_order_list_column', 'no' ) ) {
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_columns' ), PHP_INT_MAX );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_columns' ), PHP_INT_MAX );

			// HPOS column
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_columns' ), PHP_INT_MAX );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_wc_order_columns' ), PHP_INT_MAX, 2 );

			add_action( 'restrict_manage_posts', array( $this, 'display_admin_shop_order_by_meta_filter' ), PHP_INT_MAX );

			// HOPS filter
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'display_admin_shop_order_by_meta_filter_HPOS' ), PHP_INT_MAX, 2 );

			add_filter( 'request', array( $this, 'process_admin_shop_order_marketing_by_meta' ), 99 );
			add_filter( 'woocommerce_shop_order_search_fields',  array( $this, 'shop_order_meta_search_fields'), 10, 1 );

			add_filter( 'pre_get_posts', array( $this, 'euvat_filter_orders' ), 100 );

			// HOPS filter query
			add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'euvat_filter_orders_HPOS' ), 100 );
		}

		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'order_phone_backend' ), 10, 1 );

		// Add a popup metabox
		add_action( 'add_meta_boxes', array( $this, 'popup_order_meta_boxes' ) );

	}

	function popup_order_meta_boxes(){
		 add_meta_box(
			'woocommerce_eu_vat_shop_order_popup',
			__( 'Check VAT Number' ),
			array( $this, 'popup_order_meta_box_content'),
			'shop_order',
			'side',
			'low'
		);
	}

	// popup metabox content
	function popup_order_meta_box_content( $post ){
		?>
		<?php add_thickbox(); ?>
		<a href="https://ec.europa.eu/taxation_customs/vies?TB_iframe=true&width=772&height=485" class="thickbox button">Open VIES</a>
		<?php
	}

	/**
	 * order_phone_backend.
	 *
	 * @version 2.9.13
	 * @since   2.9.13
	 */

	function order_phone_backend($order){
		$field_id = alg_wc_eu_vat_get_field_id();
		$value    = $order->get_meta( '_' . $field_id . '_customer_decide' );
		if ( 1 == $value ) {
			echo "<br><p><strong>" . __( 'Let Customer Decide:', 'eu-vat-for-woocommerce' ) . "</strong> Yes</p><br>";
		}
	}


	/**
	 * add_order_columns.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function add_order_columns( $columns ) {
		$columns['alg_wc_eu_vat'] = __( 'EU VAT', 'eu-vat-for-woocommerce' );
		return $columns;
	}

	/**
	 * render_order_columns.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function render_order_columns( $column ) {
		if ( 'alg_wc_eu_vat' === $column ) {
			echo get_post_meta( get_the_ID(), '_'. alg_wc_eu_vat_get_field_id(), true );
			if ( 'yes' === get_post_meta( get_the_ID(), 'is_vat_exempt', true ) ) {
				echo ' &#10004;';
			}
		}
	}

	/**
	 * render_wc_order_columns.
	 *
	 * @version 2.11.3
	 * @since   2.11.3
	 */
	function render_wc_order_columns( $column, $order ) {
		if ( 'alg_wc_eu_vat' === $column ) {
			$key           = '_'. alg_wc_eu_vat_get_field_id();
			$vat_value     = $order->get_meta( $key );
			$is_vat_exempt = $order->get_meta( 'is_vat_exempt' );
			if ( ! empty( $vat_value ) ) {
				echo $vat_value;
			}
			if ( 'yes' === $is_vat_exempt ) {
				echo ' &#10004;';
			}
		}
	}

	/**
	 * get_filter_shop_order_meta.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function get_filter_shop_order_meta( $domain = 'woocommerce' ){
		// Add below the metakey / label pairs to filter orders
		return [
			'_billing_eu_vat_number' => __('Orders with EU VAT numbers', $domain)
		];
	}

	/**
	 * display_admin_shop_order_by_meta_filter_HPOS.
	 *
	 * @version 2.11.7
	 * @since   2.11.7
	 */

	function display_admin_shop_order_by_meta_filter_HPOS( $post_type, $which ) {

		if( 'shop_order' !== $post_type ) {
			return;
		}

		$domain    = 'eu-vat-for-woocommerce';
		$filter_id = 'filter_shop_order_by_meta';
		$current   = isset($_GET[$filter_id])? $_GET[$filter_id] : '';

		echo '<select name="'.$filter_id.'">
		<option value="">' . __('Select Filter EU VAT...', $domain) . '</option>';

		$options = $this->get_filter_shop_order_meta( $domain );

		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', $key,
				$key === $current ? '" selected="selected"' : '', $label );
		}
		echo '</select>';

	}

	/**
	 * display_admin_shop_order_by_meta_filter.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function display_admin_shop_order_by_meta_filter(){
		global $pagenow, $typenow;

		if( 'shop_order' === $typenow && 'edit.php' === $pagenow ) {
			$domain    = 'eu-vat-for-woocommerce';
			$filter_id = 'filter_shop_order_by_meta';
			$current   = isset($_GET[$filter_id])? $_GET[$filter_id] : '';

			echo '<select name="'.$filter_id.'">
			<option value="">' . __('Select Filter EU VAT...', $domain) . '</option>';

			$options = $this->get_filter_shop_order_meta( $domain );

			foreach ( $options as $key => $label ) {
				printf( '<option value="%s"%s>%s</option>', $key,
					$key === $current ? '" selected="selected"' : '', $label );
			}
			echo '</select>';
		}
	}

	/**
	 * process_admin_shop_order_marketing_by_meta.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function process_admin_shop_order_marketing_by_meta( $vars ) {
		global $pagenow, $typenow;

		$filter_id = 'filter_shop_order_by_meta';

		if ( $pagenow == 'edit.php' && 'shop_order' === $typenow
		&& isset( $_GET[$filter_id] ) && ! empty($_GET[$filter_id]) ) {
			$vars['meta_key']   = $_GET[$filter_id];
			$vars['orderby']    = 'meta_value';
		}
		return $vars;
	}

	/**
	 * shop_order_meta_search_fields.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function shop_order_meta_search_fields( $meta_keys ){
		foreach ( $this->get_filter_shop_order_meta() as $meta_key => $label ) {
			$meta_keys[] = $meta_key;
		}
		return $meta_keys;
	}

	/**
	 * euvat_filter_orders_HPOS.
	 *
	 * @version 2.11.7
	 * @since   2.11.7
	 */

	function euvat_filter_orders_HPOS( $query_args ) {

		$filter_id = 'filter_shop_order_by_meta';

		if( isset( $_GET[ $filter_id ] ) && $_GET[ $filter_id ] ) {
			$query_args[ 'meta_query' ] = array(
				array(
					'key' => '_billing_eu_vat_number',
					'value' => array(''),
					'compare' => 'NOT IN',
				)
			);
		}

		return $query_args;
	}

	/**
	 * euvat_filter_orders.
	 *
	 * @version 3.2.0
	 * @since   2.11.7
	 */
	function euvat_filter_orders($query) {
		global $pagenow, $typenow;

		$filter_id = 'filter_shop_order_by_meta';

		$qv = &$query->query_vars;

		if (
			'edit.php' === $pagenow &&
			'shop_order' === $typenow &&
			! empty( $_GET[ $filter_id ] )
		) {
			if ( 'shop_order' === $qv['post_type'] ) {
				$query->set( 'meta_key', '_billing_eu_vat_number' );
				$query->set( 'meta_value', array( '' ) );
				$query->set( 'meta_compare', 'NOT IN' );
			}
		}

		return $query;
	}

	/**
	 * add_eu_vat_reports.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function add_eu_vat_reports( $reports ) {
		if ( ! isset( $reports['taxes'] ) ) {
			$reports['taxes'] = array(
				'title'   => __( 'Taxes', 'woocommerce' ),
				'reports' => array(),
			);
		}
		$reports['taxes']['reports']['alg_wc_eu_vat'] = array(
			'title'       => __( 'EU VAT', 'eu-vat-for-woocommerce' ),
			'description' => '',
			'hide_title'  => true,
			'callback'    => array( $this, 'output_eu_vat_report' ),
		);
		return $reports;
	}

	/**
	 * output_eu_vat_report.
	 *
	 * @version 1.5.0
	 * @since   1.5.0
	 */
	function output_eu_vat_report() {
		require_once( 'class-wc-report-alg-wc-eu-vat.php' );
		$report = new WC_Report_Alg_WC_EU_VAT();
		$report->output_report();
		echo '<p><em>' .
				__( 'Report includes all EU VAT countries with existing sales.', 'eu-vat-for-woocommerce' ) . ' ' .
				__( 'Table is sorted by total tax value.', 'eu-vat-for-woocommerce' ) .
			'</em></p>';
	}

	/**
	 * add_to_admin_order_display.
	 *
	 * @version 1.4.0
	 * @since   1.0.0
	 *
	 * @todo    (dev) make full row?
	 */
	function add_to_admin_order_display( $fields ) {

		$fields[ alg_wc_eu_vat_get_field_id( true ) ] = array(
			'type'  => 'text',
			'label' => do_shortcode( get_option( 'alg_wc_eu_vat_field_label', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) ),
			'show'  => true,
		);

		return $fields;
	}

	/**
	 * add_to_ajax_get_customer_details.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function add_to_ajax_get_customer_details( $data, $customer, $user_id ) {
		$data['billing'][ alg_wc_eu_vat_get_field_id( true ) ] = get_user_meta( $user_id, alg_wc_eu_vat_get_field_id(), true );
		$data['billing'][ alg_wc_eu_vat_get_field_id( true ) . '_customer_decide' ] = get_user_meta( $user_id, alg_wc_eu_vat_get_field_id() . '_customer_decide', true );
		return $data;
	}

	/**
	 * add_meta_box.
	 *
	 * @version 2.12.6
	 * @since   1.0.0
	 */
	function add_meta_box() {
		$current_screen = get_current_screen()->id;

		if($current_screen=='shop_order' || $current_screen=='woocommerce_page_wc-orders') {
			add_meta_box(
				'alg-wc-eu-vat',
				__( 'EU VAT', 'eu-vat-for-woocommerce' ),
				array( $this, 'create_meta_box' ),
				$current_screen,
				'side',
				'low'
			);
		}
	}

	/**
	 * create_meta_box.
	 *
	 * @version 2.12.14
	 * @since   1.0.0
	 *
	 * @todo    (dev) save actual EU VAT number used on checkout (instead of `$_order->get_meta( '_' . alg_wc_eu_vat_get_field_id() )`)
	 * @todo    (dev) add country flag?
	 */
	function create_meta_box( $object ) {

		$_order = is_a( $object, 'WP_Post' ) ? wc_get_order( $object->ID ) : $object;

		$_customer_ip_address = ( alg_wc_eu_vat()->core->is_wc_version_below_3_0_0 ? $_order->customer_ip_address : $_order->get_customer_ip_address() );

		// Country by IP
		$customer_country = alg_wc_eu_vat_get_customers_location_by_ip( $_customer_ip_address );

		// Customer EU VAT number
		if ( '' == ( $customer_eu_vat_number = $_order->get_meta( '_' . alg_wc_eu_vat_get_field_id() ) ) ) {
			$customer_eu_vat_number = '-';
		}

		// Taxes
		$taxes = '';
		$taxes_array = $_order->get_tax_totals();
		if ( empty( $taxes_array ) ) {
			$taxes = '-';
		} else {
			foreach ( $taxes_array as $tax ) {
				$taxes .= $tax->label . ': ' . $tax->formatted_amount . '<br>';
			}
		}

		// Results table
		$table_data = array(
			array(
				__( 'Customer IP', 'eu-vat-for-woocommerce' ),
				$_customer_ip_address,
			),
			array(
				__( 'Country by IP', 'eu-vat-for-woocommerce' ),
				alg_wc_eu_vat_get_country_name_by_code( $customer_country ) . ' [' . $customer_country . ']',
			),
			array(
				__( 'Customer EU VAT Number', 'eu-vat-for-woocommerce' ),
				$customer_eu_vat_number,
			),
			array(
				__( 'Taxes', 'eu-vat-for-woocommerce' ),
				$taxes,
			),
		);

		// Output
		$order_id = $_order->get_id();
		echo alg_wc_eu_vat_get_table_html( $table_data, array( 'table_class' => 'widefat striped', 'table_heading_type' => 'vertical' ) );
		echo '<p>' . '<a href="' . esc_url ( add_query_arg( 'validate_vat_and_maybe_remove_taxes', $order_id ) ) . '">' .
			__( 'Validate VAT and remove taxes', 'eu-vat-for-woocommerce' ) . '</a>' . '</p>';
	}

	/**
	 * validate_vat_and_maybe_remove_taxes.
	 *
	 * @version 2.9.13
	 * @since   1.0.0
	 */
	function validate_vat_and_maybe_remove_taxes() {
		$preserve_countries = alg_wc_eu_vat()->core->eu_vat_ajax_instance->get_preserve_countrues();
		$preserve_countries_condition = false;

		if ( isset( $_GET['validate_vat_and_maybe_remove_taxes'] ) ) {
			$order_id = $_GET['validate_vat_and_maybe_remove_taxes'];
			$order    = wc_get_order( $order_id );
			if ( $order ) {

				$vat_id          = $order->get_meta( '_' . alg_wc_eu_vat_get_field_id() );
				$billing_company = $order->get_meta( '_' . 'billing_company' );
				if ( '' != $vat_id ) {
					$eu_vat_number = alg_wc_eu_vat_parse_vat( $vat_id, $order->get_billing_country() );

					if(!empty($preserve_countries)){
						if(in_array($eu_vat_number['country'],$preserve_countries)){
							$preserve_countries_condition = true;
						}
					}

					if ( !$preserve_countries_condition && alg_wc_eu_vat_validate_vat( $eu_vat_number['country'], $eu_vat_number['number'], $billing_company ) ) {
						foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item_id => $item ) {
							$item->set_taxes( false );
						}
						foreach ( $order->get_shipping_methods() as $item_id => $item ) {
							$item->set_taxes( false );
						}
						$order->update_taxes();
						$order->calculate_totals( false );
					}
				}
			}
			wp_safe_redirect( remove_query_arg( 'validate_vat_and_maybe_remove_taxes' ) );
			exit;
		}
	}

}

endif;

return new Alg_WC_EU_VAT_Admin();
