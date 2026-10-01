<?php
/**
 * Motor de generacion de miniaturas en formatos adicionales (webp, avif).
 *
 * Hace lo mismo que AdminImagesController::_regenerateNewImages(), con la misma
 * llamada a ImageManager::resize y el mismo criterio de formato, pero:
 *
 *   - Escribe siempre en un temporal en la misma carpeta y solo renombra al
 *     nombre definitivo cuando la imagen resultante es legible y del formato
 *     pedido. El rename dentro del mismo sistema de ficheros es atomico, asi
 *     que un corte a mitad no puede dejar una miniatura truncada ocupando el
 *     sitio de la buena. (El boton del back office escribe directo sobre el
 *     destino: si se corta, el fichero a medias queda como valido y ya nadie
 *     lo regenera.)
 *   - Trabaja por lotes con presupuesto de tiempo, para caber tanto en una
 *     peticion AJAX del back office como en una pasada larga por consola.
 *   - Nunca borra ni sobreescribe una miniatura valida, ni toca los JPG
 *     originales.
 *   - Se detiene solo si el disco se queda sin espacio.
 *   - Salta las imagenes que no caben en la memoria de PHP en lugar de abortar.
 *   - Un solo proceso a la vez (cerrojo).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WebpEngine
{
    /** Carpetas de origen por tipo de entidad. */
    const ENTITIES = ['products', 'categories', 'manufacturers', 'suppliers', 'stores'];

    /** @var array formatos a generar, sin jpg */
    private $formats;

    /** @var bool generar tambien las versiones 2x */
    private $highDpi;

    /** @var bool la tienda usa el sistema antiguo de rutas de imagen */
    private $legacy;

    /** @var bool false = simulacion: no escribe nada */
    private $apply;

    /** @var bool abrir las miniaturas existentes para comprobar que son legibles */
    private $verify;

    /** @var int espacio libre minimo en bytes */
    private $minFree;

    /** @var int pausa entre imagenes de origen, en milisegundos */
    private $sleepMs;

    /** @var array<int, array{0: string, 1: string}> [fichero, motivo] */
    public $errors = [];

    /** @var resource|null */
    private $lockHandle;

    /** @var string|null temporal abierto ahora mismo, para limpiarlo si el proceso muere */
    private static $openTemp;

    public function __construct(array $options = [])
    {
        $this->formats = isset($options['formats']) && $options['formats']
            ? array_values($options['formats'])
            : self::configuredFormats();
        $this->highDpi = array_key_exists('high_dpi', $options)
            ? (bool) $options['high_dpi']
            : (bool) Configuration::get('PS_HIGHT_DPI');
        $this->legacy = (bool) Configuration::get('PS_LEGACY_IMAGES');
        $this->apply = !empty($options['apply']);
        $this->verify = !empty($options['verify']);
        $this->minFree = isset($options['min_free_mb'])
            ? max(0, (int) $options['min_free_mb']) * 1024 * 1024
            : 500 * 1024 * 1024;
        $this->sleepMs = isset($options['sleep_ms']) ? max(0, (int) $options['sleep_ms']) : 0;

        // Si el proceso muere con un temporal abierto, se borra.
        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(function () {
                if (self::$openTemp !== null && is_file(self::$openTemp)) {
                    @unlink(self::$openTemp);
                }
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Configuracion                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Formatos configurados en la tienda, menos jpg: ese ya existe y volver a
     * generarlo es tiempo tirado.
     *
     * @return array
     */
    public static function configuredFormats()
    {
        $configured = (new PrestaShop\PrestaShop\Core\Image\ImageFormatConfiguration(
            new PrestaShop\PrestaShop\Adapter\Configuration()
        ))->getGenerationFormats();

        return array_values(array_diff($configured, ['jpg']));
    }

    /**
     * Estado de los dos ajustes que hacen falta. Son pantallas distintas y
     * fallar en uno deja el otro sin efecto, sin ningun aviso en el back office.
     *
     * @return array
     */
    public static function diagnostics()
    {
        $flag = (bool) Db::getInstance()->getValue(
            'SELECT state FROM `' . _DB_PREFIX_ . 'feature_flag` WHERE `name` = "multiple_image_format"'
        );
        $raw = Configuration::get('PS_IMAGE_FORMAT');
        $configured = (new PrestaShop\PrestaShop\Core\Image\ImageFormatConfiguration(
            new PrestaShop\PrestaShop\Adapter\Configuration()
        ))->getGenerationFormats();

        return [
            'flag' => $flag,
            'ps_image_format' => $raw === false ? '' : (string) $raw,
            'effective' => $configured,
            'extra' => array_values(array_diff($configured, ['jpg'])),
            'high_dpi' => (bool) Configuration::get('PS_HIGHT_DPI'),
            'gd_webp' => function_exists('imagewebp'),
            'gd_avif' => function_exists('imageavif'),
        ];
    }

    /**
     * @return array
     */
    public function getFormats()
    {
        return $this->formats;
    }

    /* ------------------------------------------------------------------ */
    /* Cerrojo                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Dos pasadas a la vez generarian los mismos ficheros y podrian pisarse al
     * renombrar.
     *
     * @return bool
     */
    public function lock()
    {
        $file = rtrim(_PS_CACHE_DIR_, '/') . '/webp.lock';
        $this->lockHandle = @fopen($file, 'c');
        if (!$this->lockHandle) {
            return true; // sin cerrojo posible; no es motivo para no trabajar
        }

        return (bool) flock($this->lockHandle, LOCK_EX | LOCK_NB);
    }

    public function unlock()
    {
        if ($this->lockHandle) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Rutas                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $entity
     *
     * @return string|null
     */
    public static function dir($entity)
    {
        $dirs = [
            'products' => _PS_PRODUCT_IMG_DIR_,
            'categories' => _PS_CAT_IMG_DIR_,
            'manufacturers' => _PS_MANU_IMG_DIR_,
            'suppliers' => _PS_SUPP_IMG_DIR_,
            'stores' => _PS_STORE_IMG_DIR_,
        ];

        return $dirs[$entity] ?? null;
    }

    /**
     * Ruta base (sin extension) de una imagen de producto.
     *
     * @param int $idImage
     * @param int $idProduct
     *
     * @return string
     */
    public function productBase($idImage, $idProduct = 0)
    {
        $dir = _PS_PRODUCT_IMG_DIR_;
        if ($this->legacy && $idProduct && is_file($dir . $idProduct . '-' . $idImage . '.jpg')) {
            return $dir . $idProduct . '-' . $idImage;
        }

        return $dir . Image::getImgFolderStatic($idImage) . $idImage;
    }

    /**
     * Imagenes de origen de una entidad.
     *
     * @param string $entity
     *
     * @return array<int, array{0: string, 1: string, 2: int, 3: int}> [jpg, base, id, id_padre]
     */
    public function sources($entity)
    {
        $dir = self::dir($entity);
        if (!$dir || !is_dir($dir)) {
            return [];
        }

        $sources = [];

        if ($entity === 'products') {
            foreach (Image::getAllImages() as $row) {
                $id = (int) $row['id_image'];
                $base = $this->productBase($id, (int) $row['id_product']);
                if (is_file($base . '.jpg') && filesize($base . '.jpg')) {
                    $sources[] = [$base . '.jpg', $base, $id, (int) $row['id_product']];
                }
            }

            return $sources;
        }

        foreach (scandir($dir, SCANDIR_SORT_NONE) as $file) {
            if (preg_match('/^([0-9]+)\.jpg$/', $file, $m)) {
                $sources[] = [$dir . $file, $dir . substr($file, 0, -4), (int) $m[1], 0];
            }
        }

        return $sources;
    }

    /**
     * Miniaturas que deberian existir para una imagen de origen.
     *
     * @param string $entity
     * @param string $base
     *
     * @return array<int, array{0: string, 1: int, 2: int, 3: string}> [destino, ancho, alto, formato]
     */
    public function targets($entity, $base)
    {
        $out = [];
        foreach (ImageType::getImagesTypes($entity) as $it) {
            $name = stripslashes($it['name']);
            foreach ($this->formats as $format) {
                $out[] = [$base . '-' . $name . '.' . $format, (int) $it['width'], (int) $it['height'], $format];
                if ($this->highDpi) {
                    $out[] = [$base . '-' . $name . '2x.' . $format, (int) $it['width'] * 2, (int) $it['height'] * 2, $format];
                }
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Validacion de ficheros                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Extension -> constante de getimagesize.
     *
     * @param string $format
     *
     * @return int|null
     */
    private static function expectedType($format)
    {
        $map = [
            'webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : 18,
            'avif' => defined('IMAGETYPE_AVIF') ? IMAGETYPE_AVIF : 19,
            'jpg' => IMAGETYPE_JPEG,
            'png' => IMAGETYPE_PNG,
        ];

        return $map[$format] ?? null;
    }

    /**
     * Una miniatura es valida si existe, pesa algo y se lee como imagen del
     * formato esperado. Es lo que detecta las que quedaron truncadas.
     *
     * @param string $path
     * @param string $format
     *
     * @return bool
     */
    public static function isValidImage($path, $format)
    {
        if (!is_file($path) || filesize($path) < 64) {
            return false;
        }
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0])) {
            return false;
        }
        $expected = self::expectedType($format);
        // jpg puede contener PNG legitimamente (PS_IMAGE_QUALITY): ahi basta con
        // que sea una imagen legible.
        if ($expected !== null && $format !== 'jpg' && $info[2] !== $expected) {
            return false;
        }

        return true;
    }

    /**
     * @param int $code
     *
     * @return string
     */
    private static function errorText($code)
    {
        $map = [
            ImageManager::ERROR_FILE_NOT_EXIST => 'el original no existe o esta vacio',
            ImageManager::ERROR_FILE_WIDTH => 'el original no tiene ancho legible',
            ImageManager::ERROR_MEMORY_LIMIT => 'no cabe en la memoria de PHP',
        ];

        return $map[$code] ?? ('error ' . (int) $code);
    }

    /* ------------------------------------------------------------------ */
    /* Generacion                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Genera una miniatura si falta (o si esta corrupta y se pidio revisar).
     *
     * @return string creada|existente|pendiente|fallida
     */
    public function generateFile($src, $dst, $width, $height, $format)
    {
        $exists = is_file($dst);

        if ($exists && !$this->verify) {
            return 'existente';
        }
        if ($exists && $this->verify && self::isValidImage($dst, $format)) {
            return 'existente';
        }
        // Aqui: falta, o existe y esta corrupta.

        if (!$this->apply) {
            return 'pendiente';
        }

        if (!ImageManager::checkImageMemoryLimit($src)) {
            $this->errors[] = [$src, 'no cabe en la memoria de PHP, se salta'];

            return 'fallida';
        }

        $tmp = $dst . '.tmp-' . getmypid();
        self::$openTemp = $tmp;

        $code = 0;
        $ok = ImageManager::resize($src, $tmp, (int) $width, (int) $height, $format, $format !== 'jpg', $code);

        if (!$ok || !self::isValidImage($tmp, $format)) {
            @unlink($tmp);
            self::$openTemp = null;
            $this->errors[] = [$src, $ok ? 'la miniatura salio corrupta' : self::errorText($code)];

            return 'fallida';
        }

        @chmod($tmp, 0644);

        if (!@rename($tmp, $dst)) {
            @unlink($tmp);
            self::$openTemp = null;
            $this->errors[] = [$dst, 'no se pudo renombrar (permisos?)'];

            return 'fallida';
        }

        self::$openTemp = null;

        return 'creada';
    }

    /**
     * Todas las miniaturas de una imagen de origen.
     *
     * @return array contadores
     */
    public function processSource($entity, $src, $base)
    {
        $stats = ['creadas' => 0, 'pendientes' => 0, 'existentes' => 0, 'fallidas' => 0];

        foreach ($this->targets($entity, $base) as [$dst, $w, $h, $format]) {
            $r = $this->generateFile($src, $dst, $w, $h, $format);
            $key = ['creada' => 'creadas', 'pendiente' => 'pendientes', 'existente' => 'existentes', 'fallida' => 'fallidas'][$r];
            ++$stats[$key];
        }

        return $stats;
    }

    /**
     * Miniaturas de una sola imagen de producto. Lo usa el hook de subida.
     *
     * @param int $idImage
     * @param int $idProduct
     *
     * @return array contadores
     */
    public function generateForImage($idImage, $idProduct = 0)
    {
        $base = $this->productBase((int) $idImage, (int) $idProduct);
        $src = $base . '.jpg';

        if (!is_file($src) || !filesize($src)) {
            return ['creadas' => 0, 'pendientes' => 0, 'existentes' => 0, 'fallidas' => 0];
        }

        return $this->processSource('products', $src, $base);
    }

    /**
     * Un lote con presupuesto de tiempo. Devuelve el estado para continuar.
     *
     * @param array $state ['entity' => string, 'offset' => int]
     * @param int $seconds presupuesto de esta tanda
     * @param int $maxSources tope de imagenes de origen de esta tanda
     *
     * @return array
     */
    public function runBatch(array $state, $seconds = 20, $maxSources = PHP_INT_MAX)
    {
        $started = microtime(true);
        $entities = array_values(array_filter(self::ENTITIES, function ($e) {
            $dir = self::dir($e);

            return $dir && is_dir($dir);
        }));

        $entity = $state['entity'] ?? ($entities[0] ?? null);
        $offset = max(0, (int) ($state['offset'] ?? 0));

        $totals = ['creadas' => 0, 'pendientes' => 0, 'existentes' => 0, 'fallidas' => 0, 'origen' => 0];
        $stop = null;

        while ($entity !== null) {
            $dir = self::dir($entity);

            if ($this->apply && !is_writable($dir)) {
                $this->errors[] = [$dir, 'sin permiso de escritura'];
                $entity = $this->nextEntity($entities, $entity);
                $offset = 0;
                continue;
            }

            $sources = $this->sources($entity);
            $count = count($sources);

            while ($offset < $count) {
                if ((microtime(true) - $started) >= $seconds) {
                    $stop = 'tiempo';
                    break 2;
                }
                if ($totals['origen'] >= $maxSources) {
                    $stop = 'limite';
                    break 2;
                }
                if ($this->apply && $this->minFree) {
                    $free = @disk_free_space($dir);
                    if ($free !== false && $free < $this->minFree) {
                        $stop = 'disco';
                        break 2;
                    }
                }

                [$src, $base] = $sources[$offset];
                $stats = $this->processSource($entity, $src, $base);
                foreach ($stats as $k => $v) {
                    $totals[$k] += $v;
                }
                ++$totals['origen'];
                ++$offset;

                if ($this->sleepMs) {
                    usleep($this->sleepMs * 1000);
                }
            }

            if ($offset >= $count) {
                $entity = $this->nextEntity($entities, $entity);
                $offset = 0;
            }
        }

        return [
            'totals' => $totals,
            'errors' => $this->errors,
            'stop' => $stop,
            'done' => $entity === null,
            'state' => ['entity' => $entity, 'offset' => $offset],
            'seconds' => round(microtime(true) - $started, 1),
        ];
    }

    /**
     * @param array $entities
     * @param string $current
     *
     * @return string|null
     */
    private function nextEntity(array $entities, $current)
    {
        $i = array_search($current, $entities, true);
        if ($i === false) {
            return null;
        }

        return $entities[$i + 1] ?? null;
    }

    /**
     * Cuenta cuantas imagenes de origen hay en total, para la barra de avance.
     *
     * @return int
     */
    public function countSources()
    {
        $n = 0;
        foreach (self::ENTITIES as $entity) {
            $n += count($this->sources($entity));
        }

        return $n;
    }

    /**
     * Borra los temporales que dejara una pasada anterior muerta de mala manera.
     *
     * @param string $entity
     *
     * @return int
     */
    public function cleanTemporaryFiles($entity)
    {
        $dir = self::dir($entity);
        if (!$dir || !is_dir($dir)) {
            return 0;
        }

        $n = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (strpos($file->getFilename(), '.tmp-') !== false && @unlink($file->getPathname())) {
                ++$n;
            }
        }

        return $n;
    }
}
