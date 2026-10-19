# Dependencia de terceros: componente DeepChat

El único código que no escribimos. Todo lo demás del proyecto es propio.

| Dato | Valor |
|---|---|
| Paquete | `deep-chat` |
| Versión | **2.5.1** (la que jsDelivr marca como `latest` el 27-sep-2026) |
| Archivo de entrada real | `dist/deepChat.min.js` |
| Origen | `https://cdn.jsdelivr.net/npm/deep-chat@2.5.1/dist/deepChat.min.js` |
| Tamaño | 494.443 bytes (484 KB) |
| SHA-256 | `051880957b0aaab79c9e5d99b0b03424dc472fc0fd5bb16dc7afe47203ed0888` |
| Repositorio | https://github.com/OvidijusParsiunas/deep-chat |
| Documentación | https://deepchat.dev/docs |

## Verificación hecha al descargarlo

No se confió en el nombre del archivo. Se comprobó que el contenido es
realmente el componente:

```
grep -o 'customElements.define("[a-z-]*"' deepChat.min.js
→ customElements.define("deep-chat")
```

Un archivo con el tamaño correcto pero que no registre el elemento
personalizado sería una página de error del CDN, no el componente.

## Dos avisos para quien actualice esto

1. **El nombre del archivo no es el que uno espera.** `dist/deep-chat.bundle.js`
   y `dist/deep-chat.js` devuelven **404**. El entry real del paquete es
   `dist/deepChat.min.js` (nota la "C" mayúscula y la ausencia de guion).
2. **Hay una versión rara en el registro.** La API de jsDelivr lista un
   `9.0.114` además del 2.5.1, pero la etiqueta `latest` apunta a **2.5.1**.
   Se ancló a 2.5.1 por ser la que el propio paquete declara estable. Si alguien
   actualiza, que verifique primero cuál es la buena antes de asumir que el
   número más alto es el más nuevo.

## Cómo actualizarlo

```bash
cd assets
curl -L -o deepChat.min.js "https://cdn.jsdelivr.net/npm/deep-chat@<VERSION>/dist/deepChat.min.js"

# 1. Que siga siendo el componente
grep -o 'customElements.define("[a-z-]*"' deepChat.min.js

# 2. Anotar el nuevo hash aquí y subir COVE_AI_VERSION en 00-config.php
#    (la versión se usa para romper el cache del navegador)
sha256sum deepChat.min.js
```

Después de actualizar, revisar en https://deepchat.dev/docs si cambiaron los
nombres de las propiedades que usamos en `60-widget.php`: `connect`,
`requestBodyLimits`, `introMessage`, `browserStorage`, `messageStyles`,
`errorMessages`, `displayLoadingBubble`. Los nombres de las propiedades de un
componente web no tienen por qué ser estables entre versiones mayores.

## Licencia

**No verificada.** Revísala en el repositorio del proyecto antes de redistribuir
este bundle (por ejemplo, si algún día empaquetas el plugin para otra tienda).
Uso local y privado no plantea el problema; la redistribución sí.

## Por qué está autoalojado y no por CDN

El sitio es local (`http://COVE.test`). Un CDN añadiría dos problemas sin
beneficio: la interfaz dejaría de cargar sin internet, y se introduciría una
dependencia externa silenciosa en un proyecto que no tiene ninguna otra.

## Por qué existe una carpeta `assets` fuera de `src`

`assets/` es la fuente del bundle; el que WordPress carga es la copia que vive
en `wp-content/mu-plugins/cove-ai-chat/assets/`. Se mantienen separados para que
`src/` sea exactamente lo que se copia a `mu-plugins/`, sin binarios de terceros
mezclados con el código propio. La copia la hace `instalar.ps1` (§2 del README).
