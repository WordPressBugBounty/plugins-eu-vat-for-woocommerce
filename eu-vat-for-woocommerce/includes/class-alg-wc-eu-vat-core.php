<?php
/**
 * EU VAT for WooCommerce - Core Class
 *
 * @version 3.2.4
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Alg_WC_EU_VAT_Core' ) ) :

class Alg_WC_EU_VAT_Core {

	/**
	 * is_wc_version_below_3_0_0.
	 */
	public $is_wc_version_below_3_0_0 = null;

	/**
	 * eu_vat_ajax_instance.
	 */
	public $eu_vat_ajax_instance = null;

	/**
	 * required_in_countries.
	 */
	public $required_in_countries = null;

	/**
	 * show_in_countries.
	 */
	public $show_in_countries = null;

	/**
	 * Global namespace instance of this plugin.
	 *
	 * @var   string.
	 * @since 2.11.6
	 */
	public $nm_space = 'alg_eu_vat';

	/**
	 * Constructor.
	 *
	 * @version 3.2.4
	 * @since   1.0.0
	 *
	 * @todo    (dev) "eu vat number" to "eu vat"?
	 * @todo    (feature) `add_eu_vat_verify_button` (`woocommerce_form_field_text`) (`return ( alg_wc_eu_vat_get_field_id() === $key ) ? $field . '<span style="font-size:smaller !important;">' . '[<a name="billing_eu_vat_number_verify" href="">' . __( 'Verify', 'eu-vat-for-woocommerce' ) . '</a>]' . '</span>' : $field;`)
	 */
	function __construct() {

		// Properties
		$this->is_wc_version_below_3_0_0 = version_compare( get_option( 'woocommerce_version', null ), '3.0.0', '<' );

		// Functions
		require_once( 'functions/alg-wc-eu-vat-functions-validation.php' );

		// Classes
		require_once( 'class-alg-wc-eu-vat-shortcode.php' );
		$this->eu_vat_ajax_instance = require_once( 'class-alg-wc-eu-vat-ajax.php' );
		require_once( 'admin/class-alg-wc-eu-vat-admin.php' );

		// Hooks: Session, exclusion, validation
		add_action( 'init', array( $this, 'start_session' ) );
		add_filter( 'init', array( $this, 'maybe_exclude_vat' ), PHP_INT_MAX );

		add_filter( 'woocommerce_checkout_update_order_review', array( $this, 'maybe_exclude_vat' ), PHP_INT_MAX );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'maybe_exclude_vat' ), 99);
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'maybe_exclude_vat' ), PHP_INT_MAX);

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validate_vat' ), PHP_INT_MAX );

		// Hooks: Customer meta, default value
		add_filter( 'woocommerce_customer_meta_fields', array( $this, 'add_eu_vat_number_customer_meta_field' ) );
		add_filter( 'default_checkout_' . alg_wc_eu_vat_get_field_id(), array( $this, 'add_default_checkout_billing_eu_vat_number' ), PHP_INT_MAX, 2 );

		// Hooks: Frontend
		if ( 'yes' !== get_option( 'alg_wc_eu_vat_hide_eu_vat', 'no' ) ) {
			add_filter( 'woocommerce_checkout_fields', array( $this, 'add_eu_vat_checkout_field_to_frontend' ), 99 );
		}

		// Hooks: Display
		$positions = get_option( 'alg_wc_eu_vat_display_position', array( 'after_order_table' ) );
		if ( empty( $positions ) ) {
			$positions = array( 'after_order_table' );
		}
		if ( ! is_array( $positions ) ) {
			$positions = array( $positions );
		}
		if ( in_array( 'after_order_table', $positions ) ) {
			add_action( 'woocommerce_order_details_after_order_table', array( $this, 'add_eu_vat_number_to_order_display' ), PHP_INT_MAX );
			add_action( 'woocommerce_email_after_order_table', array( $this, 'add_eu_vat_number_to_order_display' ), PHP_INT_MAX );
		}
		if ( in_array( 'in_billing_address', $positions ) ) {
			add_filter( 'woocommerce_order_formatted_billing_address', array( $this, 'add_eu_vat_number_to_order_billing_address' ), PHP_INT_MAX, 2 );
			add_filter( 'woocommerce_my_account_my_address_formatted_address', array( $this, 'add_eu_vat_number_to_my_account_billing_address' ), PHP_INT_MAX, 3 );
			add_filter( 'woocommerce_localisation_address_formats', array( $this, 'add_eu_vat_number_to_address_formats' ) );
			add_filter( 'woocommerce_formatted_address_replacements', array( $this, 'replace_eu_vat_number_in_address_formats' ), PHP_INT_MAX, 2 );
			// Make it editable ("My Account > Addresses")
			add_filter( 'woocommerce_address_to_edit', array( $this, 'add_eu_vat_number_to_editable_fields' ), PHP_INT_MAX, 2 );
			add_action( 'woocommerce_customer_save_address', array( $this, 'save_eu_vat_number_from_editable_fields' ), PHP_INT_MAX, 2 );
		}

		// Show zero VAT
		if ( 'yes' === get_option( 'alg_wc_eu_vat_always_show_zero_vat', 'no' ) ) {
			add_filter( 'woocommerce_cart_tax_totals', array( $this, 'always_show_zero_vat' ), PHP_INT_MAX, 2 );
		}

		// Shortcodes
		add_shortcode( 'alg_wc_eu_vat_translate', array( $this, 'language_shortcode' ) );

		add_filter( 'alg_wc_eu_vat_show_in_countries', array( $this, 'show_in_countries' ) );
		add_filter( 'alg_wc_eu_vat_reqquired_in_countries', array( $this, 'required_in_countries' ) );

		$this->required_in_countries = apply_filters( 'alg_wc_eu_vat_reqquired_in_countries', '' );
		$this->show_in_countries = apply_filters( 'alg_wc_eu_vat_show_in_countries', '' );

		// Show field for selected countries only
		$eu_vat_required = get_option( 'alg_wc_eu_vat_field_required', 'no' );

		if ( '' != $this->show_in_countries || '' != $this->required_in_countries || 'yes_for_company' === $eu_vat_required ) {
			add_filter( 'woocommerce_get_country_locale', array( $this, 'set_eu_vat_country_locale' ), PHP_INT_MAX, 3 );
			add_filter( 'woocommerce_get_country_locale_default', array( $this, 'set_eu_vat_country_locale_default' ), PHP_INT_MAX );
			add_filter( 'woocommerce_country_locale_field_selectors', array( $this, 'set_eu_vat_country_locale_field_selectors' ), PHP_INT_MAX );
		}

		// "Place order" button confirmation
		if ( 'yes' === get_option( 'alg_wc_eu_vat_field_confirmation', 'no' ) ) {
			add_filter( 'wp_enqueue_scripts', array( $this, 'add_place_order_button_confirmation_script' ) );
		}

		if ( 'yes' === get_option( 'alg_wc_eu_vat_validate_vat_admin_side', 'no' ) ) {
			if ( is_admin() ) {
				add_filter( 'woocommerce_order_is_vat_exempt', array( $this, 'admin_order_is_vat_exempt' ), PHP_INT_MAX, 2 );
			}
			add_filter( 'woocommerce_order_is_vat_exempt', array( $this, 'admin_woocommerce_order_is_vat_exempt'), PHP_INT_MAX, 2 );
		}

		add_action( 'admin_print_scripts', array( $this, 'admin_inline_js' ), PHP_INT_MAX );

		add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'admin_function_to_add_the_button'), PHP_INT_MAX );

		add_action( 'admin_footer', array( $this, 'eu_vat_admin_footer'), PHP_INT_MAX );
		add_action( 'wp_footer', array( $this, 'eu_vat_wp_footer'), PHP_INT_MAX );

		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_payment_gateways_allowed' ), PHP_INT_MAX );

		if ( 'yes' === get_option( 'alg_wc_eu_vat_field_signup_form', 'no' ) ) {
			add_action( 'woocommerce_register_form', array( $this, 'add_eu_vat_registration_woocommerce'), 15 );
			add_action( 'woocommerce_register_post', array( $this, 'add_eu_vat_registration_woocommerce_validation'), 15, 3 );
			add_action( 'woocommerce_created_customer', array( $this, 'add_eu_vat_registration_save_woocommerce_field'), 15 );
		}

		add_filter( 'alg_wc_eu_vat_maybe_exclude_vat', array( $this, 'maybe_exclude_vat_free' ) );
		add_filter( 'alg_wc_eu_vat_set_eu_vat_country_locale', array( $this, 'set_eu_vat_country_locale_core' ), PHP_INT_MAX, 3 );

		add_filter( 'wpo_wcpdf_after_billing_address', array( $this, 'alg_extend_wcpdf_after_billing_address' ), 10, 2  );

		add_action( 'restrict_manage_users', array( $this, 'add_billing_eu_vat_section_filter'), 10 );
		add_filter( 'pre_get_users', array( $this, 'filter_users_by_billing_eu_vat'), 10 );

		add_action( 'wpo_wcpdf_after_order_details', array( $this, 'add_vat_exempt_text_pdf_footer'), 10, 2 );

		add_filter( 'woocommerce_billing_fields', array( $this, 'add_frontend_edit_billing_fields' ), 10 );

		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'alg_wc_eu_vat_filter_order_response' ), PHP_INT_MAX, 3 );

		add_filter( 'yith_ywpi_template_editor_customer_info_placeholders', array( $this, 'alg_wc_eu_vat_support_yith_invoice' ), PHP_INT_MAX, 1 );

		// Checkout block
		if (
			'yes' === get_option( 'alg_wc_eu_vat_enable_checkout_block_field', 'no' ) &&
			version_compare( get_option( 'woocommerce_version', null ), '8.9.1', '>=' )
		) {

			add_action( 'woocommerce_init', array( $this, 'register_additional_checkout_block_field' ), PHP_INT_MAX );
			add_action( 'woocommerce_init', array( $this, 'alg_wc_eu_woocommerce_store_api_register_update_callback' ), 10 );

			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'alg_eu_vat_update_block_order_meta_eu_vat' ), 10, 2 );
			add_action( 'woocommerce_blocks_validate_location_contact_fields', array( $this, 'alg_wc_eu_woocommerce_validate_eu_vat_field_checkout_block' ), 10, 3 );
			add_action( 'woocommerce_blocks_validate_location_address_fields', array( $this, 'alg_wc_eu_woocommerce_validate_eu_vat_field_checkout_block' ), 10, 3 );

			add_filter( 'woocommerce_get_default_value_for_alg_eu_vat/billing_eu_vat_number', array( $this, 'alg_eu_vat_update_default_value_for_eu_vat_field' ), 99, 3 );

		}

		// Hook into 'init' to ensure proper loading order
		add_action( 'init', array( $this, 'init_hooks' ) );

		add_action( 'admin_footer', array( $this, 'alg_wc_eu_vat_admin_js_field_control' ) );

		add_action( 'wp_ajax_alg_wc_eu_vat_update_closedate', array( $this, 'alg_wc_eu_vat_update_closedate' ) );
		add_action( 'wp_ajax_nopriv_alg_wc_eu_vat_update_closedate', array( $this, 'alg_wc_eu_vat_update_closedate' ) );

		add_action( 'admin_footer', array( $this, 'alg_wc_eu_vat_admin_footer_js' ) );

	}

	/**
	 * alg_wc_eu_vat_update_closedate.
	 *
	 * @version 3.1.1
	 */
	function alg_wc_eu_vat_update_closedate() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$phpdatetime = time();
			update_user_meta( $user_id, 'alg_wc_eu_vat_closedate', $phpdatetime );
		}
		echo "ok";
		die;
	}

	/**
	 * alg_wc_eu_vat_admin_footer_js.
	 *
	 * @version 3.1.1
	 */
	function alg_wc_eu_vat_admin_footer_js( $data ) {
		?>
			<script>
			jQuery( document ).ready( function () {
				jQuery( ".alg_wc_eu_vat_close" ).on( 'click', function () {
					var closeData = {
						'action' : 'alg_wc_eu_vat_update_closedate'
					};

					jQuery.ajax( {
						type   : 'POST',
						url    : <?php echo "'" . admin_url( 'admin-ajax.php' ) . "'"; ?>,
						data   : closeData,
						async  : true,
						success: function ( response ) {
							if ( 'ok' == response ) {
								jQuery( ".alg_wc_eu_vat_right_ad" ).remove();
							}
						},
					} );
				} );
			} );
			</script>
			<style>
			.alg_wc_eu_vat_close {
				position: absolute;
				right:-13px;
				top: -26px;
				cursor: pointer;
				color: white;
				background: #000;
				width: 25px;
				height: 25px;
				text-align: center;
				border-radius: 50%;
				font-size: 32px;
			}

			.alg_wc_eu_vat_name_heading {
				position: relative;
			}
			.alg_wc_eu_vat_right_ad {
				position: absolute;
				right:20px;
				padding: 16px;
				box-shadow: 0 1px 6px 0 rgb(0 0 0 / 30%);
				border: 1px solid #dcdcdc;
				background-color: #fff;
				margin: 0px 0 20px;
				width: 25em;
				z-index: 99;
				font-weight: 600;
				border-radius: 10px;
			}
			.alg_wc_eu_vat-button-upsell {
				display:inline-flex;
				align-items:center;
				justify-content:center;
				box-sizing:border-box;
				min-height:48px;
				padding:8px 1em;
				font-size:16px;
				line-height:1.5;
				font-family:Arial,sans-serif;
				color:#000;
				border-radius:4px;
				box-shadow:inset 0 -4px 0 rgba(0,0,0,.2);
				filter:drop-shadow(0 2px 4px rgba(0,0,0,.2));
				text-decoration:none;
				background-color:#7ce577;
				font-weight: 600;
			}
			.alg_wc_eu_vat-button-upsell:hover {
				background-color:#7ce577;
				color:#000;
				font-weight: 600;
			}
			.alg_wc_eu_vat-sidebar__section li:before {
				content:"+";
				position:absolute;
				left:0;
				font-weight:700
			}
			.alg_wc_eu_vat-sidebar__section li {
				list-style:none;
				margin-left:20px
			}
			.alg_wc_eu_vat-sidebar__section {
				position: relative;
			}
			img.alg_wc_eu_vat_resize {
				width: 60px;
				float: right;
				position: absolute;
				right: 0px;
				top: -15px;
				padding-left: 10px;
			}
			.alg_wc_eu_vat_text {
				margin-right: 18%;
			}
			</style>
		<?php
	}

	/**
	 * alg_wc_eu_vat_admin_js_field_control.
	 *
	 * @version 3.1.1
	 */
	function alg_wc_eu_vat_admin_js_field_control() {
		?>
		<script>
		jQuery( document ).ready( function () {

			var eu_vat_required = jQuery( '#alg_wc_eu_vat_field_required' );

			if ( 'yes_for_countries' == eu_vat_required.val() ) {
				toogle_required_countries( 2 );
			} else if ( 'no_for_countries' == eu_vat_required.val() ) {
				toogle_required_countries( 2 );
			} else {
				toogle_required_countries( 1 );
			}

			eu_vat_required.change( function () {

				if ( 'yes_for_countries' == jQuery( this ).val() ) {
					toogle_required_countries( 2 );
				} else if ( 'no_for_countries' == jQuery( this ).val() ) {
					toogle_required_countries( 2 );
				} else {
					toogle_required_countries( 1 );
				}

			} );

		} );

		function toogle_customer_decide( flag = 1 ) {
			var customer_decide = jQuery( '#alg_wc_eu_vat_field_let_customer_decide' );
			var customer_decide_label = jQuery( '#alg_wc_eu_vat_field_let_customer_decide_label' );

			if ( 1 == flag ) {
				customer_decide.attr( 'disabled', 'disabled' );
				customer_decide_label.attr( 'disabled', 'disabled' );
			} else {
				customer_decide.removeAttr( 'disabled' );
				customer_decide_label.removeAttr( 'disabled' );
			}
		}

		function toogle_required_countries( flag = 1 ) {
			var field_required_countries = jQuery( '#alg_wc_eu_vat_field_required_countries' );

			if ( 1 == flag ) {
				field_required_countries.attr( 'disabled', 'disabled' );
			} else {
				field_required_countries.removeAttr( 'disabled' );
			}
		}
		</script>
		<?php
	}

	/**
	 * Init hooks.
	 *
	 * @version 3.0.1
	 * @since   3.0.1
	 */
	function init_hooks() {
		// Keep vat for individual product
		add_action( 'woocommerce_product_options_tax', array( $this, 'add_keep_vat_individual_product' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_keep_vat_individual_product' ) );
		add_action( 'alg_wc_eu_vat_exempt_applied', array( $this, 'handle_keep_vat_individual_product' ) );
	}

	/**
	 * add_frontend_edit_billing_fields.
	 *
	 * @version 3.2.2
	 * @since   2.12.14
	 */
	function add_frontend_edit_billing_fields( $fields ) {

		$user_roles = apply_filters( 'alg_wc_eu_vat_show_for_user_roles', array() );
		if (
			! empty( $user_roles ) &&
			! $this->check_current_user_roles( $user_roles )
		) {
			return $fields;
		}

		$field_id = alg_wc_eu_vat_get_field_id();

		$fields[ $field_id ] = array(
			'label'       => do_shortcode( get_option( 'alg_wc_eu_vat_field_label', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) ),
			'placeholder' => do_shortcode( get_option( 'alg_wc_eu_vat_field_placeholder', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) ),
			'required'    => false,
			'clear'       => false,
			'type'        => 'text',
			'class'       => array( 'alg-wc-frontend-billing-edit' ),
			'priority'    => get_option( 'alg_wc_eu_vat_field_priority', 200 ),
		);

		return $fields;
	}

	/**
	 * alg_wc_eu_vat_support_yith_invoice.
	 *
	 * @version 2.12.4
	 * @since   2.12.4
	 */
	function alg_wc_eu_vat_support_yith_invoice($fields_billing) {
		$fields_billing[] = 'billing_eu_vat_number';
		return $fields_billing;
	}

	/**
	 * alg_eu_vat_update_default_value_for_eu_vat_field.
	 *
	 * @version 3.2.4
	 * @since   2.11.6
	 */
	function alg_eu_vat_update_default_value_for_eu_vat_field( $value, $group, $wc_object ) {
		return (
			is_a( $wc_object, 'WC_Customer' ) ?
			$wc_object->get_meta( 'billing_eu_vat_number' ) :
			$value
		);
	}

	/**
	 * alg_wc_eu_woocommerce_validate_eu_vat_field_checkout_block.
	 *
	 * @version 2.11.6
	 * @since   2.11.6
	 */
	function alg_wc_eu_woocommerce_validate_eu_vat_field_checkout_block( \WP_Error $errors, $fields, $group ) {

		$field_id = alg_wc_eu_vat_get_field_id();
		$namespace = $this->nm_space;
		$field_attr = $this->get_field_data();

		$field_with_namespace = $namespace . '/' . $field_id;

		if(isset($field_attr['required']) && $field_attr['required'] && ( !isset( $fields[$field_with_namespace] ) || empty( $fields[$field_with_namespace] ) ) ) {
			$error_message = str_replace(
				'%eu_vat_number%',
				$fields[$field_with_namespace],
				do_shortcode( get_option( 'alg_wc_eu_vat_not_valid_message', __( '<strong>EU VAT Number</strong> is not valid.', 'eu-vat-for-woocommerce' ) ) )
			);
			$errors->add( 'eu_vat_required', $error_message );
		}

	}

	/**
	 * register_additional_checkout_block_field.
	 *
	 * @version 2.11.6
	 * @since   2.11.6
	 */
	function register_additional_checkout_block_field() {

		$field_attr = $this->get_field_data();
		$field_id = alg_wc_eu_vat_get_field_id();
		$namespace = $this->nm_space;

		woocommerce_register_additional_checkout_field(
			array(
				'id'            => $namespace . '/' . $field_id,
				'label'         => $field_attr['label'],
				'location'      => 'contact',
				'required'      => $field_attr['required'],
				'attributes'    => array(
					'autocomplete' => 'on',
					'title'        => $field_attr['description'],
				),
			),
		);

	}

	/**
	 * alg_wc_eu_woocommerce_store_api_register_update_callback.
	 *
	 * @version 2.11.6
	 * @since   2.10.4
	 */
	 function alg_wc_eu_woocommerce_store_api_register_update_callback() {
		woocommerce_store_api_register_update_callback(
			[
				'namespace' => 'alg-wc-eu-vat-extention-namespace',
				'callback'  => function ( $data ) {
					$country               = $data['eu_country'];
					$same_billing_shipping = $data['same_billing_shipping'];
					if ( isset( $country ) && ! empty( $country ) ) {
						WC()->customer->set_billing_country( wc_clean( $country ) );
						if ( isset( $same_billing_shipping ) && 'yes' == $same_billing_shipping ) {
							WC()->customer->set_shipping_country( wc_clean( $country ) );
						}
					}

					return;
				}
			]
		);

		woocommerce_store_api_register_update_callback(
			[
				'namespace' => 'alg-wc-eu-vat-extention-namespace-reload-first',
				'callback'  => function ( $data ) {
					return;
				}
			]
		);

	}

	/**
	 * alg_eu_vat_update_block_order_meta_eu_vat.
	 *
	 * @version 2.11.6
	 * @since   2.10.4
	 */
	function alg_eu_vat_update_block_order_meta_eu_vat( $order, $request ) {

		$field_id = alg_wc_eu_vat_get_field_id();

		$data = isset( $request['extensions']['eu-vat-for-woocommerce-block-example'] ) ? $request['extensions']['eu-vat-for-woocommerce-block-example'] : array();

		$billing_address = $order->get_address('billing');
		$shipping_address = $order->get_address('shipping');

		$posted_billing_country = $billing_address['country'];
		$posted_shipping_country = $shipping_address['country'];

		$posted_billing_company = $billing_address['company'];

		$posted_eu_vat_id = $order->get_meta('_wc_other/alg_eu_vat/billing_eu_vat_number');

		$is_valid = false;

		if ( 'yes' === get_option( 'alg_wc_eu_vat_validate', 'yes' ) ) {
			if (
				( '' != $posted_eu_vat_id  ) &&
				(
					null === alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_valid' ) ||
					false == alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_valid' ) ||
					null === alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' ) ||
					$posted_eu_vat_id  != alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' )
				)
			) {

				$is_valid = false;
				if (
					'yes' === get_option( 'alg_wc_eu_vat_force_checkout_recheck', 'no' ) &&
					$posted_eu_vat_id  != alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' )
				) {
					$is_valid = $this->check_and_save_eu_vat(
						$posted_eu_vat_id ,
						( isset( $posted_billing_country ) ? $posted_billing_country : '' ),
						( isset( $posted_billing_company ) ? $posted_billing_company : '' )
					);
				} else {

					$vat_number = $posted_eu_vat_id;
					$billing_country = isset( $posted_billing_country ) ? $posted_billing_country : '';
					$billing_company = isset( $posted_billing_company ) ? $posted_billing_company : '';
					$vat_number = preg_replace('/\s+/', '', $vat_number);
					$eu_vat_number = alg_wc_eu_vat_parse_vat( $vat_number, $billing_country );

					/* Vat validate manually presaved number */
					if( 'yes' === get_option( 'alg_wc_eu_vat_manual_validation_enable', 'no' ) ) {
						if( '' != ( $manual_validation_vat_numbers = get_option( 'alg_wc_eu_vat_manual_validation_vat_numbers', '' ) ) ) {
							$prevalidated_VAT_numbers = array();
							$prevalidated_VAT_numbers = explode( ',', $manual_validation_vat_numbers );
							$sanitized_vat_numbers = array_map('trim', $prevalidated_VAT_numbers);

							$conjuncted_vat_number = $billing_country . '' . $eu_vat_number['number'];
							if( isset($sanitized_vat_numbers[0] ) ){
								if ( in_array( $conjuncted_vat_number, $sanitized_vat_numbers ) ) {
									alg_wc_eu_vat_maybe_log( $eu_vat_number['country'], $eu_vat_number['number'], $billing_company, '', __( 'Success (checkout): VAT ID valid. Matched with prevalidated VAT numbers.', 'eu-vat-for-woocommerce' ) );
									$is_valid = true;

								}
							}
						}
					}
				}

				if ( 'no' != ( $preserve_option_value = get_option( 'alg_wc_eu_vat_preserv_vat_for_different_shipping', 'no' ) ) ) {
					$billing_country = $posted_billing_country;
					$shipping_country = $posted_shipping_country;
					$is_country_same = ( strtoupper( $billing_country ) !== strtoupper( $shipping_country) );
					if(!$is_country_same && !$is_valid){
						$is_valid = true;
					}
				}

				$is_valid = apply_filters( 'alg_wc_eu_vat_is_valid_vat_at_checkout', $is_valid );
				if ( ! $is_valid ) {

					alg_wc_eu_vat_maybe_log(
						( isset( $posted_billing_country ) ? $posted_billing_country : '' ),
						$posted_eu_vat_id,
						( isset( $posted_billing_company ) ? $posted_billing_company : '' ),
						'',
						__( 'Error: VAT is not valid (checkout)', 'eu-vat-for-woocommerce' )
					);

					throw new Exception(
						str_replace( '%eu_vat_number%', $posted_eu_vat_id,
							do_shortcode( get_option( 'alg_wc_eu_vat_not_valid_message', __( '<strong>EU VAT Number</strong> is not valid.', 'eu-vat-for-woocommerce' ) ) ) )
					);

				} else {

				}
			}
		}

		if( isset( $posted_eu_vat_id ) && !empty( $posted_eu_vat_id ) ) {
			$order->update_meta_data( '_billing_eu_vat_number', $posted_eu_vat_id );
		}
		if( $is_valid ) {
			$order->update_meta_data( 'is_vat_exempt', 'yes' );
		}

	}

	/**
	 * alg_wc_eu_vat_filter_order_response.
	 *
	 * @version 2.9.21
	 * @since   2.9.21
	 */
	function alg_wc_eu_vat_filter_order_response($response, $post, $request){

		if ( 'yes' === get_option( 'alg_wc_eu_vat_remove_country_rest_api_enable', 'no' ) ) {

			$i = 0;
			$meta_data_count = count($response->data["meta_data"]);
			while($i < $meta_data_count) {

				if( $response->data['meta_data'][$i]->get_data()['key'] == '_billing_eu_vat_number' ) {

					$value = $response->data['meta_data'][$i]->get_data()['value'];

					$vat_clean = preg_replace('/[^a-zA-Z0-9]/', '', $value);
					$vat_code = substr($vat_clean,2,15);
					$vat_country = substr($vat_clean,0,2);

					$response->data['meta_data'][$i]->__set('value', $vat_code);
					$response->data['meta_data'][$i]->__set('vat_country', $vat_country);
					$response->data['meta_data'][$i]->apply_changes();
				}

				$i++;
			}
		}

		return $response;
	}


	/**
	 * add_vat_exempt_text_pdf_footer.
	 *
	 * @version 2.9.20
	 * @since   2.9.17
	 */
	function add_vat_exempt_text_pdf_footer( $document_type, $order ) {
		$is_vat_exempt            = $order->get_meta( 'is_vat_exempt' );
		$is_vat_exempt_from_admin = $order->get_meta( 'exempt_vat_from_admin' );
		if ( 'yes' === $is_vat_exempt || 'yes' === $is_vat_exempt_from_admin ) {
			echo get_option( 'alg_wc_eu_vat_advanced_vat_shifted_text', __( 'VAT SHIFTED', 'eu-vat-for-woocommerce' ) );
		}
	}

	/**
	 * add_billing_eu_vat_section_filter.
	 *
	 * @version 2.9.13
	 * @since   2.9.11
	 */
	function add_billing_eu_vat_section_filter() {
		$section = ( isset($_GET[ 'billing_eu_vat_number' ]) && isset($_GET[ 'billing_eu_vat_number' ][0]) && $_GET[ 'billing_eu_vat_number' ][0] == 'yes' ) ? 'yes' : 'no';
		echo ' <select name="billing_eu_vat_number[]" style="float:none;"><option value="">EU VAT not provided</option>';
		$selected = 'yes' == $section ? ' selected="selected"' : '';
		echo '<option value="yes"' . $selected . '>EU VAT provided</option>';
		echo '</select>';
		echo '<input type="submit" class="button" value="Filter">';
	}

	/**
	 * filter_users_by_billing_eu_vat.
	 *
	 * @version 2.9.11
	 * @since   2.9.11
	 */
	function filter_users_by_billing_eu_vat( $query ) {
		global $pagenow;

		if ( is_admin() && 'users.php' == $pagenow) {
			$section = ( isset($_GET[ 'billing_eu_vat_number' ]) && isset($_GET[ 'billing_eu_vat_number' ][0]) && $_GET[ 'billing_eu_vat_number' ][0] == 'yes' ) ? 'yes' : 'no';
			if ( 'no' !== $section ) {
				$meta_query = array(
					array(
						'key' => 'billing_eu_vat_number',
						'value' => '',
						'compare' => '!=',
					)
				);
				$query->set( 'meta_key', 'billing_eu_vat_number' );
				$query->set( 'meta_query', $meta_query );

			}

		}
	}

	/**
	 * alg_extend_wcpdf_after_billing_address.
	 *
	 * @version 2.9.13
	 * @since   1.7.0
	 */
	function alg_extend_wcpdf_after_billing_address($type, $pdf_order){
		if( function_exists( 'alg_wc_eu_vat_get_field_id' ) ){
			$vat_id = $pdf_order->get_meta( '_' . alg_wc_eu_vat_get_field_id() );
			if( $vat_id && !empty( $vat_id ) ){
			?><div class="eu-vat"><?php echo $vat_id; ?></div><?php
			}
		}
	}

	/**
	 * set_eu_vat_country_locale.
	 *
	 * @version 1.7.0
	 * @since   1.7.0
	 */
	function set_eu_vat_country_locale_core( $country_locales, $show_in_countries, $required_in_countries ) {

		$show_eu_vat_field_countries = array_map( 'strtoupper', array_map( 'trim', explode( ',', $show_in_countries ) ) );
		$required_eu_vat_field_countries = array_map( 'strtoupper', array_map( 'trim', explode( ',', $required_in_countries ) ) );

		$eu_vat_required = get_option( 'alg_wc_eu_vat_field_required', 'no' );

		$original_hidden = false;

		// Enable field in selected locales
		$original_required = ( 'yes' === $eu_vat_required );

		if(!empty($show_eu_vat_field_countries)){
			$country_locales_keys = array_keys($country_locales);
			$ky2 = $country_locales_keys;
			$wc_countries = new WC_Countries();
			$w_countries = $wc_countries->get_countries();
			$ky1 = array_keys($w_countries);
			$arr_dif=array_diff($ky1, $ky2);
		}

		if('yes_for_company' === $eu_vat_required || 'yes_for_countries' === $eu_vat_required || 'no_for_countries' === $eu_vat_required || !empty($show_eu_vat_field_countries)){
			if('yes_for_company' === $eu_vat_required){
				if ( !empty(WC()->checkout->get_value( 'billing_company' )) ){
					$is_required = true;
				}
			}
			foreach ( $country_locales as $country_code => &$country_locale ) {

				$is_required = $original_required;
				$hidden = $original_hidden;

				if('yes_for_countries' === $eu_vat_required){
					if ( in_array( $country_code, $required_eu_vat_field_countries ) ) {
						$is_required = true;
					}
				}else if('no_for_countries' === $eu_vat_required){
					if ( in_array( $country_code, $required_eu_vat_field_countries ) ) {
						$is_required = false;
					}else{
						$is_required = true;
					}
				}

				if(!empty($show_eu_vat_field_countries) && (isset($show_eu_vat_field_countries[0]) && !empty($show_eu_vat_field_countries[0]))){
					if ( in_array( $country_code, $show_eu_vat_field_countries ) ) {
						$hidden = false;
					}else{
						$hidden = true;
					}
				}else{
					$hidden = false;
				}

				if('yes_for_company' === $eu_vat_required){
					$is_required = false;
				}

				$country_locale[ alg_wc_eu_vat_get_field_id( true ) ] = array(
						'required' => $is_required,
						'hidden'   => $hidden,
					);
			}

			if(!empty($show_eu_vat_field_countries) && (isset($show_eu_vat_field_countries[0]) && !empty($show_eu_vat_field_countries[0]))){
				foreach ( $show_eu_vat_field_countries as $count_code ) {
					$country_locales[ $count_code ][ alg_wc_eu_vat_get_field_id( true ) ] = array(
						'hidden'   => false,
					);
				}
			}

			$hidden = $original_hidden;

			if(!empty($arr_dif)){
				foreach ( $arr_dif as $con ) {
					if(!empty($show_eu_vat_field_countries) && (isset($show_eu_vat_field_countries[0]) && !empty($show_eu_vat_field_countries[0]))){
						if ( in_array( $con, $show_eu_vat_field_countries ) ) {
							$hidden = false;
						}else{
							$hidden = true;
						}
					}else{
						$hidden = false;
					}
					$country_locales[ $con ][ alg_wc_eu_vat_get_field_id( true ) ] = array(
						'hidden'   => $hidden,
						'required'   => $is_required
					);
				}
			}

			$hidden = $original_hidden;

			if(!empty($required_eu_vat_field_countries)){
				foreach ( $required_eu_vat_field_countries as $country_code_re ) {

					$is_required = $original_required;

					if('yes_for_countries' === $eu_vat_required){
						$is_required = true;
					}else if('no_for_countries' === $eu_vat_required){
						$is_required = false;
					}

					if(!empty($show_eu_vat_field_countries) && (isset($show_eu_vat_field_countries[0]) && !empty($show_eu_vat_field_countries[0]))){
						if ( in_array( $country_code_re, $show_eu_vat_field_countries ) ) {
							$hidden = false;
						}else{
							$hidden = true;
						}
					}else{
						$hidden = false;
					}

					if('yes_for_company' === $eu_vat_required){
						$is_required = false;

					}

					$country_locales[ $country_code_re ][ alg_wc_eu_vat_get_field_id( true ) ] = array(
						'required' => $is_required,
						'hidden'   => $hidden,
					);
				}
			}
		}


		return $country_locales;
	}

	/**
	 * show_in_countries.
	 *
	 * @version 1.7.0
	 * @since   1.7.0
	 */
	function show_in_countries( $value ) {
		return get_option( 'alg_wc_eu_vat_show_in_countries', '' );
	}

	/**
	 * required_in_countries.
	 *
	 * @version 1.7.0
	 * @since   1.7.0
	 */
	function required_in_countries( $value ) {
		if ('yes_for_countries' === get_option( 'alg_wc_eu_vat_field_required', 'no' ) || 'no_for_countries' === get_option( 'alg_wc_eu_vat_field_required', 'no' )){
			$arr = get_option( 'alg_wc_eu_vat_field_required_countries', array() );
			if(!empty($arr)){
				return implode(',',$arr);
			}else{
				return '';
			}
		}
		return '';
	}

	/**
	 * maybe_exclude_vat_free.
	 *
	 * @version 2.11.10
	 * @since   1.7.0
	 */
	function maybe_exclude_vat_free( $value ) {
		$selected_country_at_checkout = '';
		$preserve_base_country_check_passed = true;
		if ( 'no' != ( $preserve_option_value = get_option( 'alg_wc_eu_vat_preserve_in_base_country', 'no' ) ) ) {
			$selected_country = substr( alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' ), 0, 2 );

			if ( ! ctype_alpha( $selected_country ) ) {
				$selected_country = '';
				if ( 'yes' === get_option( 'alg_wc_eu_vat_allow_without_country_code', 'no' ) ) {
					// Getting country from POST, or from the customer object
					if ( ! ctype_alpha( $selected_country ) ) {
						$selected_country = WC()->checkout->get_value( 'billing_country' );
					}
					// Fallback #1
					if ( ! ctype_alpha( $selected_country ) && ! empty( $_REQUEST['post_data'] ) ) {
						parse_str( $_REQUEST['post_data'], $post_data_args );
						if ( ! empty( $post_data_args['billing_country'] ) ) {
							$selected_country = sanitize_text_field( $post_data_args['billing_country'] );
						}
					}
					// Fallback #2
					if ( ! ctype_alpha( $selected_country ) && ! empty( $_REQUEST['billing_country'] ) ) {
						$selected_country = sanitize_text_field( $_REQUEST['billing_country'] );
					}
					// Fallback #3
					if ( ! ctype_alpha( $selected_country ) && ! empty( $_REQUEST['country'] ) ) {
						$selected_country = sanitize_text_field( $_REQUEST['country'] );
					}
				}
				if ( ! ctype_alpha( $selected_country ) ) {
					return false;
				}
			}
			$selected_country = strtoupper( $selected_country );
			if ( 'EL' === $selected_country ) {
				$selected_country = 'GR';
			}

			$selected_country_at_checkout = WC()->checkout->get_value( 'billing_country' );

			if ( 'yes' === $preserve_option_value ) {
				$location = wc_get_base_location();
				if ( empty( $location['country'] ) ) {
					$location = wc_format_country_state_string( apply_filters( 'woocommerce_customer_default_location', get_option( 'woocommerce_default_country' ) ) );
				}

				$preserve_base_country_check_passed = ( strtoupper( $location['country'] ) !== $selected_country_at_checkout );
			} elseif ( '' != get_option( 'alg_wc_eu_vat_preserve_in_base_country_locations', '' ) ) { // `list`
				$locations = array_map( 'strtoupper', array_map( 'trim', explode( ',', get_option( 'alg_wc_eu_vat_preserve_in_base_country_locations', '' ) ) ) );
				$preserve_base_country_check_passed = ( ! in_array( $selected_country_at_checkout, $locations ) );
			}
		}

		if ( 'no' != ( $preserve_option_value = get_option( 'alg_wc_eu_vat_preserv_vat_for_different_shipping', 'no' ) ) && !$preserve_base_country_check_passed ) {

			$billing_country = isset( $_REQUEST['billing_country'] ) ? $_REQUEST['billing_country'] : '';
			$shipping_country = isset( $_REQUEST['shipping_country'] ) ? $_REQUEST['shipping_country'] : '';

			$is_country_not_same = ( strtoupper( $billing_country ) !== strtoupper( $shipping_country) );

			if($is_country_not_same){
				$preserve_base_country_check_passed = true;
			}
		}

		return $preserve_base_country_check_passed;
	}

	/**
	 * eu_vat_wp_footer.
	 *
	 * @version 2.12.11
	 * @since   2.12.11
	 */
	function eu_vat_wp_footer() {

		if ( 'yes' === get_option( 'alg_wc_eu_vat_remove_validation_color', 'no' ) ) { ?>
		<style>
			.form-row.woocommerce-invalid input#billing_eu_vat_number{
				box-shadow: inset 2px 0 0 transparent;
			}
		</style>
		<?php
		}

		?>
		<style>
		div.woocommerce-MyAccount-content .alg-wc-frontend-billing-edit {
			display: block !important;
		}
		</style>
		<?php
	}

	/**
	 * eu_vat_admin_footer.
	 *
	 * @version 3.2.3
	 * @since   1.7.0
	 */
	function eu_vat_admin_footer() {
		$nonce = wp_create_nonce( 'alg-wc-eu-vat-ajax-nonce' );
		?>
		<script type="text/javascript">
		jQuery( 'body' ).on( 'click', '.exempt_vat_from_admin', function () {
			jQuery( '#woocommerce-order-items' ).block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );

			var order_id = jQuery( this ).data( 'order_id' );
			var status = jQuery( this ).data( 'status' );
			var data = {
				action: 'exempt_vat_from_admin',
				order_id: order_id,
				status: status,
				'nonce': '<?php echo $nonce; ?>'
			};
			jQuery.ajax( {
				url:  woocommerce_admin_meta_boxes.ajax_url,
				data: data,
				type: 'POST',
				success: function ( response ) {
					jQuery( '#woocommerce-order-items' ).unblock();
					if ( 'yes' == response || 'never' == response ) {
						jQuery( '.calculate-action' ).click();
					}
				}
			} );
		} );
		</script>
		<?php
	}

	/**
	 * admin_woocommerce_order_is_vat_exempt.
	 *
	 * @version 2.9.13
	 * @since   2.9.13
	 */
	function admin_woocommerce_order_is_vat_exempt( $is_exempt, $instance ) {

		$order_id = $instance->get_id();

		$exempt_vat_from_admin = $instance->get_meta( 'exempt_vat_from_admin' );
		if($exempt_vat_from_admin == 'yes'){
			return true;
		}
		return $is_exempt;

	}

	/**
	 * admin_function_to_add_the_button.
	 *
	 * @version 2.9.13
	 * @since   2.9.13
	 */
	function admin_function_to_add_the_button( $order ) {
		$order_id = $order->get_id();
		$exempt_vat_from_admin = $order->get_meta( 'exempt_vat_from_admin' );
		if($exempt_vat_from_admin == 'yes'){
			$exempt_vat_from_admin = 'yes';
			$title = 'Impose VAT';
		}else{
			$exempt_vat_from_admin = 'never';
			$title = 'Exempt VAT';
		}
		echo '<button id="exempt_vat_from_admin"  type="button" class="button exempt_vat_from_admin button-primary" data-status="' . $exempt_vat_from_admin . '" data-order_id="'. esc_attr($order->get_id())  .'" >'.$title.'</button>';
	}

	/**
	 * current_url.
	 *
	 * @version 2.11.9
	 * @since   1.4.1
	 */
	function current_url() {
		if( ! defined('WP_CLI') ){
			if(array_key_exists('SERVER_NAME', $_SERVER) && array_key_exists('SERVER_PORT', $_SERVER) && array_key_exists('REQUEST_URI', $_SERVER)){
			if(array_key_exists('HTTPS', $_SERVER)){
				$current_url  = ( $_SERVER["HTTPS"] != 'on' ) ? 'http://'.$_SERVER["SERVER_NAME"] :  'https://'.$_SERVER["SERVER_NAME"];
			}else{
				$current_url  = 'http://'.$_SERVER["SERVER_NAME"];
			}

			$current_url .= ( $_SERVER["SERVER_PORT"] != 80 && $_SERVER["SERVER_PORT"] != 443 ) ? ":".$_SERVER["SERVER_PORT"] : "";
			$current_url .= $_SERVER["REQUEST_URI"];

				if(strpos($current_url,'wp-json/siteground-optimizer/v1/test-url-cache') !== false || strpos($current_url,'wp-json/siteground-optimizer') !== false) {
					return 'test-url-cache';
				} else {
					return $current_url;
				}
			}
		}
		return get_option('siteurl');
	}

	/**
	 * admin_inline_js.
	 *
	 * @version 2.12.2
	 * @since   1.4.1
	 */
	function admin_inline_js() {
		?>
		<script type="text/javascript">
		jQuery( function( $ ) {
			var admin_input_timer;
			var input_timer_company;
			var done_input_interval = 1000;
			var admin_vat_input    = $( 'input[name="_billing_eu_vat_number"]' );

			$( '#_billing_country' ).on( 'change', alg_wc_eu_vat_validate_vat_admin );

			// On input, start the countdown
			admin_vat_input.on( 'input', function() {
				clearTimeout( admin_input_timer );
				admin_input_timer = setTimeout( alg_wc_eu_vat_validate_vat_admin, done_input_interval );
			});

			$( '#_billing_company' ).on( 'input', function() {
				clearTimeout( input_timer_company );
				input_timer_company = setTimeout( alg_wc_eu_vat_validate_vat_admin, done_input_interval );
			});

			/**
			 * alg_wc_eu_vat_validate_vat_admin
			 *
			 * @version 1.6.0
			 * @since   1.0.0
			 */
			function alg_wc_eu_vat_validate_vat_admin() {
				$("#woocommerce-order-data").block({ message: null });
				var admin_vat_number_to_check = admin_vat_input.val();
					// Validating EU VAT Number through AJAX call
					var data = {
						'action': 'alg_wc_eu_vat_validate_action',
						'alg_wc_eu_vat_to_check': admin_vat_number_to_check,
						'billing_country': $('#_billing_country').val(),
						'billing_company': $('#_billing_company').val(),
					};
					$.ajax( {
						type: "POST",
						url: '<?php echo admin_url("admin-ajax.php"); ?>',
						data: data,
						success: function( resp ) {
							var response = resp.res;
							response = response.trim();
							$("#woocommerce-order-data").unblock();
						},
					} );

			};
		});
		</script>
		<?php
	}

	/**
	 * admin_order_is_vat_exempt.
	 *
	 * @version 1.4.1
	 * @since   1.4.1
	 */
	function admin_order_is_vat_exempt( $is_exempt, $that )
	{	global $pagenow;
		if($pagenow == 'admin-ajax.php' && $_REQUEST['action'] == 'woocommerce_calc_line_taxes' ){
			if ( $this->check_current_user_roles( get_option( 'alg_wc_eu_vat_exempt_for_user_roles', array() ) ) ) {
				$is_exempt = true;
			} elseif ( $this->check_current_user_roles( get_option( 'alg_wc_eu_vat_not_exempt_for_user_roles', array() ) ) ) {
				$is_exempt = false;
			} elseif ( $this->is_validate_and_exempt() && $this->is_valid_and_exists() ) {
				$is_exempt = apply_filters( 'alg_wc_eu_vat_maybe_exclude_vat', true );
			} else {
				$is_exempt = false;
			}
		}
	   return $is_exempt;
	}
	/**
	 * add_place_order_button_confirmation_script.
	 *
	 * @version 3.0.0
	 * @since   1.4.1
	 */
	function add_place_order_button_confirmation_script() {

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {

			wp_enqueue_script( 'alg-wc-eu-vat-place-order',
				trailingslashit( alg_wc_eu_vat()->plugin_url() ) . 'includes/js/alg-wc-eu-vat-place-order.js', array( 'jquery' ), alg_wc_eu_vat()->version, true );
			wp_localize_script( 'alg-wc-eu-vat-place-order',
				'place_order_data', array( 'confirmation_text' => do_shortcode( get_option( 'alg_wc_eu_vat_field_confirmation_text',
					__( 'You didn\'t set your VAT ID. Are you sure you want to continue?', 'eu-vat-for-woocommerce' ) ) ), 'yes_text' => __( 'Yes', 'eu-vat-for-woocommerce' ), 'no_text' => __( 'No', 'eu-vat-for-woocommerce' ), 'yesBg' => apply_filters( 'alg_wc_eu_vat_confirmation_bg_yes', 'green' ), 'noBg' => apply_filters( 'alg_wc_eu_vat_confirmation_bg_no', 'red' ) ) );

			wp_enqueue_script( 'alg-wc-eu-vat-confirmation',
				alg_wc_eu_vat()->plugin_url() . '/includes/js/alg-wc-eu-vat-confirmo.js', array('jquery'), alg_wc_eu_vat()->version, true );

			wp_enqueue_style( 'alg-wc-eu-vat-confirmation-styles',
				alg_wc_eu_vat()->plugin_url() . '/includes/css/alg-wc-eu-vat-confirmo.css', array(), alg_wc_eu_vat()->version, false );
		}
	}

	/**
	 * language_shortcode.
	 *
	 * @version 1.4.0
	 * @since   1.4.0
	 *
	 * @todo    (dev) duplicated?
	 */
	function language_shortcode( $atts, $content = '' ) {
		// E.g.: `[alg_wc_eu_vat_translate lang="DE,NL" lang_text="EU-Steuernummer" not_lang_text="EU VAT Number"]`
		if ( isset( $atts['lang_text'] ) && isset( $atts['not_lang_text'] ) && ! empty( $atts['lang'] ) ) {
			return ( ! defined( 'ICL_LANGUAGE_CODE' ) || ! in_array( strtolower( ICL_LANGUAGE_CODE ), array_map( 'trim', explode( ',', strtolower( $atts['lang'] ) ) ) ) ) ?
				$atts['not_lang_text'] : $atts['lang_text'];
		}
		// E.g.: `[alg_wc_eu_vat_translate lang="DE"]EU-Steuernummer[/alg_wc_eu_vat_translate][alg_wc_eu_vat_translate lang="NL"]BTW nummer van de EU[/alg_wc_eu_vat_translate][alg_wc_eu_vat_translate not_lang="DE,NL"]EU VAT Number[/alg_wc_eu_vat_translate]`
		return (
			( ! empty( $atts['lang'] )     && ( ! defined( 'ICL_LANGUAGE_CODE' ) || ! in_array( strtolower( ICL_LANGUAGE_CODE ), array_map( 'trim', explode( ',', strtolower( $atts['lang'] ) ) ) ) ) ) ||
			( ! empty( $atts['not_lang'] ) &&     defined( 'ICL_LANGUAGE_CODE' ) &&   in_array( strtolower( ICL_LANGUAGE_CODE ), array_map( 'trim', explode( ',', strtolower( $atts['not_lang'] ) ) ) ) )
		) ? '' : $content;
	}

	/**
	 * set_eu_vat_country_locale_field_selectors.
	 *
	 * @version 1.4.1
	 * @since   1.4.0
	 */
	function set_eu_vat_country_locale_field_selectors( $locale_fields ) {
		$locale_fields[ alg_wc_eu_vat_get_field_id( true ) ] = '#' . alg_wc_eu_vat_get_field_id() . '_field';
		return $locale_fields;
	}

	/**
	 * set_eu_vat_country_locale_default.
	 *
	 * @version 1.4.1
	 * @since   1.4.0
	 */
	function set_eu_vat_country_locale_default( $default_locale ) {
		// Disable field in default locale

		$eu_vat_required = get_option( 'alg_wc_eu_vat_field_required', 'no' );
		$required = true;

		if($eu_vat_required == 'yes'){
			$required = true;
		}else if($eu_vat_required == 'no'){
			$required = false;
		}else if($eu_vat_required == 'yes_for_countries'){
			$required = false;
		}else if($eu_vat_required == 'no_for_countries'){
			$required = true;
		}

		$default_locale[ alg_wc_eu_vat_get_field_id( true ) ] = array(
			'required' => $required,
			'hidden'   => false,
		);
		return $default_locale;
	}

	/**
	 * set_eu_vat_country_locale.
	 *
	 * @version 1.7.0
	 * @since   1.4.0
	 */
	function set_eu_vat_country_locale( $country_locales ) {
		return apply_filters( 'alg_wc_eu_vat_set_eu_vat_country_locale', $country_locales, $this->show_in_countries, $this->required_in_countries );
	}

	/**
	 * always_show_zero_vat.
	 *
	 * @version 1.4.0
	 * @since   1.4.0
	 *
	 * @todo    [dev] (maybe) remove `$zero_tax->amount`, `$zero_tax->tax_rate_id`, `$zero_tax->is_compound` (as they are not really used in `review-order` template)
	 */
	function always_show_zero_vat( $tax_totals, $cart ) {
		if ( empty( $tax_totals ) && is_checkout() ) {
			$zero_tax = new stdClass();
			$zero_tax->amount           = 0.00;
			$zero_tax->tax_rate_id      = 0;
			$zero_tax->is_compound      = false;
			$zero_tax->label            = esc_html( WC()->countries->tax_or_vat() );
			$zero_tax->formatted_amount = wc_price( 0.00 );
			$tax_totals['TAX-1'] = $zero_tax;
		}
		return $tax_totals;
	}

	/**
	 * get_customer_decide_field_data.
	 *
	 * @version 1.7.2
	 * @since   1.3.0
	 *
	 * @todo    [dev] rethink `$is_required` (check filters: `woocommerce_default_address_fields`, `woocommerce_billing_fields`)
	 * @todo    [dev] (maybe) `default`
	 * @todo    [dev] (maybe) `autocomplete`
	 * @todo    [dev] (maybe) `value`
	 */
	function get_customer_decide_field_data() {
		return array(
			'type'      => 'checkbox',
			'label'     => do_shortcode( get_option( 'alg_wc_eu_vat_field_let_customer_decide_label', __('I don\'t have a VAT ID', 'eu-vat-for-woocommerce') ) ),
			'class'     => array('form-row-wide'),
			'priority'  => get_option( 'alg_wc_eu_vat_field_priority', 200 ),
		);
	}

	/**
	 * belgium_compatibility_field_data.
	 *
	 * @version 1.7.2
	 * @since   1.3.0
	 *
	 * @todo    [dev] rethink `$is_required` (check filters: `woocommerce_default_address_fields`, `woocommerce_billing_fields`)
	 * @todo    [dev] (maybe) `default`
	 * @todo    [dev] (maybe) `autocomplete`
	 * @todo    [dev] (maybe) `value`
	 */
	function belgium_compatibility_field_data() {
		return array(
			'type'      => 'checkbox',
			'label'     => do_shortcode( get_option( 'alg_wc_eu_vat_belgium_compatibility_label', __('I have a valid VAT but not exempted', 'eu-vat-for-woocommerce') ) ),
			'class'     => array('form-row-wide'),
			'priority'  => get_option( 'alg_wc_eu_vat_field_priority', 200 ),
		);
	}

	/**
	 * get_field_data.
	 *
	 * @version 2.12.9
	 * @since   1.3.0
	 *
	 * @todo    [dev] rethink `$is_required` (check filters: `woocommerce_default_address_fields`, `woocommerce_billing_fields`)
	 * @todo    [dev] (maybe) `default`
	 * @todo    [dev] (maybe) `autocomplete`
	 * @todo    [dev] (maybe) `value`
	 */
	function get_field_data() {

		$eu_vat_required = get_option( 'alg_wc_eu_vat_field_required', 'no' );

		$is_required = ( 'yes' === $eu_vat_required );


		if ( ! empty( WC()->checkout ) ) {

			if ( '' != $this->required_in_countries ) {
				$required_eu_vat_field_countries = array_map( 'strtoupper', array_map( 'trim', explode( ',', $this->required_in_countries ) ) );
				if ('yes_for_countries' === $eu_vat_required){
					if ( in_array( WC()->checkout->get_value( 'billing_country' ), $required_eu_vat_field_countries ) ) {
						$is_required = true;
					}
				}else if ('no_for_countries' === $eu_vat_required){
					if ( in_array( WC()->checkout->get_value( 'billing_country' ), $required_eu_vat_field_countries ) ) {
						$is_required = false;
					}else{
						$is_required = true;
					}
				}else{
					if ( ! in_array( WC()->checkout->get_value( 'billing_country' ), $required_eu_vat_field_countries ) ) {
						$is_required = false;
					}
				}
			}

			if('yes_for_company' === $eu_vat_required){
				if ( !empty(WC()->checkout->get_value( 'billing_company' )) ){
					$is_required = true;
				}
				$is_required = false;
			}

			if( 'yes' === get_option( 'alg_wc_eu_vat_field_let_customer_decide', 'no' ) ){
				$field_id = alg_wc_eu_vat_get_field_id();
				if(isset($_POST[$field_id . '_customer_decide']) && $_POST[$field_id . '_customer_decide']==1){
				$is_required = false;
				}
			}

		}

		return array(
			'type'              => 'text',
			'label'             => do_shortcode( get_option( 'alg_wc_eu_vat_field_label', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) ),
			'description'       => do_shortcode( get_option( 'alg_wc_eu_vat_field_description', '' ) ),
			'placeholder'       => do_shortcode( get_option( 'alg_wc_eu_vat_field_placeholder', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) ),
			'required'          => $is_required,
			'custom_attributes' => ( 0 != ( $maxlength = get_option( 'alg_wc_eu_vat_field_maxlength', 0 ) ) ? array( 'maxlength' => $maxlength ) : array() ),
			'clear'             => ( 'yes' === get_option( 'alg_wc_eu_vat_field_clear', 'yes' ) ),
			'class'             => array( get_option( 'alg_wc_eu_vat_field_class', 'form-row-wide' ) ),
			'label_class'       => array( get_option( 'alg_wc_eu_vat_field_label_class', '' ) ),
			'validate'          => ( 'yes' === get_option( 'alg_wc_eu_vat_validate', 'yes' ) ? array( 'eu-vat-number' ) : array() ),
			'priority'          => get_option( 'alg_wc_eu_vat_field_priority', 200 ),
		);
	}

	/**
	 * add_eu_vat_number_to_editable_fields.
	 *
	 * @version 1.3.0
	 * @since   1.3.0
	 *
	 * @todo    [dev] (maybe) `check_current_user_roles()`
	 * @todo    [feature] (maybe) also add an option to display/edit in "My Account > Account details"
	 */
	function add_eu_vat_number_to_editable_fields( $address, $load_address ) {
		if ( 'billing' === $load_address ) {
			$field_id = alg_wc_eu_vat_get_field_id();

			$address[ $field_id ] = $this->get_field_data();
			$address[ $field_id ]['value'] = get_user_meta( get_current_user_id(), $field_id, true );
		}
		return $address;
	}

	/**
	 * save_eu_vat_number_from_editable_fields.
	 *
	 * @version 1.3.0
	 * @since   1.3.0
	 */
	function save_eu_vat_number_from_editable_fields( $user_id, $load_address ) {
		if ( 'billing' === $load_address ) {
			$field_id = alg_wc_eu_vat_get_field_id();
			$field_id_cd = alg_wc_eu_vat_get_field_id() . '_customer_decide';
			if ( isset( $_POST[ $field_id ] ) ) {
				update_user_meta( $user_id, $field_id, $_POST[ $field_id ] );
			}
			if ( isset( $_POST[ $field_id_cd ] ) && $_POST[ $field_id_cd ]== 1 ) {
				update_user_meta( $user_id, $field_id_cd, $_POST[ $field_id_cd ] );
			}
		}
	}

	/**
	 * is_tax_status_none.
	 *
	 * @version 2.11.5
	 * @since   2.9.18
	 */
	function is_tax_status_none(){
		if ( function_exists( 'WC' ) ) {
			if ( isset( WC()->cart ) ) {
				foreach( WC()->cart->get_cart() as $cart_item ) {

					$product_in_cart = $cart_item['product_id'];
					$product_info    = wc_get_product( $product_in_cart );
					$tax_status      = $product_info->get_tax_status();
					if ( 'none' == $tax_status ) {
						return true;
					}

				}
			}
		}
		return false;
	}

	/**
	 * add_eu_vat_checkout_field_to_frontend.
	 *
	 * @version 2.9.19
	 * @since   1.0.0
	 */
	function add_eu_vat_checkout_field_to_frontend( $fields ) {

		if ( 'yes' === get_option( 'alg_wc_eu_vat_field_hide_tax_status_none', 'no' ) ) {
			if ( $this->is_tax_status_none() ) {
				return $fields;
			}
		}

		$user_roles = apply_filters( 'alg_wc_eu_vat_show_for_user_roles', array() );
		if (
			! empty( $user_roles ) &&
			! $this->check_current_user_roles( $user_roles )
		) {
			return $fields;
		}

		$is_required = ( 'yes' === get_option( 'alg_wc_eu_vat_field_required', 'no' ) );
		if ( $is_required && 'yes' === get_option( 'alg_wc_eu_vat_field_let_customer_decide', 'no' ) ) {
			$fields['billing'][ alg_wc_eu_vat_get_field_id() . '_customer_decide' ] = $this->get_customer_decide_field_data();
		}

		$fields['billing'][ alg_wc_eu_vat_get_field_id() ] = $this->get_field_data();

		if ( 'yes' === get_option( 'alg_wc_eu_vat_belgium_compatibility', 'no' ) ) {
			$fields['billing'][ alg_wc_eu_vat_get_field_id() . '_belgium_compatibility' ] = $this->belgium_compatibility_field_data();
		}

		return $fields;
	}

	/**
	 * replace_eu_vat_number_in_address_formats.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function replace_eu_vat_number_in_address_formats( $replacements, $args ) {
		$field_name = alg_wc_eu_vat_get_field_id();
		$the_label = do_shortcode( get_option( 'alg_wc_eu_vat_field_label', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) );
		$field_name_cd = alg_wc_eu_vat_get_field_id() . '_customer_decide';
		if(isset( $args[ $field_name ] ) && !empty( $args[ $field_name ] )){
			$replacements['{' . $field_name . '}'] = ( isset( $args[ $field_name ] ) ) ? $the_label . ': ' . $args[ $field_name ] : '';
		}else{
			$replacements['{' . $field_name . '}'] = ( isset( $args[ $field_name ] ) ) ? $args[ $field_name ] : '';
		}

		return $replacements;
	}

	/**
	 * add_eu_vat_number_to_address_formats.
	 *
	 * @version 3.0.1
	 * @since   1.0.0
	 */
	function add_eu_vat_number_to_address_formats( $address_formats ) {

		if ( is_checkout() ) {
			return $address_formats;
		}

		$field_name = alg_wc_eu_vat_get_field_id();
		$modified_address_formats = array();
		foreach ( $address_formats as $country => $address_format ) {
			$modified_address_formats[ $country ] = $address_format . "\n{" . $field_name . '}';
		}
		return $modified_address_formats;
	}

	/**
	 * add_eu_vat_number_to_my_account_billing_address.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function add_eu_vat_number_to_my_account_billing_address( $fields, $customer_id, $name ) {
		if ( 'billing' === $name ) {
			$field_name = alg_wc_eu_vat_get_field_id();
			$fields[ $field_name ] = get_user_meta( $customer_id, $field_name, true );
		}
		return $fields;
	}

	/**
	 * add_eu_vat_number_to_order_billing_address.
	 *
	 * @version 2.9.13
	 * @since   1.0.0
	 */
	function add_eu_vat_number_to_order_billing_address( $fields, $_order ) {
		$field_name = alg_wc_eu_vat_get_field_id();
		$field_value = $_order->get_meta( '_' . $field_name );
		$fields[ $field_name ] = $field_value;
		return $fields;
	}

	/**
	 * add_eu_vat_number_to_order_display.
	 *
	 * @version 2.9.13
	 * @since   1.0.0
	 */
	function add_eu_vat_number_to_order_display( $order ) {
		$order_id          = alg_wc_eu_vat_get_order_id( $order );
		$html              = '';
		$option_name       = '_' . alg_wc_eu_vat_get_field_id();
		$option_name_customer_decide       = '_' . alg_wc_eu_vat_get_field_id() . '_customer_decide';

		$the_eu_vat_number = $order->get_meta( $option_name );
		$customer_decide = $order->get_meta( $option_name_customer_decide );
		if ( '' != $customer_decide ) {
			$the_label_cd = do_shortcode( __( 'Customer Decide', 'eu-vat-for-woocommerce' ) );
			$html .= '<p>' . '<strong>' . $the_label_cd . '</strong>: ' . ($customer_decide==1 ? 'yes' : 'no') . '</p>';
		}
		if ( '' != $the_eu_vat_number ) {
			$the_label = do_shortcode( get_option( 'alg_wc_eu_vat_field_label', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) );
			$html .= '<p>' . '<strong>' . $the_label . '</strong>: ' . $the_eu_vat_number . '</p>';
		}
		echo $html;
	}

	/**
	 * add_default_checkout_billing_eu_vat_number.
	 *
	 * @version 1.3.0
	 * @since   1.0.0
	 */
	function add_default_checkout_billing_eu_vat_number( $default_value, $field_key ) {
		if ( '' != ( $eu_vat_number_to_check = alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' ) ) ) {
			return $eu_vat_number_to_check;
		} elseif ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			if ( $meta = get_user_meta( $current_user->ID, alg_wc_eu_vat_get_field_id(), true ) ) {
				return $meta;
			}
		}
		return $default_value;
	}

	/**
	 * add_eu_vat_number_customer_meta_field.
	 *
	 * @version 1.4.0
	 * @since   1.0.0
	 */
	function add_eu_vat_number_customer_meta_field( $fields ) {
		$fields['billing']['fields'][ alg_wc_eu_vat_get_field_id() ] = array(
			'label'       => do_shortcode( get_option( 'alg_wc_eu_vat_field_label', __( 'EU VAT Number', 'eu-vat-for-woocommerce' ) ) ),
			'description' => ''
		);

		return $fields;
	}

	/**
	 * start_session.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function start_session() {
		$curl = rtrim($this->current_url(),'/');
		$home = rtrim(home_url(),'/');
		if(!( $curl == $home )){

			$checkout_page_url = rtrim(wc_get_checkout_url(), '/');
			$cart_url = rtrim(get_permalink( wc_get_page_id( 'cart' ) ), '/');

			if($curl == $cart_url || $curl == $checkout_page_url){
				alg_wc_eu_vat_session_start();


				$args = array();
				if ( isset( $_POST['post_data'] ) ) {
					parse_str( $_POST['post_data'], $args );
					if ( isset( $args[ alg_wc_eu_vat_get_field_id() ] ) && alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' ) != $args[ alg_wc_eu_vat_get_field_id() ] ) {
						alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_valid', null );
						alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_to_check', null );
						alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_to_check_company', null );
						alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_to_check_company_name', null );
						alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_valid_before_preserve', null );
					}
				}
			}
		}else{
			if('yes' === get_option( 'alg_wc_eu_vat_sitepress_optimizer_dynamic_caching', 'no' ))
			{
				if($curl == 'test-url-cache'){
					$return = array(
						'status'  => 200,
						'data'    => array(),
						'message' => 'La URL está en la caché'
					);

					wp_send_json($return);
				}
			}
		}
	}

	/**
	 * handle_user_roles.
	 *
	 * @version 1.6.0
	 * @since   1.6.0
	 */
	function handle_user_roles( $role ) {
		return ( '' == $role ? 'guest' : ( 'super_admin' == $role ? 'administrator' : $role ) );
	}

	/**
	 * check_current_user_roles.
	 *
	 * @version 1.7.0
	 * @since   1.7.0
	 *
	 * @todo    (dev) assign `array( 'guest' )` if `wp_get_current_user()` does not exist?
	 */
	function check_current_user_roles( $user_roles_to_check ) {
		if ( ! empty( $user_roles_to_check ) ) {
			if ( ! isset( $this->current_user ) ) {
				if ( ! function_exists( 'wp_get_current_user' ) ) {
					return false;
				}
				$this->current_user = wp_get_current_user();
				if ( ! isset( $this->current_user->roles ) || empty( $this->current_user->roles ) ) {
					$this->current_user->roles = array( 'guest' );
				}
				$this->current_user->roles = array_map( array( $this, 'handle_user_roles' ), $this->current_user->roles );
			}
			$user_roles_to_check = array_map( array( $this, 'handle_user_roles' ), $user_roles_to_check );
			$intersect           = array_intersect( $this->current_user->roles, $user_roles_to_check );
			return ( ! empty( $intersect ) );
		}
		return false;
	}

	/**
	 * is_cart_or_checkout_or_ajax.
	 *
	 * @version 1.7.0
	 * @since   1.7.0
	 */
	function is_cart_or_checkout_or_ajax() {
		return ( is_checkout() || is_cart() || defined( 'WOOCOMMERCE_CHECKOUT' ) || defined( 'WOOCOMMERCE_CART' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) );
	}

	/**
	 * is_validate_and_exempt.
	 *
	 * @version 1.7.0
	 * @since   1.7.0
	 */
	function is_validate_and_exempt() {
		return ( 'yes' === get_option( 'alg_wc_eu_vat_validate', 'yes' ) && 'yes' === get_option( 'alg_wc_eu_vat_disable_for_valid', 'yes' ) );
	}

	/**
	 * get_error_vies_unavailable.
	 *
	 * @version 2.11.11
	 * @since   2.11.11
	 */
	function get_error_vies_unavailable() {
		if('yes' === get_option( 'alg_wc_eu_vat_validate_vies_not_available', 'no' )) {
			$vies_error_message = alg_wc_eu_vat_session_get('alg_wc_eu_vat_vies_error_message', null);
			if(null !== $vies_error_message) {
				$error_msg = strval( $vies_error_message );
				$error_msg = trim( $error_msg );
				return $error_msg;
			}
		}

		return null;
	}

	/**
	 * check_and_save_eu_vat.
	 *
	 * @version 1.7.1
	 * @since   1.7.1
	 *
	 * @todo    [dev] (important) use in `Alg_WC_EU_VAT_AJAX::alg_wc_eu_vat_validate_action()`
	 */
	function check_and_save_eu_vat( $eu_vat_to_check, $billing_country, $billing_company ) {
		$eu_vat_number = alg_wc_eu_vat_parse_vat( $eu_vat_to_check, $billing_country );
		if ( 'yes' === apply_filters( 'alg_wc_eu_vat_check_ip_location_country', 'no' ) ) {
			$country_by_ip   = alg_wc_eu_vat_get_customers_location_by_ip();
			$is_county_valid = ( $country_by_ip === $eu_vat_number['country'] );
			$is_valid        = $is_county_valid ? alg_wc_eu_vat_validate_vat( $eu_vat_number['country'], $eu_vat_number['number'], $billing_company ) : false;
			if ( ! $is_county_valid ) {
				alg_wc_eu_vat_maybe_log( $eu_vat_number['country'], $eu_vat_number['number'], $billing_company, '',
					sprintf( __( 'Error: Country by IP does not match (%s)', 'eu-vat-for-woocommerce' ), $country_by_ip ) );
			}
		} else {
			$is_valid = alg_wc_eu_vat_validate_vat( $eu_vat_number['country'], $eu_vat_number['number'], $billing_company );
		}

		if( !$is_valid ){
			if( $this->get_error_vies_unavailable() !== null ) {
				$is_valid = true;
			}
		}

		alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_valid',    $is_valid );
		alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_to_check', $eu_vat_to_check );
		return $is_valid;
	}

	/**
	 * is_valid_and_exists.
	 *
	 * @version 1.7.1
	 * @since   1.7.0
	 */
	function is_valid_and_exists() {
		$is_valid = ( true === alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_valid' ) && null !== alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' ) );
		return $is_valid;
	}

	/**
	 * maybe_exclude_vat.
	 *
	 * @version 3.0.0
	 * @since   1.0.0
	 *
	 * @todo    [fix] (important) mini cart
	 */
	function maybe_exclude_vat() {
		if ( empty( WC()->customer ) || ! $this->is_cart_or_checkout_or_ajax() ) {
			return;
		}

		if ( $this->check_current_user_roles( get_option( 'alg_wc_eu_vat_exempt_for_user_roles', array() ) ) ) {
			$is_exempt = true;
		} elseif ( $this->check_current_user_roles( get_option( 'alg_wc_eu_vat_not_exempt_for_user_roles', array() ) ) ) {
			$is_exempt = false;
		} elseif ( $this->is_validate_and_exempt() && $this->is_valid_and_exists() ) {
			$is_exempt = apply_filters( 'alg_wc_eu_vat_maybe_exclude_vat', true );
		} else {
			$is_exempt = false;
		}

		if('yes' === get_option( 'alg_wc_eu_vat_validate_force_page_reload', 'no' ) ) {
			if((is_checkout() || is_cart()) && !$is_exempt) {
				$billing_eu_vat_number = WC()->customer->get_meta('billing_eu_vat_number');
				$billing_country = WC()->customer->get_meta('billing_country');
				$billing_company = WC()->customer->get_meta('billing_company');
				if ( !empty($billing_eu_vat_number) ) {
					$is_valid = $this->check_and_save_eu_vat($billing_eu_vat_number, $billing_country, $billing_company);
					if ( $is_valid ) {
						$is_exempt = apply_filters( 'alg_wc_eu_vat_maybe_exclude_vat', true );
					}
				}
			}
		}

		if( true === alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_i_am_company' )){
			$is_exempt = true;
		}

		WC()->customer->set_is_vat_exempt( $is_exempt );

		do_action( 'alg_wc_eu_vat_exempt_applied', $is_exempt );
	}

	/**
	 * filter_available_payment_gateways_allowed.
	 *
	 * @version 2.12.4
	 * @since   1.0.0
	 *
	 * @todo    [fix] (important) mini cart
	 */
	function filter_available_payment_gateways_allowed( $available_gateways ) {

		$gateways = $available_gateways;
		$is_vat_valid = false;

		if ( $this->check_current_user_roles( get_option( 'alg_wc_eu_vat_exempt_for_user_roles', array() ) ) ) {
			$is_exempt = true;
		} elseif ( $this->check_current_user_roles( get_option( 'alg_wc_eu_vat_not_exempt_for_user_roles', array() ) ) ) {
			$is_exempt = false;
		} elseif ( $this->is_validate_and_exempt() && $this->is_valid_and_exists() ) {
			$is_exempt = apply_filters( 'alg_wc_eu_vat_maybe_exclude_vat_on_available_gateway', true );
		} else {
			$is_exempt = false;
		}

		if ( true === alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_valid_before_preserve' ) ) {
			$is_vat_valid = true;
		}

		$alg_wc_eu_vat_allowed_payment_gateway = get_option( 'alg_wc_eu_vat_allowed_payment_gateway', array() );
		$alg_wc_eu_vat_allow_specific_payment = get_option( 'alg_wc_eu_vat_allow_specific_payment', 'no' );

		if ( 'yes' == $alg_wc_eu_vat_allow_specific_payment ) {
			if ( ! empty( $alg_wc_eu_vat_allowed_payment_gateway ) && ! empty( $available_gateways ) ) {
				foreach ( $available_gateways as $gateway_id => $gateway ) {
					if ( in_array($gateway_id, $alg_wc_eu_vat_allowed_payment_gateway ) ) {
						unset( $available_gateways[ $gateway_id ] );
					}
				}

				if ( $is_exempt || $is_vat_valid ) {
					return $gateways;
				} else {
					return $available_gateways;
				}
			} else {
				return $available_gateways;
			}
		} else {
			return $available_gateways;
		}
	}

	/**
	 * checkout_validate_vat.
	 *
	 * @version 3.1.4
	 * @since   1.0.0
	 *
	 * @todo    [dev] (important) simplify the code
	 */
	function checkout_validate_vat( $_posted ) {
		$is_required = ( 'yes' === get_option( 'alg_wc_eu_vat_field_required', 'no' ) );

		$eu_vat_required = get_option( 'alg_wc_eu_vat_field_required', 'no' );

		$field_id = alg_wc_eu_vat_get_field_id();

		if( $is_required && 'yes' === get_option( 'alg_wc_eu_vat_field_let_customer_decide', 'no' ) ){
			if(isset($_posted[$field_id . '_customer_decide']) && $_posted[$field_id . '_customer_decide']==1){
				return;
			}
		}

		if( 'yes' === get_option( 'alg_wc_eu_vat_belgium_compatibility', 'no' ) ){
			if(isset($_posted[$field_id . '_belgium_compatibility']) && $_posted[$field_id . '_belgium_compatibility']==1){
				alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_valid', false );
				alg_wc_eu_vat_session_set( 'alg_wc_eu_vat_to_check', null );
				return;
			}
		}

		$show_eu_vat_field_countries = array();

		if ( ! empty( $this->show_in_countries ) ) {
			$show_eu_vat_field_countries = array_map( 'strtoupper', array_map( 'trim', explode( ',', $this->show_in_countries ) ) );
		}

		$country_code = isset( $_posted['billing_country'] ) ? esc_attr( $_posted['billing_country'] ) : '';

		if (
			'yes_for_company' === $eu_vat_required &&
			! empty( $_posted['billing_company'] ) &&
			isset( $_posted[ alg_wc_eu_vat_get_field_id() ] ) &&
			empty( $_posted[ alg_wc_eu_vat_get_field_id() ] )
		) {

			$is_valid = false;

			if (
				! empty( $show_eu_vat_field_countries[0] ) &&
				! in_array( $country_code, $show_eu_vat_field_countries )
			) {
				$is_valid = true;
			}

			if ( ! $is_valid ) {
				wc_add_notice(
					str_replace( '%eu_vat_number%', esc_attr( $_posted[ alg_wc_eu_vat_get_field_id() ] ),
						do_shortcode(
							get_option(
								'alg_wc_eu_vat_not_valid_message',
								__( '<strong>EU VAT Number</strong> is not valid.', 'eu-vat-for-woocommerce' )
							)
						)
					),
					'error'
				);
			}

		}

		if ( 'yes' === get_option( 'alg_wc_eu_vat_validate', 'yes' ) ) {
			if (
				( '' != $_posted[ alg_wc_eu_vat_get_field_id() ] ) &&
				(
					null === alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_valid' ) ||
					false == alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_valid' ) ||
					null === alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' ) ||
					$_posted[ alg_wc_eu_vat_get_field_id() ] != alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' )
				)
			) {

				$is_valid = false;
				if (
					'yes' === get_option( 'alg_wc_eu_vat_force_checkout_recheck', 'no' ) &&
					$_posted[ alg_wc_eu_vat_get_field_id() ] != alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check' )
				) {
					$is_valid = $this->check_and_save_eu_vat(
						$_posted[ alg_wc_eu_vat_get_field_id() ],
						( isset( $_posted['billing_country'] ) ? esc_attr($_posted['billing_country']) : '' ),
						( isset( $_posted['billing_company'] ) ? esc_attr($_posted['billing_company']) : '' )
					);
				} else {

					$vat_number = esc_attr($_posted[ alg_wc_eu_vat_get_field_id() ]);
					$billing_country = isset( $_posted['billing_country'] ) ? esc_attr($_posted['billing_country']) : '';
					$billing_company = isset( $_posted['billing_company'] ) ? esc_attr($_posted['billing_company']) : '';
					$vat_number = preg_replace('/\s+/', '', $vat_number);
					$eu_vat_number = alg_wc_eu_vat_parse_vat( $vat_number, $billing_country );

					/* VAT validate manually pre-saved number */
					if( 'yes' === get_option( 'alg_wc_eu_vat_manual_validation_enable', 'no' ) ) {
						if( '' != ( $manual_validation_vat_numbers = get_option( 'alg_wc_eu_vat_manual_validation_vat_numbers', '' ) ) ) {
							$prevalidated_VAT_numbers = array();
							$prevalidated_VAT_numbers = explode( ',', $manual_validation_vat_numbers );
							$sanitized_vat_numbers = array_map('trim', $prevalidated_VAT_numbers);

							$conjuncted_vat_number = $billing_country . '' . $eu_vat_number['number'];
							if( isset($sanitized_vat_numbers[0] ) ){
								if ( in_array( $conjuncted_vat_number, $sanitized_vat_numbers ) ) {
									alg_wc_eu_vat_maybe_log( $eu_vat_number['country'], $eu_vat_number['number'], $billing_company, '', __( 'Success (checkout): VAT ID valid. Matched with prevalidated VAT numbers.', 'eu-vat-for-woocommerce' ) );
									$is_valid = true;

								}
							}
						}
					}
				}

				if ( 'no' != ( $preserve_option_value = get_option( 'alg_wc_eu_vat_preserv_vat_for_different_shipping', 'no' ) ) ) {
					$billing_country = isset( $_REQUEST['billing_country'] ) ? esc_attr($_REQUEST['billing_country']) : '';
					$shipping_country = isset( $_REQUEST['shipping_country'] ) ? esc_attr($_REQUEST['shipping_country']) : '';

					$is_country_same = ( strtoupper( $billing_country ) !== strtoupper( $shipping_country) );
					if ( ! $is_country_same && ! $is_valid ) {
						$is_valid = true;
					}
				}

				// Checks if company name autofill is enabled
				if ( 'no' !== get_option( 'alg_wc_eu_vat_advance_enable_company_name_autofill', 'no' ) ) {
					$company_name        = sanitize_text_field( alg_wc_eu_vat_session_get( 'alg_wc_eu_vat_to_check_company_name' ) );
					$posted_company_name = sanitize_text_field( $_posted['billing_company'] );

					// Check if the company names match and if it's not valid yet
					if ( $company_name === $posted_company_name && ! $is_valid ) {
						$is_valid = true;
					} elseif ( ! empty( $company_name ) ) {
						// If company names don't match, show an error notice
						wc_add_notice(
							str_replace( '%company_name%', esc_html( $company_name ),
								do_shortcode(
									get_option(
										'alg_wc_eu_vat_company_name_mismatch',
										__( ' VAT is valid, but registered to %company_name%.', 'eu-vat-for-woocommerce' )
									)
								)
							),
							'error'
						);
					}
				}

				$is_valid = apply_filters( 'alg_wc_eu_vat_is_valid_vat_at_checkout', $is_valid );
				if ( ! $is_valid ) {
					wc_add_notice(
						str_replace( '%eu_vat_number%', esc_attr($_posted[ alg_wc_eu_vat_get_field_id() ]),
							do_shortcode( get_option( 'alg_wc_eu_vat_not_valid_message', __( '<strong>EU VAT Number</strong> is not valid.', 'eu-vat-for-woocommerce' ) ) ) ),
						'error'
					);
					alg_wc_eu_vat_maybe_log(
						( isset( $_posted['billing_country'] ) ? esc_attr($_posted['billing_country']) : '' ),
						esc_attr($_posted[ alg_wc_eu_vat_get_field_id() ]),
						( isset( $_posted['billing_company'] ) ? esc_attr($_posted['billing_company']) : '' ),
						'',
						__( 'Error: VAT is not valid (checkout)', 'eu-vat-for-woocommerce' )
					);
				}
			}
		}
	}

	/**
	 * add_eu_vat_registration_woocommerce.
	 *
	 * @version 2.12.8
	 * @since   1.0.0
	 *
	 * @todo    [dev] new field to signup form.
	 */
	function add_eu_vat_registration_woocommerce(){
		$fields = array();
		$fields[ alg_wc_eu_vat_get_field_id() ] = $this->get_field_data();

		foreach ( $fields as $key => $field_args ) {
			woocommerce_form_field( $key, $field_args );
		}
	}

	/**
	 * add_eu_vat_registration_woocommerce_validation.
	 *
	 * @version 2.12.10
	 * @since   1.0.0
	 *
	 * @todo    [dev] new field to signup form.
	 */
	function add_eu_vat_registration_woocommerce_validation( $username, $email, $errors ){
		$field_id = alg_wc_eu_vat_get_field_id();
		$eu_vat_to_check = esc_attr($_POST[$field_id]);

		$form_company_name = isset($_POST['billing_company']) ? esc_attr($_POST['billing_company']) : '';
		$form_country = isset($_POST['billing_country']) ? esc_attr($_POST['billing_country']) : '';

		if ( 'yes' === get_option( 'alg_wc_eu_vat_field_required', 'yes' ) && 'yes' === get_option( 'alg_wc_eu_vat_validate_sign_up_page', 'yes' ) ) {
			$is_valid = $this->check_and_save_eu_vat( $eu_vat_to_check, $form_country, $form_company_name );

			if(!$is_valid){
				$text_not_valid = get_option( 'alg_wc_eu_vat_progress_text_not_valid',         __( 'VAT is not valid.', 'eu-vat-for-woocommerce' ) );

				if( isset( $_REQUEST ) && isset( $_REQUEST['oauth_consumer_key'] ) && ! empty( $_REQUEST['oauth_consumer_key'] ) ) {

				} else if (! strpos( $_SERVER['REQUEST_URI'], 'wp-json')) {
					$errors->add( $field_id . '_error', $text_not_valid );
				}
			}
		}
	}

	/**
	 * add_eu_vat_registration_save_woocommerce_field.
	 *
	 * @version 2.12.9
	 * @since   1.0.0
	 *
	 * @todo    [dev] new field to signup form.
	 */
	function add_eu_vat_registration_save_woocommerce_field( $customer_id ) {
		$field_id = alg_wc_eu_vat_get_field_id();
		if ( isset( $_POST[$field_id] ) ) {
			update_user_meta( $customer_id, $field_id, wc_clean( $_POST[$field_id] ) );
		}
	}

	/**
	 * Adds a checkbox to keep VAT for an individual product.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function add_keep_vat_individual_product() {
		// Add a checkbox below the tax options
		woocommerce_wp_checkbox(
			array(
				'id'              => '_alg_wc_eu_vat_keep_vat',
				'label'           => esc_html__( 'Keep VAT for this product', 'eu-vat-for-woocommerce' ),
				'description'     => esc_html__( 'Enable this to ensure VAT is charged on this product, even if the customer provides a valid VAT number.', 'eu-vat-for-woocommerce' ),
				'desc_tip'        => true,
				'unchecked_value' => 'no',
			)
		);
	}

	/**
	 * Saves a checkbox to keep VAT for an individual product.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function save_keep_vat_individual_product( $product ) {
		if ( isset( $_POST['_alg_wc_eu_vat_keep_vat'] ) ) {
			$product->update_meta_data(
				'_alg_wc_eu_vat_keep_vat',
				wc_clean( wp_unslash( $_POST['_alg_wc_eu_vat_keep_vat'] ) )
			);
		}
	}

	/**
	 * Handles the VAT exemption for individual products based on the "Keep VAT" checkbox.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function handle_keep_vat_individual_product( $is_exempt ) {

		if ( ! $is_exempt ) {
			return;
		}

		$vat_required_products = array();

		// Check if any products have the "Keep VAT" option enabled
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = $cart_item['product_id'];

			$do_keep_vat = get_post_meta( $product_id, '_alg_wc_eu_vat_keep_vat', true );

			if ( 'yes' === $do_keep_vat ) {
				$vat_required_products[] = $product_id;
			}
		}

		// If there are any VAT-required products, keep VAT for the customer
		if ( ! empty( $vat_required_products ) ) {
			WC()->customer->set_is_vat_exempt( false ); // Disable VAT exemption for the customer

			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$product_id = $cart_item['product_id'];

				// If the product doesn't require VAT, set its tax status to "None"
				if ( ! in_array( $product_id, $vat_required_products ) ) {
					$product             = $cart_item['data'];
					$price_excluding_tax = wc_get_price_excluding_tax( $product );
					$product->set_price( $price_excluding_tax );
					$product->set_tax_status( 'none' );
				}
			}
		}

	}

}

endif;

return new Alg_WC_EU_VAT_Core();
