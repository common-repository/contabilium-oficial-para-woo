<?php

use Contabilium\CbApi;

/**
 * Función para detectar la orden de pago correcta.
 *
 * @param WC_Order $order Orden a detectar medio de pago.
 */
function contabilium_condicion_venta( $order ) {
	$gateway         = $order->get_payment_method();
	$is_mercado_pago = false !== stripos( $gateway, 'mercado' );

	return $is_mercado_pago ? 'MercadoPago' : 'Cuenta Corriente';
}

/**
 * Retorna los datos de un usuario.
 *
 * @param WC_Order $order      Orden de WooCommerce.
 * @param string   $field_name Nombre del campo.
 *
 * @return mixed
 */
function contabilium_get_dni_field( $order, $field_name ) {
	$value = get_user_meta( $order->get_user_id(), $field_name, true );

	if ( ! $value ) {
		$value = $order->get_meta( $field_name, true );
	}

	return $value;
}

/**
 * Function para construir la sección del cliente en el comprobante.
 *
 * @param WC_Order $order Orden de woocommerce.
 *
 * @return \stdClass Objeto que rempresenta al cliente.
 */
function contabilium_build_cliente( $order ) {
	$client                  = new \stdClass();
	$client->Nombre          = $order->get_billing_first_name();
	$client->Apellido        = $order->get_billing_last_name();
	$client->TipoDocumento   = contabilium_get_dni_field( $order, 'cb_document_type' );
	$client->Documento       = contabilium_get_dni_field( $order, 'cb_document_number' );
	$client->Email           = $order->get_billing_email();
	$client->Telefono        = $order->get_billing_phone();
	$client->LineaDireccion1 = $order->get_billing_address_1();
	$client->LineaDireccion2 = $order->get_billing_address_2();
	$client->Ciudad          = $order->get_billing_city();

	$states            = WC()->countries->get_states( $order->get_billing_country() );
	$client->Provincia = $states[ $order->get_billing_state() ];

	$countries    = WC()->countries->get_countries();
	$client->Pais = $countries[ $order->get_billing_country() ];

	$client->CodigoPostal = $order->get_billing_postcode();

	return $client;
}

/**
 * Builds items qty
 *
 * @param array WC_Order_item
 *
 * @return array
 */
function contabilium_build_items( $order, $order_items, $order_shipping_items ) {
	$items = array();
  $fees = $order->get_fees();

	if ( is_array( $order_items ) ) {
		foreach ( $order_items as $order_item ) {
			$unit_price = $order_item->get_total() / $order_item->get_quantity();

			$product                 = new \stdClass();
			$product->Cantidad       = floatval( $order_item->get_quantity() );
			$product->Codigo         = $order_item->get_product()->get_sku();
			$product->Concepto       = $order_item->get_name();
			$product->PrecioUnitario = floatval( $unit_price );
			
            if ( $order_item->get_subtotal()>0 ) {
                $product->Iva        = $order_item->get_subtotal_tax() * 100 / $order_item->get_subtotal();
            }
            else{
                $product->Iva        = 0;
            }

			if ( $order->get_prices_include_tax() ) {
				$product->Bonificacion = 100 - ( ( $order_item->get_total() / $order_item->get_quantity() ) * 100 / ( $order_item->get_product()->get_price() - ( $order_item->get_total() / $order_item->get_quantity() ) * ( $product->Iva / 100 ) ) );
			} else {
				$product->Bonificacion = 100 - ( ( $order_item->get_total() / $order_item->get_quantity() ) * 100 / $order_item->get_product()->get_price() );
			}
			if ( $product->Bonificacion < 0.5 ) {
				$product->Bonificacion = 0;
			}

			$items[] = $product;
		}
	}
  

	if ( is_array( $fees ) ) {
		foreach ( $fees as $item ) {
			$unit_price = floatval( $item->get_total() );
 
			if ( $unit_price ) {
				$product                 = new \stdClass();
				$product->Cantidad       = 1;
				$product->Codigo         = "0";
				$product->Concepto       = $item->get_name();
				$product->PrecioUnitario = $unit_price;
				$product->Iva            = $item->get_total_tax() * 100 / $item->get_total();
				$product->Bonificacion   = 0;

				$items[] = $product;
			}
		}
	}

	if ( is_array( $order_shipping_items ) ) {
		foreach ( $order_shipping_items as $item ) {
			$unit_price = floatval( $item->get_total() );

			if ( $unit_price > 0 ) {
				$product                 = new \stdClass();
				$product->Cantidad       = 1;
				$product->Codigo         = "CDE";
				$product->Concepto       = "Costo de envio";
				$product->PrecioUnitario = $unit_price;
				$product->Iva            = $item->get_total_tax() * 100 / $item->get_total();
				$product->Bonificacion   = 0;

				$items[] = $product;
			}
		}
	}

	return $items;
}

