# webp — Miniaturas WebP

Genera las miniaturas de producto (y de categorías, marcas, proveedores y
tiendas) en los formatos adicionales que tenga configurada la tienda —WebP,
AVIF— sin dejar ficheros a medias, e informa de qué productos las tienen ya y
cuáles no.

Módulo independiente: no comparte nada con `redirect` ni con `metas`.

## Por qué existe

PrestaShop solo trae el botón «Regenerar miniaturas» del back office. En este
catálogo son unas 42.000 miniaturas por formato, a unos 65 ms cada una: cerca de
45 minutos seguidos. Una petición web no aguanta eso, así que el botón se queda
a mitad y hay que volver a empezar. Y hay un problema peor: escribe cada
miniatura directamente sobre su nombre final, de modo que si el proceso se corta
mientras escribe, el fichero truncado queda en su sitio y en la siguiente pasada
se da por bueno. Es una imagen rota para siempre.

## Qué hace

**Informe de cobertura.** Producto a producto: cuántas miniaturas debería tener,
cuántas hay y cuántas faltan. En pantalla salen los 20 que peor están; el CSV
lleva el catálogo entero. Avisa además de las imágenes que están registradas en
la base de datos pero no tienen su archivo JPG en disco, que no se pueden
generar hasta volver a subirlas.

**Generación por lotes.** Desde el back office en tandas de unos 20 segundos
—encadenadas por el navegador, con barra de avance y botón de parar— o desde
consola de un tirón. Las dos vías usan el mismo motor.

**Miniaturas de cada imagen nueva.** Con el hook `actionWatermark`, cada imagen
subida desde el back office, importada por CSV o creada por el webservice recibe
sus miniaturas en ese momento. Sin esto, cada importación vuelve a dejar
productos atrás. (El front también las genera al vuelo la primera vez que
alguien abre la ficha, pero a costa de la velocidad de esa visita.)

**Ranking por peso.** Un bloque aparte lista las imágenes más pesadas, separadas
en dos mundos porque se arreglan de forma distinta: las miniaturas del catálogo
(se corrigen resubiendo una foto más ligera o cambiando el formato base) y las
subidas del editor —banners, logos, imágenes de páginas—, que PrestaShop no
redimensiona ni convierte y hay que optimizar a mano. Marca además las
miniaturas que son PNG guardados dentro de un archivo `.jpg`, que pesan del
orden de tres veces más, y el ahorro que da servirlas en WebP. Todo se exporta a
CSV.

**Revisión de las existentes.** Abre las miniaturas ya creadas y comprueba que
son legibles; con `--apply` rehace las corruptas por encima, sin borrarlas.

## Garantías

- **Escritura atómica**: cada miniatura se escribe en `destino.tmp-PID`, se
  comprueba que es una imagen legible del formato pedido y solo entonces se
  renombra. El rename dentro del mismo sistema de ficheros es indivisible: o
  está la versión buena, o no hay nada.
- **No escribe nada sin `--apply`** (consola) o sin pulsar «Generar» (pantalla).
- **Nunca borra ni sobrescribe** una miniatura válida, ni toca los JPG
  originales.
- **Un solo proceso a la vez**, con cerrojo compartido entre la consola y el
  back office.
- **Se detiene solo** si el disco baja del mínimo configurado (500 MB por
  defecto).
- **Ctrl+C y SIGTERM** terminan la miniatura en curso antes de salir.
- Las imágenes que no caben en la memoria de PHP se saltan y se listan, en lugar
  de tumbar la pasada.
- Al empezar, barre los `.tmp-` que hubiera dejado una pasada anterior muerta de
  mala manera.
- Es reanudable: lo ya creado se salta.

## Los dos interruptores de PrestaShop

Para que la tienda sirva WebP hacen falta dos ajustes, en pantallas distintas, y
si falta uno el otro no hace nada **sin ningún aviso**:

1. `multiple_image_format` en _Parámetros avanzados › Funcionalidades
   experimentales_.
2. WebP marcado en _Parámetros de la tienda › Imágenes › Formato de imagen_.

La pantalla del módulo muestra el estado de los dos.

Se pueden preparar las miniaturas **antes** de activar la bandera, pasando el
formato a mano, para que al encenderla ya estén todas:

```bash
php modules/webp/cli/generate.php --formats=webp --apply --seconds=0
```

## Uso por consola

```bash
php modules/webp/cli/generate.php --informe
php modules/webp/cli/generate.php --informe --csv=/tmp/webp.csv
php modules/webp/cli/generate.php                      # simulación
php modules/webp/cli/generate.php --apply --seconds=600
php modules/webp/cli/generate.php --apply --revisar-existentes
nice -n 19 php modules/webp/cli/generate.php --apply --sleep=40
```

| Opción                 | Qué hace                                                                |
| ---------------------- | ----------------------------------------------------------------------- |
| `--informe`            | informe por producto en lugar de generar                                |
| `--csv=RUTA`           | vuelca el informe completo a CSV                                        |
| `--apply`              | genera de verdad; sin esto no se escribe nada                           |
| `--type=TIPO`          | `products`, `categories`, `manufacturers`, `suppliers`, `stores`, `all` |
| `--limit=N`            | tope de imágenes de origen de la pasada                                 |
| `--seconds=N`          | presupuesto de tiempo; `0` = sin límite                                 |
| `--sleep=MS`           | pausa entre imágenes, para no saturar el servidor                       |
| `--formats=LISTA`      | formatos a generar, ignorando la configuración                          |
| `--min-free=MB`        | espacio libre mínimo antes de parar                                     |
| `--revisar-existentes` | comprueba las ya creadas; con `--apply`, rehace las corruptas           |
| `--incluir-inactivos`  | en el informe, incluye productos desactivados                           |

Código de salida: `0` todo bien, `1` falta configuración, `2` hubo miniaturas
fallidas.

## Tarea programada

Una pasada diaria de madrugada deja al día lo que hayan traído las
importaciones:

```cron
30 3 * * * cd /ruta/a/la/tienda && nice -n 19 php modules/webp/cli/generate.php --apply --seconds=3600 >> var/logs/webp.log 2>&1
```

## Ajustes

En _Parámetros de la tienda › Miniaturas WebP_:

- **Generar las miniaturas de cada imagen nueva** (sí por defecto).
- **Duración de cada tanda**: 20 s por defecto. Debe quedar por debajo del
  tiempo máximo de PHP y del corte del proxy; Cloudflare corta sobre los 100 s.
- **Pausa entre imágenes**: súbela si la tienda va lenta mientras se generan.
- **Espacio libre mínimo**: 500 MB por defecto.
