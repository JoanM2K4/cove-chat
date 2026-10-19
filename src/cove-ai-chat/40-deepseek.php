<?php
/**
 * Cliente de la API de DeepSeek y el ciclo de tool calling.
 *
 * Formato: la API de DeepSeek es compatible con la de OpenAI. Las tools van
 * en modo "non-thinking"; activar el modo pensante rompe el function calling,
 * así que aquí no se envía nunca el parámetro "thinking".
 *
 * Los cuatro detalles que rompen una implementación de tool calling si se
 * ignoran (y que aquí están resueltos):
 *
 *   1. El mensaje del asistente se reenvía COMPLETO, incluidos sus tool_calls.
 *      Si se guarda solo el texto, se pierde el contexto del tool_call_id y
 *      la segunda llamada falla.
 *   2. Cada resultado lleva el tool_call_id EXACTO de su llamada.
 *   3. Si una herramienta falla, el error vuelve COMO RESULTADO, no como
 *      excepción: el modelo suele reformular bien cuando sabe qué falló.
 *   4. Hay un tope de rondas, para que un modelo insistente no entre en bucle
 *      y te vacíe el saldo.
 *
 * Nada de esto se ejecuta en el navegador: la API key vive solo aquí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delimitador del marcado DSML de DeepSeek.
 *
 * DeepSeek genera las llamadas a herramientas con un marcado propio que su
 * motor debería traducir al campo estructurado `tool_calls`. De vez en cuando
 * NO lo traduce y el marcado crudo aparece en `content` (el texto), con
 * finish_reason "stop" en lugar de "tool_calls". Consecuencia: la herramienta
 * nunca se ejecuta y el cliente ve el marcado.
 *
 * El delimitador cambia según el modelo: V3.2 usa UNA barra y V4-Flash usa DOS.
 * Aquí se aceptan una o dos, y tanto la barra ASCII como la de ancho completo
 * U+FF5C, porque los parsers que solo aceptan una barra fallan justo con la
 * forma de V4-Flash.
 *
 * No se intenta evitar por prompt: el marcado lo dispara un patrón del
 * tokenizador, no el seguir instrucciones, así que las mitigaciones por
 * instrucción son poco fiables. La defensa efectiva es recuperarlo aquí.
 */
const COVE_AI_DSML_BARRAS = '[|\x{FF5C}]{1,2}\s*DSML\s*[|\x{FF5C}]{1,2}';

/**
 * Posición del primer marcador DSML en el texto, o null si no hay.
 */
function cove_ai_dsml_posicion( $texto ) {
	if ( ! is_string( $texto ) || '' === $texto ) {
		return null;
	}
	if ( preg_match( '#<\s*/?\s*' . COVE_AI_DSML_BARRAS . '#iu', $texto, $coincidencia, PREG_OFFSET_CAPTURE ) ) {
		return (int) $coincidencia[0][1];
	}
	return null;
}

/**
 * Lee los atributos de una etiqueta DSML: name="x" string="true" ...
 */
function cove_ai_dsml_attrs( $crudo ) {
	$attrs = array();
	if ( preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', (string) $crudo, $encontrados, PREG_SET_ORDER ) ) {
		foreach ( $encontrados as $a ) {
			$valor = isset( $a[2] ) ? $a[2] : '';
			if ( '' === $valor && isset( $a[3] ) ) {
				$valor = $a[3];
			}
			if ( '' === $valor && isset( $a[4] ) ) {
				$valor = $a[4];
			}
			$attrs[ strtolower( $a[1] ) ] = $valor;
		}
	}
	return $attrs;
}

/**
 * Convierte el valor de un parámetro DSML a su tipo.
 *
 * El atributo string="true" significa que el valor es texto literal; si no
 * está, el valor viene en JSON y se decodifica.
 */
function cove_ai_dsml_valor( $valor, $marca_string ) {
	$valor = (string) $valor;
	if ( 'true' === strtolower( trim( (string) $marca_string ) ) ) {
		return $valor;
	}
	$decodificado = json_decode( trim( $valor ), true );
	return ( null === $decodificado && 'null' !== trim( $valor ) ) ? $valor : $decodificado;
}

/**
 * Extrae las llamadas a herramientas del marcado DSML.
 *
 * Cubre las dos formas conocidas:
 *   A) <..invoke name="X"><..parameter name="p" string="true">v</..parameter></..invoke>
 *   B) <..invoke name="X"><..invoke name="p" string="v" /></..invoke>   (V4-Flash)
 *
 * @return array Lista de array( 'name' => string, 'args' => array ).
 */
