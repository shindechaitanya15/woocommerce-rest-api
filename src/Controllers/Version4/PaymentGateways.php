<?php
/**
 * REST API WC Payment gateways controller
 *
 * Handles requests to the /payment_gateways endpoint.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\SettingsTrait;

/**
 * Payment gateways controller class.
 */
class PaymentGateways extends AbstractController {
	use SettingsTrait;

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'payment_gateways';

	/**
	 * Permission to check.
	 *
	 * @var string
	 */
	protected $resource_type = 'payment_gateways';

	/**
	 * Register the route for /payment_gateways and /payment_gateways/<id>
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w-]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);
	}

	/**
	 * Get payment gateways.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_items( $request ) {
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		$response         = array();
		foreach ( $payment_gateways as $payment_gateway_id => $payment_gateway ) {
			$payment_gateway->id = $payment_gateway_id;
			$gateway             = $this->prepare_item_for_response( $payment_gateway, $request );
			$gateway             = $this->prepare_response_for_collection( $gateway );
			$response[]          = $gateway;
		}
		return rest_ensure_response( $response );
	}

	/**
	 * Get a single payment gateway.
	 *
	 * @param \WP_REST_Request $request Request data.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$gateway = $this->get_gateway( $request );

		if ( is_null( $gateway ) ) {
			return new \WP_Error( 'woocommerce_rest_payment_gateway_invalid', __( 'Resource does not exist.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$gateway = $this->prepare_item_for_response( $gateway, $request );
		return rest_ensure_response( $gateway );
	}

	/**
	 * Update A Single Payment Method.
	 *
	 * @param \WP_REST_Request $request Request data.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$gateway = $this->get_gateway( $request );

		if ( is_null( $gateway ) ) {
			return new \WP_Error( 'woocommerce_rest_payment_gateway_invalid', __( 'Resource does not exist.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		// Get settings.
		$gateway->init_form_fields();
		$settings = $gateway->settings;

		// Update settings.
		if ( isset( $request['settings'] ) ) {
			$errors_found = false;
			foreach ( $gateway->form_fields as $key => $field ) {
				if ( isset( $request['settings'][ $key ] ) ) {
					if ( is_callable( array( $this, 'validate_setting_' . $field['type'] . '_field' ) ) ) {
						$value = $this->{'validate_setting_' . $field['type'] . '_field'}( $request['settings'][ $key ], $field );
					} else {
						$value = $this->validate_setting_text_field( $request['settings'][ $key ], $field );
					}
					if ( is_wp_error( $value ) ) {
						$errors_found = true;
						break;
					}
					$settings[ $key ] = $value;
				}
			}

			if ( $errors_found ) {
				return new \WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
			}
		}

		// Update if this method is enabled or not.
		if ( isset( $request['enabled'] ) ) {
			$settings['enabled'] = wc_bool_to_string( $request['enabled'] );
			$gateway->enabled    = $settings['enabled'];
		}

		// Update title.
		if ( isset( $request['title'] ) ) {
			$settings['title'] = $request['title'];
			$gateway->title    = $settings['title'];
		}

		// Update description.
		if ( isset( $request['description'] ) ) {
			$settings['description'] = $request['description'];
			$gateway->description    = $settings['description'];
		}

		// Update options.
		$gateway->settings = $settings;
		update_option( $gateway->get_option_key(), apply_filters( 'woocommerce_gateway_' . $gateway->id . '_settings_values', $settings, $gateway ) );

		// Update order.
		if ( isset( $request['order'] ) ) {
			$order                 = (array) get_option( 'woocommerce_gateway_order' );
			$order[ $gateway->id ] = $request['order'];
			update_option( 'woocommerce_gateway_order', $order );
			$gateway->order = absint( $request['order'] );
		}

		$gateway = $this->prepare_item_for_response( $gateway, $request );
		return rest_ensure_response( $gateway );
	}

	/**
	 * Get a gateway based on the current request object.
	 *
	 * @param \WP_REST_Request $request Request data.
	 * @return \WP_REST_Response|null
	 */
	public function get_gateway( $request ) {
		$gateway          = null;
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		foreach ( $payment_gateways as $payment_gateway_id => $payment_gateway ) {
			if ( $request['id'] !== $payment_gateway_id ) {
				continue;
			}
			$payment_gateway->id = $payment_gateway_id;
			$gateway             = $payment_gateway;
		}
		return $gateway;
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param \WC_Payment_Gateway $object Object to prepare.
	 * @param \WP_REST_Request    $request Request object.
	 * @return array Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		$order = (array) get_option( 'woocommerce_gateway_order' );
		return array(
			'id'                 => $object->id,
			'title'              => $object->title,
			'description'        => $object->description,
			'order'              => isset( $order[ $object->id ] ) ? $order[ $object->id ] : '',
			'enabled'            => ( 'yes' === $object->enabled ),
			'method_title'       => $object->get_method_title(),
			'method_description' => $object->get_method_description(),
			'method_supports'    => $object->supports,
			'settings'           => $this->get_settings( $object ),
		);
	}

