<?php
/**
 * Widget en el frontend: el componente DeepChat autoalojado.
 *
 * Reparto de responsabilidades: el componente se encarga de la interfaz, el
 * historial y el reenvío de mensajes; este archivo solo lo incrusta y le pasa
 * la configuración.
 *
 * DOS DETALLES DE LOS COMPONENTES WEB QUE IMPORTAN AQUÍ:
 *
 *  1. Un <script type="module"> está diferido: se ejecuta DESPUÉS de los
 *     scripts clásicos escritos más abajo. Si asignáramos las propiedades del
 *     componente directamente, lo haríamos antes de que exista, y la
 *     asignación quedaría como propiedad propia del elemento, tapando los
 *     accesores de la clase. El componente no se enteraría del cambio.
 *     Por eso todo va dentro de customElements.whenDefined().
 *
 *  2. La configuración se imprime como JSON en un <script type="application/
 *     json"> para no pelear con el escapado de comillas dentro de atributos.
 *
 * EL NONCE: se inyecta solo para usuarios con sesión iniciada, y es necesario
 * para que la API REST de WordPress los reconozca como conectados (sin el
 * header X-WP-Nonce, WordPress trata la petición como anónima y mis_pedidos
 * respondería "inicia sesión" a alguien que ya la inició). No se inyecta para
 * visitantes: un nonce horneado en HTML se vence si LiteSpeed sirve la página
 * desde cache, y el chat devolvería 403 sin explicación.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuración que se pasa al componente.
 */
function cove_ai_widget_config() {

	$connect = array(
		'url'                 => rest_url( COVE_AI_REST_NAMESPACE . '/chat' ),
		'method'              => 'POST',
		'credentials'         => 'same-origin',
		// Propiedades extra que se añaden al cuerpo de cada petición.
		// session_id permite al servidor recordar la última búsqueda y
		// responder bien a "y las demás que haya".
		'additionalBodyProps' => array(
			'session_id' => wp_generate_uuid4(),
		),
	);

	if ( is_user_logged_in() ) {
		$connect['headers'] = array(
			'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ),
		);
	}

	$config = array(
		'connect'              => $connect,
		// Memoria de conversación: el componente guarda los mensajes en el
		// navegador y reenvía solo los últimos N. Los resultados de las
		// herramientas NO viajan aquí: se consumen en el servidor.
		'requestBodyLimits'    => array(
			'maxMessages'                => COVE_AI_HISTORY_KEEP,
			'totalMessagesMaxCharLength' => 6000,
		),
		'displayLoadingBubble' => true,
		'errorMessages'        => array(
			'displayServiceErrorMessages' => true,
		),
		'messageStyles'        => array(
			'default' => array(
				'shared' => array(
					'bubble' => array( 'maxWidth' => '290px' ),
				),
			),
		),
	);

	// Historial persistente entre recargas (localStorage del navegador).
	// Se puede desactivar con: add_filter( 'cove_ai_persistir_historial', '__return_false' );
	if ( apply_filters( 'cove_ai_persistir_historial', true ) ) {
		$config['browserStorage'] = array(
			'key'         => 'cove-ai-historial',
			'maxMessages' => 60,
			'textInput'   => false, // No guardar lo que se estaba escribiendo a medias.
		);
	}

	$config['introMessage'] = array( 'text' => cove_ai_intro() );

	return apply_filters( 'cove_ai_widget_config', $config );
}

/**
 * Encola el bundle del componente.
 *
 * ANTES: el <script src> se imprimía a mano desde cove_ai_print_widget(), que
 * es la violación WordPress.WP.EnqueuedResources.NonEnqueuedScript. Encolarlo
 * permite que WordPress lo combine, lo versione y lo mueva al pie cuando toca.
 */
