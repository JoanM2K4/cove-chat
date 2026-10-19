# Arquitectura

Documento de orientación para quien mantenga esto después. El razonamiento
completo (alternativas evaluadas, trampas encontradas, decisiones descartadas)
está en el plan del proyecto, pero lo esencial está aquí para no obligar a nadie
a leer un documento largo antes de tocar una línea.

---

## 1. El flujo completo

```
Navegador (visitante en COVE.test)
   │
   │  1. Escribe en el chat
   ▼
<deep-chat>  ── componente de terceros, autoalojado ────────────────┐
   │   · interfaz, historial, reintentos                             │  60-widget.php
   │   · guarda los mensajes en el navegador y reenvía los últimos N │  solo lo incrusta
   │   · NO ve la API key ni conoce las herramientas                  │
   │                                                                 │
   │  2. POST /wp-json/cove-ai/v1/chat                               │
   │     { messages: [{role,text}], session_id }                     │
   ▼                                                                 │
50-rest-endpoint.php  · cuota por IP · normaliza roles · recorta mensajes
   │
   ▼
40-deepseek.php  · bucle de tool calling (máx. 3 rondas) ◄──────────┐
   │                                                               │
   ├─► POST api.deepseek.com  (servidor → servidor, sin CORS)      │
   │      ◄── texto  o  tool_calls                                 │
   │                                                               │
   ├─► si hay tool_calls:                                         │
   │      31-tools-implementations.php ──► 20-query-builder.php ───┘
   │                                          │
   │                                          └─► WooCommerce (precio, stock,
   │                                              categorías, atributos, pedidos)
   │
   │  3. { text: "..." }   ← SIEMPRE 200, incluso en errores de negocio
   ▼
Navegador
```

## 2. Reparto de responsabilidades

| Capa | Archivo | Responsabilidad |
|---|---|---|
| Interfaz + historial | `assets/deepChat.min.js` | Burbujas, scroll, reintentos, envío, persistencia en el navegador |
| Incrustación | `60-widget.php` | Pasar la configuración y el nonce. Nada de lógica |
| Entrada | `50-rest-endpoint.php` | Cuota, validación, normalización de roles |
| Orquestación | `40-deepseek.php` | Prompt, bucle de herramientas, errores |
| Datos | `20-query-builder.php` | Todas las consultas a WooCommerce |
| Herramientas | `30/31-tools-*.php` | Qué le ofrecemos al modelo y qué hace cada una |
| Estado | `10-session.php` | Última búsqueda por sesión (para "y las demás") |
| Configuración | `00-config.php` | Secretos, límites, prompt |

**La regla que no se rompe:** el ciclo de herramientas vive en PHP, nunca en el
navegador. Si viviera en JavaScript, cualquiera leería la API key desde la
consola del navegador y llamaría herramientas a mano.

---

## 3. Decisiones de diseño y su motivo

| Decisión | Alternativa descartada | Por qué |
|---|---|---|
| Una sola herramienta de búsqueda con parámetros opcionales | Una herramienta por filtro o por combinación | Combinar "precio + color" no cuesta código: son más llaves en el mismo objeto. Añadir un filtro cuesta ~8 líneas; añadir una herramienta, ~70 |
| Cupones por **allowlist** declarada por el dueño | Consultar el catálogo de cupones | Un chat público que valida cualquier código es un oráculo de enumeración. Con la lista, un código desconocido recibe la misma respuesta que uno inexistente (§5) |
| Endpoint REST propio | `admin-ajax.php` | Respuestas tipadas, códigos HTTP reales, y más fácil de excluir del cache por ruta |
| Bundle del widget autoalojado | CDN (unpkg/jsDelivr) | El sitio es local. Un CDN es un punto de fallo y una dependencia de internet para la interfaz |
| Sin nonce para visitantes anónimos | Nonce de WordPress en el HTML | Un nonce horneado en HTML se vence cuando la página viene del cache de LiteSpeed, y el chat devuelve 403 sin explicación |
| Con nonce para usuarios con sesión | — | **Necesario**: sin el header `X-WP-Nonce`, la API REST de WordPress trata la petición como anónima y `mis_pedidos` le diría "inicia sesión" a alguien que ya la inició |
| Historial en el cliente | Tabla propia / transients | Cero base de datos, cero migraciones, cero limpieza |
| Contenido largo en archivo aparte | Todo en el prompt del sistema | 10 KB de políticas en el prompt se pagan en **cada** mensaje. Leído bajo demanda, se paga solo cuando preguntan por eso |
| Endpoint sin `permission_callback` restrictivo | Solo usuarios conectados | El chat es para visitantes. La protección real es la cuota por IP |
| Errores de negocio como texto con HTTP 200 | Errores HTTP 4xx/5xx | El widget espera el tipo `Response`; un 500 se le muestra al visitante como "servicio roto" |

