# Herramientas (tools)

Contrato de lo que el modelo puede llamar. Los esquemas están en
`src/cove-ai-chat/30-tools-schemas.php` y la implementación en
`src/cove-ai-chat/31-tools-implementations.php`.

**Ninguna herramienta escribe nada.** Todas son de solo lectura. No hay forma de
que el modelo borre, cambie precios o modifique pedidos.

---

## Resumen

| Herramienta | Parámetros | Devuelve |
|---|---|---|
| `buscar_productos` | 9 opcionales | `total`, `offset`, `mostrados`, `productos[]` |
| `siguiente_bloque` | ninguno | Siguiente bloque de la última búsqueda |
| `detalle_producto` | `sku_o_id` | Precio, stock, descripción y **todas** las variaciones |
| `listar_categorias` | ninguno | Categorías reales con conteo |
| `listar_atributos` | `atributo` (opcional) | Atributos filtrables y sus valores reales |
| `politicas_tienda` | `seccion` (opcional) | Envíos, devoluciones, pagos, garantía, contacto |
| `cupones` | `codigo` (opcional) | Cupones **públicos** vigentes con sus condiciones |
| `mis_pedidos` | ninguno | Pedidos del usuario **con sesión iniciada** |

---

## `buscar_productos`

La única herramienta de búsqueda. **No existen variantes** como
`buscar_por_color` o `buscar_baratos`: todo se logra con los parámetros de esta.

| Parámetro | Tipo | Descripción |
|---|---|---|
| `termino` | string | Texto libre. Para nombres y descripciones, **no** para colores ni tallas |
| `categoria` | string | Nombre o slug de categoría real del catálogo |
| `precio_min` | number | Mínimo. Se compara con el rango real del producto, variaciones incluidas |
| `precio_max` | number | Máximo ("de menos de 100" → 100) |
| `atributos` | array | Pares `{atributo, valor}`. Ver reglas abajo |
| `solo_en_stock` | boolean | Solo productos disponibles |
| `en_oferta` | boolean | Solo productos **rebajados** (`onsale` en la tabla de lookup) |
| `orden` | enum | `relevancia`, `novedad`, `precio_asc`, `precio_desc`, `nombre` |
| `limite` | integer | 1 a 8. Por defecto 5 |
| `offset` | integer | Para paginar. Normalmente es mejor `siguiente_bloque` |

Ninguno es obligatorio, pero hace falta al menos un criterio (si no, devuelve
error explicando que hay que acotar). Es válido combinar solo filtros: "muéstrame
lo de camping de menos de 50" no necesita `termino`.

### Reglas de combinación

| Caso | Se combina con | Ejemplo |
|---|---|---|
| Filtros distintos | **Y** | precio + color + categoría → deben cumplirse los tres |
| Varios valores del **mismo** atributo | **O** | `color: azul` + `color: rojo` → cualquiera de los dos |
| Varias categorías | **O** | — |

### Ejemplo de llamada

```json
{
  "termino": "mochila",
  "precio_max": 150,
  "atributos": [
    { "atributo": "color", "valor": "azul" },
    { "atributo": "color", "valor": "negro" }
  ],
  "solo_en_stock": true,
  "orden": "precio_asc",
  "limite": 5
}
```

### Respuesta

```json
{
  "ok": true,
  "total": 47,
  "offset": 0,
  "mostrados": 5,
  "productos": [
    { "id": 812, "sku": "...", "nombre": "...", "precio": "...", "stock": "disponible", "enlace": "..." }
  ],
  "nota": "Hay 42 producto(s) más con estos criterios. Si el cliente pide ver más, usa siguiente_bloque."
}
```

**`total` es imprescindible y no es decorativo.** Sin él, el modelo no sabe si
quedan productos y solo tiene dos salidas: afirmar "esas son todas" (falso) o
inventar resultados. Es el dato que hace posible responder "y las demás".

### Errores que devuelve (y por qué son valiosos)

| Error | Cuándo | Por qué es mejor que devolver cero resultados |
|---|---|---|
| `La categoría "X" no existe. Categorías disponibles: ...` | Categoría inventada por el modelo | El modelo corrige con la lista real en la siguiente ronda |
| `El valor "X" no corresponde a ninguno de los valores válidos...` | Color mal escrito o inexistente | Igual: aprende los valores válidos en vez de decepcionar al cliente |
| `El atributo "X" no es un atributo global, así que no se puede filtrar...` | Atributo personalizado (no es taxonomía) | **Crítico**: sin esto el bot diría "no hay mochilas azules" cuando sí las hay |
| `Sin resultados con esos criterios. No afirmes que el producto no existe...` | Búsqueda sin coincidencias | Evita que el modelo concluya algo falso sobre el catálogo |

