<?php
namespace Contabilium;

use mysql_xdevapi\Exception;
use Contabilium\CbApi;

class Concept 
{
	public static function updateWcProductById( $id ) {
		if ( self::updateWcProductDetailsById( $id ) ) {
			return true;
		} else {
			return false;
		}
	}

	public static function updateWcProductDetailsById( $data ) {
		update_post_meta( $data["wc_id"], '_price', $data["price"] );
		update_post_meta( $data["wc_id"], '_stock', $data["stock"] );

		return true;
	}


	public static function syncOneForUpdate( $sku ) {
		$products = Concept::getWcProductsBySKU( $sku );

		if (!$products) { 
			cb_message('No se encontraron productos con el sku ' . $sku, 'error');
			return;
		}

		$concepto = self::getByCodigo( $sku );

		foreach ($products as $wcprd) {
			try {
				if ( Tools::update_product_price( $wcprd, $concepto ) ) {
					if ( 'variable' === $wcprd->get_type() ) {
						$variations = $wcprd->get_available_variations();
	
						if ( ! empty( $variations ) ) {
							foreach ( $variations as $variation ) {
								if ( ! empty( $variation['sku'] ) && $variation['sku'] != $sku ) { // SKIP CURRENT SKU
									$child_product = wc_get_product( $variation['variation_id'] );
									if ( $child_product ) {
										$updated_child = Tools::update_product_price( $child_product, $concepto );
									}
								}
							}
						}
					}
				}
			} catch ( \Exception $e ) {
				cb_message($e->getMessage(), 'error');
				return;
			}
		}
		
		if ( 'yes' === get_option( 'cb_sync_stock' ) ) {
			$stock = $concepto->Stock;
		} else {
			$stock = 'No sincronizado por config.';
		}

		if ( 'yes' === get_option( 'cb_sync_price' ) ) {
			$newPrice = $concepto->Precio . " (sin IVA incluido)";

        	if ( 'yes' === get_option( 'cb_sync_price_with_iva' ) ) {
            	$newPrice = $concepto->PrecioFinal . " (con IVA incluido)";
        	}
		} else {
			$newPrice = 'No sincronizado por config.';
		}

		cb_message('Se ha actualizado correctamente el producto "' . $sku . '". Precio: ' . $newPrice . ' -- Stock: ' . $stock, 'success');
		return;
	}

	public static function getWcProductBySKU( $sku ) {
		global $wpdb;

		try {
			$product_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku ) );

			return $product_id ? wc_get_product( $product_id ) : null;
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
	}

	public static function getWcProductsBySKU( $sku ) {
		global $wpdb;

		try {
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s'", $sku ) );
			
			$products = [];
			foreach ($results as $product) {
				$products[] = wc_get_product($product->post_id);
			}
			
			return empty($products) ? null : $products;
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
	}
}