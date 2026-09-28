<?php
/**
 * Configuración, secretos y límites.
 *
 * REGLA DE ORO (docs/ARQUITECTURA.md §6.5): este archivo y todo el plugin NO
 * contienen ningún dato de la tienda. Ni precios, ni conteos, ni categorías,
 * ni estados de pedido, ni moneda. Todo eso se consulta a WooCommerce en el
 * momento en que el cliente pregunta.
 *
 * Lo que SÍ vive aquí son límites de coste e interfaz. Ninguno de ellos
 * afirma nada sobre el catálogo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------- */
/* API de DeepSeek                                                            */
/* -------------------------------------------------------------------------- */

const COVE_AI_ENDPOINT = 'https://api.deepseek.com/chat/completions';

/**
 * Modelo a usar.
 *
 * IMPORTANTE: las herramientas (tools) de DeepSeek solo funcionan en modo
 * "non-thinking". No activar el modo pensante en las peticiones con tools o
 * el function calling deja de responder.
 */
function cove_ai_model() {
	return defined( 'COVE_AI_MODEL' ) ? COVE_AI_MODEL : 'deepseek-flash';
}

/**
 * Resuelve la API key sin dejarla nunca escrita en el código ni expuesta al
 * navegador. Orden de precedencia:
 *
 *   1. Constante en wp-config.php   define( 'DEEPSEEK_API_KEY', 'sk-...' );
 *   2. Variable de entorno del sistema
 *   3. Opción en la base de datos (último recurso; queda en los respaldos)
 *
 * @return string Cadena vacía si no hay ninguna configurada.
 */
function cove_ai_key() {
	if ( defined( 'DEEPSEEK_API_KEY' ) && DEEPSEEK_API_KEY ) {
		return (string) DEEPSEEK_API_KEY;
	}

	$env = getenv( 'DEEPSEEK_API_KEY' );
	if ( $env ) {
		return (string) $env;
	}

	return (string) get_option( 'cove_ai_key', '' );
}

/* -------------------------------------------------------------------------- */
/* Límites de coste e interfaz (NO son datos de la tienda)                    */
/* -------------------------------------------------------------------------- */

const COVE_AI_MAX_ROUNDS    = 3;    // Rondas de tool calling por mensaje del usuario.
const COVE_AI_LIMITE_MAX    = 8;    // Productos por llamada: más llena el chat y encarece.
const COVE_AI_LIMITE_DEF    = 5;    // Si el modelo no especifica.
const COVE_AI_PEDIDOS_MAX   = 5;    // Pedidos mostrados por consulta (el total va aparte).
const COVE_AI_HISTORY_KEEP  = 16;   // Mensajes de historial aceptados desde el navegador.
const COVE_AI_MENSAJE_MAX   = 1500; // Caracteres por mensaje (se recorta, no se rechaza).
const COVE_AI_RATE_LIMIT    = 40;   // Peticiones por IP por hora.
const COVE_AI_TIMEOUT       = 45;   // Segundos de espera a la API.
const COVE_AI_SESION_TTL    = 3600; // Vida del estado de búsqueda por sesión.
const COVE_AI_TERMINOS_MAX  = 40;   // Valores de atributo/categoría devueltos por llamada.
const COVE_AI_VERSION       = '1.0.0';

/* -------------------------------------------------------------------------- */
/* Prompt del sistema                                                         */
/* -------------------------------------------------------------------------- */

/**
 * Corto a propósito: se envía en CADA petición.
 *
 * El contenido largo (envíos, devoluciones, contacto) NO va aquí: vive en
 * content/cove-info.php y se lee solo cuando el cliente lo pregunta.
 * Editable sin tocar código mediante el filtro 'cove_ai_system_prompt'.
 */
