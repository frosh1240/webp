<?php
/**
 * Generacion de miniaturas por consola.
 *
 * Es la via para el catalogo entero: no hay tiempo maximo de ejecucion web ni
 * proxy que corte la peticion. Usa el mismo motor que la pantalla del back
 * office, asi que las garantias son las mismas: escritura atomica, cerrojo,
 * control de espacio en disco y reanudacion.
 *
 *   php modules/webp/cli/generate.php --informe
 *   php modules/webp/cli/generate.php --informe --csv=/tmp/webp.csv
 *   php modules/webp/cli/generate.php                    (simulacion)
 *   php modules/webp/cli/generate.php --apply --seconds=600
 *   php modules/webp/cli/generate.php --apply --revisar-existentes
 *
 * En produccion, sin competir con la tienda:
 *   nice -n 19 php modules/webp/cli/generate.php --apply --sleep=40
 *
 * Opciones:
 *   --informe            informe por producto en lugar de generar
 *   --csv=RUTA           vuelca el informe completo a CSV (implica --informe)
 *   --apply              genera de verdad (sin esto no se escribe nada)
 *   --type=TIPO          products | categories | manufacturers | suppliers | stores | all
 *   --limit=N            maximo de imagenes de origen en esta pasada
 *   --seconds=N          presupuesto de tiempo (0 = sin limite)
 *   --sleep=MS           pausa entre imagenes de origen
 *   --formats=LISTA      formatos a generar; util para preparar las miniaturas
 *                        ANTES de activar la bandera multiple_image_format
 *   --min-free=MB        espacio libre minimo antes de parar (por defecto 500)
 *   --revisar-existentes comprueba las miniaturas ya creadas; con --apply rehace
 *                        las corruptas
 *   --incluir-inactivos  en el informe, incluye productos desactivados
 */
if (PHP_SAPI !== 'cli') {
    exit('Solo por linea de comandos.' . PHP_EOL);
}

require dirname(__DIR__, 3) . '/config/config.inc.php';
require_once dirname(__DIR__) . '/webp.php';

$opt = getopt('', [
    'type:', 'limit:', 'seconds:', 'formats:', 'sleep:', 'min-free:', 'csv:',
    'apply', 'dry-run', 'informe', 'revisar-existentes', 'incluir-inactivos',
]);

$apply = array_key_exists('apply', $opt);
$verify = array_key_exists('revisar-existentes', $opt);
$informe = array_key_exists('informe', $opt) || isset($opt['csv']);
$type = $opt['type'] ?? 'all';
$limit = isset($opt['limit']) ? max(1, (int) $opt['limit']) : PHP_INT_MAX;
$seconds = isset($opt['seconds']) ? max(0, (int) $opt['seconds']) : 0;
$started = microtime(true);

$formats = isset($opt['formats'])
    ? array_values(array_filter(array_map('trim', explode(',', $opt['formats']))))
    : null;

$engine = new WebpEngine([
    'apply' => $apply,
    'verify' => $verify,
    'formats' => $formats,
    'sleep_ms' => isset($opt['sleep']) ? (int) $opt['sleep'] : (int) Configuration::get('WEBP_SLEEP_MS'),
    'min_free_mb' => isset($opt['min-free']) ? (int) $opt['min-free'] : (int) Configuration::get('WEBP_MIN_FREE'),
]);

/* -------------------------------------------------------------------- */
/* Diagnostico                                                           */
/* -------------------------------------------------------------------- */

if (!$engine->getFormats()) {
    $d = WebpEngine::diagnostics();

    echo 'No hay ningun formato que generar aparte de JPG.' . PHP_EOL . PHP_EOL;
    printf("  %-38s %s\n", 'Bandera multiple_image_format', $d['flag'] ? 'activada' : 'DESACTIVADA');
    printf("  %-38s %s\n", 'PS_IMAGE_FORMAT', $d['ps_image_format'] !== '' ? $d['ps_image_format'] : '(vacio)');
    printf("  %-38s %s\n", 'Formatos efectivos', implode(', ', $d['effective']));
    echo PHP_EOL;

    if (!$d['flag']) {
        echo '  Falta activar la bandera. No esta en la pantalla de Imagenes:' . PHP_EOL;
        echo '  Parametros avanzados > Funcionalidades experimentales > multiple_image_format' . PHP_EOL . PHP_EOL;
        echo '  Para preparar las miniaturas ANTES de activarla:' . PHP_EOL;
        echo '  php modules/webp/cli/generate.php --formats=webp --apply' . PHP_EOL;
    } else {
        echo '  La bandera esta activada, asi que falta marcar WebP en:' . PHP_EOL;
        echo '  Parametros de la tienda > Imagenes > Formato de imagen' . PHP_EOL;
    }

    exit(1);
}

