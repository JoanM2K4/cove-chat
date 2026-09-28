<?php
/**
 * Estado de la conversación que vive en el SERVIDOR (mínimo e imprescindible).
 *
 * Para qué sirve: cuando el cliente dice "y las demás que haya", el modelo
 * necesita saber qué lista está extendiendo. Normalmente lo deduce del
 * historial, pero si el historial se recortó se queda sin antecedente y
 * responde cualquier cosa con seguridad total.
 *
 * La solución: guardamos los ARGUMENTOS de la última búsqueda (no los
 * resultados) en un transient indexado por el id de sesión, y la herramienta
 * siguiente_bloque() los reutiliza sin parámetros.
 *
 * Qué NO se guarda aquí: nada personal. Solo argumentos de consulta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Id de sesión enviado por el widget (deep-chat additionalBodyProps).
 *
 * Se sanea a un alfabeto seguro y longitud acotada: es entrada del navegador,
 * o sea territorio hostil. Si no es válido, no hay estado de sesión y
 * siguiente_bloque() lo dirá con claridad en vez de inventar.
 *
 * @param mixed $raw Valor crudo recibido.
 * @return string Cadena vacía si no es utilizable.
 */
function cove_ai_session_id( $raw ) {
	if ( ! is_string( $raw ) ) {
		return '';
	}
	$raw = trim( $raw );
	if ( '' === $raw || strlen( $raw ) > 64 ) {
		return '';
	}
	if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $raw ) ) {
		return '';
	}
	return $raw;
}

/**
 * Clave del transient para una sesión.
 */
function cove_ai_state_key( $session_id ) {
	return 'cove_ai_st_' . md5( $session_id );
}

/**
 * Lee el estado de búsqueda de una sesión.
 *
 * @return array{args:array,offset:int,leidos:int,total:int}|null
 */
function cove_ai_state_get( $session_id ) {
	if ( '' === $session_id ) {
		return null;
	}
	$state = get_transient( cove_ai_state_key( $session_id ) );
	return is_array( $state ) ? $state : null;
}

/**
 * Guarda el estado de búsqueda de una sesión.
 *
 * @param string $session_id Sesión.
 * @param array  $args       Argumentos ya normalizados de buscar_productos.
 * @param int    $offset     Offset alcanzado.
 * @param int    $leidos     Cuántos productos se le mostraron ya en total.
 * @param int    $total      Total real de resultados de esa búsqueda.
 */
function cove_ai_state_set( $session_id, array $args, $offset, $leidos, $total ) {
	if ( '' === $session_id ) {
		return;
	}
	set_transient(
		cove_ai_state_key( $session_id ),
		array(
			'args'   => $args,
			'offset' => (int) $offset,
			'leidos' => (int) $leidos,
			'total'  => (int) $total,
		),
		COVE_AI_SESION_TTL
	);
}

/**
 * Borra el estado de una sesión (útil si el cliente cambia de tema).
 */
function cove_ai_state_clear( $session_id ) {
	if ( '' !== $session_id ) {
		delete_transient( cove_ai_state_key( $session_id ) );
	}
}
