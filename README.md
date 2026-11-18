# SW Easy Variation Images

Imágenes de variaciones de WooCommerce sin trabajo repetitivo: eliges **una imagen por valor de atributo** (por ejemplo, por color) y todas las variaciones con ese valor la reciben. Compatible con la galería de variaciones del tema **WoodMart**.

Un producto con 2 colores y 8 tallas tiene 16 variaciones. En WooCommerce cada una necesita su imagen por separado. Con este plugin eliges 2 imágenes y listo.

## Funciones

- Panel **«Imágenes por atributo»** en la pestaña **Variaciones** del producto: una fila por cada valor (Azul marino, Blanco…), con su imagen.
- **Aplicar a las variaciones**: todas las variaciones con ese valor reciben la imagen (sustituye la actual).
- **Automático**: las variaciones nuevas sin imagen (por ejemplo, tras «Generar variaciones») la reciben solas al crearlas o al guardar. Las que ya tienen imagen no se tocan.
- Funciona con cualquier atributo, global o personalizado. Por defecto elige el color; se puede cambiar en el propio panel.
- **WoodMart**: si el tema está activo, aparece una columna de **imágenes adicionales** (galería de la variación) que se reparte igual.
- Inglés por defecto; español y ruso automáticos según el idioma de WordPress.

## Instalación

1. Descarga `sw-easy-variation-images.zip` de la última [Release](../../releases/latest).
2. WordPress → Plugins → Añadir nuevo → Subir plugin → Activar.
3. Abre un producto variable → pestaña **Variaciones** → panel **Imágenes por atributo**.

Requisitos: WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+.

Las siguientes versiones llegan como actualización normal en **Plugins**.

## Uso

1. Elige el atributo en **Compartir imágenes por** (normalmente ya viene el color).
2. Pulsa **Elegir** en cada valor y selecciona la imagen. Con WoodMart, también las imágenes adicionales.
3. Pulsa **Aplicar a las variaciones**.

Las variaciones con «Cualquier …» en ese atributo no se modifican.

## Hooks

- `sw_evi_default_attribute` — atributo por defecto (`string $key, WC_Product $product, array $attributes`).
- `sw_evi_autofill` — devolver `false` para desactivar el relleno automático (`bool $enabled, int $product_id`).
- `sw_evi_woodmart_gallery` — forzar la compatibilidad con la galería de WoodMart (`bool $active`).
- `sw_evi_applied` — acción tras aplicar (`int $product_id, string $attribute, int[] $variation_ids`).

## Publicar una nueva versión

1. Sube `Version:` en la cabecera de `sw-easy-variation-images.php` y `SW_EVI_VERSION`.
2. Escribe las novedades en `RELEASE_NOTES.md`.
3. Haz push a `main`. GitHub Actions crea la etiqueta, la Release y el zip en ~1 minuto.

## Idiomas

Para añadir o cambiar textos: edita las traducciones en `tools/build_i18n.py` y ejecuta `python3 tools/build_i18n.py .` — regenera `languages/*.pot/.po/.mo` y avisa si falta alguna traducción.

## Licencia

GPL-2.0-or-later. Las actualizaciones usan [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
