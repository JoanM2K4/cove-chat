<?php
/**
 * Endpoint REST.
 *
 * POST /wp-json/cove-ai/v1/chat
 *
 * CONTRATO (docs/TOOLS.md):
 *   Entrada:  { "messages": [ { "role": "user"|"ai", "text": "..." } ],
 *               "session_id": "abc-123" }
 *   Salida:   { "text": "..." }
 *
 * REGLA IMPORTANTE: todo error de negocio vuelve como TEXTO en un 200, no
 * como error HTTP. El widget espera el tipo Response; un 500 se le muestra al
 * visitante como "servicio roto". Solo los fallos de infraestructura devuelven
 * 4xx/5xx.
 *
 * SOBRE EL NONCE: para usuarios con sesión iniciada, DeepChat envía el header
 * X-WP-Nonce (lo inyecta el widget, ver 60-widget.php). Para visitantes
 * anónimos NO se usa nonce a propósito: un nonce horneado en HTML se vence
 * cuando la página viene del cache de LiteSpeed y el chat devolvería 403 sin
 * explicación. Los visitantes se protegen con la cuota por IP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const COVE_AI_REST_NAMESPACE = 'cove-ai/v1';

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			COVE_AI_REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => 'cove_ai_rest_chat',
				// Abierto a propósito: el chat es para visitantes sin sesión.
				// La protección real es la cuota por IP de abajo.
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * ¿La IP puede hacer otra consulta? Cuota simple por hora.
 *
 * Una pestaña olvidada no debe consumir el saldo de nadie.
 */
function cove_ai_rate_limit_ok() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip = '' !== $ip ? $ip : 'desconocida';

	$clave = 'cove_ai_rl_' . md5( $ip );
	$usos  = (int) get_transient( $clave );

	if ( $usos >= COVE_AI_RATE_LIMIT ) {
		return false;
	}

	set_transient( $clave, $usos + 1, HOUR_IN_SECONDS );
	return true;
}

/**
 * Maneja la petición del chat.
 *
 * @param WP_REST_Request $request Petición.
 * @return WP_REST_Response
 */
function cove_ai_rest_chat( WP_REST_Request $request ) {

	// 1. Cuota por IP.
	if ( ! cove_ai_rate_limit_ok() ) {
		return new WP_REST_Response(
			array( 'text' => 'Estás haciendo muchas consultas seguidas. Espera un momento y volvemos a intentar.' ),
			200
		);
	}

	// 2. Leer el cuerpo. DeepChat manda JSON; se acepta también form-data
	//    por si alguien cambia el método de envío.
	$params = $request->get_json_params();
	if ( ! is_array( $params ) || empty( $params ) ) {
		$params = $request->get_params();
	}

	$mensajes_crudos = isset( $params['messages'] ) ? $params['messages'] : null;

	// Tolerancia: si viene como cadena JSON (form-data), decodificar.
	if ( is_string( $mensajes_crudos ) ) {
		$decodificado    = json_decode( $mensajes_crudos, true );
		$mensajes_crudos = is_array( $decodificado ) ? $decodificado : null;
	}

	$session_id = cove_ai_session_id( isset( $params['session_id'] ) ? $params['session_id'] : '' );

	// 3. Normalizar el historial (roles, longitud, cantidad).
	$historial = cove_ai_normalizar_historial( $mensajes_crudos );

	if ( empty( $historial ) ) {
		return new WP_REST_Response(
			array( 'text' => 'No llegó ningún mensaje. Intenta escribirlo de nuevo.' ),
			200
		);
	}

	// 4. El último mensaje debe ser del usuario.
	$ultimo = end( $historial );
	if ( 'user' !== $ultimo['role'] ) {
		return new WP_REST_Response(
			array( 'text' => 'Esperaba una pregunta tuya. ¿Me la repites?' ),
			200
		);
	}

	// 5. Armar la conversación completa: prompt del sistema + historial.
	$mensajes = array_merge(
		array(
			array(
				'role'    => 'system',
				'content' => cove_ai_system_prompt(),
			),
		),
		$historial
	);

	// 6. Conversar (aquí ocurre todo el ciclo de herramientas).
	$resultado = cove_ai_converse( $mensajes, $session_id );

	if ( is_wp_error( $resultado ) ) {

		// Error de configuración: mensaje claro y accionable para quien administra.
		if ( 'cove_ai_sin_key' === $resultado->get_error_code() ) {
			return new WP_REST_Response( array( 'text' => $resultado->get_error_message() ), 200 );
		}

		// Cualquier otro fallo: se emite un evento para que quien administre lo
		// registre si quiere, y al visitante se le da un mensaje humano, sin
		// detalles técnicos.
		do_action( 'cove_ai_error', $resultado->get_error_code(), $resultado->get_error_message() );

		return new WP_REST_Response(
			array( 'text' => 'No pude completar la consulta. ¿Puedes reformular tu pregunta?' ),
			200
		);
	}

	$texto = trim( (string) $resultado['reply'] );
	if ( '' === $texto ) {
		$texto = 'No se me ocurrió una respuesta. ¿Puedes preguntarlo de otra forma?';
	}

	// Red de seguridad de los enlaces: cualquier URL pelada que haya soltado el
	// modelo se convierte en un enlace tocable. Ver cove_ai_linkify().
	$texto = cove_ai_linkify( $texto );

	// Red de seguridad del marcado DSML: si quedó algún resto del marcado de
	// herramientas de DeepSeek, se quita. El cliente nunca debe verlo.
	$texto = cove_ai_dsml_limpiar( $texto );

	if ( '' === trim( $texto ) ) {
		$texto = 'No pude completar la consulta. ¿Puedes reformular tu pregunta?';
	}

	return new WP_REST_Response( array( 'text' => $texto ), 200 );
}