---

## 4. Las dos trampas de WooCommerce, documentadas para el próximo

### 4.1 El precio NO se filtra por el meta `_price`

El `_price` del producto padre guarda el precio de su variación **más barata**.
Un filtro "hasta 150" sobre ese meta descarta o incluye productos variables de
forma incorrecta — y en COVE casi todo el catálogo es variable (colores).

Lo correcto está en `20-query-builder.php`: se une la tabla
`{prefix}wc_product_meta_lookup`, que tiene `min_price` y `max_price`
**indexadas** y en un producto variable abarca todas sus variaciones. Un
producto entra si su rango de precios **toca** el rango pedido:

```sql
AND covelookup.max_price >= <precio_min>
AND covelookup.min_price <= <precio_max>
```

Es la misma lógica que usa WooCommerce internamente para el filtro de precio del
catálogo.

**Consecuencia importante:** como el filtrado ocurre en la base de datos,
`found_posts` sigue siendo el total correcto. Si se filtrara el precio en PHP
después de traer los productos, el total no correspondería a nada y "y las
demás" volvería a romperse.

Esta es **la única parte del plugin donde se escribe SQL propio**, y está
justificado. Siempre con `$wpdb->prepare` y siempre de solo lectura.

### 4.2 No todos los atributos se pueden filtrar

Solo los atributos **globales** (`pa_color`, `pa_talla`) son taxonomías con
términos. Un atributo **personalizado** (creado dentro de un producto) vive como
una cadena dentro de un postmeta: no hay términos ni nada contra lo que hacer un
JOIN. **Es imposible filtrarlo.**

El plugin **detecta** el caso (`cove_ai_resolve_attribute()`) y devuelve un
error explícito con la lista de los atributos que sí sirven. Nunca devuelve cero
resultados en silencio, porque entonces el bot diría "no hay mochilas azules"
cuando sí las hay. Ese es el peor fallo posible: una respuesta segura y falsa.

---

## 5. Modelo de seguridad

| Superficie | Estado |
|---|---|
| API key | Solo en `wp-config.php`. Nunca en HTML, ni en el JSON del widget, ni en el navegador |
| Datos que leen las herramientas | Catálogo **público**: precio, stock, categorías, atributos. Nada personal |
| Datos personales | Solo en `mis_pedidos`, y solo del usuario conectado. Se devuelven número, fecha, estado, total y artículos. **Nunca** dirección, teléfono ni correo |
| Pedidos ajenos | Imposibles por construcción: `mis_pedidos` **no acepta parámetros de cliente**, el usuario sale de `get_current_user_id()` |
| Enumeración de cupones | Solo se comunica una **allowlist** de códigos declarados públicos. Un código fuera de la lista recibe la misma respuesta que uno inexistente. Los cupones con restricción por correo no se exponen nunca, ni estando en la lista |
| Fuga de información por mensajes de error | Los errores de cupón no llevan motivo; el motivo real lo da WooCommerce en el checkout. Los errores técnicos se registran en el log y al visitante le llega un mensaje genérico |
| Entrada del navegador | Se aceptan solo roles `user`/`ai`, se recortan los mensajes a 1.500 caracteres, máximo 16, y el id de sesión se valida contra un alfabeto seguro |
| Abuso / coste | Cuota de 40 peticiones por hora por IP, tope de 3 rondas de herramientas, tope de 8 productos por llamada |
| Inyección en el render | El JSON se escapa con `JSON_HEX_TAG` (un `</script>` en una política no rompe la página) y el título con `esc_html`/`esc_attr` |