/**
 * Función para enviar un concepto cuando la orden pasa al estado de completado.
 *
 * @param integer  $order_id Número de la orden.
 * @param WC_Order $order    Orden de WooCommerce.
 */
function contabilium_send_order( $order_id, $order ) {

	$country = 'ar';

	if(strtolower(get_option('cb_api_country')) == 'chile') {
		$country = 'cl';
	}  else if(strtolower(get_option('cb_api_country')) == 'uruguay') {
		$country = 'uy';
	}

	$new_comprobante = array(
		'Cliente'             => contabilium_build_cliente( $order ),
		'IDVentaIntegracion'  => $order_id,
		'Pais'			      => $country,
		'IDEstadoIntegracion' => 'Aceptada',
		'IDIntegracion'       => intval( get_option( 'cb_api_integration' ) ),
		'CondicionVenta'      => contabilium_condicion_venta( $order ),
		'FechaEmision'        => $order->get_date_created()->format( 'Y-m-d' ),
		'Observaciones'       => $order->get_customer_note(),
		'Items'               => contabilium_build_items( $order, $order->get_items( 'line_item' ), $order->get_items( 'shipping' ) )
	);

	$api = CbApi::getInstance( get_option( 'cb_api_client_id' ), get_option( 'cb_api_client_secret' ) );

	$response = $api->postRequest( 
		CbApi::getApiUrl('notificador/wordpress'),
		$new_comprobante
	);
	
	if ( $response[0] == 200 ) {
		update_post_meta( $order_id, 'cb_comprobante_id', $response[1] );
		  $msg = 'Orden '.$order_id.' actualizada en Contabilum el ' . date( 'd/m/Y' ) . ' .Solicitud: ' . json_encode( $new_comprobante );
	} else {
		$msg = 'Error ' . $response[0] . ' al enviar orden '.$order_id.' a Contabilum el ' . date( 'd/m/Y' ) . ' .Solicitud: ' . json_encode( $new_comprobante );
	}

	$order->add_order_note( $msg, false );
}

$statuses = get_option( 'cb_accepted_status', [ 'completed' ] );

if ( ! is_array( $statuses ) ) {
	$statuses = [];
}

foreach ( $statuses as $status ) {
	$cleaned_status = str_replace( 'wc-', '', $status );
	add_action( 'woocommerce_order_status_' . $cleaned_status, 'contabilium_send_order', 10, 2 );
}


/**
 * Función para enviar un concepto cuando la orden pasa al estado de completado.
 *
 * @param integer  $order_id Número de la orden.
 * @param WC_Order $order    Orden de WooCommerce.
 */
