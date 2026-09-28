<?php
/**
 * Consultas a WooCommerce: el único lugar donde se toca el catálogo.
 *
 * Aquí viven las dos trampas documentadas en docs/ARQUITECTURA.md §6.6:
 *
 *  1. EL PRECIO NO SE FILTRA POR EL META `_price`.
 *     El `_price` del producto padre guarda el precio de su variación MÁS
 *     BARATA. Un filtro de "hasta 150" sobre ese meta descarta o incluye
 *     productos variables de forma incorrecta. Lo correcto es usar la tabla
 *     `wc_product_meta_lookup`, que tiene min_price y max_price indexadas y
 *     abarca todas las variaciones. Es lo que usa WooCommerce internamente.
 *
 *  2. NO TODOS LOS ATRIBUTOS SE PUEDEN FILTRAR.
 *     Solo los atributos globales (pa_color, pa_talla) son taxonomías con
 *     términos. Un atributo personalizado vive como texto dentro de un
 *     postmeta del producto: no hay nada contra lo que hacer un JOIN. Cuando
 *     eso pasa, se devuelve un error EXPLÍCITO con los atributos que sí
 *     sirven. Nunca cero resultados en silencio: el bot diría "no hay
 *     mochilas azules" cuando sí las hay.
 *
 * Nota de carga: nada de este archivo se ejecuta al cargar el plugin. Las
 * funciones de WooCommerce (wc_get_product, wc_price...) no existen todavía
 * cuando WordPress carga los mu-plugins; sí existen cuando entra una petición.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normaliza una cadena para comparar: minúsculas y sin acentos.
 */
function cove_ai_normaliza( $texto ) {
	$texto = is_string( $texto ) ? $texto : '';
	$texto = remove_accents( $texto );
	$texto = function_exists( 'mb_strtolower' ) ? mb_strtolower( $texto, 'UTF-8' ) : strtolower( $texto );
	return trim( $texto );
}

/**
 * Atributos globales filtrables del catálogo, indexados por nombre y por etiqueta.
 *
 * Se lee de WooCommerce en cada petición: si mañana agregas un atributo, el
 * bot lo ve sin tocar código.
 *
 * @return array<string,array{taxonomy:string,label:string,name:string}>
 */
function cove_ai_attribute_map() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$cache = array();
	if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
		return $cache;
	}

	foreach ( wc_get_attribute_taxonomies() as $attr ) {
		$taxonomy = wc_attribute_taxonomy_name( $attr->attribute_name );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue; // Existe en la configuración pero no como taxonomía: no es filtrable.
		}
		$item = array(
			'taxonomy' => $taxonomy,
			'name'     => (string) $attr->attribute_name,
			'label'    => (string) $attr->attribute_label,
		);
		$cache[ cove_ai_normaliza( $attr->attribute_name ) ] = $item;
		$cache[ cove_ai_normaliza( $attr->attribute_label ) ] = $item;
		$cache[ cove_ai_normaliza( $taxonomy ) ] = $item;
	}

	return $cache;
}

/**
 * Resuelve el nombre que dio el modelo a un atributo global real.
 *
 * @return array{taxonomy:string,label:string,name:string}|null
 */
function cove_ai_resolve_attribute( $nombre ) {
	$map = cove_ai_attribute_map();
	$key = cove_ai_normaliza( $nombre );
	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}
	// "pa_color" → "color" y variantes con espacios o guiones.
	$key = cove_ai_normaliza( str_replace( array( 'pa_', 'pa-', ' ' ), '', $key ) );
	return $map[ $key ] ?? null;
}

/**
 * Busca un término dentro de una taxonomía de atributo, tolerando mayúsculas,
 * acentos y nombres parciales ("azul" encuentra "Azul Marino").
 *
 * @return WP_Term|WP_Error
 */
