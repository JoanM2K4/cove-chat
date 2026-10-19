<?php
/**
 * Plugin Name: Cove AI Chat — Chat IA con function calling sobre WooCommerce
 * Description: Chat para visitantes de COVE. Habla con la API de DeepSeek y ejecuta herramientas de solo lectura contra WooCommerce (catálogo, filtros, categorías, atributos, políticas y pedidos del usuario con sesión). Interfaz: componente DeepChat autoalojado.
 * Version:     1.0.0
 * Author:      Joan Soto
 * Requires:    PHP 8.1+ / WordPress 6.x / WooCommerce 8+
 *
 * ---------------------------------------------------------------------------
 * ESTE ES EL CARGADOR. Va en:  wp-content/mu-plugins/cove-ai-chat.php
 * La carpeta  wp-content/mu-plugins/cove-ai-chat/  va a su lado.
 *
 * WordPress solo carga automáticamente los .php que están DIRECTAMENTE en
 * mu-plugins/, así que este archivo existe para cargar la carpeta. No borrar
 * ninguno de los dos.
 * ---------------------------------------------------------------------------
 *
 * Orden de carga (los prefijos numéricos son intencionales: se ve el orden
 * de un vistazo y no hay que adivinar quién define qué).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ruta base, para que los módulos no adivinen dónde están.
define( 'COVE_AI_DIR', __DIR__ . '/cove-ai-chat' );

require_once COVE_AI_DIR . '/00-config.php';              // Constantes, secretos, prompt
require_once COVE_AI_DIR . '/10-session.php';             // Id de sesión y estado de búsqueda
require_once COVE_AI_DIR . '/20-query-builder.php';       // Consultas a WooCommerce y filtros
require_once COVE_AI_DIR . '/30-tools-schemas.php';       // Esquemas JSON de las herramientas
require_once COVE_AI_DIR . '/31-tools-implementations.php'; // Implementación de las herramientas
require_once COVE_AI_DIR . '/40-deepseek.php';            // Cliente de API y ciclo de tool calling
require_once COVE_AI_DIR . '/50-rest-endpoint.php';       // Endpoint REST + cuota
require_once COVE_AI_DIR . '/60-widget.php';              // Embed del componente en el frontend

/**
 * Aviso temprano si falta la API key.
 *
 * No se aborta nada: el chat responde "no configurado" y el resto del sitio
 * sigue funcionando. Se emite un evento, sin escribir en el log por cuenta
 * propia: ver el apartado de registro en docs/ARQUITECTURA.md.
 */
add_action(
	'init',
	function () {
		if ( '' === cove_ai_key() ) {
			do_action( 'cove_ai_sin_api_key' );
		}
	}
);