**Lo que la sanitización se reduce a ser:** como las herramientas de catálogo
leen datos públicos, aquí no hay nada que filtrar. Forzar tipos, acotar números
y limitar roles son unas cinco líneas en total. El infierno de autorización solo
aparece con datos privados, y eso está acotado a una herramienta con una regla
de una línea.

---

## 6. Límites, memoria y "y las demás"

### 6.1 El historial no lleva los resultados de las herramientas

Los resultados se consumen **dentro** de la petición de PHP y nunca vuelven al
navegador. Lo que viaja en `messages` es solo texto de usuario y de asistente.
Por eso subir `maxMessages` cuesta poco: el gasto real está en los esquemas de
las herramientas y el prompt del sistema, no en la conversación.

### 6.2 Qué pasa cuando el historial se recorta

Cada petición es **sin estado**: la API no recuerda nada, y lo que no se envía no
existe para el modelo. Y cuando le falta el antecedente, el modelo **no dice "no
lo sé": rellena el hueco y suena seguro.**

De ahí dos defensas:

1. **`total` + `offset`** en toda búsqueda. Sin `total`, el modelo no sabe si
   quedan más productos y solo tiene dos salidas, ambas malas: decir "esas son
   todas" o inventar.
2. **Estado de sesión** (`10-session.php`): se guardan los *argumentos* de la
   última búsqueda (no los resultados) en un transient indexado por id de
   sesión, y `siguiente_bloque()` los continúa sin parámetros. Así "y las demás"
   funciona aunque el historial se haya cortado.
3. **Red de seguridad en el prompt:** si no hay antecedente claro, el bot
   pregunta en vez de adivinar.

---

#### 6.4 Fallo conocido del proveedor: la llamada a herramientas llega como texto

DeepSeek genera internamente las llamadas a herramientas en su propio marcado
(**DSML**) y su motor debería traducirlo al campo estructurado `tool_calls`.
**A veces no lo traduce** y el marcado crudo sale en `content`, con
`finish_reason: "stop"`: la herramienta no se ejecuta y el cliente ve el
marcado. Es un fallo del proveedor, reportado en varios proyectos abiertos
(vLLM, Cherry Studio, openclaw) y no algo que hayamos introducido aquí.

Detalles que importan:

- El delimitador depende del modelo: V3.2 usa **una** barra y V4-Flash usa
  **dos**. Los parsers que solo aceptan una barra fallan con V4-Flash, que es
  justo el modelo que sirve `deepseek-flash`.
- **No se puede evitar por prompt.** Lo dispara un patrón del tokenizador, no
  el seguir instrucciones; las mitigaciones por instrucción son poco fiables.
- Se han observado varias formas: envoltorio ausente, envoltorio mal escrito
  (`toolcalls`), y parámetros autocerrados con el valor en el atributo `string`.

Cómo lo resuelve el plugin (`40-deepseek.php`):

| Situación | Comportamiento |
|---|---|
| Sin `tool_calls` y el contenido **empieza** con el marcado | Se recuperan las llamadas, se reconstruye el turno del asistente con la estructura correcta y **el ciclo continúa**. El cliente no nota nada |
| Marcado **en medio** de una respuesta | **No se ejecuta**: es el modelo citando contenido (por ejemplo, texto que llegó del cliente). Se limpia |
| Marcado **incompleto** | **No se ejecuta nada.** Se limpia y el cliente recibe una invitación a reformular. Fallar cerrado es mejor que ejecutar una herramienta con argumentos inventados |
| Cualquier caso | El marcado se elimina antes de responder: el cliente nunca lo ve |