function cove_ai_find_term( $taxonomy, $valor ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return new WP_Error( 'cove_ai_sin_terminos', 'Ese atributo no tiene valores asignados en el catálogo.' );
	}

	$buscado = cove_ai_normaliza( $valor );

	$disponibles = array();
	foreach ( $terms as $term ) {
		$name_n = cove_ai_normaliza( $term->name );
		$slug_n = cove_ai_normaliza( $term->slug );

		$disponibles[] = $term->name;

		if ( $buscado === $name_n || $buscado === $slug_n ) {
			return $term; // Coincidencia exacta.
		}
	}

	// Coincidencia parcial: si hay una sola que contiene el texto, es esa.
	$parciales = array();
	foreach ( $terms as $term ) {
		$name_n = cove_ai_normaliza( $term->name );
		if ( '' !== $buscado && ( false !== strpos( $name_n, $buscado ) || false !== strpos( $buscado, $name_n ) ) ) {
			$parciales[] = $term;
		}
	}
	if ( 1 === count( $parciales ) ) {
		return $parciales[0];
	}

	return new WP_Error(
		'cove_ai_valor_invalido',
		'El valor "' . $valor . '" no corresponde a ninguno de los valores válidos de ese atributo. Valores disponibles: ' . implode( ', ', array_slice( $disponibles, 0, COVE_AI_TERMINOS_MAX ) ) . '.'
	);
}

/**
 * Construye y ejecuta una búsqueda de productos.
 *
 * @param array  $args       Argumentos del modelo (termino, categoria, precio_min, ...).
 * @param string $session_id Sesión, para guardar el estado de paginación.
 * @return array|WP_Error
 */