function cove_ai_dsml_llamadas( $texto ) {

	$barras = COVE_AI_DSML_BARRAS;

	if ( ! preg_match_all(
		// Se exige la etiqueta de cierre: un bloque a medias NO se ejecuta.
		// Es preferible pedirle al cliente que reformule antes que ejecutar una
		// herramienta con argumentos inventados a partir de una salida truncada.
		'#<\s*' . $barras . '\s*invoke\b([^>]*?)>(.*?)</\s*' . $barras . '\s*invoke\s*>#isu',
		(string) $texto,
		$bloques,
		PREG_SET_ORDER
	) ) {
		return array();
	}

	$llamadas = array();

	foreach ( $bloques as $bloque ) {

		$attrs  = cove_ai_dsml_attrs( $bloque[1] );
		$nombre = isset( $attrs['name'] ) ? (string) $attrs['name'] : '';
		if ( '' === $nombre ) {
			continue;
		}

		$cuerpo = $bloque[2];
		$args   = array();

		// Forma A: parámetros con cuerpo.
		if ( preg_match_all(
			'#<\s*' . $barras . '\s*parameter\b([^>]*?)>(.*?)</\s*' . $barras . '\s*parameter\s*>#isu',
			$cuerpo,
			$params,
			PREG_SET_ORDER
		) ) {
			foreach ( $params as $p ) {
				$pa = cove_ai_dsml_attrs( $p[1] );
				if ( ! isset( $pa['name'] ) ) {
					continue;
				}
				$args[ $pa['name'] ] = cove_ai_dsml_valor( $p[2], isset( $pa['string'] ) ? $pa['string'] : '' );
			}
		}

		// Forma B: etiquetas autocerradas, con el valor en el atributo string.
		if ( preg_match_all(
			'#<\s*' . $barras . '\s*(?:parameter|invoke)\b([^>]*?)/>#isu',
			$cuerpo,
			$autos,
			PREG_SET_ORDER
		) ) {
			foreach ( $autos as $a ) {
				$aa = cove_ai_dsml_attrs( $a[1] );
				if ( ! isset( $aa['name'] ) || isset( $args[ $aa['name'] ] ) ) {
					continue;
				}
				$args[ $aa['name'] ] = cove_ai_dsml_valor( isset( $aa['string'] ) ? $aa['string'] : '', 'true' );
			}
		}

		$llamadas[] = array(
			'name' => $nombre,
			'args' => $args,
		);
	}

	return $llamadas;
}

/**
 * Intenta recuperar llamadas a herramientas que DeepSeek dejó en texto plano.
 *
 * @param string $texto Contenido devuelto por el modelo.
 * @return array|null array( 'tool_calls' => [...] ) o null si no aplica.
 */
function cove_ai_dsml_recuperar( $texto ) {

	$posicion = cove_ai_dsml_posicion( $texto );
	if ( null === $posicion ) {
		return null;
	}

	// Regla de seguridad: una llamada real en texto plano es TODO el turno del
	// asistente, así que el marcado tiene que empezar el mensaje (solo espacios
	// antes). Marcado en medio de una respuesta es el modelo citando contenido
	// —por ejemplo, texto que llegó del cliente— y NO debe ejecutarse.
	if ( '' !== trim( substr( (string) $texto, 0, $posicion ) ) ) {
		return null;
	}

	$llamadas = cove_ai_dsml_llamadas( $texto );
	if ( empty( $llamadas ) ) {
		return null;
	}

	$tool_calls = array();
	foreach ( $llamadas as $i => $llamada ) {
		if ( '' === $llamada['name'] ) {
			continue;
		}
		$tool_calls[] = array(
			'id'       => 'dsml_' . $i,
			'type'     => 'function',
			'function' => array(
				'name'      => $llamada['name'],
				'arguments' => $llamada['args'] ? wp_json_encode( $llamada['args'] ) : '{}',
			),
		);
	}

	return $tool_calls ? array( 'tool_calls' => $tool_calls ) : null;
}

/**
 * Quita cualquier resto de marcado DSML del texto.
 *
 * Se aplica siempre antes de devolver una respuesta, para que el cliente no
 * vea nunca el marcado, ni siquiera cuando no se pudo recuperar como llamada.
 */