function contabilium_refund_order( $order_id, $order ) {

	$country = 'ar';

	if(strtolower(get_option('cb_api_country')) == 'chile') {
		$country = 'cl';
	}  else if(strtolower(get_option('cb_api_country')) == 'uruguay') {
		$country = 'uy';
	}

	$new_comprobante = array(
		'Cliente'             => contabilium_build_cliente( $order ),
		'IDVentaIntegracion'  => $order_id,
		'Pais'		      => $country,
		'IDEstadoIntegracion' => 'Cancelada',
		'IDIntegracion'       => intval( get_option( 'cb_api_integration' ) ),
		'CondicionVenta'      => contabilium_condicion_venta( $order ),
		'FechaEmision'        => $order->get_date_created()->format( 'Y-m-d' ),
		'Observaciones'       => $order->get_customer_note(),
		'Items' => contabilium_build_items( $order, $order->get_items( 'line_item' ), $order->get_items( 'shipping' ) )
	);

	$api = CbApi::getInstance( get_option( 'cb_api_client_id' ), get_option( 'cb_api_client_secret' ) );
	

	$response = $api->postRequest( 
		CbApi::getApiUrl('notificador/wordpress'),
		$new_comprobante
	);

	if ( $response[0] == 200 ) {
		update_post_meta( $order_id, 'cb_comprobante_id', $response[1] );
		  $msg = 'Orden '.$order_id.' actualizada en Contabilum el ' . date( 'd/m/Y' ) . ' .Solicitud: ' . json_encode( $new_comprobante );
	} else {
		$msg = 'Error ' . $response[0] . ' al enviar orden '.$order_id.' a Contabilum el ' . date( 'd/m/Y' ) . ' .Solicitud: ' . json_encode( $new_comprobante );
	}
	
	$order->add_order_note( $msg, false );
}

$statuses = get_option( 'cb_cancelled_status', [ 'refunded' ] );

if ( ! is_array( $statuses ) ) {
	$statuses = [];
}

foreach ( $statuses as $status ) {
	$cleaned_status = str_replace( 'wc-', '', $status );
	add_action( 'woocommerce_order_status_' . $cleaned_status, 'contabilium_refund_order', 10, 2 );
}

/**
 * Adds new fields
 */
