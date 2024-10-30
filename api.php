<?php

use Contabilium\CbApi;
use Contabilium\Concept;
use Contabilium\Tools;
use Contabilium\WooApi;


add_action( 'rest_api_init', function () {
    register_rest_route( 'wp/v2', '/webhook/(?P<integration_id>\S+)', array(
        'methods'  => 'POST',
        'callback' => 'contabilium_delete_webhook',
        'permission_callback' => '__return_true',
    ) );
} );

function contabilium_delete_webhook( WP_REST_Request $request, $print_response = true ) {
   $integration_id = urldecode($request->get_param( 'integration_id' ));
   $body = $request->get_json_params();
   $consumer = $body['ConsumerKey'];
   $secret = $body['SecretKey'];
   if( !isIntegrationValid($integration_id, $consumer, $secret )) {
       contabilium_handle_error('No se pudo eliminar correctamente el webhook asociado a la integracion '.$integration_id.'.', 404);
   }

    WooApi::disableWebhooks();
    $data = [
        'msg' => 'Se elimino correctamente el webhook asociado a la integracion '.$integration_id.'.',
    ];
    wp_send_json_success( $data );


}

function isIntegrationValid( string $integration_id, $consumer, $secret )
{
    if( get_option('cb_api_integration') === $integration_id && isValidCredentials($consumer, $secret) ) {
        return true;
    }
    return  false;
}

function isValidCredentials($consumer_key, $secret)
{
    global $wpdb;

    $consumer_key = wc_api_hash( sanitize_text_field( $consumer_key ) );

    $keys = $wpdb->get_row( $wpdb->prepare( "
		SELECT key_id, user_id, permissions, consumer_key, consumer_secret, nonces
		FROM {$wpdb->prefix}woocommerce_api_keys
		WHERE consumer_key = '%s' AND consumer_secret = '%s'
	", $consumer_key, $secret ), ARRAY_A );

    if ( empty( $keys ) ) {
       return false;
    }

    return true;
}

function contabilium_handle_error( $error, $code = 500 ) {
	$data = array(
		'msg' => $error,
	);

	wp_send_json_error( $data, $code );
	wp_die();
}
