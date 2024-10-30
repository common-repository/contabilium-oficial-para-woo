<?php
namespace Contabilium;
use \WC_Webhook;


/**
 * Class WooApi. Habilita y desabilita la API REST y los webhooks de WooCommerce para Contabilium
 *  @author Contabilium
 */
class WooApi
{
    private $webhook_delivery_url;
	private $credential_delivery_url;
	private $webhooks_list;
	
    /**
	 * Constructs a new WebhookHandler instance.
	 *
	 * Inicializar webhook delivery URL, credential delivery URLy webhook list.
	 *
	 * @return void
	 */
    private function __construct(  )
    {
		$this->webhook_delivery_url = ContabiliumRoutes::URL['IPN'][get_option('cb_api_country')] .'/notificador/wordpress';
		$this->credential_delivery_url = ContabiliumRoutes::URL['Rest'][get_option('cb_api_country')] . '/parametros/integracion/woocommerce';
		/**
		 * Descomentar/comentar para agregar/quitar webhooks.
		 */
		$this->webhooks_list = array(
			//'order_created' => 'order.created',
			//'order_updated' => 'order.updated',
			//'order_deleted' => 'order.deleted',
			'product_created' => 'product.created',
			'product_updated' => 'product.updated',
			'product_deleted' => 'product.deleted',
			// 'order_restored' => 'order.restored',
			// 'product_restored' => 'product.restored'
			// 'coupon_created'   => 'coupon.created'
			// 'coupon_updated'   => 'coupon.updated'
			// 'coupon_deleted'   => 'coupon.deleted'
			// 'coupon_restored'  => 'coupon.restored'
			// 'customer_created' => 'customer.created'
			// 'customer_updated' => 'customer.updated'
			// 'customer_deleted' => 'customer.deleted'
		);

    }

    /**
	 * Habilita la API REST de WooCommerce para Contabilium.
	 * 
	 * @return array Un arreglo que contiene el estado de la operación y un mensaje.
	*/
    public static function enableApiRest() {
		global $wpdb;
        $response = [];
		$user = self::current_user_id();
		$c_key = "ck_" . self::contabilium_key_generator();
		$c_secret = "cs_" . self::contabilium_key_generator();
		$result = $wpdb->insert(
		$wpdb->prefix . "woocommerce_api_keys",
		array(
			"user_id" => $user,
			"description" => "contabilium_key",
			"permissions" => "read_write",
			"consumer_key"=> wc_api_hash($c_key),
			"consumer_secret" => $c_secret,
			"truncated_key" => substr($c_key, -7)
		)
		);

        return [$c_key, $c_secret];
	}

	/**
	 * Habilita los Webhooks de WooCommerce para Contabilium.
	 * 
	 * @return array Un arreglo que contiene el estado de la operación y un mensaje.
	*/
	public static function enableWebhooks($idIntegracion) {
		global $wpdb;
		$response = [];
		$user = self::current_user_id();
		$url = self::getWebhookDeliveryUrl();
		$webhook_list = self::getWebhooksList();

		try {
            foreach ($webhook_list as $key => $value) {
                $webhook = new \WC_Webhook;
                $webhook->set_name('contabilium_' . $key);
                $webhook->set_user_id($user); 
                $webhook->set_topic( $value );
                $webhook->set_secret( 'mi_secret_key' ); //enviar el apikey
                $webhook->set_delivery_url( $url ); 
                $webhook->set_status( 'active' ); 
                $save = $webhook->save();
            }
			$response = array(
                'status' => true,
                'msg'   => 'WebHook creado exitosamente'
            );
        } catch (Exception $e) {
            $response = array(
                'status' => false,
                'msg'   => 'No se pudo crear el Webhook'
            );
        }
		
		return $response;
	}
	
