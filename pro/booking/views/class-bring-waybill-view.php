<?php
/**
 * This file is part of Bring Fraktguiden for WooCommerce.
 *
 * @package Bring_Fraktguiden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Add meta box to the waybill edit view
 */
class Bring_Waybill_View {

	/**
	 * Request data
	 *
	 * @var array
	 */
	public static $request_data = [];

	/**
	 * Setup
	 *
	 * @return void
	 */
	public static function setup() {
		add_action( 'add_meta_boxes', __CLASS__ . '::add_meta_box', 10, 2 );
		add_action( 'save_post_mailbox_waybill', __CLASS__ . '::save_waybill', 10, 2 );
	}

	/**
	 * Save Waybill
	 *
	 * @param integer $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_waybill( $post_id, $post ) {
		$consignment_numbers = filter_input( INPUT_POST, 'consignment_numbers', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );

		if ( empty( $consignment_numbers ) ) {
			return;
		}

		self::$request_data  = get_post_meta( $post_id, '_waybill_request_data', true );

		$retry_request = filter_input( INPUT_POST, 'retry_request' );

		// Return early if retry request is not pressed.
		if ( self::$request_data && ! $retry_request ) {
			return;
		}

		// Make sure request data is an array.
		if ( empty( self::$request_data ) ) {
			self::$request_data = [];
		}

		// Deactivate all consignment numbers.
		foreach ( self::$request_data as &$customer_data ) {
			$customer_data['inactive_consignment_numbers'] = $customer_data['consignment_numbers'];
			$customer_data['errors']                       = [];
		}

		// Remove the pointer to $customer_data.
		unset( $customer_data );

		// Book the consignment numbers.
		foreach ( $consignment_numbers as $customer_number => $consignment_data ) {
			// Book the labels with Mybring.
			$customer_data = self::book_mailbox_consignment(
				$customer_number,
				$consignment_data
			);

			self::set_request_data( $customer_number, $customer_data );

			// Update the labels.
			foreach ( $consignment_data as $label_id => $consignment_number ) {
				update_post_meta( $label_id, '_mailbox_waybill_id', $post_id );
				wp_update_post(
					[
						'ID'          => $label_id,
						'post_status' => 'publish',
					]
				);
			}
		}

		update_post_meta( $post_id, '_waybill_request_data', self::$request_data );

		$order_ids = [];
		$suffix    = '';

		foreach ( self::$request_data as $customer_data ) {
			if ( ! $customer_data['waybill'] ) {
				continue;
			}
			$order_ids[] = $customer_data['waybill']['data']['id'];
		}

		if ( ! empty( $order_ids ) ) {
			$suffix = ' ' . implode( ',', $order_ids );
		}

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => __( 'Waybill', 'bring-fraktguiden-for-woocommerce' ) . $suffix,
			]
		);
	}

	/**
	 * Set Request Data
	 *
	 * @param integer $customer_number Customer number.
	 * @param array   $customer_data   Customer data.
	 */
	public static function set_request_data( $customer_number, $customer_data ) {
		if ( ! isset( self::$request_data[ $customer_number ] ) ) {
			$customer_data['inactive_consignment_numbers'] = [];
			self::$request_data[ $customer_number ]        = $customer_data;
			return;
		}

		// Overwrite the original request data.
		$data                        = &self::$request_data[ $customer_number ];
		$data['consignment_numbers'] = $customer_data['consignment_numbers'];
		$data['errors']              = $customer_data['errors'];

		// Remove the active consignment from inactive consignments.
		foreach ( $customer_data['consignment_numbers'] as $consignment_number ) {
			$pos = array_search( $consignment_number, $data['inactive_consignment_numbers'] );

			if ( ! $pos ) {
				continue;
			}

			unset( $data['inactive_consignment_numbers'][ $pos ] );
		}

		// Add the remaining inactive consignments to the consignment list.
		foreach ( $data['inactive_consignment_numbers'] as $label_id => $consignment_number ) {
			if ( in_array( $consignment_number, $data['consignment_numbers'] ) ) {
				continue;
			}

			$data['consignment_numbers'][ $label_id ] = $consignment_number;
		}
	}

	/**
	 * Book Mailbox Consignment
	 *
	 * @param  integer $customer_number     Customer number.
	 * @param  array   $consignment_numbers Consignment numbers.
	 *
	 * @return array
	 */
	public static function book_mailbox_consignment( $customer_number, $consignment_numbers ) {
		require_once dirname( __DIR__ ) . '/classes/class-bring-mailbox-waybill-request.php';

		// Waybill booking does not have a test option.
		$request  = new Bring_Mailbox_Waybill_Request( $customer_number, $consignment_numbers );
		$response = $request->post();

		// Parse the response data.
		$errors       = $response->errors;
		$waybill_data = null;

		if ( property_exists( $response, 'status_code' ) && 201 === $response->status_code ) {
			$waybill_data = json_decode( $response->body, 1 );
		} else {
			$data = json_decode( $response->body, 1 );
			if ( isset( $data['errors'] ) ) {
				$errors = [];
				foreach ( $data['errors'] as $key => $error ) {
					$errors[] = $error['code'] . ': ' . $error['title'];
				}
			}
		}

		// Create a new array with the parsed data.
		return [
			'consignment_numbers' => $consignment_numbers,
			'errors'              => $errors,
			'waybill'             => $waybill_data,
		];
	}

