<?php
/**
 * Pantalla del back office: diagnostico, informe de cobertura y generacion por
 * lotes.
 *
 * La generacion va por AJAX en tandas cortas (por defecto 20 s) porque una
 * peticion web no puede durar los 45 minutos que lleva un catalogo entero: el
 * tiempo maximo de PHP y el corte del proxy la matarian a mitad. Cada tanda
 * devuelve donde se quedo y el navegador pide la siguiente.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'webp/webp.php';

class AdminWebpController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        $this->lang = false;

        parent::__construct();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);

        // La fecha del fichero va en la URL: sin esto, el navegador sigue
        // usando la copia que tenga guardada y los cambios no llegan hasta que
        // alguien vacia la cache a mano.
        $js = _PS_MODULE_DIR_ . 'webp/views/js/webp.js';
        $this->addJS(_MODULE_DIR_ . 'webp/views/js/webp.js?v=' . (is_file($js) ? filemtime($js) : $this->module->version));
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_title = $this->l('Miniaturas WebP');
        parent::initPageHeaderToolbar();
    }

    /**
     * Guardado de ajustes y descarga del CSV.
     */
    public function postProcess()
    {
        if (Tools::isSubmit('submitwebpSettings')) {
            Configuration::updateValue('WEBP_ON_UPLOAD', (int) Tools::getValue('WEBP_ON_UPLOAD'));
            Configuration::updateValue('WEBP_BATCH_SECONDS', max(5, min(120, (int) Tools::getValue('WEBP_BATCH_SECONDS'))));
            Configuration::updateValue('WEBP_SLEEP_MS', max(0, min(2000, (int) Tools::getValue('WEBP_SLEEP_MS'))));
            Configuration::updateValue('WEBP_MIN_FREE', max(0, (int) Tools::getValue('WEBP_MIN_FREE')));
            $this->confirmations[] = $this->l('Ajustes guardados.');
        }

        if (Tools::isSubmit('exportcsv')) {
            $this->exportCsv();
        }

        if (Tools::isSubmit('exportpesos')) {
            $this->exportWeightCsv();
        }

        return parent::postProcess();
    }

    /**
     * Informe completo en CSV.
     */
    private function exportCsv()
    {
        $engine = $this->buildEngine(false, false);
        $report = WebpReport::build($engine, false, (bool) Tools::getValue('inactivos'));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="miniaturas-webp-' . date('Y-m-d') . '.csv"');
        $handle = fopen('php://output', 'w');
        // BOM para que Excel respete los acentos.
        fwrite($handle, "\xEF\xBB\xBF");
        WebpReport::writeCsv($report, $handle);
        fclose($handle);
        exit;
    }

    /**
     * Ranking de pesos en CSV, catalogo y subidas en el mismo fichero.
     */
    private function exportWeightCsv()
    {
        $engine = $this->buildEngine(false, false);
        $catalogo = WebpWeight::catalog($engine, 100000);
        $subidas = WebpWeight::uploads(100000);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="imagenes-mas-pesadas-' . date('Y-m-d') . '.csv"');
        $handle = fopen('php://output', 'w');
        fwrite($handle, "\xEF\xBB\xBF");

        // Peso en texto para leerlo de un vistazo, y en KB aparte para poder
        // ordenar la columna en la hoja de calculo.
        fputcsv($handle, [
            'origen', 'estado', 'referencia o ruta', 'nombre', 'id producto', 'formato', 'peso', 'peso (KB)',
            'peso WebP', 'peso WebP (KB)', 'ahorro', 'medidas', 'aviso',
        ], ';');

        foreach ($catalogo['items'] as $i) {
            fputcsv($handle, [
                'catálogo',
                $i['convertida'] ? 'con WebP' : 'SIN WebP',
                $i['referencia'],
                $i['nombre'],
                $i['id_product'],
                $i['formato'],
                WebpWeight::human($i['peso']),
                (int) round($i['peso'] / 1024),
                $i['peso_webp'] ? WebpWeight::human($i['peso_webp']) : '',
                $i['peso_webp'] ? (int) round($i['peso_webp'] / 1024) : '',
                $i['ahorro'] === null ? '' : ('-' . $i['ahorro'] . ' %'),
                $i['ancho'] ? $i['ancho'] . 'x' . $i['alto'] : '',
                $i['png'] ? 'PNG dentro de un .jpg' : ($i['peso_webp'] ? '' : 'sin WebP generado'),
            ], ';');
        }

        foreach ($subidas['items'] as $i) {
            fputcsv($handle, [
                'subida del editor',
                $i['sin_webp'] ? 'SIN WebP' : 'con WebP',
                $i['ruta'],
                '',
                '',
                $i['formato'],
                WebpWeight::human($i['peso']),
                (int) round($i['peso'] / 1024),
                '',
                '',
                '',
                $i['ancho'] ? $i['ancho'] . 'x' . $i['alto'] : '',
                $i['sin_webp'] ? 'sin WebP al lado' : '',
            ], ';');
        }

        fclose($handle);
        exit;
    }

    /**
     * Responde JSON y corta la peticion.
     *
     * ajaxRender() por si solo no detiene el controlador: despues seguiria
     * initContent() y display(), que pegarian la pantalla entera detras del
     * JSON y dejarian la respuesta ilegible para el navegador.
     *
     * @param array $data
     */
    private function respond(array $data)
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxRender(json_encode($data));
        exit;
    }

    /**
     * @param bool $apply
     * @param bool $verify
     *
     * @return WebpEngine
     */
    private function buildEngine($apply, $verify)
    {
        return new WebpEngine([
            'apply' => $apply,
            'verify' => $verify,
            'sleep_ms' => (int) Configuration::get('WEBP_SLEEP_MS'),
            'min_free_mb' => (int) Configuration::get('WEBP_MIN_FREE'),
        ]);
    }

    /**
     * Informe de cobertura. Puede tardar unos segundos: son decenas de miles de
     * comprobaciones en disco.
     */
    public function ajaxProcessReport()
    {
        $verify = (bool) Tools::getValue('verify');
        $inactive = (bool) Tools::getValue('inactivos');

        $engine = $this->buildEngine(false, $verify);
        $report = WebpReport::build($engine, $verify, $inactive);

        $this->respond([
            'ok' => true,
            'summary' => $report['summary'],
            'worst' => array_values(WebpReport::worst($report, 20)),
        ]);
    }

    /**
     * Una tanda de generacion.
     */
    public function ajaxProcessBatch()
    {
        $apply = (bool) Tools::getValue('apply');
        $verify = (bool) Tools::getValue('verify');
        $entity = Tools::getValue('entity') ?: null;
        $offset = (int) Tools::getValue('offset');

        // Un fallo aqui tiene que llegar al navegador como JSON con su motivo.
        // Si se escapa, la respuesta seria una pagina de error y la pantalla se
        // quedaria parada sin decir por que.
        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }

            return false;
        });

        try {
            $this->runBatchRequest($apply, $verify, $entity, $offset);
        } catch (Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param bool $apply
     * @param bool $verify
     * @param string|null $entity
     * @param int $offset
     */
    private function runBatchRequest($apply, $verify, $entity, $offset)
    {
        $engine = $this->buildEngine($apply, $verify);

        if (!$engine->getFormats()) {
            $this->respond([
                'ok' => false,
                'error' => $this->l('No hay ningún formato adicional configurado. Revisa el estado de arriba.'),
            ]);
        }

        if (!$engine->lock()) {
            $this->respond([
                'ok' => false,
                'error' => $this->l('Ya hay otra pasada en marcha (puede ser la de consola). Espera a que termine.'),
            ]);
        }

        // Al empezar de cero, se barren los temporales de una pasada anterior
        // que muriera de mala manera.
        if ($apply && $entity === null && $offset === 0) {
            foreach (WebpEngine::ENTITIES as $e) {
                $engine->cleanTemporaryFiles($e);
            }
        }

        $result = $engine->runBatch(
            ['entity' => $entity, 'offset' => $offset],
            max(5, (int) Configuration::get('WEBP_BATCH_SECONDS'))
        );

        $engine->unlock();

        $errors = [];
        foreach (array_slice($result['errors'], 0, 5) as [$file, $reason]) {
            $errors[] = $reason . ' → ' . str_replace(_PS_ROOT_DIR_, '', $file);
        }

        $this->respond([
            'ok' => true,
            'done' => $result['done'],
            'stop' => $result['stop'],
            'state' => $result['state'],
            'totals' => $result['totals'],
            'seconds' => $result['seconds'],
            'errors' => $errors,
        ]);
    }

    /**
     * Ranking de imagenes por peso.
     */
    public function ajaxProcessWeight()
    {
        $limit = max(10, min(200, (int) Tools::getValue('limit', 50)));
        $engine = $this->buildEngine(false, false);

        $catalogo = WebpWeight::catalog($engine, $limit);
        $subidas = WebpWeight::uploads($limit);

        foreach (['convertidas', 'pendientes', 'items'] as $lista) {
            foreach ($catalogo[$lista] as &$item) {
                $item['peso_txt'] = WebpWeight::human($item['peso']);
                $item['peso_webp_txt'] = $item['peso_webp'] ? WebpWeight::human($item['peso_webp']) : '—';
                $item['enlace'] = $this->context->link->getAdminLink('AdminProducts', true, [
                    'id_product' => $item['id_product'],
                    'action' => 'edit',
                ]);
            }
            unset($item);
        }

        foreach ($subidas['items'] as &$subida) {
            $subida['peso_txt'] = WebpWeight::human($subida['peso']);
        }
        unset($subida);

        $this->respond([
            'ok' => true,
            'catalogo' => $catalogo,
            'subidas' => $subidas,
            'textos' => [
                'catalogo_peso' => WebpWeight::human($catalogo['summary']['peso_base']),
                'catalogo_webp' => WebpWeight::human($catalogo['summary']['peso_webp']),
                'catalogo_ahorro' => WebpWeight::human($catalogo['summary']['ahorro_webp']),
                'catalogo_png' => WebpWeight::human($catalogo['summary']['peso_png_disfrazado']),
                'originales' => WebpWeight::human($catalogo['summary']['peso_originales']),
                'subidas_peso' => WebpWeight::human($subidas['summary']['peso']),
            ],
        ]);
    }

    /**
     * Total de imagenes de origen, para la barra de avance.
     */
    public function ajaxProcessCount()
    {
        $engine = $this->buildEngine(false, false);

        $this->respond(['ok' => true, 'total' => $engine->countSources()]);
    }

    public function renderView()
    {
        $diagnostics = WebpEngine::diagnostics();

        $this->context->smarty->assign([
            'webp_diagnostics' => $diagnostics,
            'webp_formats' => implode(', ', $diagnostics['extra']),
            'webp_config' => [
                'on_upload' => (int) Configuration::get('WEBP_ON_UPLOAD'),
                'batch_seconds' => (int) Configuration::get('WEBP_BATCH_SECONDS'),
                'sleep_ms' => (int) Configuration::get('WEBP_SLEEP_MS'),
                'min_free' => (int) Configuration::get('WEBP_MIN_FREE'),
            ],
            'webp_token' => $this->token,
            'webp_link' => self::$currentIndex,
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'webp/views/templates/admin/panel.tpl');
    }
}
