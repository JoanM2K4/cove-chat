# Configuración — lo que tienes que hacer tú

Nada de esta lista lo puede hacer el plugin por ti. Si algo falta, el chat falla
de una forma que **parece un bug y no lo es** (normalmente respondiendo "no está
configurado" o devolviendo respuestas raras).

Están ordenadas por prioridad: las primeras cuatro son las que hay que hacer
para que funcione. Las demás son ajustes.

---

## 1. La API key de DeepSeek (obligatorio)

**Dónde:** `C:\laragon\www\COVE\wp-config.php`

**Qué:** antes de la línea `/* That's all, stop editing! Happy publishing. */`,
agrega:

```php
define( 'DEEPSEEK_API_KEY', 'sk-tu-key-aqui' );
```

**Por qué en wp-config.php y no en la base de datos:** los respaldos de Laragon
(`C:\laragon\backup\mysql\*.sql`) exportan la base completa en texto plano. Un
secreto ahí acaba en archivos que se copian, se comparten y se olvidan.

**Opcional:** si algún día quieres el nombre del modelo en otro valor,

```php
define( 'COVE_AI_MODEL', 'deepseek-flash' );
```

⚠️ **No cambies el modelo a `deepseek-v4-pro` ni actives el modo "thinking".**
Las herramientas (tools) de DeepSeek solo funcionan en modo non-thinking. Con el
modo pensante activo, el function calling deja de responder.

**Verificación:** el comando A de la sección "Prueba rápida" del README.

---

## 2. Copiar el plugin a WordPress (obligatorio)

Tres copias, no una. La tercera es la que se olvida:

| Qué | Desde | Hacia |
|---|---|---|
| Cargador | `src/cove-ai-chat.php` | `wp-content\mu-plugins\` |
| Módulos | `src/cove-ai-chat\` | `wp-content\mu-plugins\` |
| **Bundle del widget** | `assets\deepChat.min.js` | `wp-content\mu-plugins\cove-ai-chat\assets\` |

La carpeta `mu-plugins` puede no existir: créala. Y ojo, los mu-plugins **no se
activan desde el panel de WordPress**: se cargan solos. No los vas a ver en
"Plugins", y eso es correcto.

**Atajo:** ejecuta `instalar.ps1` (está en la raíz del proyecto y hace las tres
copias). Léelo antes si te gusta saber qué ejecutas.

**Cómo saber que quedó bien:** `docs/CONFIGURACION.md` §3.

---

## 3. Comprobar que el endpoint responde

```bash
curl -s "http://COVE.test/wp-json/" | grep -o "cove-ai/v1" | head -1
```

Si imprime `cove-ai/v1`, la ruta existe. Si no imprime nada, revisa que el
cargador esté en `mu-plugins/` (no en una subcarpeta) y que el PHP no esté
lanzando un error fatal en el log.

---

## 4. Las políticas de tu tienda (obligatorio)

**Dónde:** `wp-content\mu-plugins\cove-ai-chat\content\cove-info.php`

Cinco secciones, todas con el texto `PENDIENTE DE CONFIGURAR`. Reemplázalas con
tus datos reales:

| Sección | Qué escribir |
|---|---|
| `envios` | Cobertura, costo, tiempo de entrega, si hay retiro en tienda |
| `devoluciones` | Plazo, condiciones del producto, cómo se inicia el proceso |
| `pagos` | Medios que aceptas de verdad, recargos o descuentos |
| `garantia` | Qué cubre, cuánto dura, qué debe presentar el cliente |
| `contacto` | **Tu WhatsApp, correo y horario real** |

Puedes agregar más secciones: el asistente las descubre solo.

**Por qué el texto dice "PENDIENTE DE CONFIGURAR" a propósito:** mientras no lo
cambies, el bot repetirá ese texto al cliente. Es incómodo a propósito, para que
lo notes en la prueba en vez de que invente una política que tu tienda no tiene.

**Importante:** no pongas precios ni promos aquí. Eso cambia y debe salir de
WooCommerce, no de un archivo.

---

## 5. El tono y las instrucciones del bot

**Dónde:** `wp-content\mu-plugins\cove-ai-chat\00-config.php`, función
`cove_ai_system_prompt()`.

Ya trae nueve reglas funcionando (no inventar precios, usar herramientas,
preguntar en vez de adivinar, no filtrar pedidos ajenos...). Lo que conviene que
**tú** ajustes:

- Cómo se llama la tienda y qué vende exactamente.
- El tono (formal, coloquial, panameño neutro).
- Qué hacer cuando no sabe algo, y a quién derivar.

Se puede sobreescribir sin tocar el archivo, desde un plugin de snippets:

```php
add_filter( 'cove_ai_system_prompt', function ( $prompt ) {
    return $prompt . "\n10) Atendemos solo en Panamá; si preguntan por envíos internacionales, di que no por ahora.";
} );
```

---

## 6. Excluir el chat del cache de LiteSpeed (importante)

**Dónde:** `LiteSpeed Cache → Cache → Excludes → Do Not Cache URIs`

**Qué:** agrega

```
/wp-json/cove-ai/
```

**Por qué:** si el cache sirve respuestas viejas del endpoint, el chat responde
cosas que ya no aplican. Y si alguna vez se inyecta un nonce en una página
cacheada, el chat devuelve 403 sin ningún mensaje que explique por qué.

**Truco al depurar:** si algo se comporta raro, prueba primero con LiteSpeed
**desactivado**. Si con el cache apagado funciona y con él no, el problema es el
cache y no el código. Ahorra horas.

---

## 7. Wordfence, solo si da problemas

**Dónde:** `Wordfence → Firewall → Allowlisted URLs`

**Cuándo:** solo si ves errores 403 intermitentes al chatear. Wordfence puede
interpretar los POST repetidos como un escaneo.

---

## 8. Vigilar el gasto

Tu key de DeepSeek es de pago por tokens. El plugin ya trae una **cuota de 40
peticiones por hora por IP**, que evita que una pestaña olvidada te consuma el
saldo.

- Para subirla o bajarla: `00-config.php`, constante `COVE_AI_RATE_LIMIT`.
- Para ver el consumo real: `platform.deepseek.com` → uso.
- Otras perillas de coste en el mismo archivo: `COVE_AI_LIMITE_MAX` (productos
  por llamada), `COVE_AI_HISTORY_KEEP` (mensajes de historial).

---

## 9. Verificar que tus atributos de color son globales (necesario para los filtros)

**Por qué:** solo los **atributos globales** (`pa_color`, `pa_talla`) se pueden
filtrar. Un atributo personalizado vive como texto dentro de un producto, sin
taxonomía, y no hay forma de filtrarlo. Si el bot intenta filtrar por él, dirá
honestamente que no se puede en vez de inventar un "no hay".

**Cómo comprobar (con WP-CLI, si lo tienes):**

```bash
wp wc product_attribute list --user=1 --fields=id,name,slug,type,has_archives
```

Una fila por atributo global. Como tienes **Variation Swatches** instalado, es
muy probable que tus colores ya sean globales (los swatches los requieren), pero
conviene confirmarlo antes de depender de los filtros por color.

**Sin WP-CLI:** `Productos → Atributos` en el panel. Los que aparezcan ahí son
globales; los que veas solo dentro de un producto son personalizados.

---

## 10. Pedidos: decidir si quieres la función activa

La herramienta `mis_pedidos` solo funciona con **clientes con sesión iniciada**.

- Revisa `WooCommerce → Ajustes → Cuentas`: si el registro de clientes está
  desactivado, la herramienta nace sin usuarios que la usen.
- Un invitado no tiene pedidos. El bot le dirá amablemente que entre a su
  cuenta, que es el comportamiento correcto (lo contrario — buscar pedidos por
  número o correo — permitiría a cualquiera ver pedidos ajenos).

---

## 11. Guardar una copia fuera de WordPress

`wp-content/mu-plugins/` es lo primero que se pierde al reinstalar WordPress o
al mover el sitio. Este proyecto vive en la carpeta de este repositorio; tenla
como fuente de verdad y copia **desde** ella **hacia** `mu-plugins`, nunca al
revés.

---

## 12. Detalles de aspecto (opcional)

| Qué | Dónde | Cómo |
|---|---|---|
| Texto de bienvenida | `00-config.php` | Función `cove_ai_intro()` o filtro `cove_ai_intro` |
| Título de la ventana | Filtro | `add_filter( 'cove_ai_widget_titulo', fn() => 'Hablemos' );` |
| Tamaño y posición | `60-widget.php` | El bloque `<<<'CSS'` (el `%` del `border-radius` ya está resuelto) |
| Historial persistente | Filtro | Ya viene activado. Para desactivar: `add_filter( 'cove_ai_persistir_historial', '__return_false' );` |
| Configuración del componente | `60-widget.php` | Función `cove_ai_widget_config()`, filtro `cove_ai_widget_config` |

---

## 13. Cupones: qué comunicar y qué no

El asistente puede hablar de cupones, pero **solo de los que tú declares
públicos**. Por defecto la lista está vacía, así que sin configurar esto el bot
no comunica ningún cupón.

**Dónde:** `wp-content\mu-plugins\cove-ai-chat\00-config.php`, función
`cove_ai_cupones_publicos()`.

```php
// Ejemplo: editar la función para que devuelva tu lista
return apply_filters( 'cove_ai_cupones_publicos', array( 'COVE10', 'BIENVENIDA' ) );
```

O sin tocar el archivo, desde un plugin de snippets:

```php
add_filter( 'cove_ai_cupones_publicos', function ( $lista ) {
    $lista[] = 'COVE10';
    return $lista;
} );
```

**Aquí van solo los CÓDIGOS.** El porcentaje, el mínimo de compra, la fecha de
vencimiento y los usos restantes los lee el plugin del propio cupón cada vez que
alguien pregunta. Si editas el cupón en WooCommerce, el asistente ya lo refleja:
no hay nada que sincronizar.

**Por qué una lista y no una consulta automática:** un chat público que valida
cualquier código se convierte en un oráculo de enumeración — cualquiera prueba
`COVE10`, `COVE11`, `COVE12` y descubre qué cupones existen. Con la lista, un
código que no esté en ella recibe la **misma** respuesta que uno inexistente.

**Qué se comunica de cada cupón:**

| Dato | Se comunica |
|---|---|
| Tipo de descuento (%, monto fijo, por producto) | ✅ |
| Compra mínima / máxima | ✅ |
| Envío gratis incluido | ✅ |
| Categorías o productos a los que aplica | ✅ |
| Fecha de vencimiento | ✅ (o "sin fecha de vencimiento") |
| Usos restantes y usos por cliente | ✅ |
| **Cupones privados o por correo** | ❌ Nunca, ni aunque estén en la lista |
| **Si un código desconocido existe o no** | ❌ Nunca: la respuesta es siempre la misma |

## 14. Ofertas: el filtro que puede "no encontrar nada"

Cuando le pides al bot "¿qué hay en oferta?", filtra por la columna `onsale` de
la tabla `wc_product_meta_lookup` — la misma que usa WooCommerce para su filtro
de ofertas del catálogo.

**El problema:** esa tabla **se desincroniza después de importaciones masivas**
(importar productos escribe el postmeta `_sale_price` pero no siempre refresca
la tabla). El síntoma exacto es: *los productos se ven rebajados en su página,
pero el filtro de ofertas no los encuentra* — la columna `onsale` se quedó en 0.

Tu tienda tuvo importaciones de productos, así que es un escenario realista.
Si pasa, la solución es regenerar la tabla:

```
wp wc tool run regenerate_product_lookup_tables
```

Sin WP-CLI: WooCommerce → Estado → Herramientas → *Regenerar las tablas de
búsqueda de productos*. Si el filtro de ofertas funciona pero el **precio** se ve
raro en las búsquedas, la causa es la misma tabla y el mismo arreglo.

## Resumen en una línea

Para que funcione: **key (§1) + copiar los tres bloques (§2) + políticas
(§4)**. El resto mejora la experiencia y evita fallos difíciles de diagnosticar.
