<?php

/*
Plugin Name: Contabilium Oficial para Woo
Plugin URI:  https://contabilium.com/
Description: Conector de integración a la API de Contabilium. Sincronice su stock, precios y ventas con Contabilium.
Version:     3.0.0
Author:      contabilium
Author URI:  https://contabilium.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: contabilium-oficial-woo
Domain Path: /languages
*/

require_once(dirname(__FILE__) . '/classes/ContabiliumRoutes.php');

require(dirname(__FILE__) . '/classes/CbApi.php');
require(dirname(__FILE__) . '/classes/Concept.php');
require(dirname(__FILE__) . '/classes/Tools.php');
require(dirname(__FILE__) . '/classes/WooApi.php');

date_default_timezone_set('America/Argentina/Buenos_Aires');

use Contabilium\CbApi;
use Contabilium\Concept;
use Contabilium\Tools;
use Contabilium\WooApi;
use Contabilium\ContabiliumRoutes;

defined('ABSPATH') or die('¡Acceso prohibido! Su dirección IP ha sido reportada');

global $woocommerce;

$payment_methods = [
	'Efectivo'    => 'Efectivo',
	'Cheque'      => 'Cheque',
	'MercadoPago' => 'MercadoPago',
	'Transferencia' => 'Transferencia',
	'Cuenta corriente' => 'Cuenta corriente',
];