function cove_ai_search_products( array $args, $session_id = '' ) {

	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error( 'cove_ai_sin_wc', 'WooCommerce no está activo en este sitio.' );
	}

	global $wpdb;

	$termino       = isset( $args['termino'] ) ? sanitize_text_field( (string) $args['termino'] ) : '';
	$categoria     = isset( $args['categoria'] ) ? sanitize_text_field( (string) $args['categoria'] ) : '';
	$solo_en_stock = ! empty( $args['solo_en_stock'] );
	$en_oferta     = ! empty( $args['en_oferta'] );

	$precio_min = ( isset( $args['precio_min'] ) && '' !== $args['precio_min'] ) ? (float) $args['precio_min'] : null;
	$precio_max = ( isset( $args['precio_max'] ) && '' !== $args['precio_max'] ) ? (float) $args['precio_max'] : null;
	if ( null !== $precio_min && null !== $precio_max && $precio_min > $precio_max ) {
		$swap       = $precio_min;
		$precio_min = $precio_max;
		$precio_max = $swap;
	}

	$limite_default = COVE_AI_LIMITE_DEF;
	$limite         = isset( $args['limite'] ) ? absint( $args['limite'] ) : $limite_default;
	$limite         = max( 1, min( COVE_AI_LIMITE_MAX, $limite ? $limite : $limite_default ) );
	$offset         = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

	$ordenes_validos = array( 'relevancia', 'novedad', 'precio_asc', 'precio_desc', 'nombre' );
	$orden           = isset( $args['orden'] ) ? (string) $args['orden'] : 'relevancia';
	if ( ! in_array( $orden, $ordenes_validos, true ) ) {
		$orden = 'relevancia';
	}

	/* --------------------------------------------------------------------- */
	/* tax_query: categoría, atributos y visibilidad                          */
	/* --------------------------------------------------------------------- */

	$tax_query    = array();
	$atributos_ok = array(); // Para el estado de sesión.

	// Respetar la visibilidad configurada en la tienda (productos ocultos del catálogo).
	$tax_query[] = array(
		'taxonomy' => 'product_visibility',
		'field'    => 'name',
		'terms'    => array( 'exclude-from-catalog' ),
		'operator' => 'NOT IN',
	);

	if ( '' !== $categoria ) {
		$cat = get_term_by( 'slug', sanitize_title( $categoria ), 'product_cat' );
		if ( ! $cat ) {
			$cat = get_term_by( 'name', $categoria, 'product_cat' );
		}
		if ( ! $cat ) {
			$todas = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => COVE_AI_TERMINOS_MAX ) );
			$lista = array();
			if ( ! is_wp_error( $todas ) ) {
				foreach ( $todas as $t ) {
					$lista[] = $t->name;
				}
			}
			return new WP_Error( 'cove_ai_cat_invalida', 'La categoría "' . $categoria . '" no existe. Categorías disponibles: ' . implode( ', ', $lista ) . '.' );
		}
		$tax_query[] = array(
			'taxonomy' => 'product_cat',
			'field'    => 'term_id',
			'terms'    => array( (int) $cat->term_id ),
		);
	}

	// Atributos: array de {atributo, valor}. Varios valores del MISMO atributo = O.
	if ( ! empty( $args['atributos'] ) && is_array( $args['atributos'] ) ) {
		$por_taxonomia = array();

		foreach ( $args['atributos'] as $par ) {
			if ( ! is_array( $par ) || empty( $par['atributo'] ) || empty( $par['valor'] ) ) {
				continue;
			}
			$attr = cove_ai_resolve_attribute( (string) $par['atributo'] );
			if ( ! $attr ) {
				$validos = array();
				foreach ( cove_ai_attribute_map() as $item ) {
					$validos[ $item['taxonomy'] ] = $item['label'];
				}
				$validos = array_unique( $validos );
				return new WP_Error(
					'cove_ai_attr_no_filtrable',
					'El atributo "' . $par['atributo'] . '" no es un atributo global, así que no se puede filtrar por él. Atributos filtrables: ' . implode( ', ', $validos ) . '. Si el cliente pidió algo por ese atributo, dile que lo busque por texto libre en su lugar.'
				);
			}

			$term = cove_ai_find_term( $attr['taxonomy'], (string) $par['valor'] );
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			$por_taxonomia[ $attr['taxonomy'] ][] = (int) $term->term_id;
			$atributos_ok[]                       = array(
				'atributo' => $attr['name'],
				'valor'    => $term->name,
			);
		}

		foreach ( $por_taxonomia as $taxonomy => $term_ids ) {
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => array_values( array_unique( $term_ids ) ),
				'operator' => 'IN', // Varios valores del mismo atributo: azul O rojo.
			);
		}
	}

	if ( count( $tax_query ) > 1 ) {
		$tax_query['relation'] = 'AND'; // Atributos distintos se combinan con Y.
	}

	/* --------------------------------------------------------------------- */
	/* meta_query: stock                                                      */
	/* --------------------------------------------------------------------- */

	$meta_query = array();
	if ( $solo_en_stock ) {
		$meta_query[] = array(
			'key'     => '_stock_status',
			'value'   => 'instock',
			'compare' => '=',
		);
	}

	/* --------------------------------------------------------------------- */
	/* WP_Query                                                               */
	/* --------------------------------------------------------------------- */

	$query_args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => $limite,
		'offset'         => $offset,
		// OJO: nunca poner 'no_found_rows' => true aquí.
		// Esa opción mata found_posts, y sin found_posts no hay "total",
		// y sin total el bot no puede responder "y las demás que haya".
		'no_found_rows'  => false,
	);

	if ( '' !== $termino ) {
		$query_args['s'] = $termino;
	}
	if ( count( $tax_query ) > 0 ) {
		$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( count( $meta_query ) > 0 ) {
		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	$orden_por_precio = in_array( $orden, array( 'precio_asc', 'precio_desc' ), true );
	$tabla_lookup     = $wpdb->prefix . 'wc_product_meta_lookup';
	$necesita_lookup  = ( null !== $precio_min || null !== $precio_max || $orden_por_precio || $en_oferta );

	switch ( $orden ) {
		case 'novedad':
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
			break;
		case 'nombre':
			$query_args['orderby'] = 'title';
			$query_args['order']   = 'ASC';
			break;
		case 'precio_asc':
		case 'precio_desc':
		default:
			// 'relevancia' usa el orden natural de WP_Query (fecha si no hay búsqueda).
			if ( '' !== $termino && 'relevancia' === $orden ) {
				$query_args['orderby'] = 'relevance';
				$query_args['order']   = 'DESC';
			}
			break;
	}

	/**
	 * Filtro sobre las cláusulas SQL: aquí se une wc_product_meta_lookup.
	 *
	 * Es la única parte del plugin donde se escribe SQL propio, y está
	 * justificado: la alternativa (filtrar en PHP después de traer productos)
	 * rompería `found_posts` y con él la paginación de "y las demás".
	 * Todo va con $wpdb->prepare y es de solo lectura.
	 */
	$clauses_cb = null;
	if ( $necesita_lookup ) {
		$clauses_cb = function ( $clauses ) use ( $wpdb, $tabla_lookup, $precio_min, $precio_max, $orden, $en_oferta ) {
			$clauses['join'] .= " INNER JOIN {$tabla_lookup} AS covelookup ON ( {$wpdb->posts}.ID = covelookup.product_id )";

			// El flag de oferta vive en la MISMA tabla que ya unimos para el
			// precio, así que filtrar por ofertas no cuesta una consulta más.
			//
			// OJO: esta tabla se desincroniza después de importaciones masivas.
			// Síntoma: no aparecen ofertas aunque los productos se vean
			// rebajados en su página (el flag queda en 0 mientras _sale_price
			// sí está en la base). Solución:
			//   wp wc tool run regenerate_product_lookup_tables
			if ( $en_oferta ) {
				$clauses['where'] .= ' AND covelookup.onsale = 1 ';
			}

			if ( null !== $precio_min ) {
				// Un producto entra si su rango de precios toca el rango pedido.
				$clauses['where'] .= $wpdb->prepare( ' AND covelookup.max_price >= %f ', $precio_min );
			}
			if ( null !== $precio_max ) {
				$clauses['where'] .= $wpdb->prepare( ' AND covelookup.min_price <= %f ', $precio_max );
			}
			if ( 'precio_asc' === $orden ) {
				$clauses['orderby'] = 'covelookup.min_price ASC';
			} elseif ( 'precio_desc' === $orden ) {
				$clauses['orderby'] = 'covelookup.min_price DESC';
			}
			return $clauses;
		};
		add_filter( 'posts_clauses', $clauses_cb );
	}

	$query = new WP_Query( $query_args );

	if ( $clauses_cb ) {
		remove_filter( 'posts_clauses', $clauses_cb );
	}

	/* --------------------------------------------------------------------- */
	/* Respaldo por SKU exacto                                                 */
	/* --------------------------------------------------------------------- */

	if ( '' !== $termino && 0 === (int) $query->found_posts && function_exists( 'wc_get_product_id_by_sku' ) ) {
		$por_sku = wc_get_product_id_by_sku( $termino );
		if ( $por_sku ) {
			$query_args['s']       = '';
			$query_args['post__in'] = array( $por_sku );
			$query = new WP_Query( $query_args );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Resultado                                                              */
	/* --------------------------------------------------------------------- */

	$productos = array();
	foreach ( $query->posts as $post_id ) {
		$producto = wc_get_product( $post_id );
		if ( $producto ) {
			$productos[] = cove_ai_format_producto( $producto );
		}
	}

	$total = (int) $query->found_posts;

	// Estado de sesión: argumentos, no resultados. Es lo que permite
	// "y las demás" aunque el historial del navegador se haya recortado.
	$args_estado = array(
		'termino'       => $termino,
		'categoria'     => $categoria,
		'precio_min'    => $precio_min,
		'precio_max'    => $precio_max,
		'atributos'     => $atributos_ok,
		'solo_en_stock' => $solo_en_stock,
		'en_oferta'     => $en_oferta,
		'orden'         => $orden,
		'limite'        => $limite,
	);
	cove_ai_state_set( $session_id, $args_estado, $offset + count( $productos ), $offset + count( $productos ), $total );

	$salida = array(
		'ok'        => true,
		'total'     => $total,
		'offset'    => $offset,
		'mostrados' => count( $productos ),
		'productos' => $productos,
	);

	if ( 0 === $total ) {
		$salida['nota'] = 'Sin resultados con esos criterios. No afirmes que el producto no existe en la tienda: ofrece quitar algún filtro o buscar de otra forma.';
	} elseif ( $offset + count( $productos ) < $total ) {
		$salida['nota'] = 'Hay ' . ( $total - ( $offset + count( $productos ) ) ) . ' producto(s) más con estos criterios. Si el cliente pide ver más, usa siguiente_bloque.';
	}

	return $salida;
}

/**
 * Datos de oferta de un producto, leídos en el momento.
 *
 * Nada de esto se escribe en el código: is_on_sale(), el precio regular y el
 * rango de variaciones los calcula WooCommerce en cada consulta.
 *
 * Si el producto es variable, se usa el rango de variaciones
 * (get_variation_regular_price) porque get_regular_price() devuelve vacío en
 * ese tipo de producto.
 */
function cove_ai_datos_de_oferta( $producto ) {

	if ( ! $producto->is_on_sale() ) {
		return array( 'en_oferta' => false );
	}

	$regular = $producto->is_type( 'variable' )
		? $producto->get_variation_regular_price( 'min' )
		: $producto->get_regular_price();

	if ( '' === $regular || null === $regular ) {
		// Está en oferta pero no hay un precio regular comparable del que
		// informar: mejor decir solo que está rebajado que inventar una cifra.
		return array( 'en_oferta' => true );
	}

	return array(
		'en_oferta'     => true,
		'precio_normal' => wp_strip_all_tags(
			wc_price( wc_get_price_to_display( $producto, array( 'price' => $regular ) ) )
		),
	);
}

/**
 * Da formato a un producto. Todos los valores se leen del objeto en el
 * momento: precio con impuestos y moneda de la tienda, stock según WooCommerce,
 * y datos de oferta si el producto está rebajado.
 */
function cove_ai_format_producto( $producto ) {
	$salida = array(
		'id'     => $producto->get_id(),
		'sku'    => $producto->get_sku(),
		'nombre' => $producto->get_name(),
		// get_price_to_display devuelve el precio EFECTIVO: si hay oferta, es
		// el precio de oferta. Por eso el bot cotiza bien sin hacer nada más.
		'precio' => wp_strip_all_tags( wc_price( wc_get_price_to_display( $producto ) ) ),
		'stock'  => $producto->is_in_stock() ? 'disponible' : 'agotado',
		'enlace' => get_permalink( $producto->get_id() ),
	);

	return array_merge( $salida, cove_ai_datos_de_oferta( $producto ) );
}

/**
 * Detalle completo de un producto, incluidas sus variaciones.
 *
 * @param string $llave SKU o ID.
 * @return array|WP_Error
 */
function cove_ai_detalle_producto( $llave ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error( 'cove_ai_sin_wc', 'WooCommerce no está activo en este sitio.' );
	}

	$llave = trim( (string) $llave );
	if ( '' === $llave ) {
		return new WP_Error( 'cove_ai_llave_vacia', 'Falta el SKU o el ID.' );
	}

	$product_id = ctype_digit( $llave ) ? absint( $llave ) : wc_get_product_id_by_sku( $llave );
	$producto   = $product_id ? wc_get_product( $product_id ) : false;
	if ( ! $producto ) {
		return new WP_Error( 'cove_ai_no_existe', 'No existe un producto con la referencia "' . $llave . '".' );
	}

	$variaciones = array();
	if ( $producto->is_type( 'variable' ) ) {
		foreach ( $producto->get_children() as $child_id ) {
			$variacion = wc_get_product( $child_id );
			if ( ! $variacion ) {
				continue;
			}
			$variaciones[] = array(
				'sku'       => $variacion->get_sku(),
				'atributos' => wp_strip_all_tags( wc_get_formatted_variation( $variacion, true, false, false ) ),
				'precio'    => wp_strip_all_tags( wc_price( wc_get_price_to_display( $variacion ) ) ),
				'stock'     => $variacion->is_in_stock() ? 'disponible' : 'agotado',
			);
		}
	}

	$salida = array(
		'ok'          => true,
		'id'          => $producto->get_id(),
		'sku'         => $producto->get_sku(),
		'nombre'      => $producto->get_name(),
		'precio'      => wp_strip_all_tags( wc_price( wc_get_price_to_display( $producto ) ) ),
		'stock'       => $producto->is_in_stock() ? 'disponible' : 'agotado',
		'descripcion' => wp_strip_all_tags( $producto->get_short_description() ),
		'enlace'      => get_permalink( $producto->get_id() ),
	);

	$salida = array_merge( $salida, cove_ai_datos_de_oferta( $producto ) );

	if ( $variaciones ) {
		$salida['variaciones'] = $variaciones;
	}

	return $salida;
}

/**
 * Categorías reales del catálogo, con sus conteos.
 *
 * Sin topes fijos: si la tienda crece, esto crece. El único corte es el de
 * tamaño de respuesta (COVE_AI_TERMINOS_MAX) y se informa cuando aplica.
 */
function cove_ai_listar_categorias() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array( 'ok' => false, 'nota' => 'No hay categorías de producto con productos publicados.' );
	}

	$categorias = array();
	foreach ( $terms as $term ) {
		$categorias[] = array(
			'nombre'    => $term->name,
			'slug'      => $term->slug,
			'productos' => (int) $term->count,
		);
	}

	$total = count( $categorias );
	$salida = array(
		'ok'              => true,
		'total_categorias' => $total,
		'categorias'      => array_slice( $categorias, 0, COVE_AI_TERMINOS_MAX ),
	);
	if ( $total > COVE_AI_TERMINOS_MAX ) {
		$salida['nota'] = 'Se listan las primeras ' . COVE_AI_TERMINOS_MAX . ' de ' . $total . ' categorías.';
	}
	return $salida;
}

