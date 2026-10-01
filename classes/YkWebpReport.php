<?php
/**
 * Informe de cobertura: que productos tienen ya sus miniaturas en los formatos
 * adicionales y cuales no.
 *
 * Solo lee disco y base de datos. Con $verify = true abre ademas cada miniatura
 * existente para comprobar que es legible, lo que es mucho mas lento (del orden
 * de 80 ms por fichero) pero detecta las que quedaron truncadas.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WebpReport
{
    /**
     * @param WebpEngine $engine
     * @param bool $verify comprobar que las existentes son legibles
     * @param bool $includeInactive incluir productos desactivados
     *
     * @return array ['summary' => array, 'products' => array]
     */
    public static function build(WebpEngine $engine, $verify = false, $includeInactive = false)
    {
        $formats = $engine->getFormats();
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $idShop = (int) Context::getContext()->shop->id;

        $imageTypes = ImageType::getImagesTypes('products');
        $highDpi = (bool) Configuration::get('PS_HIGHT_DPI');
        $perImage = count($imageTypes) * count($formats) * ($highDpi ? 2 : 1);

        $rows = Db::getInstance()->executeS('
            SELECT i.`id_image`, i.`id_product`, p.`active`, p.`reference`, pl.`name`
            FROM `' . _DB_PREFIX_ . 'image` i
            INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = i.`id_product`
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = p.`id_product`
                AND pl.`id_lang` = ' . $idLang . '
                AND pl.`id_shop` = ' . $idShop . '
            ORDER BY i.`id_product` ASC, i.`id_image` ASC');

        $products = [];

        foreach ($rows as $row) {
            if (!$includeInactive && !$row['active']) {
                continue;
            }

            $idProduct = (int) $row['id_product'];
            $idImage = (int) $row['id_image'];

            if (!isset($products[$idProduct])) {
                $products[$idProduct] = [
                    'id' => $idProduct,
                    'activo' => (int) $row['active'],
                    'referencia' => (string) $row['reference'],
                    'nombre' => (string) $row['name'],
                    'imagenes' => 0,
                    'esperadas' => 0,
                    'existentes' => 0,
                    'corruptas' => 0,
                    'sin_original' => 0,
                ];
            }

            ++$products[$idProduct]['imagenes'];

            $base = $engine->productBase($idImage, $idProduct);
            if (!is_file($base . '.jpg') || !filesize($base . '.jpg')) {
                ++$products[$idProduct]['sin_original'];
                continue;
            }

            $products[$idProduct]['esperadas'] += $perImage;

            foreach ($engine->targets('products', $base) as [$dst, , , $format]) {
                if (!is_file($dst)) {
                    continue;
                }
                ++$products[$idProduct]['existentes'];
                if ($verify && !WebpEngine::isValidImage($dst, $format)) {
                    ++$products[$idProduct]['corruptas'];
                }
            }
        }

        $summary = [
            'formatos' => $formats,
            'tipos_imagen' => count($imageTypes),
            'alta_densidad' => $highDpi,
            'por_imagen' => $perImage,
            'productos' => count($products),
            'completos' => 0,
            'a_medias' => 0,
            'sin_ninguna' => 0,
            'faltan' => 0,
            'corruptas' => 0,
            'sin_original' => 0,
            'incluye_inactivos' => $includeInactive,
            'verificado' => $verify,
        ];

        foreach ($products as $id => $p) {
            $faltan = max(0, $p['esperadas'] - $p['existentes']);
            $products[$id]['faltan'] = $faltan;
            $products[$id]['estado'] = ($faltan === 0 && $p['esperadas'] > 0 && !$p['corruptas'])
                ? 'completo'
                : ($p['existentes'] === 0 ? 'sin_miniaturas' : 'a_medias');

            $summary['faltan'] += $faltan;
            $summary['corruptas'] += $p['corruptas'];
            $summary['sin_original'] += $p['sin_original'];

            if ($products[$id]['estado'] === 'completo') {
                ++$summary['completos'];
            } elseif ($products[$id]['estado'] === 'sin_miniaturas') {
                ++$summary['sin_ninguna'];
            } else {
                ++$summary['a_medias'];
            }
        }

        return ['summary' => $summary, 'products' => $products];
    }

    /**
     * Los que mas miniaturas tienen pendientes.
     *
     * @param array $report
     * @param int $limit
     *
     * @return array
     */
    public static function worst(array $report, $limit = 20)
    {
        $pending = array_filter($report['products'], function ($p) {
            return $p['estado'] !== 'completo';
        });

        usort($pending, function ($a, $b) {
            return $b['faltan'] <=> $a['faltan'];
        });

        return array_slice($pending, 0, $limit);
    }

    /**
     * @param array $report
     * @param resource $handle
     */
    public static function writeCsv(array $report, $handle)
    {
        fputcsv($handle, [
            'id_producto', 'referencia', 'nombre', 'activo', 'imagenes',
            'esperadas', 'existentes', 'faltan', 'corruptas', 'sin_original', 'estado',
        ], ';');

        foreach ($report['products'] as $p) {
            fputcsv($handle, [
                $p['id'], $p['referencia'], $p['nombre'], $p['activo'], $p['imagenes'],
                $p['esperadas'], $p['existentes'], $p['faltan'], $p['corruptas'],
                $p['sin_original'], $p['estado'],
            ], ';');
        }
    }
}