function create_plugin_database_table()
{
    global $wpdb;

	$table_name = $wpdb->prefix . 'contabilium_log';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS `". $table_name . "` ( ";
	$sql .= "  `id`  int(11)   NOT NULL auto_increment, ";
	$sql .= "  `sku`  varchar(100)   NOT NULL, ";
	$sql .= "  `log_date`  datetime   NOT NULL, ";
	$sql .= "  `original_price`  decimal(19,4)   NOT NULL, ";
	$sql .= "  `new_price`  decimal(19,4)   NOT NULL, ";
	$sql .= "  `original_stock`  double   NOT NULL, ";
	$sql .= "  `new_stock`  double   NOT NULL, ";
	$sql .= "  PRIMARY KEY `log_id` (`id`) "; 
	$sql .= ") $charset_collate;";
	
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta($sql);
}

function purge_plugin_database()
{
    delete_option( 'cb_api_client_id' );
	delete_option( 'cb_api_client_secret' );
	delete_option( 'cb_api_country' );
	delete_option( 'cb_api_integration' );
	delete_option( 'cb_sync_price_with_iva' );
	delete_option( 'cb_version' );
	delete_option( 'cb_accepted_status' );
	delete_option( 'cb_cancelled_status' );
	delete_option('cb_credenciales_enviadas');
	update_option( 'cb_uninstalled_at', date('Y-m-d H:i:s') );

	WooApi::disableApiRest();
	WooApi::disableWebhooks();
}

register_activation_hook( __FILE__, 'create_plugin_database_table' );

register_uninstall_hook( __FILE__, 'purge_plugin_database' );


/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins') ) ) ) {
	wp_cache_delete( 'alloptions', 'options' );

	if ( defined( 'cb_api_client_id' ) == null || defined( 'cb_api_client_secret' ) == null ) {
		add_option( 'cb_api_client_id', get_option( 'user_email' ) );
		add_option( 'cb_api_client_secret', '' );
	}

	function woocommerce_contabilium() 
	{
		return true;
	}

	function overrule_webhook_disable_limit( $limit ) {
		return 999999999;
	}

	function contabilium_main_menu() {
		add_menu_page( 'Configuración', 'Contabilium', 'manage_options', 'contabilium_main_menu', 'contabilium_config_page_html', plugin_dir_url( __FILE__ ) . 'images/logo-icon.svg', 20 );
	}
	add_action( 'admin_menu', 'contabilium_main_menu' );

	add_filter( 'woocommerce_webhook_payload', 'product_payload_edit', 10, 4 );

	add_filter('woocommerce_max_webhook_delivery_failures', 'overrule_webhook_disable_limit');

	function product_payload_edit( $payload, $resource, $resource_id, $id ){
		if ( $resource !== 'product' ) {
			return $payload;
		}

		$integration_id = get_option('cb_api_integration');
		$country = 'ar';
		if(strtolower(get_option('cb_api_country')) == 'chile') {
			$country = 'cl';
		}  else if(strtolower(get_option('cb_api_country')) == 'uruguay') {
			$country = 'uy';
		}

		$payload['IDIntegracion'] = $integration_id;
		$payload['Pais'] = $country;

		return $payload;
	}

	function showNewInstallMessage() {
		return "<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" align=\"center\" style=\"background:#f9f9f9;border-collapse:collapse;line-height:100%!important;margin:0;padding:0;width:100%!important\" bgcolor=\"#f9f9f9\">
		<tbody>
			<tr>
				<td>
					<table style=\"border-collapse: collapse; margin: auto; max-width: 635px; min-width: 320px; width: 100%\">
						<tbody>
							<tr>
								<td valign=\"top\">
									<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"border-collapse: collapse; color: #c0c0c0; font-family: 'Helvetica Neue' ,Arial,sans-serif; font-size: 13px; line-height: 26px; margin: 0 auto 26px; width: 100%\">
									</table>
								</td>
							</tr>
							<tr>
								<td valign=\"top\" style=\"padding: 0 20px\">
									<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" align=\"center\" style=\"background-clip: padding-box; border-collapse: collapse; border-radius: 3px; color: #545454; font-family: 'Helvetica Neue' ,Arial,sans-serif; font-size: 13px; line-height: 20px; margin: 0 auto; width: 100%\">
									</table>
									<table cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-clip: padding-box; border-collapse: collapse; border-color: #dddddd; border-radius: 0 0 3px 3px; border-style: solid; border-width: 0 1px 1px; width: 100%;padding: 0px 10px 10px\">
										<tbody>
											<!--<tr>
												<td style=\"background-color: #f2f2f2;\" align=\"center\">
													<img src=\"https://app.contabilium.com/images/mails/Contabilium_logo_horizontal.png\" width=\"50%\" height=\"50%\">
												</td>
											</tr>-->
											<tr>
												<td style=\"background: white; background-clip: padding-box; border-radius: 0 0 3px 3px; color: #525252; font-family: 'Helvetica Neue' ,Arial,sans-serif; font-size: 15px; line-height: 22px; overflow: hidden; padding: 0px 0px 15px\" bgcolor=\"white\">
													<div style=\"margin-bottom: 16px; margin-top: 0; padding-top: 0; text-align: left!important\" align=\"left\">
														<img src=\"https://app.contabilium.com/images/mails/wordpress_banner_v3.png\" style=\"max-width: 100%\">
													</div>
													<div style=\"padding-left: 20px;padding-right: 10px;\">
														<div align=\"center\">
															<h2>Conecta tu Plugin con Contabilium</h2>
														</div>
														<div align=\"center\">
														</div>
														<div style=\"font-size:12px;\">
															<p>¡Hola!</p>
															<p>Para aprovechar al máximo tu plugin, es necesario conectarlo con tu cuenta de Contabilium.</p>
															<p>Revisa el Tutorial <a href=\"https://contabilium.zendesk.com/hc/es/articles/25185858427795\">Aquí</a></p>
														</div>
													</div>
													<div align=\"center\">
														<div>
														</div>
														<p style=\"font-size: 16px;\">Equipo de Contabilium <br>
															<b style=\"color: #FF2569;\">Tu empresa, crece.</b>
														</p>
													</div>
													</div>
													</div>
												</td>
											</tr>
										</tbody>
									</table>
								</td>
							</tr>
						</tbody>
					</table>
				</td>
			</tr>
			<tr>
				<td valign=\"top\" height=\"20\"></td>
			</tr>
		</tbody>
	</table>
	</td>
	</tr>
	</table>";
	}

	function contabilium_config_page_html() 
	{
		wp_enqueue_script( 'jquery' );

		// check user capabilities
		if ( !current_user_can('manage_options') ) {
			return;
		}

		if( (int)get_version_plugin() >= 3 ) {

			global $wpdb;
			if(!get_option('cb_api_integration')){
				if(isset($_GET['type']) && $_GET['type'] == 'connection') {
					$IDIntegracion = isset($_GET['IDIntegracion']) ?  $_GET['IDIntegracion'] : null;
					$Email = isset($_GET['Email']) ?  $_GET['Email'] : null;
					$ApiKey = isset($_GET['ApiKey']) ?  $_GET['ApiKey'] : null;
					$redirect_url = isset($_GET['redirect_url']) ?  $_GET['redirect_url'] : null;
					$country = isset($_GET['country']) ? $_GET['country'] : null;
		
					
					if (
						empty( $IDIntegracion ) || $IDIntegracion === 0 || 
						empty($Email) || empty($ApiKey) || 
						empty($redirect_url) ||
						empty($country)
					) {
						exit('Error en los parametros de conexión.');
					}
		
					update_option('cb_api_country', ucfirst(strtolower($country)));
		
					/*if($IDIntegracion != get_option('cb_api_integration')) {
						contabilium_enable_api($IDIntegracion);
					}*/
		
					update_option('cb_api_client_id',  $Email);
					update_option('wc_api_integration', $Email);
					update_option('cb_api_integration', $IDIntegracion);
					update_option('wc_api_integration', $IDIntegracion);
					update_option('cb_api_client_secret',  $ApiKey);
					update_option('wc_api_client_secret',  $ApiKey);
					update_option('cb_sync_price_with_iva', 'yes');
					update_option('cb_version', get_version_plugin());
					update_option( 'cb_installed_at', date('Y-m-d H:i:s') );
					
					$keys = WooApi::enableApiRest();
					WooApi::enableWebhooks($IDIntegracion);

					$c_key = $keys[0];
					$c_secret = $keys[1];
		
					$base_url = ContabiliumRoutes::URL['Base'][get_option('cb_api_country')];
		
					$url = $base_url . "/modulos/ventas/integraciones.aspx?tipoIntegracion=WordPress&cs_key=".$c_key."&cs_secret=".$c_secret."&apikey=".$ApiKey."&email=".$Email."&version=" . get_version_plugin() . "&url=" . get_site_url() . "&idintegracion=".$IDIntegracion;
					
					update_option('cb_credenciales_enviadas', 'yes');

					header('Location: ' . $url);
				}
				echo showNewInstallMessage();
				exit;
			}

			$table_name = $wpdb->prefix . 'woocommerce_api_keys';

			$keys_exists = $wpdb->get_row("
					SELECT count(1) as count
					FROM " . $table_name . "
					WHERE description = 'contabilium_key' ", ARRAY_A );
					
			$url_post  = ContabiliumRoutes::URL['Rest'][get_option('cb_api_country')];
			
			if((int) $keys_exists["count"] == 0) {
				delete_option('cb_credenciales_enviadas');
				$keys = WooApi::enableApiRest();
				$enableWh 	= 	WooApi::enableWebhooks(get_option('cb_api_integration'));

				$c_key = $keys[0];
				$c_secret = $keys[1];

				wp_remote_post(
					$url_post ."/parametros/integracion/woocommerce/update",
					array(
						'headers' => array(
							'Content-Type'  => 'application/json'
						),
						'body' => json_encode([
							"Url" => "https://" . $_SERVER['SERVER_NAME'],
							"IDIntegracion" => get_option('cb_api_integration'),
							"Version" => get_version_plugin(),
							"SecretKey" => $c_secret,
							"ConsumerKey" => $c_key
						])
					)
				);
				update_option('cb_credenciales_enviadas', 'yes');
			} else {
				if(!get_option('cb_credenciales_enviadas') || get_option('cb_credenciales_enviadas') == '') {
					WooApi::disableApiRest();
					WooApi::disableWebhooks();

					$keys = WooApi::enableApiRest();
					WooApi::enableWebhooks(get_option('cb_api_integration'));

					$c_key = $keys[0];
					$c_secret = $keys[1];

					wp_remote_post(
						$url_post ."/parametros/integracion/woocommerce/update",
						array(
							'headers' => array(
								'Content-Type'  => 'application/json'
							),
							'body' => json_encode([
								"Url" => "https://" . $_SERVER['SERVER_NAME'],
								"IDIntegracion" => get_option('cb_api_integration'),
								"Version" => get_version_plugin(),
								"SecretKey" => $c_secret,
								"ConsumerKey" => $c_key
							])
						)
					);

					update_option('cb_credenciales_enviadas', 'yes');
				}
			}
		}

		$countries = array(
			'Argentina' => 'Argentina',
			'Chile' => 'Chile',
			'Uruguay'	=> 'Uruguay',
		);


		if (Tools::isSubmit('submit')) {
			delete_transient( 'contabilium_access_token' ); // borro el cache del token para forzar reconectar a la API suponiendo que el submit es porque se cambio el user o el access key

			update_option('cb_api_client_id',  sanitize_email( empty($_POST["wc_api_client_id"]) ) ? null : $_POST["wc_api_client_id"]);
			update_option('cb_api_client_secret', sanitize_key( empty($_POST["wc_api_client_secret"]) ) ? null : $_POST["wc_api_client_secret"]);

            if ($_POST["wc_api_integration"] != 0 && $_POST["wc_api_integration"] != get_option('cb_api_integration')) {
				
                update_option('cb_api_integration', $_POST["wc_api_integration"]);
                // Activar API y Webhooks
                update_option('woocommerce_api_enabled', 'yes');
                contabilium_enable_api(get_option('cb_api_integration'));
            }

			update_option('plugin_version', get_version_plugin());
			update_option('cb_sync_price', filter_input(INPUT_POST, 'wc_sync_price'));
			update_option('cb_sync_price_with_iva', 'yes');
			update_option('cb_sync_stock', filter_input(INPUT_POST, 'wc_sync_stock'));

			
			update_option('wc_add_dni_fields', filter_input(INPUT_POST, 'wc_add_dni_fields'));

			update_option('cb_cancelled_status', sanitize_text_field( isset($_POST['wc_contabilium_cancelled_status']) ) ? $_POST['wc_contabilium_cancelled_status'] : []);
			update_option('cb_accepted_status', sanitize_text_field( isset($_POST['wc_contabilium_accepted_status']) ) ? $_POST['wc_contabilium_accepted_status'] : []);
			
			update_option('cb_api_country', sanitize_text_field( isset($_POST['wc_contabilium_api_country']) ) ? $_POST['wc_contabilium_api_country'] : []);

			cb_message('La configuración se actualizó correctamente', 'success');
		}

		if ( get_option( 'cb_api_client_id' ) && get_option( 'cb_api_client_secret' ) ) {
			$api = CbApi::getInstance( get_option( 'cb_api_client_id' ), get_option( 'cb_api_client_secret' ) );
			$api->getAuth();
		}
		
		$statuses = wc_get_order_statuses();
		
		?>
		
		<div class="wrap">
			<div class="cb_container">
				<div class="cb_heading"><?php echo esc_html(get_admin_page_title())." - V".get_version_plugin(); ?></div>
				<form action="" method="post">
					<input type="hidden" name="action" value="updatesettings"/>
					<?php wp_nonce_field('add-user', '_wpnonce_add-user') ?>
					<table class="form-table">
						<thead></thead>
						<tbody>
						<tr class="form-field form-required">
							<th scope="row">
								<label for="api_country">
									<?php echo __('País', 'contabilium'); ?>
								</label>
							</th>
							<td>
								<select name="wc_contabilium_api_country" id="api_country">
									<?php
									$country = get_option('cb_api_country', [ 'Argentina' ]);
									?>
									<?php foreach ($countries as $key => $label) : ?>
										<option value="<?php echo $key; ?>" <?php echo $key === $country ? 'selected="selected"' : ''; ?>>
											<?php echo $label; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="form-field form-required">
							<th scope="row">
								<label><?php echo esc_html('Email', 'woocommerce') ?>
									<span class="description"><?php esc_html_e('(required)'); ?></span>
								</label></th>
							<td><input type="email" required name="wc_api_client_id" id="wc_api_client_id" value="<?php echo sanitize_email( get_option('cb_api_client_id') ); ?>" autocapitalize="none" autocorrect="off" maxlength="60" /></td>
						</tr>
						<tr class="form-field form-required">
							<th scope="row">
								<label><?php echo esc_html('Api Key', 'woocommerce'); ?>
									<span class="description"><?php esc_html_e('(required)'); ?></span>
								</label>
							</th>
							<td>
								<input type="text" required name="wc_api_client_secret" id="wc_api_client_secret" value="<?php echo sanitize_key( get_option('cb_api_client_secret') ); ?>" />
							</td>
						</tr>
						<tr class="form-field form-required">
							<th scope="row">
								<label><?php echo esc_html('ID de Integración', 'woocommerce') ?> 
									<span class="description"><?php esc_html_e('(required)'); ?></span>
								</label>
							</th>
							<td><input type="text" required name="wc_api_integration" id="wc_api_integration" value="<?php echo absint( get_option('cb_api_integration') ); ?>" /></td>
						</tr>
						

						<!--<tr class="form-field">
							<th scope="row"><label
									for=""><?php //echo __('Estado de conexión', 'contabilium') ?> </label></th>
							<td>
							<?php /*if (isset($api)) {
								if (is_object($api) && $api->last_error !== false ) {
									cb_message($api->last_error, 'error');
								} elseif (is_object($api) && $api->last_error === false ) {
									cb_message('Conectado a la API', 'success');
								} else {
									cb_message('Upsss! algo no funcionó bien al conectar a Contabilium', 'warning');
								}
							} else {
								cb_message('Favor de ingresar API Key', 'error');
							} */?>
							</td>
						</tr>-->

                        <!--<tr class="form-field">
							<th scope="row">
								<label for="wc_sync_price"><?php echo esc_html('Sincronizar precios', 'contabilium') ?> </label>
							</th>
							<td>
								<input type="checkbox" name="wc_sync_price" id="wc_sync_price" value="yes" <?php echo 'yes' === get_option('cb_sync_price') ? 'checked' : '' ?>/>
							</td>
						</tr>

						<tr class="form-field">
							<th scope="row">
								<label for="wc_sync_price_with_iva"><?php echo esc_html('Utilizar precio con IVA incluido', 'contabilium') ?> </label>
							</th>
							<td>
								<input type="checkbox" name="wc_sync_price_with_iva" id="wc_sync_price_with_iva" value="yes" <?php echo 'yes' === get_option('cb_sync_price_with_iva') ? 'checked' : 'yes' ?>/>
							</td>
						</tr>-->
                        <!--<tr class="form-field">
							<th scope="row">
								<label for="wc_sync_stock">
									<?php echo __('Sincronizar stock', 'contabilium'); ?>
								</label>
							</th>
							<td><input type="checkbox" name="wc_sync_stock" id="wc_sync_stock"
									value="yes" <?php echo 'yes' === get_option('cb_sync_stock') ? 'checked' : ''; ?>/>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row">
								<label for="wc_activar_api">
									<?php echo __('Activar API REST', 'contabilium'); ?>
								</label>
							</th>
							<td><input type="checkbox" name="wc_activar_api" id="wc_activar_api"
									value="yes" <?/*php echo 'yes' === get_option('woocommerce_api_enabled') ? 'checked' : ''; */?>/>
							</td>
						</tr>-->
						<tr>
							<th>
								<label for="accepted_status">
									<?php echo __('Pedidos aceptados', 'contabilium'); ?>
								</label>
							</th>
							<td>
								<select name="wc_contabilium_accepted_status[]" id="accepted_status" multiple>
									<?php
									$status = get_option('cb_accepted_status', [ 'completed' ]);

									if (! is_array($status)) {
										$status = [];
									}
									?>
									<?php foreach ($statuses as $key => $label) : ?>
										<option value="<?php echo $key; ?>" <?php echo in_array($key, $status) ? 'selected="selected"' : ''; ?>>
											<?php echo $label; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th>
								<label for="cancelled_status">Pedidos cancelados</label>
							</th>
							<td>
								<select name="wc_contabilium_cancelled_status[]" id="cancelled_status" multiple>
									<?php
									$status = get_option('cb_cancelled_status', [ 'refunded' ]);

									if (! is_array($status)) {
										$status = [];
									}
									?>
									<?php foreach ($statuses as $key => $label) : ?>
										<option value="<?php echo $key; ?>" <?php echo in_array($key, $status) ? 'selected="selected"' : ''; ?>>
											<?php echo $label; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="form-field">
						    
							<?php $value =  get_option('wc_add_dni_fields'); ?>
							<th scope="row"><label
									for="wc_sync_price_with_iva"><?php echo __('Identificación en checkout', 'contabilium') ?> </label></th>
							<td>

							<input 
									type="checkbox" 
									name="wc_add_dni_fields"
									id="wc_add_dni_fields"
									value="yes" 
									<?php echo $value === "yes" ? 'checked' : '' ?>
								/>
									<p>Solicitar tipo y número de documento en checkout</p>
							</td>
						</tr>
                        <!--<tr class="form-field">
							<td colspan="2">
								<h3>URL para ser configurada en Contabilium.com</h3>
							</td>
						</tr>
						<tr>
							<th>Callback URL</th>
							<td>
								<input type="text" id="callback" name="callback_url" readonly="readonly" value="<?php esc_url( bloginfo('url') ); ?>/wp-json/wp/v2/contabilium/" style="width: 80%">
							</td>
						</tr>-->
						</tbody>
						<tfooter>
							<th scope="row">
							</td>
							<td><?php submit_button('Guardar'); ?></td>
						</tfooter>
					</table>
				</form>
			</div>
			<?php
			if ( Tools::getValue( 'download_log' ) ) {
				Tools::downloadLog();
			}
			?>
			
			<?php if (isset($api) && $api->getAuth()): ?>
			<?php
			if ( Tools::getValue( 'proceed_single' ) && Tools::getValue( 'item_sku' ) ) {
				Concept::syncOneForUpdate( Tools::getValue( 'item_sku') );
			} elseif ( Tools::getValue( 'proceed_full') ) {
				//Concept::syncAllForUpdate();
			}
			?>
			<!-- <a id="#sync_form"></a>
			<div class="cb_container">
				<form action="admin.php?page=contabilium_main_menu#sync_form" method="post">
					<div class="cb_heading">
						Sincronizaci&oacute;n manual
					</div>
					<?=cb_message('Use esta opción si desea actualizar el listado de productos desde Contabilium a su tienda', 'info')?>
					<input type="hidden" name="item_sku" id="item_sku" value="" />
					
					<table>
						<tr class="form-field">
							<td>
								<button type="submit" class="button button-secondary" name="proceed_full" value="1"><?=_e("Iniciar sincronización completa", "woocommerce")?></button>
							</td>
							<td width="50">&nbsp;</td>
							<td>
								<button type="submit" class="button button-secondary" name="proceed_single" value="1"
									onclick="code = prompt('Por favor ingresá un código de producto válido'); if(code) { document.getElementById('item_sku').value = code; } else { return false; }">
									<?=_e("Iniciar sincronización de un solo producto", "woocommerce")?>
								</button>
							</td>
						</tr>
					</table>
				</form>
			</div>-->
			<a id="#download_log"></a>
			<div class="cb_container">
				<form action="admin.php?page=contabilium_main_menu#download_log" method="post">
					<div class="cb_heading">
						Log
					</div>
					<?=cb_message('Use esta opción si desea descargar el log de actualizaciónes de sus productos', 'info')?>
					
					<table>
						<tr class="form-field">
							<td>
								<button type="submit" class="button button-secondary" name="download_log" value="1"><?=_e("Descargar log", "woocommerce")?></button>
							</td>
						</tr>
					</table>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<script>
			jQuery(document).ready(function () {
				jQuery('#wc_sync_price').on('click', function () {
					if (jQuery(this).prop('checked')) {
						jQuery('tr.update-price').removeClass('hidden');
					} else {
						jQuery('tr.update-price').addClass('hidden');
					}
				});
			});
		</script>
		<?php
	}

	function contabilium_sync_page_html() {
		$active_tab = sanitize_text_field( !empty($_GET["tab"]) ) ? $_GET["tab"] : 'concepts-tab';
		if (!current_user_can('manage_options')) {
			return;
		}
		?>
		<div class="wrap">
			<?php
			if ($active_tab != null) {
				$file = dirname(__FILE__) . '/tabs/' . $active_tab . '.php';
				if (file_exists($file)) {
					require($file);
				} else {
					cb_message('Opción no válida, por favor seleccione otra', 'error');
				}
			}
			?>
		</div>
		<?php
	}

// Get customer ID
	function get_customer_id($order_id) {	
		$user_id = get_post_meta($order_id, '_customer_user', true);
		return $user_id;
	}

	function contabilium_enable_api($idIntegracion){
		$enableApi 	= 	WooApi::enableApiRest();
		$enableWh 	= 	WooApi::enableWebhooks($idIntegracion);
	}

	function contabilium_disable_api() {
		$disableApi = 	WooApi::disableApiRest();
		$disableWh 	= 	WooApi::disableWebhooks();

		if (!$disableApi['status'])
		{
			//update_option('woocommerce_api_enabled', 'no');
			//cb_message($disableApi['msg'], 'error');
		}
		if (!$disableWh['status'])
		{
			//update_option('woocommerce_api_enabled', 'no');
			//cb_message($disableWh['msg'], 'error');
		}
	}

	/*function current_user_id() {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return 0;
		}
		$user = wp_get_current_user();
		return ( isset( $user->ID ) ? (int) $user->ID : 0 );
	}*/

	function get_version_plugin(){
		$plugins = get_plugins();
		$version = "0.0";
		
		foreach ($plugins as $plugin){
			if($plugin['Author'] == 'contabilium'){
				$version = $plugin['Version'];
			}
		}
		
		return $version;
	}

	function get_customer_address($user_id) {

		$address = '';
		$address .= get_user_meta($user_id, 'shipping_first_name', true);
		$address .= ' ';
		$address .= get_user_meta($user_id, 'shipping_last_name', true);
		$address .= "\n";
		$address .= get_user_meta($user_id, 'shipping_company', true);
		$address .= "\n";
		$address .= get_user_meta($user_id, 'shipping_address_1', true);
		$address .= "\n";
		$address .= get_user_meta($user_id, 'shipping_address_2', true);
		$address .= "\n";
		$address .= get_user_meta($user_id, 'shipping_city', true);
		$address .= "\n";
		$address .= get_user_meta($user_id, 'shipping_state', true);
		$address .= "\n";
		$address .= get_user_meta($user_id, 'shipping_postcode', true);
		$address .= "\n";
		$address .= get_user_meta($user_id, 'shipping_country', true);

		return $address;
	}


	function cb_message($text, $type = 'success', $domain = 'woocommerce') {
		if (! empty($text)) {
			$class   = 'notice notice-' . $type;
			$message = __($text, $domain);
			printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
		}
	}

	add_action('admin_notices', 'cb_message');

	// function cb_message($text, $type = 'success', $domain = 'woocommerce') {
	// 	if (! empty($text)) {
	// 		$class   = 'notice notice-' . $type;
	// 		$message = __($text, $domain);
	// 		printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
	// 	}
	// }

	// add_action('admin_notices', 'cb_message');


	/** WooCommerce campos extras necesarios para el registro **/
	function wc_extra_register_fields() { ?>
		      <p class="form-row form-row-wide">
			      <label for=""><?php _e('Nombre / Razón Social', 'woocommerce'); ?><span
					class="required">*</span></label>
			      <input type="text" class="input-text" name="cbCustomerName" id="" value=""/>
			      </p>
				      
				<p class="form-row form-row-wide">
					      <label for=""><?php _e('Tipo y Número de Documento', 'woocommerce'); ?><span
							class="required">*</span></label>
					<select class="woocommmerce-input input-text" name="cbDocumentType" id="cbDocumentType"
							style="max-width:14%;float:left;margin-right:1%;display: block;">
						<option value="DNI">DNI</option>
						<option value="CUIT">CUIT/CUIL</option>
					</select>
					      <input type="text" style="max-width:80%;float:left;display: block;"
								class="woocommmerce-input input-text" name="cbDocumentNumber" id="cbDocumentNumber"
								placeholder="Ingrese el número"/>
					      
				</p>
				<p class="form-row form-row-wide">
					      <label for=""><?php _e('Domicilio', 'woocommerce'); ?><span class="required">*</span></label>
					      <input type="text" class="input-text" name="cbCustomerAddress" id="cbCustomerAddress"
								placeholder="Ingrese su dirección"/>
					      </p>
				      
				<div class="clear"></div>
		      <?php
	}

	//add_action('woocommerce_register_form_start', 'wc_extra_register_fields');

	/** Hoja de estilos propia **/
	add_action('admin_head', 'cb_styles');

	function cb_styles() {
		echo '<style>
    .cb_container {
        background:white;
        border-top:3px solid #2B9B8F;
        border-bottom:1px solid #ebebeb;
        border-right:1px solid #ebebeb;
        border-left:1px solid #ebebeb;
        margin: 0 0 10px 0;
        padding:8px;
        overflow:auto;
        -moz-box-shadow: 0 3px 0 rgba(12,12,12,0.03);
    -webkit-box-shadow: 0 3px 0 rgba(12,12,12,0.03);
    box-shadow: 0 3px 0 rgba(12,12,12,0.03);
    }
    .cb_container .cb_heading {
        color: #666;
        text-transform: uppercase;
        border-bottom: 1px solid #ebebeb;
        margin-bottom: 6px;
        padding: 4px 0;
        font-weight: bold;
    }
    .cb_container .cb_footer {
        color: #666;
        border-top: 1px solid #ebebeb;
        margin-top: 6px;
        padding: 4px 0;
    }
    .cb_container label {

    }
    .cb_row {
        padding: 2px;
        margin-top: 0px;
        margin-left: 0px;
        margin-right: 0px;
        margin-bottom: 12px;
    }   
    .cb_container .cb_description {
        color: #777;
    }
 </style>';
	}

	add_action( 'add_meta_boxes', 'create_log_meta_box' );
	if ( ! function_exists( 'create_log_meta_box' ) )
	{
		function create_log_meta_box()
		{
			add_meta_box(
				'contabilium_log_meta_box',
				'Log Contabilium',
				'add_log_content_meta_box',
				'product',
				'side',
				'high'
			);
		}
	}

	//  Custom metabox content in admin product pages
	if ( ! function_exists( 'add_log_content_meta_box' ) )
	{
		function add_log_content_meta_box( $post )
		{
			global $wpdb;

			$table_name = $wpdb->prefix . 'contabilium_log';
			$sku = get_post_meta($post->ID, '_sku', true);

			$query_logs = "
			SELECT * 
			FROM $table_name
			WHERE sku = '$sku' 
			ORDER BY log_date DESC
			";

			$logs = $wpdb->get_results($query_logs, OBJECT);

			echo '<table><tbody>';
			foreach ($logs as $entry)
			{
				echo '<tr style="background-color: #ccc;padding: 5px;"><td colspan="2"><b>' . $entry->log_date . '</b></td></tr>';
				echo '<tr>';
				echo '<td><b>Precio anterior:</b> $' . $entry->original_price . '</td>';
				echo '<td><b>Precio nuevo:</b> $' . $entry->new_price . '</td>';
				echo '</tr>';
				echo '<tr>';
				echo '<td><b>Stock anterior:</b>' . $entry->original_stock . '</td>';
				echo '<td><b>Stock nuevo:</b>' . $entry->new_stock . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

}

// New modifications
function contabilium_hide_top_menu() {
	echo '<style  type="text/css">.toplevel_page_contabilium_main_menu .wp-first-item </style>';
}

add_action('admin_head', 'contabilium_hide_top_menu');

include_once('api.php');
include_once('includes/manage-orders.php');