	/**
	 * Deshabilita la API REST de WooCommerce para Contabilium.
	 * 
	 * @return array Un arreglo que contiene el estado de la operación y un mensaje.
	*/
	public static function disableApiRest() {
		global $wpdb;
		$result = $wpdb->delete(
		$wpdb->prefix . "woocommerce_api_keys", array( "description" => "contabilium_key" ) 
		);
		if ($result > 0)
		{
			$response = array(
                'status' => true,
                'msg'   => 'Las credeciales fueron revocadas con exito'
            );
		}else{
			$response = array(
                'status' => false,
                'msg'   => 'No se pudieron revocar las credenciales'
            );
		}
		return $response;

	}
	/**
	 * Deshabilita los Webhooks de WooCommerce para Contabilium.
	 * 
	 * @return array Un arreglo que contiene el estado de la operación y un mensaje.
	*/
	public static function disableWebhooks(){
		global $wpdb;
		$webhook_list = self::getWebhooksList();

        try {
            foreach ($webhook_list as $key => $value) {
                $wpdb->delete(
                    $wpdb->prefix . "wc_webhooks", array( "name" => "contabilium_" . $key ) 
                );
            }
            $response = array(
                'status' => true,
                'msg'   => 'Webhooks eliminados permanentemente'
            );
        } catch (Exceptio $e) {
            $response = array(
                'status' => false,
                'msg'   => 'No se pudieron eliminar los webhooks'
            );
        }
		return $response;
	}


	/**
	 * Genera una clave aleatoria de 40 caracteres en formato hexadecimal.
	 *
	 * @return string La clave generada.
	*/
    public static function contabilium_key_generator() {
		return bin2hex(random_bytes(20));
	}

	/**
	 * Envia las credenciales al endpoint de Contabilium para sincronizar con el ERP.
	 *
	 * @return array Un arreglo que contiene el estado de la operación y un mensaje segun el status code.
	*/
	public static function sendCredentials(string $ck,string $cs) {
		$url = self::getCredentialsDeliveryUrl();
		$domain = "http://" . $_SERVER['HTTP_HOST'];
		$response = [];
        $version = get_option('plugin_version');
		$data = array(
		  'IDIntegracion'	=>	intval( get_option( 'cb_api_integration' ) ),
		  'ConsumerKey' 	=> 	$ck,
		  'SecretKey' 		=> 	$cs,
		  'AccountName'		=> 	wp_get_current_user()->display_name, //$_SERVER['HTTP_HOST'],
		  'Url'				=> 	$domain,
          'Version'         =>  $version
		);

		$json_data = json_encode($data);
		$curl = curl_init();

		curl_setopt_array($curl, [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => $json_data,
		CURLOPT_HTTPHEADER => [
			"Accept: */*",
			"Content-Type: application/json",
		],
		]);

		$result = curl_exec($curl);
		$err = curl_error($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		
		if ($err) {
			$response = array(
				'status' => false,
				'msg'   => $err //gasgen: corrijo, habian puesto $error en lugar de $err
				);
			return $response;
		} else if ($http_code == 200 )
		{
			$response = array(
                'status' => true,
                'msg'   => 'Credenciales enviadas exitosamente.'
            );
		} else if ($http_code == 409)  { //gasgen: corrijo, habian puesto este http code junto al 200
			$response = array(
                'status' => true,
                'msg'   => 'La integración ya existe en Contabilium.'
            );
		} else if ($http_code == 400 ){
			$response = array(
                'status' => false,
                'msg'   => 'Los datos de las credenciales son incorrectos'
            );
		}else if ($http_code == 404 ){
			$response = array(
                'status' => false,
                'msg'   => 'Id de integracion inexistente'
            );
		}else{
			$response = array(
                'status' => false,
                'msg'   => 'Error code: ' . $http_code
			);
		}
		
		return $response;
		
	}

	/**
	 * Devuelve el ID del usuario actual de WordPress.
	 *
	 * @return int El ID del usuario actual, o 0 si no hay usuario autenticado.
	*/
    public static function current_user_id() {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return 0;
		}
		$user = wp_get_current_user();
		return ( isset( $user->ID ) ? (int) $user->ID : 0 );
	}


	/**
	 * Devuelve la URL de entrega de Webhooks de WooCommerce.
	 *
	 * @return string La URL de entrega de Webhooks.
	*/
    private static function getWebhookDeliveryUrl() {
        $url = new WooApi();
        return $url->webhook_delivery_url;
    }

	/**
	 * Devuelve la URL de entrega de credenciales de WooCommerce.
	 *
	 * @return string La URL de entrega de credenciales.
	*/
	private static function getCredentialsDeliveryUrl() {
        $url = new WooApi();
        return $url->credential_delivery_url;
    }

	/**
	 * Devuelve el listado de Webhooks de WooCommerce disponibles.
	 *
	 * @return array Array de los webhooks disponibles.
	*/
	private static function getWebhooksList() {
        $wh = new WooApi();
        return $wh->webhooks_list;
    }

}