function cove_ai_dsml_limpiar( $texto ) {
	if ( ! is_string( $texto ) ) {
		return '';
	}
	if ( '' === $texto || null === cove_ai_dsml_posicion( $texto ) ) {
		return $texto;
	}

	$barras = COVE_AI_DSML_BARRAS;

	$limpio = preg_replace(
		array(
			// Envoltorio abierto: se descarta también lo que venga detrás,
			// porque el resto del turno era la petición de herramientas.
			'#<\s*' . $barras . '\s*(?:tool_calls|calls|toolcalls)\s*>.*$#isu',
			'#</\s*' . $barras . '\s*(?:tool_calls|calls|toolcalls)\s*>#iu',
			'#<\s*' . $barras . '\s*invoke\b[^>]*?(?:/>|>.*?</\s*' . $barras . '\s*invoke\s*>)#isu',
			'#<\s*' . $barras . '\s*parameter\b[^>]*?>.*?</\s*' . $barras . '\s*parameter\s*>#isu',
			'#<\s*' . $barras . '\s*parameter\b[^>]*?/>#iu',
			// Residuo: cualquier etiqueta DSML suelta, con o sin atributos.
			'#<\s*/?\s*' . $barras . '\s*[a-zA-Z_]*[^>]*>#iu',
		),
		'',
		$texto
	);

	return trim( is_string( $limpio ) ? $limpio : $texto );
}

/**
 * Conversa con DeepSeek resolviendo el ciclo de herramientas.
 *
 * @param array  $messages   Mensajes en formato OpenAI (role/content).
 * @param string $session_id Sesión, para el estado de paginación.
 * @return array{reply:string}|WP_Error
 */
function cove_ai_converse( array $messages, $session_id = '' ) {

	$api_key = cove_ai_key();
	if ( '' === $api_key ) {
		return new WP_Error( 'cove_ai_sin_key', 'El asistente no está configurado. Falta la API key de DeepSeek.' );
	}

	$tools = cove_ai_tools();

	for ( $ronda = 0; $ronda <= COVE_AI_MAX_ROUNDS; $ronda++ ) {

		$cuerpo = array(
			'model'       => cove_ai_model(),
			'messages'    => $messages,
			'stream'      => false,
			'temperature' => 0.3, // Baja a propósito: menos creatividad, menos invenciones.
		);

		// Solo se ofrecen herramientas si quedan rondas para ejecutarlas.
		if ( $ronda < COVE_AI_MAX_ROUNDS ) {
			$cuerpo['tools']       = $tools;
			$cuerpo['tool_choice'] = 'auto';
		}

		$respuesta = wp_remote_post(
			COVE_AI_ENDPOINT,
			array(
				'timeout' => COVE_AI_TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $cuerpo ),
			)
		);

		if ( is_wp_error( $respuesta ) ) {
			return $respuesta;
		}

		$codigo       = (int) wp_remote_retrieve_response_code( $respuesta );
		$crudo        = (string) wp_remote_retrieve_body( $respuesta );
		$decodificado = json_decode( $crudo, true );

		if ( 200 !== $codigo || ! is_array( $decodificado ) ) {
			$detalle = '';
			if ( is_array( $decodificado ) && isset( $decodificado['error']['message'] ) ) {
				$detalle = (string) $decodificado['error']['message'];
			} else {
				$detalle = substr( $crudo, 0, 300 );
			}
			return new WP_Error( 'cove_ai_api_error', 'La API respondió HTTP ' . $codigo . ': ' . $detalle );
		}

		if ( ! isset( $decodificado['choices'][0]['message'] ) ) {
			return new WP_Error( 'cove_ai_forma_inesperada', 'La API devolvió una respuesta sin choices[0].message.' );
		}

		$mensaje    = $decodificado['choices'][0]['message'];
		$contenido  = isset( $mensaje['content'] ) ? (string) $mensaje['content'] : '';
		$tool_calls = isset( $mensaje['tool_calls'] ) ? $mensaje['tool_calls'] : array();

		// Sin herramientas estructuradas: puede ser una respuesta normal, o el
		// caso en que DeepSeek no tradujo su propio marcado (DSML) y la llamada
		// llegó como texto plano. Ver cove_ai_dsml_recuperar().
		if ( empty( $tool_calls ) ) {

			$recuperado = cove_ai_dsml_recuperar( $contenido );

			if ( $recuperado ) {
				$tool_calls = $recuperado['tool_calls'];
				// Se reconstruye el turno del asistente con la estructura que
				// la API espera, para poder continuar el ciclo.
				$mensaje = array(
					'role'       => 'assistant',
					'content'    => null,
					'tool_calls' => $tool_calls,
				);
				error_log( '[Cove AI] Recuperadas ' . count( $tool_calls ) . ' llamada(s) a herramienta que DeepSeek dejó en texto plano (DSML).' );
			} else {
				return array(
					// Se limpia el marcado por si quedó un envoltorio que no se
					// pudo recuperar: el cliente nunca debe verlo.
					'reply' => cove_ai_dsml_limpiar( $contenido ),
					'usage' => isset( $decodificado['usage'] ) ? $decodificado['usage'] : null,
				);
			}
		}

		// Hay herramientas pedidas. Se guarda el mensaje del asistente TAL CUAL.
		$messages[] = $mensaje;

		foreach ( $tool_calls as $llamada ) {

			$nombre = isset( $llamada['function']['name'] ) ? (string) $llamada['function']['name'] : '';
			$args   = array();
			if ( isset( $llamada['function']['arguments'] ) ) {
				$decodificados = json_decode( (string) $llamada['function']['arguments'], true );
				if ( is_array( $decodificados ) ) {
					$args = $decodificados;
				}
			}

			$resultado = cove_ai_run_tool( $nombre, $args, $session_id );

			$messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => isset( $llamada['id'] ) ? (string) $llamada['id'] : '',
				'name'         => $nombre,
				'content'      => wp_json_encode( $resultado ),
			);
		}
	}

	return new WP_Error( 'cove_ai_max_rondas', 'Se alcanzó el máximo de rondas de herramientas sin respuesta final.' );
}