/**
 * Atributos filtrables y sus valores reales.
 *
 * Imprescindible: sin conocer los valores que existen, el modelo adivina
 * "azul" y si la tienda usa "Azul Marino" obtiene cero resultados y parece
 * roto. Los valores nunca se escriben en el código: se leen de la taxonomía.
 *
 * @param string $atributo Opcional: limita la respuesta a un atributo.
 */
function cove_ai_listar_atributos( $atributo = '' ) {
	$map = cove_ai_attribute_map();
	if ( empty( $map ) ) {
		return array( 'ok' => false, 'nota' => 'La tienda no tiene atributos globales, así que no hay filtros por atributo disponibles. Busca por texto libre.' );
	}

	$unicos = array();
	foreach ( $map as $item ) {
		$unicos[ $item['taxonomy'] ] = $item;
	}

	if ( '' !== $atributo ) {
		$resuelto = cove_ai_resolve_attribute( $atributo );
		if ( ! $resuelto ) {
			$lista = array();
			foreach ( $unicos as $item ) {
				$lista[] = $item['label'];
			}
			return array(
				'ok'    => false,
				'nota'  => 'El atributo "' . $atributo . '" no es un atributo global filtrable.',
				'filtrables' => $lista,
			);
		}
		$unicos = array( $resuelto['taxonomy'] => $resuelto );
	}

	$salida = array();
	foreach ( $unicos as $taxonomy => $item ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}
		$valores = array();
		foreach ( $terms as $term ) {
			if ( $term->count > 0 ) {
				$valores[] = $term->name;
			}
		}
		if ( empty( $valores ) ) {
			continue;
		}
		$salida[] = array(
			'atributo' => $item['label'],
			'clave'    => $item['name'],
			'valores'  => array_slice( $valores, 0, COVE_AI_TERMINOS_MAX ),
		);
	}

	if ( empty( $salida ) ) {
		return array( 'ok' => false, 'nota' => 'Ningún atributo tiene valores con productos publicados.' );
	}

	return array( 'ok' => true, 'atributos' => $salida );
}
