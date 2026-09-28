# Cove AI Chat — chat con IA sobre WooCommerce

Chat para los visitantes de COVE que consulta el catálogo **de verdad**. La IA
no inventa precios ni stock: cuando necesita un dato llama a una herramienta
que lo lee de WooCommerce.

| | |
|---|---|
| **Qué es** | Un mu-plugin de WordPress (~1.190 líneas de código, 10 archivos) + un componente de chat autoalojado |
| **Modelo** | DeepSeek, con tu propia API key (`deepseek-flash`) |
| **Interfaz** | [DeepChat](https://deepchat.dev) 2.5.1, autoalojado (sin CDN) |
| **Funciona en** | `http://COVE.test` (Laragon local). Sin desplegar nada |
| **Coste de terceros** | Ninguno: sin SaaS, sin suscripción, sin tarjeta. Solo los tokens de tu key |

---

## Antes de nada: lee esto

**La IA no es la fuente de verdad; las herramientas lo son.**

Todo dato de negocio (precio, stock, categorías, estados de pedido) sale de una
función que consulta WooCommerce en el momento de la pregunta. En el código **no
hay ni un dato de la tienda**: ni precios, ni conteos, ni nombres de categoría,
ni estados de pedido, ni moneda. Eso se llama "dinámico" y es intencional: si un
dato está escrito en el plugin, es un bug esperando a quedar desactualizado.

---

## Estructura

```
cove-ai-chat/
├── README.md                    ← estás aquí
├── docs/
│   ├── CONFIGURACION.md         ← LO QUE TIENES QUE CONFIGURAR TÚ (empieza por ahí)
│   ├── ARQUITECTURA.md          ← cómo está construido y por qué
│   └── TOOLS.md                 ← contrato de cada herramienta
├── assets/
│   ├── deepChat.min.js          ← componente DeepChat 2.5.1 (tercero, ver VENDOR.md)
│   └── VENDOR.md                ← versión, hash y cómo actualizarlo
└── src/
    ├── cove-ai-chat.php         ← cargador. VA EN mu-plugins/
    └── cove-ai-chat/            ← VA EN mu-plugins/cove-ai-chat/
        ├── 00-config.php        ← constantes, secretos, prompt del sistema
        ├── 10-session.php       ← id de sesión y estado de la última búsqueda
        ├── 20-query-builder.php ← TODAS las consultas a WooCommerce y los filtros
        ├── 30-tools-schemas.php ← esquemas JSON de las herramientas
        ├── 31-tools-implementations.php ← qué hace cada herramienta
        ├── 40-deepseek.php      ← cliente de API y ciclo de tool calling
        ├── 50-rest-endpoint.php ← endpoint REST y cuota por IP
        ├── 60-widget.php        ← incrusta el componente en el sitio
        └── content/
            └── cove-info.php    ← LAS POLÍTICAS DE TU TIENDA (esto lo editas tú)
```

Los prefijos numéricos son intencionales: el orden de carga se ve de un vistazo
y no hay que adivinar qué archivo define qué. El cargador los incluye en ese
orden.

---

## Instalación

WordPress solo carga automáticamente los `.php` que están **directamente** en
`mu-plugins/`, no los de subcarpetas. De ahí la pareja cargador + carpeta.

```bash
# 1. Cargador + módulos
cd "C:/Users/jjsot/Documents/Hermes generated/cove-ai-chat"
mkdir -p "C:/laragon/www/COVE/wp-content/mu-plugins"
cp src/cove-ai-chat.php "C:/laragon/www/COVE/wp-content/mu-plugins/"
cp -r src/cove-ai-chat "C:/laragon/www/COVE/wp-content/mu-plugins/"

# 2. El bundle del widget (esta es la que se olvida)
mkdir -p "C:/laragon/www/COVE/wp-content/mu-plugins/cove-ai-chat/assets"
cp assets/deepChat.min.js "C:/laragon/www/COVE/wp-content/mu-plugins/cove-ai-chat/assets/"
```

El script `instalar.ps1` de la raíz hace esos tres pasos de una vez si prefieres
no copiar a mano.

Después:

```php
// 2. En C:\laragon\www\COVE\wp-config.php, antes de "That's all, stop editing!":
define( 'DEEPSEEK_API_KEY', 'sk-tu-key-aqui' );
```

```bash
# 3. Comprobar que el endpoint existe
curl -s "http://COVE.test/wp-json/" | grep -o "cove-ai/v1" | head -1
# debe imprimir: cove-ai/v1
```

Y el manual completo, con lo que hay que configurar de tu lado, está en
**`docs/CONFIGURACION.md`**.

---

## Prueba rápida sin tocar el navegador

Esto aísla los problemas. Si el paso A funciona y el B no, el problema está
entre la key y el plugin, no en el widget.

```bash
# A) ¿La API key funciona? (aislado de WordPress)
curl -s https://api.deepseek.com/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-TU-KEY" \
  -d '{"model":"deepseek-flash","messages":[{"role":"user","content":"hola"}]}'

# B) ¿Responde el plugin? (aislado del navegador)
curl -s -X POST http://COVE.test/wp-json/cove-ai/v1/chat \
  -H "Content-Type: application/json" \
  -d '{"messages":[{"role":"user","text":"¿qué neveras tienen?"}],"session_id":"prueba1"}'
```

La respuesta de B debe ser `{"text": "..."}`. Si sale el mensaje de "no está
configurado", falta la key. Si sale "no pude completar la consulta", mira
`wp-content/debug.log` o el log de PHP de Laragon: el error real se registra
ahí.

---

## Estado de este código

**Verificado:**

- Los 10 archivos pasan `php -l` con el PHP 8.3.33 de tu Laragon.
- El bundle de DeepChat es el componente real (registra
  `customElements.define("deep-chat")`, hash en `assets/VENDOR.md`).
- El esquema de mensajes (`{text, role}` con roles `user`/`ai`) y las
  propiedades del widget están tomados de la documentación oficial, no de
  memoria.
- El formato de las tools (`tools`, `tool_choice`, `tool_calls`,
  `tool_call_id`) sigue la documentación de DeepSeek.

**NO verificado todavía** (requiere tu API key, que no tengo):

- Una llamada real al modelo con herramientas.
- El ciclo completo de tool calling contra tu catálogo.
- Que tus atributos de color sean globales (ver `docs/CONFIGURACION.md` §9).

**Limitaciones conocidas:**

- El historial vive en el navegador. Al limpiar los datos del navegador se
  pierde la conversación (por diseño: cero base de datos).
- La tool de pedidos solo sirve para clientes con cuenta. Un invitado no tiene
  pedidos que mostrar.
- Los atributos **personalizados** (creados dentro de un producto, no en
  Productos → Atributos) no se pueden filtrar. WooCommerce no los expone como
  taxonomía. La herramienta lo detecta y lo dice, en vez de devolver cero
  resultados.