---

## `siguiente_bloque`

Sin parámetros **a propósito**. Continúa exactamente la última búsqueda de la
sesión usando el estado guardado en el servidor.

| Caso | Respuesta |
|---|---|
| Hay más resultados | El siguiente bloque, más `continuacion_de: "texto \"mochila\", color=azul"` |
| Ya se mostraron todos | "No hay más resultados: ya se mostraron los N productos" |
| No hay búsqueda previa en la sesión | Error pidiendo al modelo **preguntar** al cliente a qué lista se refiere |

Ese último caso es la red de seguridad: cuando el historial se recortó y no hay
antecedente, es mejor preguntar que adivinar.

---

## `detalle_producto`

| Parámetro | Tipo | Descripción |
|---|---|---|
| `sku_o_id` | string | SKU o ID numérico |

Devuelve precio, stock, descripción corta, enlace y —si es un producto
variable— **todas** sus variaciones, cada una con sus atributos formateados
(`Color: Azul, Talla: L`), su precio y su stock.

Es la herramienta para "¿en qué colores viene esta mochila?".

---

## `listar_categorias`

Sin parámetros. Devuelve las categorías reales con su conteo de productos
publicados y su slug.

Sirve para dos cosas: orientar al cliente que no sabe qué buscar, y darle al
modelo los nombres exactos antes de filtrar por categoría.

---

## `listar_atributos`

| Parámetro | Tipo | Descripción |
|---|---|---|
| `atributo` | string | Opcional. Limita la respuesta a uno (ej. `color`). Si se omite, devuelve todos |

Devuelve los atributos **filtrables** (solo los globales) y sus valores reales,
leídos de la taxonomía en el momento.

**Por qué existe:** un filtro solo sirve si el modelo conoce los valores que
existen. Sin esto adivinaría "azul", y si la tienda usa "Azul Marino" obtendría
cero resultados y parecería roto. Los valores nunca se escriben en el código: se
consultan.

---

## `politicas_tienda`

| Parámetro | Tipo | Descripción |
|---|---|---|
| `seccion` | string | Opcional. Si se omite, devuelve el **índice** de secciones disponibles |

Las secciones y su contenido están en `src/cove-ai-chat/content/cove-info.php`,
que **editas tú**. El archivo es `.php` y no `.md` a propósito: así una petición
HTTP directa no lo sirve como texto plano.

La búsqueda de sección tolera acentos, mayúsculas y nombres parciales: "envíos"
encuentra `envios`.

El contenido largo no vive en el prompt del sistema porque ahí se pagaría en
cada mensaje. Aquí se paga solo cuando el cliente pregunta por estos temas — y el
bot cita el texto oficial en lugar de parafrasearlo de memoria.

---

## `cupones`

| Parámetro | Tipo | Descripción |
|---|---|---|
| `codigo` | string | Opcional. Un código concreto. Sin parámetro, devuelve la lista de públicos vigentes |

Devuelve, por cada cupón: el tipo de descuento, las condiciones (mínimo de
compra, envío gratis, categorías o productos a los que aplica, usos por
cliente), **la fecha de vencimiento** y **los usos restantes**. Todo leído del
cupón en el momento con `WC_Coupon`, nunca escrito en el código: si cambias el
cupón en WooCommerce, el asistente ya lo refleja.

### La decisión de seguridad más importante de todo el proyecto

Este chat es **anónimo y público**. Una herramienta que valide cualquier código
se convierte en un **oráculo de enumeración**: cualquiera pregunta por `COVE10`,
`COVE11`, `COVE12`... y va descubriendo qué cupones existen y cuánto valen. Es el
problema clásico de filtración de cupones en e-commerce.

La solución implementada es una **allowlist**: el plugin solo conoce los códigos
que el dueño declare públicos en `cove_ai_cupones_publicos()`
(`00-config.php`). Si un código no está en esa lista, la respuesta es
**idéntica** a la de un código inexistente, así que el atacante no aprende nada.
La lista por defecto está **vacía**: sin configurar, el asistente no comunica
ningún cupón.

### Reglas que no se deben tocar

| Regla | Por qué |
|---|---|
| Solo códigos de la allowlist | Es lo que elimina el oráculo |
| La misma respuesta para "no existe" y "existe pero es privado" | Cualquier diferencia en el mensaje vuelve a filtrar información |
| **Nunca** listar el catálogo de cupones de la tienda | Solo los declarados públicos |
| Un cupón con `get_email_restrictions()` **nunca** se expone | Está dirigido a personas concretas, aunque esté en la allowlist |
| Un cupón en borrador (`post_status != publish`) no se expone | No está publicado |

