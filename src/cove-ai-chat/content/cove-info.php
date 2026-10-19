<?php
/**
 * INFORMACIÓN DE LA TIENDA — ESTE ARCHIVO LO EDITAS TÚ.
 *
 * Fuente: los documentos que enviaste (aviso de privacidad, política de
 * reembolsos y devoluciones, y términos y condiciones), con última
 * actualización 20/07/2026 según ellos mismos.
 *
 * ---------------------------------------------------------------------------
 * CÓMO EDITARLO
 * ---------------------------------------------------------------------------
 *  · Cada llave del array es una sección: 'envios' => 'texto'.
 *  · Al asistente se le entrega SOLO la sección que el cliente pregunta, no el
 *    archivo completo: por eso las secciones son granulares, así no paga
 *    tokens de envíos cuando le preguntan por devoluciones.
 *  · Texto plano, sin HTML. Saltos de línea con "\n".
 *  · NO pongas precios de producto, stock ni promos: eso sale de WooCommerce.
 *  · Si editas este archivo aquí, vuelve a copiarlo al sitio (o corre
 *    instalar.ps1, que conserva una versión editada en el sitio y te avisa).
 *
 * ---------------------------------------------------------------------------
 * HUECOS QUE DETECTÉ EN TUS DOCUMENTOS (decisiones tuyas, no las inventé)
 * ---------------------------------------------------------------------------
 * De estos temas NO hay sección, así que el asistente dirá honestamente que no
 * tiene esa información y ofrecerá pasar con una persona. Cuando decidas el
 * contenido, agrega la sección aquí y el bot la verá sin tocar nada más:
 *
 *  1. COSTO DEL ENVÍO Y ZONAS DE COBERTURA. Los términos dan el PLAZO (3 a 5
 *     días hábiles) pero no el precio del envío ni hasta dónde llegan.
 *  2. FORMAS DE PAGO. Los términos hablan de precios, pero no listan los medios
 *     aceptados. Tenlo en cuenta: en el checkout la tienda acepta lo que esté
 *     configurado en WooCommerce -> Ajustes -> Pagos.
 *  3. GARANTÍA. Se menciona como una de las causas válidas de devolución, pero
 *     no existe una política de garantía con plazo y cobertura.
 *  4. RETIRO EN TIENDA Y HORARIO DE ATENCIÓN. No aparecen en ningún documento.
 *
 * Y una más, esta ya resuelta al no incluirla:
 *
 *  5. DIRECCIÓN FÍSICA. Los términos dicen "Dirección: PA", que no es una
 *     dirección utilizable. No la incluí a propósito: un asistente recitando
 *     "nuestra dirección es PA" es peor que uno que dice que no la tiene.
 *     Cuando tengas la dirección real, agrégala a la sección 'contacto'.
 *
 * ---------------------------------------------------------------------------
 * CAMBIOS QUE HICE AL TEXTO ORIGINAL (revisa si estás de acuerdo)
 * ---------------------------------------------------------------------------
 *  a) "Cove Ourdoor" -> "Cove Outdoor". Aparecía con ese error en el título de
 *     los términos y dos veces más en el primer punto. Si el error era
 *     intencional, revertirlo.
 *  b) La frase de derechos ARCO terminaba así: "deberá presentar la solicitud
 *     respectiva a través de Al mismo correo.." — estaba cortada y sin sentido.
 *     La completé usando el correo de contacto que tú mismo indicaste.
 *  c) El procedimiento de devolución repetía tres veces los mismos puntos
 *     (devolución del dinero / cambio / cupón): los puntos 2 y 3 del original
 *     repetían texto del punto 1. Lo dejé una sola vez, sin duplicados.
 *  d) Reemplacé viñetas y guiones tipográficos por texto plano, porque esto lo
 *     lee un modelo de lenguaje y no un navegador.
 *  e) Agregué la sección 'cupones' con la ubicación del campo de cupón. Eso es
 *     el comportamiento POR DEFECTO de WooCommerce (carrito + checkout), así
 *     que si algún plugin mueve ese campo, corrige el texto en esa sección.
 *     Las condiciones y el vencimiento de cada cupón NO se escriben aquí: los
 *     lee el plugin del propio cupón en cada consulta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	'terminos'     => "TÉRMINOS Y CONDICIONES\n\n"
		. "Este sitio web es operado por Cove Outdoor. En todo el sitio, los términos «nosotros», «nos» y «nuestro» se refieren a Cove Outdoor.\n"
		. "Sitio web: Cove.test\n"
		. "Correo de contacto: jjsotom2k4@gmail.com\n\n"
		. "Nos reservamos el derecho de modificar estos términos y condiciones en cualquier momento. Los cambios entran en vigor inmediatamente después de su publicación en el sitio web.\n\n"
		. 'Última actualización: 20 de julio de 2026.',

	'productos'    => 'Nos esforzamos por mostrar con la mayor precisión los colores y las imágenes de nuestros productos en la tienda. '
		. 'No podemos garantizar que la visualización de cualquier color en tu monitor sea exacta.',

	'precios'      => 'Todos los precios están sujetos a cambios sin previo aviso. '
		. 'Los precios mostrados incluyen IVA cuando corresponda.',

	'envios'       => 'El tiempo estimado de envío es de 3 a 5 días hábiles. '
		. 'Los tiempos de entrega son estimados y no podemos garantizar entregas en fechas específicas.',

	'devoluciones' => "POLÍTICA DE DEVOLUCIONES\n\n"
		. "Aceptamos devoluciones dentro de los 14 días posteriores a la recepción del producto.\n\n"
		. "CONDICIONES DEL PRODUCTO\n"
		. 'El producto debe devolverse en óptimas condiciones, sin rastros de haber sido utilizado, con las etiquetas originales; si ya las retiraste, introdúcelas en el empaque. '
		. "Una vez recibido el producto en nuestra bodega verificamos sus condiciones y, según el resultado, te enviamos un producto nuevo o te entregamos un cupón para una nueva compra.\n\n"
		. "PROCEDIMIENTO\n"
		. "1. Devolución del dinero (solo en casos de garantía y ley de retracto):\n"
		. "   - Por transferencia (cuenta de ahorros, cuenta corriente o ahorro a la mano): se realiza aproximadamente dentro de los 5 días hábiles siguientes a recibir el producto en nuestra bodega.\n"
		. "   - Por reversión del pago: 15 días hábiles después de recibir el producto en nuestra bodega. La reversión corre por cuenta y orden de tu entidad bancaria; cualquier duda sobre ese plazo debes consultarla directamente con tu banco.\n"
		. '2. Cambio del producto: sujeto a disponibilidad de inventario al momento del cambio. Solo se puede cambiar por productos de valor igual o inferior al original; la diferencia, si aplica, se entrega en un cupón para una nueva compra en la tienda online. '
		. "Si no hay disponibilidad para el cambio, se entrega el valor del producto en un cupón.\n"
		. '3. Cupón para una nueva compra: tiene validez de 6 meses desde su fecha de creación.',

	'privacidad'   => "AVISO DE PRIVACIDAD\n\n"
		. "Joan Soto, comercialmente conocido como Cove Outdoor, es el responsable del uso y protección de tus datos personales.\n\n"
		. "FINALIDADES\n"
		. 'Los datos personales que recabamos los utilizamos para: respuesta a mensajes del formulario de contacto, prestación de cualquier servicio solicitado, y envío de productos adquiridos en esta tienda en línea. '
		. "Para esas finalidades podemos recabar tus datos de identificación y contacto.\n\n"
		. "DATOS QUE RECABA EL SITIO WEB\n"
		. "Nuestro sitio recaba automáticamente identificadores, nombre de usuario y contraseñas de sesión, y la región en la que se encuentra el usuario.\n\n"
		. "DERECHOS ARCO\n"
		. 'Tienes derecho a conocer qué datos personales tenemos de ti, para qué los utilizamos y las condiciones del uso que les damos (Acceso); a solicitar la corrección de tu información cuando esté desactualizada, sea inexacta o incompleta (Rectificación); a que la eliminemos de nuestros registros o bases de datos cuando consideres que no está siendo utilizada adecuadamente (Cancelación); y a oponerte al uso de tus datos personales para fines específicos (Oposición). '
		. "Estos derechos se conocen como derechos ARCO.\n"
		. "Para ejercer cualquiera de los derechos ARCO debes presentar la solicitud a través del correo de contacto indicado abajo. La respuesta se atiende en un plazo máximo de 15 días hábiles.\n\n"
		. 'Última actualización: 20 de julio de 2026.',

	'cupones'      => "Los cupones de descuento se aplican en el campo de cupón que aparece en la página del carrito y en el checkout, antes de pagar.\n\n"
		. 'Si un cupón no se aplica, normalmente es por una de estas razones: ya venció, no se alcanzó la compra mínima requerida, ya se usó el número de veces permitido, o no aplica a los productos que están en el carrito. '
		. "El checkout muestra el motivo exacto en el momento de intentarlo.\n\n"
		. 'Los descuentos vigentes en productos ya se ven reflejados en el precio que muestra la tienda.',

	'contacto'     => "Correo electrónico: jjsotom2k4@gmail.com\n"
		. "Sitio web: Cove.test\n\n"
		. 'Si tu consulta es sobre un pedido en curso, lo más rápido es que entres a tu cuenta en la tienda y revises el estado del pedido.',
);
