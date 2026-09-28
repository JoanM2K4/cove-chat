<?php
/**
 * Implementación de las herramientas.
 *
 * Reglas que se respetan aquí y en ningún sitio más:
 *
 *  · Ningún dato de la tienda escrito en el código: precios, totales,
 *    estados de pedido y moneda se leen de WooCommerce en cada llamada.
 *  · mis_pedidos NO acepta parámetros de cliente: el usuario sale siempre de
 *    get_current_user_id(). Es lo que hace imposible que alguien consulte
 *    pedidos ajenos pasando un número.
 *  · Ningún dato personal sale al chat: de un pedido se devuelven número,
 *    fecha, estado, total y artículos. Nunca dirección, teléfono ni correo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ejecuta una herramienta por nombre.
 *
 * @param string $nombre     Nombre pedido por el modelo.
 * @param array  $args       Argumentos (ya decodificados del JSON).
 * @param string $session_id Sesión, para el estado de paginación.
 * @return array Resultado serializable. Los errores vuelven como dato, no como
 *               excepción: el modelo suele reformular bien cuando se le dice
 *               con claridad qué falló.
 */
function cove_ai_run_tool( $nombre, $args, $session_id = '' ) {
	$args = is_array( $args ) ? $args : array();

	switch ( $nombre ) {

		case 'buscar_productos':
			$resultado = cove_ai_search_products( $args, $session_id );
			break;

		case 'siguiente_bloque':
			$resultado = cove_ai_siguiente_bloque( $session_id );
			break;

		case 'detalle_producto':
			$llave     = isset( $args['sku_o_id'] ) ? sanitize_text_field( (string) $args['sku_o_id'] ) : '';
			$resultado = cove_ai_detalle_producto( $llave );
			break;

		case 'listar_categorias':
			$resultado = cove_ai_listar_categorias();
			break;

		case 'listar_atributos':
			$attr      = isset( $args['atributo'] ) ? sanitize_text_field( (string) $args['atributo'] ) : '';
			$resultado = cove_ai_listar_atributos( $attr );
			break;

		case 'politicas_tienda':
			$seccion   = isset( $args['seccion'] ) ? sanitize_text_field( (string) $args['seccion'] ) : '';
			$resultado = cove_ai_politicas_tienda( $seccion );
			break;

		case 'cupones':
			$codigo    = isset( $args['codigo'] ) ? sanitize_text_field( (string) $args['codigo'] ) : '';
			$resultado = cove_ai_cupones( $codigo );
			break;

		case 'mis_pedidos':
			$resultado = cove_ai_mis_pedidos();
			break;

		default:
			$resultado = new WP_Error(
				'cove_ai_tool_desconocida',
				'Herramienta desconocida: "' . $nombre . '". Usa únicamente las herramientas disponibles.'
			);
	}

	if ( is_wp_error( $resultado ) ) {
		return array(
			'ok'    => false,
			'error' => $resultado->get_error_message(),
		);
	}

	return $resultado;
}

/**
 * Continúa la última búsqueda de la sesión.
 *
 * Existe porque "y las demás que haya" es irresoluble si el modelo solo tiene
 * una lista de 5 productos sin saber cuántos hay: o dice "esas son todas"
 * (falso) o inventa. Con el estado guardado, extiende la búsqueda real.
 */
function cove_ai_siguiente_bloque( $session_id ) {
	$state = cove_ai_state_get( $session_id );

	if ( ! $state ) {
		return array(
			'ok'   => false,
			'error' => 'No hay una búsqueda anterior registrada en esta conversación. En vez de adivinar, pregúntale al cliente a qué lista se refiere, o haz una búsqueda nueva.',
		);
	}

	if ( (int) $state['leidos'] >= (int) $state['total'] ) {
		return array(
			'ok'        => true,
			'total'     => (int) $state['total'],
			'mostrados' => 0,
			'productos' => array(),
			'nota'      => 'No hay más resultados: ya se mostraron los ' . (int) $state['total'] . ' productos que cumplen esos criterios.',
		);
	}

	$args           = is_array( $state['args'] ) ? $state['args'] : array();
	$args['offset'] = (int) $state['leidos'];

	$resultado = cove_ai_search_products( $args, $session_id );

	// La búsqueda reescribe el estado con el nuevo offset; lo que falta es
	// dejar claro en la respuesta qué se está continuando.
	if ( is_array( $resultado ) && ! empty( $resultado['ok'] ) ) {
		$descripcion = array();
		if ( ! empty( $args['termino'] ) ) {
			$descripcion[] = 'texto "' . $args['termino'] . '"';
		}
		if ( ! empty( $args['categoria'] ) ) {
			$descripcion[] = 'categoría "' . $args['categoria'] . '"';
		}
		if ( ! empty( $args['atributos'] ) && is_array( $args['atributos'] ) ) {
			foreach ( $args['atributos'] as $par ) {
				$descripcion[] = $par['atributo'] . '=' . $par['valor'];
			}
		}
		$resultado['continuacion_de'] = $descripcion ? implode( ', ', $descripcion ) : 'la búsqueda anterior';
	}

	return $resultado;
}