/* -------------------------------------------------------------------- */
/* Informe                                                               */
/* -------------------------------------------------------------------- */

if ($informe) {
    $report = WebpReport::build($engine, $verify, array_key_exists('incluir-inactivos', $opt));
    $s = $report['summary'];

    echo 'INFORME DE MINIATURAS ' . strtoupper(implode('/', $s['formatos'])) . PHP_EOL . PHP_EOL;
    printf("  %-34s %d\n", 'Tipos de imagen de producto', $s['tipos_imagen']);
    printf("  %-34s %s\n", 'Alta densidad (2x)', $s['alta_densidad'] ? 'si' : 'no');
    printf("  %-34s %d\n", 'Miniaturas por imagen', $s['por_imagen']);
    printf("  %-34s %d%s\n", 'Productos con imagenes', $s['productos'], $s['incluye_inactivos'] ? '' : ' (solo activos)');
    echo PHP_EOL;
    printf("  %-34s %d\n", 'Completos', $s['completos']);
    printf("  %-34s %d\n", 'A medias', $s['a_medias']);
    printf("  %-34s %d\n", 'Sin ninguna miniatura', $s['sin_ninguna']);
    printf("  %-34s %d\n", 'Miniaturas por generar', $s['faltan']);
    echo PHP_EOL;

    $worst = WebpReport::worst($report, 20);
    if ($worst) {
        echo '  Los 20 que mas faltan:' . PHP_EOL . PHP_EOL;
        printf("    %-8s %-18s %-40s %6s %6s %6s\n", 'ID', 'REFERENCIA', 'NOMBRE', 'IMGS', 'HAY', 'FALTAN');
        foreach ($worst as $p) {
            printf(
                "    %-8d %-18s %-40s %6d %6d %6d\n",
                $p['id'],
                mb_substr($p['referencia'], 0, 18),
                mb_substr($p['nombre'], 0, 40),
                $p['imagenes'],
                $p['existentes'],
                $p['faltan']
            );
        }
        echo PHP_EOL;
    }

    if (isset($opt['csv'])) {
        $fh = @fopen($opt['csv'], 'w');
        if (!$fh) {
            exit('No se pudo escribir el CSV en ' . $opt['csv'] . PHP_EOL);
        }
        WebpReport::writeCsv($report, $fh);
        fclose($fh);
        echo '  CSV escrito en ' . $opt['csv'] . PHP_EOL . PHP_EOL;
    }

    if ($s['sin_original']) {
        echo '  Aviso: ' . $s['sin_original'] . ' imagenes registradas en la base de datos no' . PHP_EOL;
        echo '  tienen su JPG en disco. Esas no se pueden generar hasta resubirlas.' . PHP_EOL . PHP_EOL;
    }

    if ($verify) {
        echo '  Miniaturas existentes pero ilegibles: ' . $s['corruptas'] . PHP_EOL;
        if ($s['corruptas']) {
            echo '  Se rehacen con:  php modules/webp/cli/generate.php --revisar-existentes --apply' . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if ($s['faltan']) {
        echo '  Para generarlas:  php modules/webp/cli/generate.php --apply --seconds=0' . PHP_EOL;
    }

    exit(0);
}

/* -------------------------------------------------------------------- */
/* Generacion                                                            */
/* -------------------------------------------------------------------- */

if (!$engine->lock()) {
    exit('Ya hay otra pasada en marcha (consola o back office). Espera a que termine.' . PHP_EOL);
}

$entidades = WebpEngine::ENTITIES;
if ($type !== 'all') {
    if (!in_array($type, $entidades, true)) {
        exit('Tipo desconocido: ' . $type . '. Validos: ' . implode(', ', $entidades) . ', all' . PHP_EOL);
    }
}

// Ctrl+C y SIGTERM: se termina la miniatura en curso y se sale, en vez de
// cortar en seco a mitad de una escritura.
$parar = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = function () use (&$parar) {
        $parar = true;
        echo PHP_EOL . '  Senal recibida: se termina la tanda en curso y se sale...' . PHP_EOL;
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
}

echo 'Formatos a generar : ' . implode(', ', $engine->getFormats()) . PHP_EOL;
echo 'Modo               : ' . ($apply ? 'GENERANDO (escribe en disco)' : 'simulacion, no escribe nada') . PHP_EOL;
echo 'Existentes         : ' . ($verify ? 'se revisan y se rehacen las corruptas' : 'se saltan sin abrirlas') . PHP_EOL;
if ($seconds) {
    echo 'Tiempo maximo      : ' . $seconds . ' s' . PHP_EOL;
}
echo PHP_EOL;

if ($apply) {
    foreach (($type === 'all' ? $entidades : [$type]) as $e) {
        $n = $engine->cleanTemporaryFiles($e);
        if ($n) {
            echo '  limpiados ' . $n . ' temporales de una pasada anterior en ' . $e . PHP_EOL;
        }
    }
}

$totals = ['creadas' => 0, 'pendientes' => 0, 'existentes' => 0, 'fallidas' => 0, 'origen' => 0];
$state = ['entity' => $type === 'all' ? null : $type, 'offset' => 0];
$stop = null;

do {
    $restante = $seconds ? max(1, $seconds - (int) (microtime(true) - $started)) : 30;
    $result = $engine->runBatch($state, $restante, max(1, $limit - $totals['origen']));

    foreach ($result['totals'] as $k => $v) {
        $totals[$k] += $v;
    }
    $state = $result['state'];
    $stop = $result['stop'];

    printf("  %-12s %6d imagenes  creadas %-7d fallidas %-5d  %.0f s\n",
        $state['entity'] ?: 'fin',
        $totals['origen'],
        $totals['creadas'],
        $totals['fallidas'],
        microtime(true) - $started
    );

    if ($result['done'] || $parar || $stop === 'disco') {
        break;
    }
    if ($totals['origen'] >= $limit) {
        $stop = 'limite';
        break;
    }
    if ($seconds && (microtime(true) - $started) >= $seconds) {
        $stop = 'tiempo';
        break;
    }
} while (true);

$engine->unlock();

$elapsed = microtime(true) - $started;
echo PHP_EOL;
printf("  originales procesados : %d\n", $totals['origen']);
printf("  %-21s : %d\n", $apply ? 'miniaturas creadas' : 'miniaturas por crear', $apply ? $totals['creadas'] : $totals['pendientes']);
printf("  ya existian           : %d\n", $totals['existentes']);
printf("  fallidas              : %d\n", $totals['fallidas']);
printf("  tiempo                : %.1f s", $elapsed);
if ($apply && $totals['creadas'] > 0) {
    printf("   (%.0f ms por miniatura)", $elapsed * 1000 / $totals['creadas']);
}
echo PHP_EOL;

if ($engine->errors) {
    echo PHP_EOL . '  Primeros errores:' . PHP_EOL;
    foreach (array_slice($engine->errors, 0, 10) as [$file, $motivo]) {
        echo '    ' . $motivo . ' -> ' . $file . PHP_EOL;
    }
    if (count($engine->errors) > 10) {
        echo '    ... y ' . (count($engine->errors) - 10) . ' mas' . PHP_EOL;
    }
}

if ($parar) {
    echo PHP_EOL . '  Interrumpido a peticion tuya. Vuelve a lanzarlo: lo hecho no se repite.' . PHP_EOL;
} elseif ($stop === 'disco') {
    echo PHP_EOL . '  ATENCION: queda poco espacio libre en disco. No se genero mas.' . PHP_EOL;
} elseif ($stop) {
    echo PHP_EOL . '  Quedo a medias por el limite indicado. Vuelve a lanzarlo: lo hecho no se repite.' . PHP_EOL;
} elseif ($apply) {
    echo PHP_EOL . '  No queda nada pendiente.' . PHP_EOL;
} elseif ($totals['pendientes']) {
    echo PHP_EOL . '  Simulacion. Para generarlas de verdad, repite el comando con --apply' . PHP_EOL;
}

exit($totals['fallidas'] > 0 ? 2 : 0);
