<?php
/**
 * Ranking de imagenes por peso, para saber cuales merece la pena optimizar.
 *
 * Distingue dos mundos, porque se arreglan de forma distinta:
 *
 *   1. Catalogo: las miniaturas que sirve la tienda. Se regeneran solas a
 *      partir del original, asi que lo que se arregla aqui es resubir una foto
 *      mas ligera o corregir el formato base.
 *   2. Subidas del editor (img/cms y demas): banners y logos que PrestaShop no
 *      gestiona. No hay miniaturas ni WebP automatico: hay que subir el fichero
 *      optimizado a mano.
 *
 * Solo lee. No modifica ni borra nada.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WebpWeight
{
    /** Carpetas de img/ que si gestiona PrestaShop, y por tanto no son "subidas". */
    const MANAGED_DIRS = ['p', 'c', 'm', 'su', 'st', 'tmp', 'l', 'co', 'genders', 'scenes', 'lang'];

    /** Extensiones que se consideran imagen. */
    const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /**
     * Miniaturas de producto mas pesadas de las que se sirven.
     *
     * Para cada imagen se mira el tipo mas grande configurado (el que abre la
     * lupa), que es el que puede costar cientos de KB, y se compara con su
     * equivalente en WebP.
     *
     * @param WebpEngine $engine
     * @param int $limit cuantas filas devolver
     *
     * @return array ['items' => array, 'summary' => array]
     */
    public static function catalog(WebpEngine $engine, $limit = 50)
    {
        $types = ImageType::getImagesTypes('products');
        if (!$types) {
            return ['items' => [], 'summary' => []];
        }

        // El tipo que abre la lupa es el que de verdad puede costar cientos de KB
        // a un visitante. Si el tema no tiene superlarge_default, se toma el mas
        // ancho de los configurados.
        usort($types, function ($a, $b) {
            return (int) $b['width'] <=> (int) $a['width'];
        });
        $biggest = stripslashes($types[0]['name']);
        foreach ($types as $t) {
            if (stripslashes($t['name']) === 'superlarge_default') {
                $biggest = 'superlarge_default';
                break;
            }
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $idShop = (int) Context::getContext()->shop->id;

        $rows = Db::getInstance()->executeS('
            SELECT i.`id_image`, i.`id_product`, p.`active`, p.`reference`, pl.`name`
            FROM `' . _DB_PREFIX_ . 'image` i
            INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = i.`id_product`
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = p.`id_product`
                AND pl.`id_lang` = ' . $idLang . '
                AND pl.`id_shop` = ' . $idShop . '
            ORDER BY i.`id_product` ASC, i.`id_image` ASC');

        $items = [];
        $summary = [
            'tipo' => $biggest,
            'ancho' => (int) $types[0]['width'],
            'imagenes' => 0,
            'peso_base' => 0,
            'peso_webp' => 0,
            'sin_webp' => 0,
            'png_disfrazado' => 0,
            'peso_png_disfrazado' => 0,
            'originales' => 0,
            'peso_originales' => 0,
        ];

        foreach ($rows as $row) {
            $idImage = (int) $row['id_image'];
            $base = $engine->productBase($idImage, (int) $row['id_product']);

            if (is_file($base . '.jpg')) {
                ++$summary['originales'];
                $summary['peso_originales'] += (int) filesize($base . '.jpg');
            }

            $jpg = $base . '-' . $biggest . '.jpg';
            if (!is_file($jpg)) {
                continue;
            }

            $sizeJpg = (int) filesize($jpg);
            $webp = $base . '-' . $biggest . '.webp';
            $sizeWebp = is_file($webp) ? (int) filesize($webp) : 0;

            // PrestaShop guarda PNG dentro de ficheros .jpg cuando el formato
            // base esta en PNG. Pesan del orden de tres veces mas.
            $info = @getimagesize($jpg);
            $esPng = is_array($info) && isset($info[2]) && $info[2] === IMAGETYPE_PNG;

            ++$summary['imagenes'];
            $summary['peso_base'] += $sizeJpg;
            $summary['peso_webp'] += $sizeWebp ?: $sizeJpg;
            if (!$sizeWebp) {
                ++$summary['sin_webp'];
            }
            if ($esPng) {
                ++$summary['png_disfrazado'];
                $summary['peso_png_disfrazado'] += $sizeJpg;
            }

            $items[] = [
                'id_image' => $idImage,
                'id_product' => (int) $row['id_product'],
                'activo' => (int) $row['active'],
                'referencia' => (string) $row['reference'],
                'nombre' => (string) $row['name'],
                'peso' => $sizeJpg,
                'peso_webp' => $sizeWebp,
                'ahorro' => $sizeWebp ? (int) round(100 - ($sizeWebp * 100 / max(1, $sizeJpg))) : null,
                'ancho' => is_array($info) ? (int) $info[0] : 0,
                'alto' => is_array($info) ? (int) $info[1] : 0,
                'png' => $esPng,
                'formato' => $esPng ? 'PNG' : 'JPG',
                'convertida' => $sizeWebp > 0,
            ];
        }

        usort($items, function ($a, $b) {
            return $b['peso'] <=> $a['peso'];
        });

        $summary['ahorro_webp'] = max(0, $summary['peso_base'] - $summary['peso_webp']);

        // Dos listas separadas: quien ya esta convertido y solo pesa mucho, y
        // quien todavia no lo esta. La accion a tomar es distinta en cada caso y
        // mezclarlos no deja ver de que hay que ocuparse.
        $convertidas = [];
        $pendientes = [];
        foreach ($items as $item) {
            if ($item['convertida']) {
                $convertidas[] = $item;
            } else {
                $pendientes[] = $item;
            }
        }

        // Las ya convertidas se ordenan por lo que pesa el WebP, que es lo que
        // se descarga el visitante. Ordenarlas por el JPG daria un ranking de
        // ficheros que ya nadie pide.
        usort($convertidas, function ($a, $b) {
            return $b['peso_webp'] <=> $a['peso_webp'];
        });

        return [
            'convertidas' => array_slice($convertidas, 0, $limit),
            'pendientes' => array_slice($pendientes, 0, $limit),
            'total_convertidas' => count($convertidas),
            'total_pendientes' => count($pendientes),
            'summary' => $summary,
            'total' => count($items),
            // Se conserva la lista completa para el CSV.
            'items' => array_slice($items, 0, $limit),
        ];
    }

    /**
     * Imagenes subidas por el editor: banners, logos y demas contenido que
     * PrestaShop no redimensiona ni convierte.
     *
     * @param int $limit
     *
     * @return array ['items' => array, 'summary' => array]
     */
    public static function uploads($limit = 50)
    {
        $root = rtrim(_PS_IMG_DIR_, '/');
        $items = [];
        $summary = ['ficheros' => 0, 'peso' => 0, 'sin_webp' => 0];

        if (!is_dir($root)) {
            return ['items' => [], 'summary' => $summary];
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $file) {
            if ($file->isDir()) {
                continue;
            }

            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $primera = explode('/', $rel)[0];
            if (in_array($primera, self::MANAGED_DIRS, true)) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (!in_array($ext, self::EXTENSIONS, true)) {
                continue;
            }

            $size = (int) $file->getSize();
            ++$summary['ficheros'];
            $summary['peso'] += $size;

            $sinWebp = in_array($ext, ['jpg', 'jpeg', 'png'], true)
                && !is_file(preg_replace('/\.[^.]+$/', '.webp', $file->getPathname()));
            if ($sinWebp) {
                ++$summary['sin_webp'];
            }

            $info = $size > 0 && $ext !== 'svg' ? @getimagesize($file->getPathname()) : false;

            $items[] = [
                'ruta' => $rel,
                'peso' => $size,
                'ancho' => is_array($info) ? (int) $info[0] : 0,
                'alto' => is_array($info) ? (int) $info[1] : 0,
                'formato' => strtoupper($ext),
                'sin_webp' => $sinWebp,
            ];
        }

        usort($items, function ($a, $b) {
            return $b['peso'] <=> $a['peso'];
        });

        return ['items' => array_slice($items, 0, $limit), 'summary' => $summary, 'total' => count($items)];
    }

    /**
     * @param int $bytes
     *
     * @return string
     */
    public static function human($bytes)
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