/**
 * Políticas de la tienda, leídas de content/cove-info.php.
 *
 * El contenido largo NO va en el prompt del sistema: se paga en cada mensaje.
 * Aquí se paga solo cuando el cliente pregunta por esto, y el bot cita el
 * texto oficial en vez de parafrasearlo de memoria.
 */
function cove_ai_politicas_tienda( $seccion = '' ) {
	$archivo = COVE_AI_DIR . '/content/cove-info.php';
	if ( ! file_exists( $archivo ) ) {
		return array(
			'ok'    => false,
			'error' => 'No está el archivo de información de la tienda (content/cove-info.php).',
		);
	}

	$info = require $archivo;
	if ( ! is_array( $info ) || empty( $info ) ) {
		return array(
			'ok'    => false,
			'error' => 'El archivo de información de la tienda no devolvió contenido. Si el cliente insiste en envíos o devoluciones, dile que pase con una persona.',
		);
	}

	// Sin sección pedida: devolver el índice para que el modelo elija.
	if ( '' === $seccion ) {
		$indice = array();
		foreach ( $info as $clave => $bloque ) {
			$indice[] = $clave;
		}
		return array(
			'ok'       => true,
			'secciones' => $indice,
			'nota'     => 'Llama de nuevo con el parámetro "seccion" para leer el contenido completo.',
		);
	}

	$buscado = cove_ai_normaliza( $seccion );
	foreach ( $info as $clave => $bloque ) {
		if ( cove_ai_normaliza( $clave ) === $buscado ) {
			return array( 'ok' => true, 'seccion' => $clave, 'contenido' => (string) $bloque );
		}
	}
	// Coincidencia parcial, para que "envíos" encuentre "envios_nacionales".
	foreach ( $info as $clave => $bloque ) {
		$clave_n = cove_ai_normaliza( $clave );
		if ( false !== strpos( $clave_n, $buscado ) || false !== strpos( $buscado, $clave_n ) ) {
			return array( 'ok' => true, 'seccion' => $clave, 'contenido' => (string) $bloque );
		}
	}

	$indice = array();
	foreach ( $info as $clave => $bloque ) {
		$indice[] = $clave;
	}
	return array(
		'ok'       => false,
		'error'    => 'No existe la sección "' . $seccion . '".',
		'secciones' => $indice,
		'nota'     => 'Puedes llamar de nuevo con una de estas secciones, o responder con lo que tengas de las otras.',
	);
}

/**
 * Pedidos del usuario con sesión iniciada.
 *
 * LA REGLA QUE LO HACE SEGURO: no acepta ningún parámetro de cliente. El
 * usuario lo determina el servidor. Si aceptara un customer_id, cualquier
 * visitante leería pedidos ajenos pasando un número.
 *
 * Y lo que NO se devuelve nunca: dirección, teléfono, correo. Un pedido trae
 * datos personales; al chat solo van número, fecha, estado, total y artículos.
 */
function cove_ai_mis_pedidos() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array( 'ok' => false, 'error' => 'WooCommerce no está activo en este sitio.' );
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return array(
			'ok'   => false,
			'error' => 'El cliente no tiene sesión iniciada, así que no hay pedidos que consultar. Dile con amabilidad que entre a su cuenta (o use el enlace de Mi cuenta del sitio) para que puedas verlos. No intentes adivinar ni buscar pedidos por correo o número.',
		);
	}

	$pedidos = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => COVE_AI_PEDIDOS_MAX,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);

	$total_cliente = (int) wc_get_customer_order_count( $user_id );

	if ( empty( $pedidos ) ) {
		return array(
			'ok'                  => true,
			'total_pedidos_cliente' => $total_cliente,
			'pedidos'             => array(),
			'nota'                => 'Este cliente no tiene pedidos todavía.',
		);
	}

	$salida = array();
	foreach ( $pedidos as $pedido ) {
		$articulos = array();
		foreach ( $pedido->get_items() as $item ) {
			$articulos[] = $item->get_name() . ' x' . (int) $item->get_quantity();
		}

		$fecha = $pedido->get_date_created();

		$salida[] = array(
			'numero'    => $pedido->get_order_number(),
			'fecha'     => $fecha ? wc_format_datetime( $fecha ) : '',
			// Estado traducido por WooCommerce: nunca 'wc-processing' al cliente.
			'estado'    => wc_get_order_status_name( $pedido->get_status() ),
			'total'     => wp_strip_all_tags( $pedido->get_formatted_order_total() ),
			'articulos' => $articulos,
		);
	}

	return array(
		'ok'                    => true,
		'total_pedidos_cliente' => $total_cliente,
		'mostrados'             => count( $salida ),
		'pedidos'               => $salida,
		'nota'                  => 'Solo se muestran los últimos ' . COVE_AI_PEDIDOS_MAX . ' de ' . $total_cliente . ' pedidos. No menciones direcciones ni datos de contacto.',
	);
}