	/**
	 * Return settings associated with this payment gateway.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 *
	 * @return array
	 */
	public function get_settings( $gateway ) {
		$settings = array();
		$gateway->init_form_fields();
		foreach ( $gateway->form_fields as $id => $field ) {
			// Make sure we at least have a title and type.
			if ( empty( $field['title'] ) || empty( $field['type'] ) ) {
				continue;
			}

			// Ignore 'enabled' and 'description' which get included elsewhere.
			if ( in_array( $id, array( 'enabled', 'description' ), true ) ) {
				continue;
			}

			$data = array(
				'id'          => $id,
				'label'       => empty( $field['label'] ) ? $field['title'] : $field['label'],
				'description' => empty( $field['description'] ) ? '' : $field['description'],
				'type'        => $field['type'],
				'value'       => empty( $gateway->settings[ $id ] ) ? '' : $gateway->settings[ $id ],
				'default'     => empty( $field['default'] ) ? '' : $field['default'],
				'tip'         => empty( $field['description'] ) ? '' : $field['description'],
				'placeholder' => empty( $field['placeholder'] ) ? '' : $field['placeholder'],
			);
			if ( ! empty( $field['options'] ) ) {
				$data['options'] = $field['options'];
			}
			$settings[ $id ] = $data;
		}
		return $settings;
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		$links = array(
			'self'       => array(
				'href' => rest_url( sprintf( '/%s/%s/%s', $this->namespace, $this->rest_base, $item->id ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		return $links;
	}

	/**
	 * Get the payment gateway schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'payment_gateway',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => __( 'Payment gateway ID.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'title'              => array(
					'description' => __( 'Payment gateway title on checkout.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'description'        => array(
					'description' => __( 'Payment gateway description on checkout.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'order'              => array(
					'description' => __( 'Payment gateway sort order.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'absint',
					),
				),
				'enabled'            => array(
					'description' => __( 'Payment gateway enabled status.', 'woocommerce-rest-api' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'method_title'       => array(
					'description' => __( 'Payment gateway method title.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'method_description' => array(
					'description' => __( 'Payment gateway method description.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'method_supports'    => array(
					'description' => __( 'Supported features for this payment gateway.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type' => 'string',
					),
				),
				'settings'           => array(
					'description' => __( 'Payment gateway settings.', 'woocommerce-rest-api' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'id'          => array(
							'description' => __( 'A unique identifier for the setting.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'label'       => array(
							'description' => __( 'A human readable label for the setting used in interfaces.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'description' => array(
							'description' => __( 'A human readable description for the setting used in interfaces.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'type'        => array(
							'description' => __( 'Type of setting.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'enum'        => array( 'text', 'email', 'number', 'color', 'password', 'textarea', 'select', 'multiselect', 'radio', 'image_width', 'checkbox' ),
							'readonly'    => true,
						),
						'value'       => array(
							'description' => __( 'Setting value.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'default'     => array(
							'description' => __( 'Default value for the setting.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'tip'         => array(
							'description' => __( 'Additional help text shown to the user about the setting.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'placeholder' => array(
							'description' => __( 'Placeholder text to be displayed in text inputs.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get any query params needed.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'context' => $this->get_context_param( array( 'default' => 'view' ) ),
		);
	}

}