Además, cada recuperación emite el evento `cove_ai_dsml_recuperado` (ver 6.5),
así que quien quiera medir con qué frecuencia ocurre solo tiene que engancharse
a él.

**Trampa de implementación que costó una iteración:** las expresiones regulares
de este archivo llevan el modificador `/u` y la barra de ancho completo se
escribe como `\x{FF5C}`, **no** como el carácter literal. Sin `/u`, PCRE trabaja
en modo byte: U+FF5C ocupa tres bytes, así que `{1,2}` nunca completa la barra y
no se reconoce ningún marcado. Se detectó con una prueba aislada que usa el
texto exacto que devolvió el proveedor, y quedó como prueba de regresión en
`lab-te2-02-soporte/test-dsml.php`.

---

#### 6.5 Registro: tres eventos, ningún `error_log`

El plugin **no escribe en el log por su cuenta**. `error_log()` es una función de
desarrollo que WordPress Coding Standards marca
(`WordPress.PHP.DevelopmentFunctions`), y un plugin de tienda no debería decidir
dónde van los registros del sitio: eso es cosa del tema o de quien administra.

En su lugar emite tres eventos. Para activarlos, en el tema hijo o en otro
plugin:

```php
add_action( 'cove_ai_dsml_recuperado', function ( $cantidad ) {
	error_log( '[Cove AI] Recuperadas ' . $cantidad . ' llamada(s) DSML.' );
} );

add_action( 'cove_ai_error', function ( $codigo, $mensaje ) {
	error_log( '[Cove AI] ' . $codigo . ': ' . $mensaje );
} );

add_action( 'cove_ai_sin_api_key', function () {
	error_log( '[Cove AI] Falta DEEPSEEK_API_KEY en wp-config.php.' );
} );
```

| Evento | Cuándo se dispara | Argumentos |
|---|---|---|
| `cove_ai_dsml_recuperado` | El proveedor devolvió herramientas como texto y se recuperaron | cantidad de llamadas |
| `cove_ai_error` | Fallo de la API o del ciclo de herramientas | código y mensaje del `WP_Error` |
| `cove_ai_sin_api_key` | Alguien usa el chat y falta la constante | — |

Ventaja doble: el plugin deja de tener opinión sobre el registro, y el linter
queda en cero violaciones sin necesidad de excepciones.

## 7. Por dónde empezar a leer el código

Si vas a tocar algo, en este orden:

1. **`00-config.php`** — es corto y define todo lo que se puede ajustar.
2. **`31-tools-implementations.php`** — qué hace cada herramienta, sin JSON de
   por medio.
3. **`20-query-builder.php`** — el único lugar con las consultas y el SQL.
4. **`40-deepseek.php`** — el bucle de tool calling, con los cuatro detalles que
   rompen una implementación mal hecha.
5. **`60-widget.php`** — la incrustación y las dos trampas de los componentes web
   (el `whenDefined` y el `JSON_HEX_TAG`).

---

## 8. Cosas que se dejaron fuera a propósito

- **Persistencia del historial en el servidor.** Hoy vive en el navegador. Si
  algún día se quiere leer las conversaciones para mejorar el bot, es otro
  proyecto: tabla propia o `wp_usermeta`, y trae obligaciones de privacidad.
- **Búsqueda de pedidos de invitados.** Sería por número de pedido + correo, y
  eso es un vector de enumeración: cualquiera prueba combinaciones y saca pedidos
  ajenos. Si algún día se quiere, va con verificación real por correo (enlace con
  token), nunca como herramienta del modelo.
- **Facetado** (los conteos de "Azul (42)" de las tiendas grandes). Es la misma
  consulta a `wc_product_meta_lookup` más conteos por término. Se puede agregar
  sin tocar nada de lo existente.
- **Streaming de respuestas.** El endpoint devuelve texto completo. Se puede
  activar, pero complica el bucle de herramientas sin aportar al caso de uso.