	/**
	 * Add meta box
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 */
	public static function add_meta_box( $post_type, $post ) {
		if ( 'mailbox_waybill' !== $post_type ) {
			return;
		}

		add_meta_box(
			'woocommerce-order-bring-booking',
			__( 'Bring Booking', 'bring-fraktguiden-for-woocommerce' ),
			__CLASS__ . '::render_booking_meta_box',
			'mailbox_waybill',
			'normal',
			'high'
		);
	}

	/**
	 * Render Booking Meta Box
	 *
	 * @param WP_Post $post WP Post.
	 */
	public static function render_booking_meta_box( $post ) {
		$new                          = 'auto-draft' === $post->post_status;
		$inactive_consignment_numbers = [];
		$waybills                     = [];

		if ( $new ) {
			$consignments = self::get_unbooked_consignments();
		} else {
			$waybill_data = get_post_meta( $post->ID, '_waybill_request_data', true );
			$consignments = self::get_consignments( $waybill_data );
			$errors       = [];

			foreach ( $waybill_data as $customer_number => $customer_data ) {
				$waybills[ $customer_number ] = $customer_data['waybill'];

				if ( ! empty( $customer_data['errors'] ) ) {
					  $errors[ $customer_number ] = $customer_data['errors'];
				}

				if ( ! isset( $customer_data['inactive_consignment_numbers'] ) ) {
					continue;
				}

				foreach ( $customer_data['inactive_consignment_numbers'] as $consignment_number ) {
					$inactive_consignment_numbers[] = $consignment_number;
				}
			}
			require dirname( __DIR__ ) . '/templates/waybills-messages.php';
		}
		add_filter( 'bring_fraktguiden_waybill_consignments', __CLASS__ . '::filter_consignments', 10, 2 );
		$consignments = apply_filters( 'bring_fraktguiden_waybill_consignments', $consignments, $post );

		require dirname( __DIR__ ) . '/templates/waybills-table-labels.php';
		require dirname( __DIR__ ) . '/templates/waybills-waybill.php';
	}


	/**
	 * Render Booking Meta Box
	 *
	 * @param WP_Post $post WP Post.
	 */
	static function filter_consignments( $consignments, $post ) {
		$number_only = [];
		foreach ( $consignments as $customer_number => $customer_consignments ) {
			if ( preg_match( '/^\d+$/', $customer_number ) ) {
				$number_only[] = $customer_number;
			}
		}
		foreach ( $consignments as $customer_number => $customer_consignments ) {
			if ( preg_match( '/\-(\d+)$/', $customer_number, $matches ) ) {
				$search = $matches[1];
				if ( in_array( $search, $number_only ) ) {
					unset( $consignments[ $customer_number ] );
					foreach ( $customer_consignments as $order_id => $consignment ) {
						$consignments[ $search ][ $order_id ] = $consignment;
						$consignment->set_customer_number( $search );
					}
				}
			}
		}
		return $consignments;
	}

	/**
	 * Get Consignments
	 *
	 * @param array $waybill_data Waybill data.
	 *
	 * @return array
	 */
	public static function get_consignments( $waybill_data ) {
		$consignments = [];

		foreach ( $waybill_data as $customer_number => $request_data ) {
			$consignment_numbers = $request_data['consignment_numbers'];

			foreach ( $consignment_numbers as $post_id => $consignment_number ) {
				$consignment = self::get_label_consignment( $post_id );

				if ( ! isset( $consignments[ $customer_number ] ) ) {
					$consignments[ $customer_number ] = [];
				}

				$consignments[ $customer_number ][ $post_id ] = $consignment;
			}
		}

		return $consignments;
	}

	/**
	 * Get Unbooked Consignments
	 *
	 * @return array
	 */
	public static function get_unbooked_consignments() {
		$test_mode = Fraktguiden_Helper::get_option( 'booking_test_mode' );

		// Get all labels that have no waybill ID.
		$posts        = get_posts(
			[
				'post_type'      => 'mailbox_label',
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation'       => 'AND',
					'booking_clause' => [
						'key'     => '_mailbox_waybill_id',
						'compare' => 'NOT EXISTS',
					],
					'test_clause'    => [
						'key'   => '_test_mode',
						'value' => ( $test_mode ? 'yes' : 'no' ),
					],
				],
			]
		);

		$consignments = [];

		foreach ( $posts as $post_id ) {
			$consignment     = self::get_label_consignment( $post_id );
			$customer_number = $consignment->get_customer_number();

			if ( ! isset( $consignments[ $customer_number ] ) ) {
				$consignments[ $customer_number ] = [];
			}

			$consignments[ $customer_number ][ $post_id ] = $consignment;
		}

		return $consignments;
	}

	/**
	 * Get label consignments
	 *
	 * @param  integer $post_id Post ID.
	 *
	 * @return Bring_Mailbox_Consignment
	 */
	public static function get_label_consignment( $post_id ) {
		$order_id           = get_post_meta( $post_id, '_order_id', true );
		$consignment_number = get_post_meta( $post_id, '_consignment_number', true );
		$wc_order           = wc_get_order( $order_id );
		$adapter            = new Bring_WC_Order_Adapter( $wc_order );
		$consignments       = $adapter->get_booking_consignments();

		foreach ( $consignments as $consignment ) {
			if ( $consignment->get_consignment_number() != $consignment_number ) {
				continue;
			}

			return $consignment;
		}
	}
}
