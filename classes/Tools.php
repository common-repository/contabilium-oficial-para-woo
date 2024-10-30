<?php
namespace Contabilium;

class Tools
{
	/**
	 * Updates price for a product
	 *
	 * @param WC_Product $wc_product WooCommerce Product
	 * @param \stdClass  $concepto   Concepto de Contabilium
	 */
	public static function update_product_price( $wc_product, $concepto ) 
	{
		global $wpdb;
		$wc_product;
		$sku = $wc_product->get_sku();
		$original_price = $wc_product->get_regular_price();
		$original_stock = $wc_product->get_stock_quantity();
		$new_price = $original_price;
		$new_stock = $original_stock;

		if ( 'yes' === get_option( 'cb_sync_stock' ) ) {
			$wc_product->set_stock_quantity( $concepto->Stock );
			$new_stock = $concepto->Stock;
		}

		if ( 'yes' === get_option( 'cb_sync_price' ) ) {
			$newPrice = $concepto->Precio;

        	if ( 'yes' === get_option( 'cb_sync_price_with_iva' ) ) {
            	$newPrice = $concepto->PrecioFinal;
        	}
			$wc_product->set_regular_price( $newPrice );
			$new_price = $newPrice;
		}
		
		$table_name = $wpdb->prefix . 'contabilium_log';
  		$date = date('Y-m-d H:i:s');

		$wpdb->insert($table_name, array(
			'sku' => $sku,
			'log_date' => $date,
			'original_price' => $original_price, 'new_price' => $new_price,
			'original_stock' => $original_stock, 'new_stock' => $new_stock,
		));

		$query_old_logs = "
		SELECT id 
		FROM $table_name
		WHERE sku = '$sku' 
		ORDER BY log_date ASC
		";

		$old_logs = $wpdb->get_results($query_old_logs, OBJECT);

		if ($wpdb->num_rows > 10)
		{
			$deleteCount = $wpdb->num_rows - 10;
			$deleteIds = array();

			for ($i = 0; $i < $deleteCount; $i++)
			{
				$deleteIds[] = $old_logs[$i]->id;
			}

			$ids = implode( ',', array_map( 'absint', $deleteIds ) );
			$wpdb->query( "DELETE FROM $table_name WHERE ID IN($ids)" );
		}

		return $wc_product->save();
	}

	public static function isSubmit($field)
    {
        return ( isset( $field ) && self::getValue( "$field" ) !== null ) ? true : false;
    }

	public static function getValue( $field )
    {
		return sanitize_text_field(empty( $_REQUEST[ "$field" ] )) ? null : $_REQUEST["$field"]; 
	}
	
	public static function dieObject( $obj )
	{
		print "<pre>";
		var_export($obj);
		print "</pre>";
		die();
	}

	public static function csvstr($data)
	{
		$f = fopen('php://memory', 'r+');
		foreach ($data as $line) {
            fputcsv($f, $line);
            fseek($f, -1, SEEK_CUR);
            fwrite($f, "\r\n");
        }
		rewind($f);
		$csv_line = stream_get_contents($f);
		return rtrim($csv_line);
	}

	public static function downloadLog()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'contabilium_log';

		$query_logs = "
		SELECT * 
		FROM $table_name
		ORDER BY sku, log_date DESC
		";

		$logs = $wpdb->get_results($query_logs, OBJECT);

		$csv = array(
			array(
				'SKU',
				'Fecha',
				'Precio original',
				'Stock original',
				'Nuevo precio',
				'Nuevo stock',
			),
		);

		foreach ($logs as $entry)
		{
			$csv[] = array(
				$entry->sku,
				$entry->log_date,
				'$' . $entry->original_price,
				$entry->original_stock,
				'$' . $entry->new_price,
				$entry->new_stock,
			);
		}

		$filename = date('y_m_d_G_i_s') . '_log.csv';
		$csv = self::csvstr($csv);

		header('Content-Disposition: attachment; filename=' . $filename);
		header("Content-type: text/csv");
		ob_clean();
		flush();
		echo $csv;
		exit;
	}

	//Genera una key de 40 caracteres aleatorios
	
	  
}