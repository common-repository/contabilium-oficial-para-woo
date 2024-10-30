<?php
namespace Contabilium;

class CbApi
{
    private static $instance_of_api;
    
    private $client_id;
    private $client_secret;
    
    public  $last_error = false;
    public  $token = false;

    private function __construct( $client_id, $client_secret )
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
    }

    public static function getInstance( $client_id, $client_secret ) 
    {
        if( !isset( self::$instance_of_api ) ) {
            self::$instance_of_api = new CbApi( $client_id, $client_secret );
        }

        return self::$instance_of_api;
    }

    public static function getApiUrl($endpoint)
    {
        $country = get_option( 'cb_api_country' , [ 'Argentina' ]);

        $baseUrl = ContabiliumRoutes::URL['IPN'][$country];
        
        return $baseUrl . '/' . $endpoint;
    }

    public function getAuth( )
    {
        $this->last_error = false;
        $this->token = get_transient( 'contabilium_access_token' );

        if ($this->token === false) {
            $response = wp_remote_post(
                self::getApiUrl('token'),
                array(
                    'headers' => array(
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Accept'       => 'application/json'
                    ),
                    'body' => array(
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $this->client_id,
                        'client_secret' => $this->client_secret
                    )
                )
            );

            $this->token = false;
            if ( wp_remote_retrieve_response_code( $response ) == 200 ) {
                $obj = json_decode( wp_remote_retrieve_body( $response ) );
                if (!is_object( $obj ) || (is_object( $obj ) && empty($obj->access_token))) {
                    delete_transient( 'contabilium_access_token' );
                } else {
                    set_transient( 'contabilium_access_token', $obj->access_token, 60 * 60 * 5 );
                    $this->token = $obj->access_token;
                }
            } else {
                delete_transient( 'contabilium_access_token' );
                $obj = json_decode( wp_remote_retrieve_body( $response ) );
                if (is_object($obj) && isset($obj->error)) {
                    $this->last_error = $obj->error;
                } else { 
					$this->last_error = 'Error al conectar a Contabilium. Detalle: <pre>' . print_r($response, true).'</pre>';
				}
            }
        }

        return $this->token;
    }

    public function postRequest( $url, $post_data )
    {
        $response = wp_remote_post(
            $url,
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAuth()
                ),
                'body' => json_encode($post_data)
            )
        );
        
        return [wp_remote_retrieve_response_code( $response ), json_decode( wp_remote_retrieve_body( $response ))];
    }
}