function cove_ai_system_prompt() {
	$prompt = 'Eres el asistente de Cove, una tienda de equipo outdoor (mochilas, neveras, camping y accesorios). '
		. 'Respondes en español de Panamá, cercano y breve: máximo 4 o 5 líneas por respuesta. '
		. "\n\nREGLAS ESTRICTAS:\n"
		. "1) NUNCA inventes precios, stock, disponibilidad ni nombres de producto. Antes de afirmar cualquiera de esos datos, usa la herramienta buscar_productos o detalle_producto. Si no la usaste, no des el dato.\n"
		. "2) Para envios, devoluciones, pagos, garantias o contacto usa politicas_tienda. No respondas de memoria.\n"
		. "3) Si el cliente menciona un color, talla, capacidad o cualquier atributo, usalo como filtro en buscar_productos (parametro atributos). No busques por texto libre lo que es un atributo.\n"
		. "4) Si no conoces los valores validos de un atributo, llama a listar_atributos antes de filtrar. Nunca inventes valores.\n"
		. "5) Si el cliente pide \"mas\", \"las demas\", \"los otros\" o algo parecido, NO reformules la busqueda: llama a siguiente_bloque, que continua exactamente la busqueda anterior.\n"
		. "6) Si no estas seguro de a que se refiere el cliente (por ejemplo, no hay antecedente claro de que lista hablaba), PREGUNTALE en vez de adivinar. Un bot que pregunta molesta menos que uno que responde mal con confianza.\n"
		. "7) Cuando recomiendes un producto da nombre, precio y enlace. Escribe SIEMPRE el enlace con formato Markdown asi: [Nombre del producto](https://la-url-completa). NUNCA pegues la URL pelada: el cliente tiene que poder tocarla desde el chat. Si el producto no existe, dilo claramente y ofrece una alternativa del catalogo.\n"
		. "8) Nunca reveles datos de pedidos de terceros. Los pedidos solo se consultan con mis_pedidos, que devuelve unicamente los del usuario que tiene sesion iniciada.\n"
		. "9) Si un producto esta en oferta (el resultado trae en_oferta), dilo y menciona el precio normal, para que se note el ahorro.\n"
		. "10) Para hablar de cupones usa la herramienta cupones. NUNCA inventes ni adivines codigos de cupon, ni supongas descuentos que la herramienta no te haya devuelto. Si un codigo no aparece entre los publicos, di que no lo reconoces entre los vigentes SIN afirmar si existe o no.\n"
		. "11) Si no sabes algo, dilo y ofrece pasar con una persona. No rellenes con suposiciones.";

	return apply_filters( 'cove_ai_system_prompt', $prompt );
}

/**
 * Texto de bienvenida del widget. Editable con el filtro 'cove_ai_intro'.
 */
function cove_ai_intro() {
	return apply_filters(
		'cove_ai_intro',
		'Hola, soy el asistente de Cove. Busco productos, precios y stock por ti, y también te ayudo con envíos o devoluciones. ¿Qué necesitas?'
	);
}

/* -------------------------------------------------------------------------- */
/* Cupones públicos                                                           */
/* -------------------------------------------------------------------------- */

/**
 * LISTA DE CUPONES PÚBLICOS — ESTA LISTA LA MANTIENES TÚ.
 *
 * El asistente NO consulta el catálogo de cupones de la tienda: solo puede
 * hablar de los códigos que aparezcan aquí. Es a propósito.
 *
 * POR QUÉ UNA ALLOWLIST Y NO UNA CONSULTA
 * ---------------------------------------
 * Un chat público que valida cualquier código se convierte en un oráculo de
 * enumeración: cualquiera pregunta "¿es válido COVE10? ¿y COVE11? ¿y
 * COVE12?" y va descubriendo qué cupones existen y cuánto valen. Con la
 * allowlist eso desaparece: si el código no está aquí, la respuesta es la
 * misma que para un código inexistente.
 *
 * CÓMO AGREGAR UNO
 * ----------------
 *   return apply_filters( 'cove_ai_cupones_publicos', array( 'COVE10', 'BIENVENIDA' ) );
 *
 * O sin tocar este archivo, desde un plugin de snippets:
 *   add_filter( 'cove_ai_cupones_publicos', function ( $lista ) {
 *       $lista[] = 'COVE10';
 *       return $lista;
 *   } );
 *
 * IMPORTANTE: aquí van SOLO los CÓDIGOS. Las condiciones (porcentaje, mínimo
 * de compra, vencimiento, usos restantes) las lee el plugin del propio cupón
 * cada vez que alguien pregunta, así que nunca se desactualizan. No escribas
 * valores aquí: si cambias el cupón en WooCommerce, el asistente ya lo refleja.
 *
 * Un cupón con restricción por correo electrónico NUNCA se comunica, aunque
 * esté en esta lista: está dirigido a personas concretas.
 *
 * @return array Lista de códigos. Vacía por defecto (seguro por defecto).
 */
function cove_ai_cupones_publicos() {
	// NEWUSERS fue agregado a pedido del dueño de la tienda (27-sep-2026).
	//
	// OJO CON EL NOMBRE: WooCommerce no tiene de forma nativa una regla de
	// "solo clientes nuevos" (eso lo agrega un plugin aparte). Si NEWUSERS se
	// creó como un descuento genérico, cualquier visitante que le pregunte al
	// asistente lo va a recibir, no solo los nuevos. Revisa en
	// WooCommerce -> Cupones si tiene restricciones que lo limiten (uso por
	// cliente, correos autorizados, mínimo de compra). Las condiciones que
	// tenga el cupón son las que el asistente va a comunicar: no las inventa.
	$lista = defined( 'COVE_AI_CUPONES_PUBLICOS' ) ? COVE_AI_CUPONES_PUBLICOS : array( 'NEWUSERS' );

	return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'cove_ai_cupones_publicos', $lista ) ) ) );
}