### Por qué NO se usa `WC_Coupon::is_valid()`

Es tentador: devuelve el motivo exacto del rechazo, que sería cómodo para el
bot. Dos razones para no usarlo:

1. **Técnica:** `is_valid()` evalúa el cupón contra el **carrito activo**
   (mínimos de compra, productos aplicables). En una petición REST el carrito
   está vacío porque WooCommerce solo lo inicializa en el frontend, así que
   diría "no cumple el mínimo de compra" aunque el cliente sí lo cumpla. El
   plugin lee las propiedades del cupón y calcula la vigencia él mismo, que es
   determinista y no depende de contexto.
2. **De seguridad:** el motivo del rechazo **es información**.

Si un cupón no aplica, la causa real (vencido, mínimo no alcanzado, usos
agotados) la explica **WooCommerce en el checkout con su propio mensaje**.
Duplicar esa lógica en el plugin sería otra vez código que se desactualiza.

---

## `mis_pedidos`

**Sin parámetros, y eso es lo importante.**

El usuario no lo pasa el modelo ni el navegador: lo determina el servidor con
`get_current_user_id()`. Si esta herramienta aceptara un `customer_id`,
cualquier visitante leería los pedidos de otro pasando un número. Con esta regla,
es imposible por construcción.

| Caso | Respuesta |
|---|---|
| Con sesión y pedidos | Últimos 5: número, fecha, estado traducido, total y artículos + cuántos tiene en total |
| Con sesión y sin pedidos | "Este cliente no tiene pedidos todavía" |
| **Sin sesión** | Error pidiendo al modelo que invite al cliente a entrar a su cuenta |

### Lo que NUNCA sale al chat

Dirección, teléfono y correo. Un pedido trae datos personales; al chat van
número, fecha, estado, total y nombres de artículos. Nada más.

### Lo que esta herramienta NO puede responder (la duda más común)

`mis_pedidos` lee **pedidos**. El carrito es otra cosa: vive en la sesión del
navegador (`WC()->cart`), no es un pedido, y no aparece en `wc_get_orders()`.

| El cliente pregunta | Qué pasa hoy |
|---|---|
| "¿Cómo va mi pedido?" | ✅ Lo responde: número, fecha, estado, total, artículos |
| "¿Qué tengo en mi carrito?" | ❌ No puede. Ver abajo |
| "¿Puedo cancelarlo?" | ❌ La herramienta es de solo lectura. Va a soporte |
| "¿Cuál es el número de guía / dónde viene?" | ❌ No hay datos de rastreo: la tienda no tiene módulo de seguimiento de envíos |
| "¿Me llegó el pago?" | ✅ Si quedó en "Pendiente de pago", se lo dice |

**El carrito no es trivial de agregar.** En una petición REST, `WC()->cart` está
vacío por defecto: WooCommerce inicializa el carrito solo en peticiones del
frontend, con la cookie de sesión. Una herramienta de carrito tendría que
inicializar la sesión a mano (`wc_load_cart()`), y además el carrito de un
invitado no está ligado a ninguna cuenta. Es un proyecto aparte, no una línea.

**Limitación de los pedidos:** solo aparecen los que se hicieron **con la cuenta
iniciada**. Un cliente que compró como invitado y después se registró **no verá**
esas compras: WooCommerce no las asocia retroactivamente por correo. Si eso pasa,
hay que dirigirlo a soporte.

### Detalles de implementación

- `wc_get_orders()` / `wc_get_customer_order_count()`. **Nunca** SQL directo
  sobre `wp_postmeta`: WooCommerce moderno guarda los pedidos en sus propias
  tablas (HPOS) y el SQL de los tutoriales viejos devuelve vacío o basura.
- El estado se muestra con `wc_get_order_status_name()`, nunca `wc-processing`.
  El total con `get_formatted_order_total()`, que respeta la moneda del pedido.

---

## Cómo agregar una herramienta nueva

1. Añade su esquema en `30-tools-schemas.php`.
2. Añade su rama en el `switch` de `cove_ai_run_tool()` en
   `31-tools-implementations.php`.

Son unas 70 líneas entre las dos. **Recuerda:** si la herramienta nueva toca
datos de usuario, **no debe aceptar el identificador del usuario como
parámetro** — se deriva siempre del lado del servidor. Es la regla de
`mis_pedidos` y no tiene excepciones.

## Cómo agregar un filtro a `buscar_productos`

Unas 8 líneas: una propiedad en el esquema y su resolución en
`cove_ai_search_products()`. **No** crees una herramienta nueva para eso.