/* -------------------------------------------------------------------------- */
/* Cupones                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * Resumen de un cupón PÚBLICO, o false si no debe exponerse.
 *
 * POR QUÉ ESTO NO USA WC_Coupon::is_valid()
 * -----------------------------------------
 * is_valid() evalúa el cupón contra el CARRITO activo: comprueba mínimos de
 * compra, productos aplicables y demás. En una petición REST el carrito está
 * vacío (WooCommerce solo lo inicializa en peticiones del frontend), así que
 * is_valid() diría cosas como "no cumple el mínimo de compra" aunque el
 * cliente sí lo cumpla. Se leen las propiedades del cupón y se calcula la
 * vigencia aquí, que es determinista.
 *
 * @param string $codigo Código del cupón.
 * @return array|false
 */
function cove_ai_cupon_resumen( $codigo ) {

	$cupon = new WC_Coupon( $codigo );

	// No existe.
	if ( ! $cupon->get_id() ) {
		return false;
	}

	// Un cupón en borrador no se publica, así que no se expone.
	if ( 'publish' !== get_post_status( $cupon->get_id() ) ) {
		return false;
	}

	// Dirigido a correos concretos: aunque esté en la allowlist, es privado.
	if ( ! empty( $cupon->get_email_restrictions() ) ) {
		return false;
	}

	// Vencido.
	$expira = $cupon->get_date_expires();
	if ( $expira && $expira->getTimestamp() < time() ) {
		return false;
	}

	// Agotado.
	$limite = (int) $cupon->get_usage_limit(); // 0 = sin límite
	$usos   = (int) $cupon->get_usage_count();
	if ( $limite > 0 && $usos >= $limite ) {
		return false;
	}

	/* --------------------------------------------------------------------- */
	/* Condiciones, todas leídas del cupón en el momento                      */
	/* --------------------------------------------------------------------- */

	$monto = (float) $cupon->get_amount();

	switch ( $cupon->get_discount_type() ) {
		case 'percent':
			$descuento = rtrim( rtrim( number_format( $monto, 2, '.', '' ), '0' ), '.' ) . '% de descuento';
			break;
		case 'fixed_cart':
			$descuento = wp_strip_all_tags( wc_price( $monto ) ) . ' de descuento sobre el total del carrito';
			break;
		case 'fixed_product':
			$descuento = wp_strip_all_tags( wc_price( $monto ) ) . ' de descuento por producto';
			break;
		default:
			$descuento = 'descuento especial';
	}

	$condiciones = array();

	$minimo = (float) $cupon->get_minimum_amount();
	if ( $minimo > 0 ) {
		$condiciones[] = 'Compra mínima de ' . wp_strip_all_tags( wc_price( $minimo ) );
	}

	$maximo = (float) $cupon->get_maximum_amount();
	if ( $maximo > 0 ) {
		$condiciones[] = 'Aplica hasta ' . wp_strip_all_tags( wc_price( $maximo ) );
	}

	if ( $cupon->get_free_shipping() ) {
		$condiciones[] = 'Incluye envío gratis';
	}

	// Alcance: categorías y productos. Dinámico, nunca escrito a mano.
	$categorias = $cupon->get_product_categories();
	if ( ! empty( $categorias ) ) {
		$nombres = array();
		foreach ( array_slice( (array) $categorias, 0, 5 ) as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$nombres[] = $term->name;
			}
		}
		if ( $nombres ) {
			$condiciones[] = 'Aplica a las categorías: ' . implode( ', ', $nombres );
		}
	}

	$productos = $cupon->get_product_ids();
	if ( ! empty( $productos ) ) {
		$condiciones[] = 'Aplica solo a ' . count( (array) $productos ) . ' producto(s) específicos del catálogo';
	}

	$excluidos = $cupon->get_excluded_product_ids();
	if ( ! empty( $excluidos ) ) {
		$condiciones[] = 'No aplica a ' . count( (array) $excluidos ) . ' producto(s)';
	}

	if ( $limite > 0 ) {
		$condiciones[] = 'Quedan ' . max( 0, $limite - $usos ) . ' usos';
	}

	$por_cliente = (int) $cupon->get_usage_limit_per_user();
	if ( $por_cliente > 0 ) {
		$condiciones[] = ( 1 === $por_cliente ) ? 'Un uso por cliente' : ( $por_cliente . ' usos por cliente' );
	}

	$resumen = array(
		'codigo'    => $cupon->get_code(),
		'descuento' => $descuento,
	);

	if ( $condiciones ) {
		$resumen['condiciones'] = $condiciones;
	}

	$resumen['vence'] = $expira
		? $expira->date_i18n( get_option( 'date_format' ) )
		: 'sin fecha de vencimiento';

	$resumen['como_usarlo'] = 'Se aplica en el campo de cupón que aparece en la página del carrito y en el checkout, antes de pagar.';

	return $resumen;
}