/**
 * Convierte URLs peladas en enlaces Markdown.
 *
 * POR QUÉ HACE FALTA ESTO
 * -----------------------
 * Depurar el prompt no es suficiente. El widget renderiza el campo "text" con
 * Markdown (usa el renderizador remarkable), y remarkable NO autolinkea URLs
 * sueltas: si el modelo escribe https://... a secas, el cliente ve texto plano
 * y no puede tocarlo. Solo la sintaxis [texto](url) se convierte en un enlace.
 *
 * Esta función es la red de seguridad: pase lo que pase con el prompt, toda
 * URL que salga en la respuesta termina siendo un enlace tocable.
 *
 * No toca las URLs que ya vienen dentro de un enlace Markdown, para no anidar
 * corchetes ni duplicar el enlace.
 *
 * @param string $texto Respuesta del modelo.
 * @return string Respuesta con las URLs convertidas en enlaces.
 */
function cove_ai_linkify( $texto ) {
	if ( '' === trim( (string) $texto ) ) {
		return (string) $texto;
	}

	// 1. Proteger los enlaces que el modelo ya escribió bien.
	$guardados = array();
	$protegido = preg_replace_callback(
		'/\[[^\]]*\]\(\s*(https?:\/\/[^\s\)]+)\s*\)/i',
		function ( $coincidencia ) use ( &$guardados ) {
			$clave               = 'COVEAIENLACE' . count( $guardados ) . 'FIN';
			$guardados[ $clave ] = $coincidencia[0];
			return $clave;
		},
		(string) $texto
	);

	if ( null === $protegido ) {
		return (string) $texto; // Si la expresión falla, mejor devolver el original.
	}

	// 2. Convertir las URLs sueltas. Se respeta la puntuación final: en
	//    "mira https://x.com/producto." el punto no es parte de la URL.
	$convertido = preg_replace_callback(
		'~https?://[^\s<>()\[\]"\'`]+~i',
		function ( $coincidencia ) {
			$url   = rtrim( $coincidencia[0], '.,;:!?' );
			$sobra = substr( $coincidencia[0], strlen( $url ) );
			return '[' . $url . '](' . $url . ')' . $sobra;
		},
		$protegido
	);

	if ( null === $convertido ) {
		return (string) $texto;
	}

	// 3. Restaurar los enlaces originales.
	return str_replace( array_keys( $guardados ), array_values( $guardados ), $convertido );
}

/**
 * Convierte el historial que envía el widget al formato de la API.
 *
 * DeepChat usa los roles "user" y "ai" con el campo "text"; la API usa
 * "user" y "assistant" con "content". Aquí es donde se traduce, y aquí es
 * donde se descarta cualquier rol que no sea uno de esos dos: el navegador es
 * territorio hostil.
 *
 * @param array $entrante Lista cruda de mensajes del widget.
 * @return array Lista lista para la API.
 */
function cove_ai_normalizar_historial( $entrante ) {
	if ( ! is_array( $entrante ) ) {
		return array();
	}

	$salida = array();
	foreach ( array_slice( $entrante, -COVE_AI_HISTORY_KEEP ) as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$rol = isset( $item['role'] ) ? (string) $item['role'] : '';
		if ( 'ai' === $rol || 'assistant' === $rol ) {
			$rol = 'assistant';
		} elseif ( 'user' !== $rol ) {
			continue;
		}

		$texto = '';
		if ( isset( $item['text'] ) ) {
			$texto = (string) $item['text'];
		} elseif ( isset( $item['content'] ) ) {
			$texto = (string) $item['content'];
		}

		$texto = trim( $texto );
		if ( '' === $texto ) {
			continue;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$texto = mb_substr( $texto, 0, COVE_AI_MENSAJE_MAX, 'UTF-8' );
		} else {
			$texto = substr( $texto, 0, COVE_AI_MENSAJE_MAX );
		}

		$salida[] = array(
			'role'    => $rol,
			'content' => $texto,
		);
	}

	return $salida;
}
