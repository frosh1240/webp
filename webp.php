<?php
/**
 * webp — Miniaturas WebP/AVIF para PrestaShop 8.
 *
 * PrestaShop solo sabe regenerar miniaturas con el boton de "Regenerar
 * miniaturas" del back office, que en un catalogo grande se queda sin tiempo a
 * media faena y, si se corta mientras escribe un fichero, deja miniaturas
 * truncadas que nadie vuelve a mirar.
 *
 * Este modulo aporta tres cosas:
 *
 *   1. Un informe de cobertura: que productos tienen ya sus miniaturas en los
 *      formatos adicionales y cuales no.
 *   2. Generacion por lotes, reanudable y con escritura atomica, desde el back
 *      office o por consola.
 *   3. Generacion automatica de las miniaturas de cada imagen nueva, para que
 *      el catalogo no se vuelva a quedar atras despues de una importacion.
 *
 * No borra ni sobreescribe nada valido, y no toca los JPG originales.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/WebpEngine.php';
require_once __DIR__ . '/classes/WebpReport.php';
require_once __DIR__ . '/classes/WebpWeight.php';

class Webp extends Module
{
    /** Valores por defecto de la configuracion. */
    const DEFAULTS = [
        // Generar las miniaturas de cada imagen nueva en cuanto se sube.
        'WEBP_ON_UPLOAD' => 1,
        // Segundos de cada tanda lanzada desde el back office. Por debajo del
        // tiempo maximo de ejecucion de PHP y del corte del proxy.
        'WEBP_BATCH_SECONDS' => 20,
        // Pausa entre imagenes de origen, para no saturar el servidor.
        'WEBP_SLEEP_MS' => 0,
        // Espacio libre minimo antes de parar, en MB.
        'WEBP_MIN_FREE' => 500,
    ];

    public function __construct()
    {
        $this->name = 'webp';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Carlos Moreno';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Miniaturas WebP');
        $this->description = $this->l('Genera por lotes las miniaturas en WebP y AVIF sin dejar ficheros a medias, informa de que productos las tienen y cuales no, y las crea automaticamente para cada imagen nueva.');
        $this->confirmUninstall = $this->l('Se quitara la pantalla del back office. Las miniaturas ya generadas se quedan como estan.');
    }

    /* ------------------------------------------------------------------ */
    /* Instalacion                                                         */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        return parent::install()
            && $this->installConfiguration()
            && $this->installTab()
            // Se dispara cuando una imagen de producto ya esta en disco: subida
            // desde el back office, importacion CSV o webservice.
            && $this->registerHook('actionWatermark');
    }

    public function uninstall()
    {
        return $this->uninstallTab()
            && $this->uninstallConfiguration()
            && parent::uninstall();
    }

    /**
     * @return bool
     */
    public function installConfiguration()
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    private function uninstallConfiguration()
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * Pestana del back office, junto a las demas pantallas de imagenes.
     *
     * @return bool
     */
    private function installTab()
    {
        if (Tab::getIdFromClassName('AdminWebp')) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminWebp';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentPreferences');
        $tab->icon = 'photo_size_select_large';
        $tab->active = true;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Miniaturas WebP';
        }

        return (bool) $tab->add();
    }

    /**
     * @return bool
     */
    private function uninstallTab()
    {
        $id = (int) Tab::getIdFromClassName('AdminWebp');
        if ($id) {
            $tab = new Tab($id);
            $tab->delete();
        }

        return true;
    }

    /**
     * El enlace de configuracion del listado de modulos lleva a la pantalla.
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminWebp'));
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Imagen de producto recien subida: se generan sus miniaturas al momento.
     *
     * Sin esto, cada importacion deja productos sin miniaturas hasta la
     * siguiente pasada manual. El front las genera al vuelo la primera vez que
     * alguien abre la ficha, pero eso son decimas de segundo por imagen a
     * cuenta del visitante.
     *
     * Cualquier fallo aqui se traga a proposito: subir una imagen no puede
     * romperse porque falte generar una miniatura.
     *
     * @param array $params
     */
    public function hookActionWatermark($params)
    {
        if (!Configuration::get('WEBP_ON_UPLOAD') || empty($params['id_image'])) {
            return;
        }

        try {
            $engine = new WebpEngine([
                'apply' => true,
                'min_free_mb' => (int) Configuration::get('WEBP_MIN_FREE'),
            ]);

            if (!$engine->getFormats()) {
                return;
            }

            $engine->generateForImage((int) $params['id_image'], (int) ($params['id_product'] ?? 0));
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'webp: no se pudieron generar las miniaturas de la imagen '
                    . (int) $params['id_image'] . ': ' . $e->getMessage(),
                2
            );
        }
    }
}