/**
 * Cupones PÚBLICOS de la tienda.
 *
 * POR QUÉ ES UNA ALLOWLIST Y NO UNA CONSULTA AL CATÁLOGO DE CUPONES
 * ----------------------------------------------------------------
 * Un chat público que valida cualquier código es un oráculo de enumeración:
 * cualquiera pregunta por COVE10, COVE11, COVE12... y va descubriendo qué
 * cupones existen y cuánto valen. Con la allowlist eso desaparece: si el
 * código no está en la lista, la respuesta es IDÉNTICA a la de un código
 * inexistente, así que el atacante no aprende nada nuevo (y la lista de
 * públicos ya se la puede pedir sin código, así que tampoco gana nada).
 *
 * La lista la mantiene el dueño de la tienda: ver cove_ai_cupones_publicos()
 * en 00-config.php. Por defecto está VACÍA: sin configurar, el asistente no
 * comunica ningún cupón.
 *
 * @param string $codigo Opcional: un código concreto.
 */
function cove_ai_cupones( $codigo = '' ) {

	if ( ! class_exists( 'WC_Coupon' ) ) {
		return array( 'ok' => false, 'error' => 'WooCommerce no está activo en este sitio.' );
	}

	$publicos = cove_ai_cupones_publicos();

	if ( empty( $publicos ) ) {
		return array(
			'ok'    => false,
			'error' => 'La tienda no tiene cupones públicos publicados. Dilo con naturalidad: ahora mismo no hay cupones vigentes que puedas comunicar, y NO inventes ninguno.',
		);
	}

	/* --------------------------------------------------------------------- */
	/* Sin código: la lista de públicos vigentes                              */
	/* --------------------------------------------------------------------- */

	if ( '' === trim( (string) $codigo ) ) {

		$vigentes = array();
		foreach ( $publicos as $uno ) {
			$resumen = cove_ai_cupon_resumen( $uno );
			if ( $resumen ) {
				$vigentes[] = $resumen;
			}
		}

		if ( empty( $vigentes ) ) {
			return array(
				'ok'    => true,
				'cupones' => array(),
				'nota'  => 'Ninguno de los cupones publicados está vigente ahora mismo (vencidos o agotados). Comunícalo tal cual: actualmente no hay cupones vigentes.',
			);
		}

		return array( 'ok' => true, 'cupones' => $vigentes );
	}

	/* --------------------------------------------------------------------- */
	/* Con código: allowlist y respuesta UNIFORME                              */
	/* --------------------------------------------------------------------- */

	$buscado = cove_ai_normaliza( $codigo );

	foreach ( $publicos as $uno ) {
		if ( cove_ai_normaliza( $uno ) === $buscado ) {
			$resumen = cove_ai_cupon_resumen( $uno );
			if ( ! $resumen ) {
				return array(
					'ok'      => true,
					'codigo'  => $uno,
					'vigente' => false,
					'nota'    => 'Ese cupón ya venció o se agotó.',
				);
			}
			return array( 'ok' => true, 'vigente' => true, 'cupon' => $resumen );
		}
	}

	// Código que no está en la allowlist. La respuesta es deliberadamente la
	// MISMA que para un código inexistente: no se revela si existe o no, y no
	// se especula con su valor. Nunca cambiar este mensaje por uno más
	// detallado: el detalle es exactamente lo que se filtra.
	return array(
		'ok'      => true,
		'vigente' => false,
		'nota'    => 'No reconoces ese código entre los cupones vigentes. Responde exactamente eso, sugiere revisar si está bien escrito y ofrece la lista de cupones vigentes si el cliente la quiere. NO afirmes si el cupón existe o no, ni supongas su valor.',
	);
}
