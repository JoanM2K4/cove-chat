<?php
/**
 * Esquemas JSON de las herramientas que el modelo puede llamar.
 *
 * DISEÑO CLAVE: hay UNA herramienta de búsqueda con N parámetros OPCIONALES,
 * no una herramienta por filtro ni por combinación. Combinar "precio + color +
 * categoría" no cuesta código: son más llaves puestas en el mismo objeto.
 *
 * Ver docs/TOOLS.md para el contrato completo de cada herramienta.
 *
 * Detalle de implementación: para las herramientas sin parámetros se usa
 * (object) array() y no array() a propósito. Un array vacío en PHP se
 * codifica como [] y algunos proveedores rechazan un `properties` que no sea
 * un objeto JSON.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array Lista de herramientas en formato OpenAI/DeepSeek.
 */
function cove_ai_tools() {
	$sin_propiedades = array(
		'type'       => 'object',
		'properties' => (object) array(),
	);

	return array(

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'buscar_productos',
				'description' => 'Busca productos en el catálogo de Cove. TODOS los parámetros de filtro son opcionales y se pueden combinar libremente. Es la única herramienta necesaria para buscar: no inventes variantes como "buscar_por_color" o "buscar_baratos". Devuelve el total de coincidencias y una página de resultados; usa "offset" o siguiente_bloque para ver más.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'termino'       => array(
							'type'        => 'string',
							'description' => 'Texto libre a buscar en el nombre y contenido del producto. Ej: "mochila", "nevera", "beluga". No lo uses para colores, tallas ni capacidades: eso son atributos.',
						),
						'categoria'     => array(
							'type'        => 'string',
							'description' => 'Nombre o slug de una categoría del catálogo. Si no conoces las categorías reales, usa listar_categorias primero.',
						),
						'precio_min'    => array(
							'type'        => 'number',
							'description' => 'Precio mínimo (en la moneda de la tienda). Se compara contra el rango real de precios del producto, variaciones incluidas.',
						),
						'precio_max'    => array(
							'type'        => 'number',
							'description' => 'Precio máximo (en la moneda de la tienda). Ej: el cliente dice "de menos de 100".',
						),
						'atributos'     => array(
							'type'        => 'array',
							'description' => 'Filtros por atributo del producto (color, talla, capacidad, material...). Cada elemento es un par atributo/valor. Si repites el mismo atributo con valores distintos, se interpreta como "cualquiera de ellos" (azul O rojo). Atributos distintos se combinan con Y. Solo funcionan atributos globales: consulta listar_atributos si dudas de los valores válidos.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'atributo' => array(
										'type'        => 'string',
										'description' => 'Nombre del atributo, ej. "color" o "Color".',
									),
									'valor'    => array(
										'type'        => 'string',
										'description' => 'Valor del atributo, ej. "azul". Se toleran mayúsculas y acentos.',
									),
								),
								'required'   => array( 'atributo', 'valor' ),
							),
						),
						'solo_en_stock' => array(
							'type'        => 'boolean',
							'description' => 'Si es true, solo devuelve productos disponibles. Úsalo cuando el cliente pregunte por algo disponible o urgente.',
						),
						'en_oferta'     => array(
							'type'        => 'boolean',
							'description' => 'Si es true, solo devuelve productos REBAJADOS (con precio de oferta activo). Úsalo cuando el cliente pregunte por ofertas, rebajas, descuentos o "qué está en promoción".',
						),
						'orden'         => array(
							'type'        => 'string',
							'enum'        => array( 'relevancia', 'novedad', 'precio_asc', 'precio_desc', 'nombre' ),
							'description' => 'Orden de los resultados. "precio_asc" para "el más barato primero".',
						),
						'limite'        => array(
							'type'        => 'integer',
							'description' => 'Cuántos productos devolver (1 a 8). Por defecto 5.',
						),
						'offset'        => array(
							'type'        => 'integer',
							'description' => 'Desde qué posición empezar, para paginar. Normalmente usa siguiente_bloque en vez de esto.',
						),
					),
				),
			),
		),

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'siguiente_bloque',
				'description' => 'Devuelve el bloque SIGUIENTE de la última búsqueda de esta conversación. Úsala cuando el cliente diga "y las demás", "los otros", "muéstrame más" o similar. No acepta parámetros a propósito: continúa exactamente la búsqueda anterior aunque el historial se haya recortado.',
				'parameters'  => $sin_propiedades,
			),
		),

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'detalle_producto',
				'description' => 'Devuelve el detalle completo de UN producto por su SKU o su ID: precio, stock, descripción corta y TODAS sus variaciones con sus atributos. Úsala cuando el cliente quiera saber colores, tallas o capacidades disponibles de un producto concreto.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'sku_o_id' => array(
							'type'        => 'string',
							'description' => 'SKU o ID numérico del producto.',
						),
					),
					'required'   => array( 'sku_o_id' ),
				),
			),
		),

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'listar_categorias',
				'description' => 'Lista las categorías reales del catálogo con cuántos productos tiene cada una. Úsala cuando el cliente no sepa qué buscar, o antes de filtrar por categoría si no conoces el nombre exacto.',
				'parameters'  => $sin_propiedades,
			),
		),

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'listar_atributos',
				'description' => 'Devuelve los atributos globales filtrables y sus valores reales (por ejemplo: color → azul, rojo, verde). Úsala antes de filtrar por un atributo si no sabes con certeza qué valores existen. Nunca inventes valores.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'atributo' => array(
							'type'        => 'string',
							'description' => 'Opcional. Limita la respuesta a un atributo (ej. "color"). Si se omite, devuelve todos.',
						),
					),
				),
			),
		),

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'politicas_tienda',
				'description' => 'Devuelve la información oficial de la tienda: envíos, devoluciones, pagos, garantías y contacto. Úsala para cualquier pregunta de ese tipo en lugar de responder de memoria. Si no sabes qué secciones hay, llámala sin parámetros.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'seccion' => array(
							'type'        => 'string',
							'description' => 'Opcional. Sección concreta, ej. "envios", "devoluciones", "contacto".',
						),
					),
				),
			),
		),

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'cupones',
				'description' => 'Devuelve los cupones PÚBLICOS vigentes de la tienda con sus condiciones: tipo de descuento, mínimo de compra, si incluye envío gratis, fecha de vencimiento y usos restantes. Sin parámetros devuelve la lista completa. Con el parámetro "codigo" consulta uno en concreto. IMPORTANTE: solo conoce los cupones que la tienda publicó; si un código no aparece, la respuesta es la misma que para un código inexistente. NUNCA inventes ni adivines códigos de cupón.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'codigo' => array(
							'type'        => 'string',
							'description' => 'Opcional. El código exacto que mencionó el cliente, por ejemplo COVE10.',
						),
					),
				),
			),
		),

		// ---------------------------------------------------------------- //
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'mis_pedidos',
				'description' => 'Devuelve los pedidos del usuario que tiene la sesión iniciada: número, fecha, estado, total y artículos. NO acepta parámetros de cliente a propósito: siempre son los pedidos de quien está conectado. Si el cliente no tiene sesión iniciada, responde pidiéndole que entre a su cuenta. Nunca prometas pedidos de otra persona ni de un invitado.',
				'parameters'  => $sin_propiedades,
			),
		),
	);
}
