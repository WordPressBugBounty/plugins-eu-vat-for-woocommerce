<?php
/**
 * EU VAT for WooCommerce - Meta Boxes
 *
 * @version 4.4.1
 * @since   4.2.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Alg_WC_EU_VAT_Meta_Boxes' ) ) :

class Alg_WC_EU_VAT_Meta_Boxes {

	/**
	 * Constructor.
	 *
	 * @version 4.2.0
	 * @since   4.2.0
	 */
	function __construct() {

		// EU VAT number summary on order edit page
		if ( 'yes' === get_option( 'alg_wc_eu_vat_add_order_edit_metabox', 'no' ) ) {

			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

			// "Validate VAT and remove taxes" button
			add_action( 'admin_init', array( $this, 'validate_vat_and_maybe_remove_taxes' ), PHP_INT_MAX );

			// Get VAT details
			add_action( 'admin_init', array( $this, 'get_vat_details' ), PHP_INT_MAX );
			add_action( 'admin_notices', array( $this, 'admin_notice' ), PHP_INT_MAX );

		}

		// Popup metabox
		add_action( 'add_meta_boxes', array( $this, 'add_popup_order_meta_box' ) );

	}

	/**
	 * Update the order vat details.
	 *
	 * @version 4.2.5
	 * @since   4.0.0
	 */
	function get_vat_details() {
		if ( isset( $_GET['get_vat_details'], $_GET['number'], $_GET['country'] ) ) {
			$order_id      = absint( $_GET['get_vat_details'] );
			$vat_number    = sanitize_text_field( wp_unslash( $_GET['number'] ) );
			$country       = sanitize_text_field( wp_unslash( $_GET['country'] ) );
			$eu_vat_number = alg_wc_eu_vat_parse_vat( $vat_number, $country );
			$is_valid      = alg_wc_eu_vat_validate_vat( $eu_vat_number['country'], $eu_vat_number['number'] );
			if ( $is_valid ) {
				$vat_response_data = alg_wc_eu_vat()->core->vat_details_data;
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->update_meta_data( alg_wc_eu_vat_get_field_id() . '_details', $vat_response_data );
					$order->save();
				}
			}
			wp_safe_redirect(
				add_query_arg(
					'alg_wc_eu_vat_details_result',
					( $is_valid ? 'success' : 'error' ),
					remove_query_arg( array( 'get_vat_details', 'country', 'number' ) )
				)
			);
			exit;
		}
	}

	/**
	 * Admin notice.
	 *
	 * @version 4.2.5
	 * @since   4.0.0
	 */
	function admin_notice() {
		if ( ! isset( $_GET['alg_wc_eu_vat_details_result'] ) ) {
			return;
		}
		$result  = sanitize_text_field( wp_unslash( $_GET['alg_wc_eu_vat_details_result'] ) );
		$message = (
			( 'success' === $result ) ?
			__( 'VAT details have been updated successfully.', 'eu-vat-for-woocommerce' ) :
			__( 'VAT details update failed. Please set a valid valid VAT number.', 'eu-vat-for-woocommerce' )
		);
		?>
		<div class="notice notice-<?php echo esc_attr( $result ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * add_popup_order_meta_box.
	 *
	 * @version 4.2.0
	 */
	function add_popup_order_meta_box() {
		 add_meta_box(
			'woocommerce_eu_vat_shop_order_popup',
			__( 'Check VAT Number', 'eu-vat-for-woocommerce' ),
			array( $this, 'create_popup_order_meta'),
			'shop_order',
			'side',
			'low'
		);
	}

	/**
	 * create_popup_order_meta.
	 *
	 * @version 4.2.0
	 */
	function create_popup_order_meta( $post ) {
		add_thickbox();
		?>
		<a href="https://ec.europa.eu/taxation_customs/vies?TB_iframe=true&width=772&height=485" class="thickbox button"><?php
			esc_html_e( 'Open VIES', 'eu-vat-for-woocommerce' );
		?></a>
		<?php
	}

	/**
	 * add_meta_box.
	 *
	 * @version 2.12.6
	 * @since   1.0.0
	 */
	function add_meta_box() {
		$current_screen = get_current_screen()->id;

		if (
			'shop_order' == $current_screen ||
			'woocommerce_page_wc-orders' == $current_screen
		) {
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
	 * @version 4.3.0
	 * @since   1.0.0
	 */
	function create_meta_box( $object ) {
		$this->output_meta_box_data( $object );
	}

	/**
	 * output_meta_box_data.
	 *
	 * @version 4.3.0
	 * @since   4.3.0
	 *
	 * @todo    (dev) save actual EU VAT number used on checkout (instead of `$_order->get_meta( '_' . alg_wc_eu_vat_get_field_id() )`)?
	 * @todo    (dev) add country flag?
	 */
	function output_meta_box_data( $object, $do_return_table_data = false ) {

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

		// VAT Details
		$customer_eu_vat_details = $_order->get_meta( alg_wc_eu_vat_get_field_id() . '_details' );
		if ( is_array( $customer_eu_vat_details ) ) {
			$table_data = array_merge(
				$table_data,
				array(
					array(
						__( 'Business Name', 'eu-vat-for-woocommerce' ),
						esc_html( $customer_eu_vat_details['business_name']['data'] ?? '' ),
					),
					array(
						__( 'Business Address', 'eu-vat-for-woocommerce' ),
						esc_html( $customer_eu_vat_details['business_address']['data'] ?? '' ),
					),
					array(
						__( 'Country Code', 'eu-vat-for-woocommerce' ),
						esc_html( $customer_eu_vat_details['country_code']['data'] ?? '' ),
					),
					array(
						__( 'VAT Number', 'eu-vat-for-woocommerce' ),
						esc_html( $customer_eu_vat_details['vat_number']['data'] ?? '' ),
					),
				)
			);
		}

		// Request Identifier
		$request_identifier = $_order->get_meta(
			apply_filters(
				'alg_wc_eu_vat_request_identifier_meta_key',
				alg_wc_eu_vat_get_field_id() . '_request_identifier'
			)
		);
		if ( '' !== $request_identifier ) {
			$table_data = array_merge(
				$table_data,
				array(
					array(
						__( 'Request Identifier', 'eu-vat-for-woocommerce' ),
						esc_html( $request_identifier ),
					),
				)
			);
		}

		// Return table data?
		if ( $do_return_table_data ) {
			return $table_data;
		}

		// Output
		echo alg_wc_eu_vat_get_table_html(
			$table_data,
			array(
				'table_class'        => 'widefat striped',
				'table_heading_type' => 'vertical',
			)
		);

		// Order ID
		$order_id = $_order->get_id();

		// Validate VAT and remove taxes
		echo '<p>' .
			'<a href="' . esc_url( add_query_arg( 'validate_vat_and_maybe_remove_taxes', absint( $order_id ) ) ) . '">' .
				esc_html__( 'Validate VAT and remove taxes', 'eu-vat-for-woocommerce' ) .
			'</a>' .
		'</p>';

		// Fetch VAT details and display the business name and address
		echo '<p>' .
			'<a href="' . esc_url( add_query_arg( array(
				'get_vat_details' => absint( $order_id ),
				'country'         => esc_html( $_order->get_billing_country() ),
				'number'          => esc_html( $customer_eu_vat_number ),
			) ) ) . '">' .
				esc_html__( 'Get VAT details', 'eu-vat-for-woocommerce' ) .
			'</a>' .
		'</p>';

	}

	/**
	 * validate_vat_and_maybe_remove_taxes.
	 *
	 * @version 4.4.1
	 * @since   1.0.0
	 *
	 * @todo    (dev) Remove taxes: remove and instead do `$order->calculate_totals()` (after setting `is_vat_exempt` to `yes`)
	 * @todo    (dev) Request Identifier: merge with `save_request_identifier_to_order()` (`Alg_WC_EU_VAT_Orders` class)
	 * @todo    (dev) `alg_wc_eu_vat()->core->eu_vat_response_data`: clear after use?
	 */
	function validate_vat_and_maybe_remove_taxes() {

		if ( ! isset( $_GET['validate_vat_and_maybe_remove_taxes'] ) ) {
			return;
		}

		$preserve_countries           = alg_wc_eu_vat()->core->eu_vat_ajax_instance->get_preserve_countries();
		$preserve_countries_condition = false;

		$order_id = sanitize_text_field( wp_unslash( $_GET['validate_vat_and_maybe_remove_taxes'] ) );
		$order    = wc_get_order( $order_id );

		if ( $order ) {

			$vat_id          = $order->get_meta( '_' . alg_wc_eu_vat_get_field_id() );
			$billing_company = $order->get_billing_company();

			if ( '' != $vat_id ) {

				$eu_vat_number = alg_wc_eu_vat_parse_vat( $vat_id, $order->get_billing_country() );

				if ( ! empty( $preserve_countries ) ) {
					if ( in_array( $eu_vat_number['country'], $preserve_countries ) ) {
						$preserve_countries_condition = true;
					}
				}

				if (
					! $preserve_countries_condition &&
					alg_wc_eu_vat_validate_vat(
						$eu_vat_number['country'],
						$eu_vat_number['number'],
						$billing_company
					)
				) {

					// Remove taxes
					foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item_id => $item ) {
						$item->set_taxes( false );
					}
					foreach ( $order->get_shipping_methods() as $item_id => $item ) {
						$item->set_taxes( false );
					}
					$order->update_taxes();
					$order->calculate_totals( false );

					// "VAT exempt" meta
					$order->update_meta_data( 'is_vat_exempt', 'yes' );

					// Request Identifier
					$vat_response_data = alg_wc_eu_vat()->core->eu_vat_response_data;
					if ( isset( $vat_response_data->requestIdentifier ) ) {
						$order->update_meta_data(
							apply_filters(
								'alg_wc_eu_vat_request_identifier_meta_key',
								alg_wc_eu_vat_get_field_id() . '_request_identifier'
							),
							$vat_response_data->requestIdentifier
						);
					}

					// Save updated meta
					$order->save();

				}

			}

		}

		// Redirect
		wp_safe_redirect( remove_query_arg( 'validate_vat_and_maybe_remove_taxes' ) );
		exit;

	}

}

endif;

return new Alg_WC_EU_VAT_Meta_Boxes();