function contabilium_add_dni_fields( $fields ) {
	$user_id         = get_current_user_id();
	$default_dni     = get_option( 'cb_default_dni' );
	$default_type    = get_option( 'cb_default_type' );
	$type_field_name = get_option( 'cb_custom_type_name' );
	$dni_field_name  = get_option( 'cb_custom_dni_name' );
	$country 		 = get_option( 'cb_api_country' , [ 'Argentina' ]);

	if ( empty( $type_field_name ) && empty( $default_type ) ) {
		$fields['billing']['tipo_documento'] = array(
			'label'       => __( 'Tipo Documento', 'woocommerce' ),
			'placeholder' => _x( 'Tipo Documento', 'placeholder', 'woocommerce' ),
			'type'        => 'select',
			'options'     => array(
				'DNI'  => 'DNI',
				'CUIT' => 'CUIT',
			),
			'required'    => true,
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'default'     => get_user_meta( $user_id, 'cb_document_type', true )
		);

		if ($country === 'Uruguay' ) {
			$fields['billing']['tipo_documento']['options'] = array(
				'RUT'	=> 'RUT',
				'CI' 	=> 'CI',
				'PAS'	=> 'PAS',
			);
		}
		if ($country === 'Chile' ) {
			$fields['billing']['tipo_documento']['options'] = array(
				'CI' 	=> 'RUT Persona natural',
				'RUT'	=> 'RUT Empresa',
				'PAS'	=> 'Extranjero',
			);
		}
	}

	if ( empty( $dni_field_name ) && empty( $default_dni ) ) {
		if ($country === 'Argentina' ) {
			$fields['billing']['numero_documento'] = array(
				'label'    => __( 'CUIT o DNI', 'woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'class'    => array( 'form-row-wide' ),
				'clear'    => true,
				'default'  => get_user_meta( $user_id, 'cb_document_number', true )
			);
		}
		if ($country === 'Chile' ) {
			$fields['billing']['numero_documento'] = array(
				'label'    => __( 'RUT', 'woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'class'    => array( 'form-row-wide' ),
				'clear'    => true,
				'default'  => get_user_meta( $user_id, 'cb_document_number', true )
			);
		}
		
		if ($country === 'Uruguay' ) {
			$fields['billing']['numero_documento'] = array(
				'label'    => __( 'RUT o CI', 'woocommerce' ),
				'type'     => 'text',
				'required' => true,
				'class'    => array( 'form-row-wide' ),
				'clear'    => true,
				'default'  => get_user_meta( $user_id, 'cb_document_number', true )
			);
		}
	}

	return $fields;
}

//debug gasgen
if(get_option('wc_add_dni_fields') == "yes") 
{
	add_filter( 'woocommerce_checkout_fields', 'contabilium_add_dni_fields' );
}


function contabilium_validate_dni() {
	contabilium_get_fields_name( $type_field_name, $dni_field_name );
	contabilium_load_defaults_values( $default_type, $default_dni );

	if ( empty( $default_type ) && empty( filter_input( INPUT_POST, $type_field_name ) ) ) {
		wc_add_notice( __( 'Por favor seleccione el tipo de documento.' ), 'error' );
	}
	if ( empty( $default_dni ) && empty( filter_input( INPUT_POST, $dni_field_name ) ) ) {
		wc_add_notice( __( 'Por favor escriba el número del documento.' ), 'error' );
	}
}

if(get_option('wc_add_dni_fields') == "yes") 
{
	add_action( 'woocommerce_checkout_process', 'contabilium_validate_dni' );
}

/**
 * Guarda los datos del usuario. Primero chequea los valores enviados por POST
 * si están vacios se pasan a los valores definidos por defecto.
 *
 * @param integer $order_id Order's ID
 */
function contabilium_save_dni( $order_id ) {
	$user_id = get_current_user_id();
	contabilium_get_fields_name( $type_field_name, $dni_field_name );
	contabilium_load_defaults_values( $default_type, $default_dni );

	if ( $user_id ) {
		if ( ! empty( filter_input( INPUT_POST, $type_field_name ) ) ) {
			update_user_meta( $user_id, 'cb_document_type', sanitize_text_field( $_POST[ $type_field_name ] ) );
		} else {
			if ( ! empty( $default_type ) ) {
				update_user_meta( $user_id, 'cb_document_type', sanitize_text_field( $default_type ) );
			}
		}
		if ( ! empty( filter_input( INPUT_POST, $dni_field_name ) ) ) {
			update_user_meta( $user_id, 'cb_document_number', sanitize_text_field( $_POST[ $dni_field_name ] ) );
		} else {
			if ( ! empty( $default_dni ) ) {
				update_user_meta( $user_id, 'cb_document_number', sanitize_text_field( $default_dni ) );
			}
		}
	} else {
		$order = wc_get_order( $order_id );

		if ( $order && ! empty( filter_input( INPUT_POST, $type_field_name ) ) ) {
			$order->update_meta_data( 'cb_document_type', sanitize_text_field( $_POST[ $type_field_name ] ) );
		} else {
			if ( $order && ! empty( $default_type ) ) {
				$order->update_meta_data( $user_id, 'cb_document_type', sanitize_text_field( $default_type ) );
			}
		}
		if ( $order && ! empty( filter_input( INPUT_POST, $dni_field_name ) ) ) {
			$order->update_meta_data( 'cb_document_number', sanitize_text_field( $_POST[ $dni_field_name ] ) );
		} else {
			if ( $order && ! empty( $default_dni ) ) {
				$order->update_meta_data( 'cb_document_number', sanitize_text_field( $default_dni ) );
			}
		}

		$order->save();
	}
}

function contabilium_get_fields_name( &$type_field_name, &$dni_field_name ) {
	$type_field_name = get_option( 'cb_custom_type_name' );
	$dni_field_name  = get_option( 'cb_custom_dni_name' );

	if ( empty( $type_field_name ) ) {
		$type_field_name = 'tipo_documento';
	}
	if ( empty( $dni_field_name ) ) {
		$dni_field_name = 'numero_documento';
	}
}

function contabilium_load_defaults_values( &$default_type, &$default_dni ) {
	$default_dni  = get_option( 'cb_default_dni' );
	$default_type = get_option( 'cb_default_type' );
}

add_action( 'woocommerce_checkout_update_order_meta', 'contabilium_save_dni' );