function cove_ai_enqueue_assets() {

	if ( is_admin() || ! defined( 'WPMU_PLUGIN_URL' ) ) {
		return;
	}

	wp_enqueue_script(
		'cove-ai-deepchat',
		WPMU_PLUGIN_URL . '/cove-ai-chat/assets/deepChat.min.js',
		array(),
		COVE_AI_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'cove_ai_enqueue_assets' );

/**
 * El componente se distribuye como módulo ES y wp_enqueue_script no tiene un
 * argumento para eso, así que se añade el atributo al tag encolado.
 */
function cove_ai_script_module_tag( $tag, $handle ) {

	if ( 'cove-ai-deepchat' === $handle ) {
		return str_replace( ' src', ' type="module" src', $tag );
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'cove_ai_script_module_tag', 10, 2 );

/**
 * Imprime el widget en el pie de página.
 */
function cove_ai_print_widget() {

	if ( is_admin() || ! defined( 'WPMU_PLUGIN_URL' ) ) {
		return;
	}

	// JSON_HEX_TAG evita que un "</script>" dentro de los datos (una política
	// de la tienda, por ejemplo) rompa el bloque de configuración.
	$config = wp_json_encode( cove_ai_widget_config(), JSON_HEX_TAG );
	$titulo = apply_filters( 'cove_ai_widget_titulo', 'Asistente Cove' );

	$css = <<<'CSS'
#cove-ai-bubble{position:fixed;right:22px;bottom:22px;width:58px;height:58px;border-radius:50%;background:#1f2937;color:#fff;border:0;cursor:pointer;font-size:24px;line-height:1;box-shadow:0 6px 20px rgba(0,0,0,.28);z-index:999998;transition:transform .15s ease}
#cove-ai-bubble:hover{transform:scale(1.06)}
#cove-ai-panel{position:fixed;right:22px;bottom:92px;width:390px;max-width:calc(100vw - 32px);height:560px;max-height:calc(100vh - 140px);background:#fff;border-radius:14px;box-shadow:0 14px 44px rgba(0,0,0,.3);display:none;flex-direction:column;overflow:hidden;z-index:999999}
#cove-ai-panel.cove-abierto{display:flex}
#cove-ai-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:#1f2937;color:#fff;font-weight:600;font-size:15px}
#cove-ai-head button{background:none;border:0;color:#fff;font-size:17px;line-height:1;cursor:pointer;padding:2px 6px}
#cove-ai-chat{flex:1;display:block;min-height:0;border:0}
#cove-ai-foot{padding:0 0 7px;text-align:center;font-size:11px;color:#9ca3af}
CSS;

	$js = <<<'JS'
(function () {
  var cfgEl = document.getElementById('cove-ai-config');
  var bubble = document.getElementById('cove-ai-bubble');
  var panel = document.getElementById('cove-ai-panel');
  if (!cfgEl || !bubble || !panel) { return; }

  var CFG = {};
  try { CFG = JSON.parse(cfgEl.textContent); } catch (e) { CFG = {}; }

  // El botón funciona de inmediato, sin esperar al componente.
  bubble.addEventListener('click', function () {
    panel.classList.toggle('cove-abierto');
  });
  document.getElementById('cove-ai-close').addEventListener('click', function () {
    panel.classList.remove('cove-abierto');
  });

  // Los enlaces del chat deben abrirse en una pestaña nueva: si navegan en la
  // misma, el cliente pierde la conversación. DeepChat renderiza el markdown
  // dentro de su shadow DOM, así que interceptamos el clic ahí dentro.
  // Todo va envuelto en try/catch: si el componente cambia su estructura
  // interna, esto no puede romper el chat.
  function abrirEnlacesFuera(el) {
    try {
      var raiz = el.shadowRoot;
      if (!raiz) { return; }
      raiz.addEventListener('click', function (ev) {
        var a = (ev.target && ev.target.closest) ? ev.target.closest('a[href]') : null;
        if (!a) { return; }
        var href = a.getAttribute('href') || '';
        if (!/^https?:/i.test(href)) { return; } // no tocar enlaces internos del widget
        ev.preventDefault();
        ev.stopPropagation();
        window.open(href, '_blank', 'noopener');
      }, true);
    } catch (e) { /* sin intercepción el enlace sigue siendo tocable */ }
  }

  // Las propiedades se asignan solo cuando el componente ya está definido.
  // Asignarlas antes las dejaría como propiedades propias y el componente no
  // leería los cambios (ver comentario de arriba).
  function inicializar() {
    var el = document.getElementById('cove-ai-chat');
    if (!el) { return; }
    if (CFG.connect)            { el.connect = CFG.connect; }
    if (CFG.requestBodyLimits)  { el.requestBodyLimits = CFG.requestBodyLimits; }
    if (CFG.introMessage)       { el.introMessage = CFG.introMessage; }
    if (CFG.browserStorage)     { el.browserStorage = CFG.browserStorage; }
    if (CFG.messageStyles)      { el.messageStyles = CFG.messageStyles; }
    if (CFG.errorMessages)      { el.errorMessages = CFG.errorMessages; }
    if (CFG.displayLoadingBubble) { el.displayLoadingBubble = true; }
    abrirEnlacesFuera(el);
  }

  if (window.customElements && customElements.whenDefined) {
    customElements.whenDefined('deep-chat').then(inicializar);
  } else {
    window.addEventListener('load', inicializar);
  }
})();
JS;

	// Se arma por concatenación y NO con printf a propósito: el CSS contiene
	// un "%" literal (border-radius:50%) y en una cadena de formato de printf
	// eso es un especificador inválido que revienta en tiempo de ejecución.
	$html = '<script type="application/json" id="cove-ai-config">' . $config . '</script>'
		. '<style>' . $css . '</style>'
		. '<button id="cove-ai-bubble" type="button" aria-label="Abrir el asistente">💬</button>'
		. '<div id="cove-ai-panel" role="dialog" aria-label="' . esc_attr( $titulo ) . '">'
		. '<div id="cove-ai-head"><span>' . esc_html( $titulo ) . '</span>'
		. '<button id="cove-ai-close" type="button" aria-label="Cerrar">✕</button></div>'
		. '<deep-chat id="cove-ai-chat"></deep-chat>'
		. '<div id="cove-ai-foot">Respuestas generadas por IA · pueden contener errores</div>'
		. '</div>'
		. '<script>' . $js . '</script>';

	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado por partes arriba.
}
add_action( 'wp_footer', 'cove_ai_print_widget', 99 );
