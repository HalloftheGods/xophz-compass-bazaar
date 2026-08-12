<?php

/**
 * The admin-specific functionality of the procurement/PO system.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */

class Xophz_Compass_Bazaar_Admin_Procurement {

	private $plugin_name;
	private $version;
	public $action_hooks;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;

		// Map WP hooks to class methods
		$this->action_hooks = [
			'init' => 'register_cpts',
			'wp_ajax_bazaar_get_suppliers' => 'get_suppliers',
			'wp_ajax_bazaar_create_supplier' => 'create_supplier',
			'wp_ajax_bazaar_get_pos' => 'get_pos',
			'wp_ajax_bazaar_create_po' => 'create_po',
			'wp_ajax_bazaar_receive_po' => 'receive_po',
		];
	}

	public function register_cpts() {
		// Register Supplier CPT
		$supplier_labels = array(
			'name'                  => _x( 'Suppliers', 'Post Type General Name', 'xophz-compass-bazaar' ),
			'singular_name'         => _x( 'Supplier', 'Post Type Singular Name', 'xophz-compass-bazaar' ),
			'menu_name'             => __( 'Suppliers', 'xophz-compass-bazaar' ),
			'name_admin_bar'        => __( 'Supplier', 'xophz-compass-bazaar' ),
		);
		$supplier_args = array(
			'label'                 => __( 'Supplier', 'xophz-compass-bazaar' ),
			'labels'                => $supplier_labels,
			'supports'              => array( 'title', 'editor', 'custom-fields' ),
			'public'                => false,
			'show_ui'               => true,
			'show_in_menu'          => 'edit.php?post_type=bazaar_po', // Show under PO menu
			'show_in_rest'          => true,
		);
		register_post_type( 'bazaar_supplier', $supplier_args );

		// Register Purchase Order CPT
		$po_labels = array(
			'name'                  => _x( 'Purchase Orders', 'Post Type General Name', 'xophz-compass-bazaar' ),
			'singular_name'         => _x( 'Purchase Order', 'Post Type Singular Name', 'xophz-compass-bazaar' ),
			'menu_name'             => __( 'Procurement', 'xophz-compass-bazaar' ),
			'name_admin_bar'        => __( 'Purchase Order', 'xophz-compass-bazaar' ),
		);
		$po_args = array(
			'label'                 => __( 'Purchase Order', 'xophz-compass-bazaar' ),
			'labels'                => $po_labels,
			'supports'              => array( 'title', 'custom-fields' ),
			'public'                => false,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'menu_icon'             => 'dashicons-clipboard',
			'show_in_rest'          => true,
		);
		register_post_type( 'bazaar_po', $po_args );
	}

	public function get_suppliers() {
		$args = array(
			'post_type'      => 'bazaar_supplier',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);
		$query = new WP_Query( $args );
		$suppliers = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();
				$email = get_post_meta( $id, '_supplier_email', true );
				$phone = get_post_meta( $id, '_supplier_phone', true );
				$company = get_post_meta( $id, '_supplier_company', true );
				$wp_user_id = get_post_meta( $id, '_supplier_wp_user_id', true );

				// Check WP user mapping
				$user_data = null;
				if ( !empty($wp_user_id) ) {
					$user = get_userdata( intval($wp_user_id) );
					if ( $user ) {
						$user_data = array(
							'id' => $user->ID,
							'display_name' => $user->display_name,
							'user_email' => $user->user_email,
						);
					}
				}

				$suppliers[] = array(
					'id'         => $id,
					'name'       => get_the_title(),
					'company'    => !empty($company) ? $company : get_the_title(),
					'email'      => $email,
					'phone'      => $phone,
					'wp_user_id' => intval($wp_user_id),
					'user_data'  => $user_data,
					'has_crm'    => post_type_exists('questbook_contact') || post_type_exists('questbook_company'),
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 'suppliers' => $suppliers ) );
	}

	public function create_supplier() {
		$name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
		$company = isset($_POST['company']) ? sanitize_text_field($_POST['company']) : '';
		$email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
		$phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
		$wp_user_id = isset($_POST['wp_user_id']) ? intval($_POST['wp_user_id']) : 0;

		if ( empty($name) && empty($company) ) {
			wp_send_json_error( array( 'message' => 'Supplier name or company is required.' ) );
		}

		$title = !empty($name) ? $name : $company;

		$supplier_id = wp_insert_post( array(
			'post_type'   => 'bazaar_supplier',
			'post_title'  => $title,
			'post_status' => 'publish',
		) );

		if ( is_wp_error( $supplier_id ) ) {
			wp_send_json_error( array( 'message' => 'Failed to create supplier.' ) );
		}

		// Auto-link existing WP User by email if not explicitly provided
		if ( empty($wp_user_id) && !empty($email) ) {
			$existing_user = get_user_by('email', $email);
			if ( $existing_user ) {
				$wp_user_id = $existing_user->ID;
			}
		}

		update_post_meta( $supplier_id, '_supplier_company', $company );
		update_post_meta( $supplier_id, '_supplier_email', $email );
		update_post_meta( $supplier_id, '_supplier_phone', $phone );
		update_post_meta( $supplier_id, '_supplier_wp_user_id', $wp_user_id );

		// Optional Graceful CRM Linking (only if Questbook CRM is active)
		if ( post_type_exists('questbook_company') && !empty($company) ) {
			$crm_company_id = wp_insert_post( array(
				'post_type'   => 'questbook_company',
				'post_title'  => $company,
				'post_status' => 'publish',
			) );
			if ( !is_wp_error($crm_company_id) ) {
				update_post_meta( $supplier_id, '_supplier_crm_company_id', $crm_company_id );
			}
		}

		if ( post_type_exists('questbook_contact') && !empty($email) ) {
			$crm_contact_id = wp_insert_post( array(
				'post_type'   => 'questbook_contact',
				'post_title'  => $title,
				'post_status' => 'publish',
			) );
			if ( !is_wp_error($crm_contact_id) ) {
				update_post_meta( $crm_contact_id, '_contact_email', $email );
				update_post_meta( $crm_contact_id, '_contact_phone', $phone );
				update_post_meta( $supplier_id, '_supplier_crm_contact_id', $crm_contact_id );
			}
		}

		wp_send_json_success( array(
			'supplier_id' => $supplier_id,
			'message'     => 'Supplier created successfully.'
		) );
	}

	public function get_pos() {
		$args = array(
			'post_type'      => 'bazaar_po',
			'posts_per_page' => -1,
		);
		$query = new WP_Query( $args );
		$pos = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pos[] = array(
					'id'       => get_the_ID(),
					'title'    => get_the_title(),
					'supplier' => get_post_meta( get_the_ID(), '_po_supplier_id', true ),
					'total'    => get_post_meta( get_the_ID(), '_po_total', true ),
					'status'   => get_post_meta( get_the_ID(), '_po_status', true ),
					'date'     => get_the_date('c'),
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 'pos' => $pos ) );
	}

	public function create_po() {
		$supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;
		// Handle json decoded array or direct array
		$items = isset($_POST['items']) ? $_POST['items'] : array();
		if (is_string($items)) {
			$items = json_decode(stripslashes($items), true);
		}
		$total = 0;

		$po_id = wp_insert_post( array(
			'post_type'   => 'bazaar_po',
			'post_title'  => 'PO-' . time(),
			'post_status' => 'publish',
		) );

		if ( is_wp_error( $po_id ) ) {
			wp_send_json_error( array( 'message' => 'Failed to create PO' ) );
		}

		// Save items & calculate total. Handle dynamic material creation.
		$saved_items = array();
		foreach ($items as $item) {
			$product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
			
			// If product_id is 0, we need to create a draft WooCommerce product
			if ( $product_id === 0 && !empty($item['name']) ) {
				$new_product = new WC_Product_Simple();
				$new_product->set_name( sanitize_text_field( $item['name'] ) );
				$new_product->set_status( 'draft' ); // Hidden from public catalog
				$new_product->set_regular_price( $item['cost'] );
				$new_product->set_manage_stock( true );
				$new_product->set_stock_quantity( 0 ); // Initial stock is 0 until received
				$product_id = $new_product->save();
			}

			$line_total = floatval($item['cost']) * intval($item['qty']);
			$total += $line_total;

			$saved_items[] = array(
				'product_id' => $product_id,
				'name'       => sanitize_text_field( $item['name'] ),
				'qty'        => intval( $item['qty'] ),
				'cost'       => floatval( $item['cost'] ),
				'total'      => $line_total,
			);
		}

		// Update PO Title to be cleaner now that we have an ID
		wp_update_post( array(
			'ID' => $po_id,
			'post_title' => 'PO-' . str_pad($po_id, 5, '0', STR_PAD_LEFT),
		) );

		update_post_meta( $po_id, '_po_supplier_id', $supplier_id );
		update_post_meta( $po_id, '_po_items', $saved_items );
		update_post_meta( $po_id, '_po_total', $total );
		update_post_meta( $po_id, '_po_status', 'sent' );

		wp_send_json_success( array( 
			'po_id' => $po_id,
			'message' => 'Purchase Order created successfully.' 
		) );
	}

	public function receive_po() {
		$po_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		$current_status = get_post_meta( $po_id, '_po_status', true );

		if ( $current_status === 'received' ) {
			wp_send_json_error( array( 'message' => 'PO is already received.' ) );
		}

		$items = get_post_meta( $po_id, '_po_items', true );
		if ( !empty($items) && is_array($items) ) {
			foreach ($items as $item) {
				$product_id = $item['product_id'];
				$qty = $item['qty'];

				if ( $product_id ) {
					$product = wc_get_product( $product_id );
					if ( $product && $product->managing_stock() ) {
						$current_stock = $product->get_stock_quantity() ? $product->get_stock_quantity() : 0;
						$product->set_stock_quantity( $current_stock + $qty );
						$product->save();
					}
				}
			}
		}

		update_post_meta( $po_id, '_po_status', 'received' );

		wp_send_json_success( array( 
			'message' => 'Inventory updated and PO marked as received.' 
		) );
	}

}
