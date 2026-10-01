{*
 * Pantalla de miniaturas WebP: estado, informe y generacion por lotes.
 *}
<div id="webp" data-token="{$webp_token|escape:'html':'UTF-8'}">

	<div class="panel">
		<div class="panel-heading"><i class="icon-cogs"></i> Estado de la configuración</div>

		<table class="table">
			<tbody>
				<tr>
					<td style="width:40%">Formatos adicionales activos</td>
					<td>
						{if $webp_diagnostics.extra}
							<span class="badge badge-success">{$webp_formats|escape:'html':'UTF-8'}</span>
						{else}
							<span class="badge badge-danger">ninguno</span>
						{/if}
					</td>
				</tr>
				<tr>
					<td>Bandera <code>multiple_image_format</code></td>
					<td>
						{if $webp_diagnostics.flag}
							<span class="text-success">activada</span>
						{else}
							<span class="text-danger">desactivada</span> &mdash;
							Parámetros avanzados &rsaquo; Funcionalidades experimentales
						{/if}
					</td>
				</tr>
				<tr>
					<td>Formato de imagen (<code>PS_IMAGE_FORMAT</code>)</td>
					<td>
						{if $webp_diagnostics.ps_image_format}{$webp_diagnostics.ps_image_format|escape:'html':'UTF-8'}{else}<em>vacío</em>{/if}
						&mdash; Parámetros de la tienda &rsaquo; Imágenes
					</td>
				</tr>
				<tr>
					<td>Soporte del servidor</td>
					<td>
						WebP: {if $webp_diagnostics.gd_webp}<span class="text-success">sí</span>{else}<span class="text-danger">no</span>{/if}
						&nbsp;·&nbsp;
						AVIF: {if $webp_diagnostics.gd_avif}<span class="text-success">sí</span>{else}<span class="text-muted">no</span>{/if}
						&nbsp;·&nbsp;
						Alta densidad (2x): {if $webp_diagnostics.high_dpi}sí{else}no{/if}
					</td>
				</tr>
			</tbody>
		</table>

		{if !$webp_diagnostics.flag}
			<div class="alert alert-warning">
				Mientras la bandera esté apagada, PrestaShop ignora el formato marcado en
				Imágenes y genera solo JPG, sin avisar de nada.
				<strong>Se pueden preparar las miniaturas antes de activarla</strong> desde la
				consola, con <code>--formats=webp</code>, para que al encenderla ya estén todas.
			</div>
		{/if}
	</div>

	<div class="panel">
		<div class="panel-heading"><i class="icon-bar-chart"></i> Qué productos tienen miniaturas</div>

		<p class="alert alert-info">
			Recorre el catálogo y cuenta, producto a producto, cuántas miniaturas
			deberían existir y cuántas hay. Puede tardar unos segundos.
		</p>

		<div class="form-group">
			<label class="checkbox-inline">
				<input type="checkbox" id="webp-report-inactive"> Incluir productos desactivados
			</label>
			<label class="checkbox-inline">
				<input type="checkbox" id="webp-report-verify"> Comprobar que las existentes se pueden abrir (lento)
			</label>
		</div>

		<button type="button" class="btn btn-primary" id="webp-report-run">
			<i class="icon-refresh"></i> Analizar catálogo
		</button>
		<a class="btn btn-default" href="{$webp_link|escape:'html':'UTF-8'}&amp;exportcsv=1&amp;token={$webp_token|escape:'html':'UTF-8'}">
			<i class="icon-download"></i> Descargar informe en CSV
		</a>

		<div id="webp-report-result" class="webp-mt" hidden>
			<div class="row webp-summary">
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-stat-completos">0</span><span class="webp-stat-label">completos</span></div></div>
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-stat-medias">0</span><span class="webp-stat-label">a medias</span></div></div>
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-stat-ninguna">0</span><span class="webp-stat-label">sin ninguna</span></div></div>
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-stat-faltan">0</span><span class="webp-stat-label">miniaturas por generar</span></div></div>
			</div>

			<div id="webp-report-notes"></div>

			<table class="table" id="webp-report-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Referencia</th>
						<th>Producto</th>
						<th class="text-center">Imágenes</th>
						<th class="text-center">Hay</th>
						<th class="text-center">Faltan</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
			<p class="text-muted">Los 20 productos a los que más les falta. La lista completa está en el CSV.</p>
		</div>
	</div>

	<div class="panel">
		<div class="panel-heading"><i class="icon-sort-by-attributes-alt"></i> Imágenes más pesadas</div>

		<p class="alert alert-info">
			Lo que pesa para el visitante son las miniaturas que se sirven, no los
			archivos originales. Aquí salen las más grandes de cada mundo: las del
			catálogo, que se arreglan resubiendo una foto más ligera, y las que sube
			el editor (banners y logos), que PrestaShop no redimensiona ni convierte
			y hay que optimizar a mano.
		</p>

		<button type="button" class="btn btn-primary" id="webp-weight-run">
			<i class="icon-sort-by-attributes-alt"></i> Buscar las más pesadas
		</button>
		<a class="btn btn-default" href="{$webp_link|escape:'html':'UTF-8'}&amp;exportpesos=1&amp;token={$webp_token|escape:'html':'UTF-8'}">
			<i class="icon-download"></i> Descargar listado completo
		</a>

		<div id="webp-weight-result" class="webp-mt" hidden>
			<div class="row webp-summary">
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-w-base">—</span><span class="webp-stat-label">en disco, versión JPG o PNG</span></div></div>
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-w-ahorro">—</span><span class="webp-stat-label">se ahorran al servir WebP</span></div></div>
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-w-png">—</span><span class="webp-stat-label">en PNG disfrazado de .jpg</span></div></div>
				<div class="col-lg-3"><div class="webp-stat"><span class="webp-stat-value" id="webp-w-subidas">—</span><span class="webp-stat-label">subidas del editor</span></div></div>
			</div>

			<div id="webp-weight-notes"></div>

			<p class="subtitulo">
				<strong>Sin convertir</strong> — estas fotos solo existen en JPG o PNG
				<span id="webp-w-pend-n" class="badge"></span>
			</p>
			<p class="text-muted">
				El visitante se descarga el archivo pesado, porque no hay versión WebP que
				servir. Se arreglan con el botón de abajo, sin tocar nada más.
			</p>
			<button type="button" class="btn btn-primary btn-sm webp-mb" id="webp-weight-convert">
				<i class="icon-play"></i> Convertir las que faltan
			</button>
			<table class="table" id="webp-weight-pending">
				<thead>
					<tr><th>Producto</th><th>Referencia</th><th class="text-center">Medidas</th><th class="text-center">Formato</th><th class="text-center">Peso</th><th></th></tr>
				</thead>
				<tbody></tbody>
			</table>
			<p id="webp-w-pend-vacio" class="alert alert-success" hidden>
				Todas las fotos del catálogo tienen su versión WebP.
			</p>

			<p class="subtitulo">
				<strong>Ya convertidas, pero pesadas</strong> — tamaño <span id="webp-w-tipo"></span>
				<span id="webp-w-conv-n" class="badge"></span>
			</p>
			<p class="text-muted">
				Ordenadas por lo que descarga el visitante, que es el WebP. Las marcadas
				como «PNG en .jpg» se guardaron en un formato que pesa el triple: su WebP
				funciona, pero sale peor de lo que podría, así que son las candidatas si
				alguna vez se vuelven a subir las fotos.
			</p>
			<table class="table" id="webp-weight-catalog">
				<thead>
					<tr><th>Producto</th><th>Referencia</th><th class="text-center">Medidas</th><th class="text-center">Lo que se sirve</th><th></th></tr>
				</thead>
				<tbody></tbody>
			</table>

			<p class="subtitulo"><strong>Subidas del editor</strong> — banners, logos y contenido de páginas</p>
			<p class="text-muted">
				PrestaShop no las redimensiona ni las convierte: no hay botón que valga.
				Hay que optimizarlas fuera y volver a subirlas con el mismo nombre.
			</p>
			<table class="table" id="webp-weight-uploads">
				<thead>
					<tr><th>Archivo</th><th class="text-center">Formato</th><th class="text-center">Medidas</th><th class="text-center">Peso</th><th></th></tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</div>

	<div class="panel">
		<div class="panel-heading"><i class="icon-picture"></i> Generar las que faltan</div>

		<p class="alert alert-info">
			Va por tandas de {$webp_config.batch_seconds|intval} segundos y se puede
			parar en cualquier momento: lo ya hecho no se repite. Cada miniatura se
			escribe en un fichero temporal y solo ocupa su sitio definitivo cuando se
			comprueba que es válida, así que una interrupción no deja imágenes a medias.
		</p>

		<div class="form-group">
			<label class="checkbox-inline">
				<input type="checkbox" id="webp-run-verify">
				Revisar también las miniaturas ya existentes y rehacer las que estén corruptas
			</label>
		</div>

		<button type="button" class="btn btn-default" id="webp-run-simulate">
			<i class="icon-search"></i> Simular (no escribe nada)
		</button>
		<button type="button" class="btn btn-primary" id="webp-run-apply">
			<i class="icon-play"></i> Generar
		</button>
		<button type="button" class="btn btn-danger" id="webp-run-stop" hidden>
			<i class="icon-stop"></i> Detener
		</button>

		<div id="webp-progress-wrap" class="webp-mt" hidden>
			<div class="progress">
				<div class="progress-bar progress-bar-success" id="webp-progress" style="width:0%">0%</div>
			</div>
			<p id="webp-progress-text" class="text-muted"></p>
			<pre id="webp-log" class="webp-log"></pre>
		</div>
	</div>

	<form method="post" action="{$webp_link|escape:'html':'UTF-8'}&amp;token={$webp_token|escape:'html':'UTF-8'}" class="panel">
		<div class="panel-heading"><i class="icon-wrench"></i> Ajustes</div>

		<div class="form-group">
			<label class="control-label col-lg-4">Generar las miniaturas de cada imagen nueva</label>
			<div class="col-lg-8">
				<span class="switch prestashop-switch fixed-width-lg">
					<input type="radio" name="WEBP_ON_UPLOAD" id="webp_upload_on" value="1" {if $webp_config.on_upload}checked{/if}>
					<label for="webp_upload_on">Sí</label>
					<input type="radio" name="WEBP_ON_UPLOAD" id="webp_upload_off" value="0" {if !$webp_config.on_upload}checked{/if}>
					<label for="webp_upload_off">No</label>
					<a class="slide-button btn"></a>
				</span>
				<p class="help-block">
					Al subir una imagen o importar productos, sus miniaturas se crean en ese
					momento. Sin esto, cada importación deja productos atrás hasta la
					siguiente pasada manual.
				</p>
			</div>
		</div>

		<div class="form-group">
			<label class="control-label col-lg-4">Duración de cada tanda (segundos)</label>
			<div class="col-lg-2">
				<input type="number" name="WEBP_BATCH_SECONDS" min="5" max="120" value="{$webp_config.batch_seconds|intval}" class="form-control">
			</div>
			<div class="col-lg-6"><p class="help-block">Debe quedar por debajo del tiempo máximo de PHP y del corte del proxy (Cloudflare corta sobre los 100 s).</p></div>
		</div>

		<div class="form-group">
			<label class="control-label col-lg-4">Pausa entre imágenes (milisegundos)</label>
			<div class="col-lg-2">
				<input type="number" name="WEBP_SLEEP_MS" min="0" max="2000" value="{$webp_config.sleep_ms|intval}" class="form-control">
			</div>
			<div class="col-lg-6"><p class="help-block">Sube este valor si la tienda va lenta mientras se generan.</p></div>
		</div>

		<div class="form-group">
			<label class="control-label col-lg-4">Espacio libre mínimo (MB)</label>
			<div class="col-lg-2">
				<input type="number" name="WEBP_MIN_FREE" min="0" value="{$webp_config.min_free|intval}" class="form-control">
			</div>
			<div class="col-lg-6"><p class="help-block">Por debajo de esta cifra se detiene sola, para no llenar el disco del servidor.</p></div>
		</div>

		<div class="panel-footer">
			<button type="submit" name="submitwebpSettings" class="btn btn-default pull-right">
				<i class="process-icon-save"></i> Guardar
			</button>
		</div>
	</form>

	<div class="panel">
		<div class="panel-heading"><i class="icon-terminal"></i> Por consola</div>
		<p>
			Para el catálogo entero de una vez conviene lanzarlo desde el servidor, sin
			límites de tiempo web, y programarlo de madrugada:
		</p>
		<pre>php modules/webp/cli/generate.php --informe
php modules/webp/cli/generate.php --apply --seconds=0
nice -n 19 php modules/webp/cli/generate.php --apply --sleep=40</pre>
	</div>
</div>

<style>
	#webp .webp-mt { margin-top: 15px; }
	#webp .webp-stat { background: #fff; border: 1px solid #d6d6d6; border-radius: 3px; padding: 12px 14px; text-align: center; }
	#webp .webp-stat-value { display: block; font-size: 26px; font-weight: 600; font-variant-numeric: tabular-nums; }
	#webp .webp-stat-label { display: block; font-size: 12px; text-transform: uppercase; color: #6c868e; }
	#webp .webp-summary { margin-bottom: 15px; }
	#webp .webp-log { max-height: 220px; overflow: auto; font-size: 12px; }
	#webp .subtitulo { margin: 22px 0 6px; font-size: 14px; }
	#webp .aviso-png { color: #b35a00; }
	#webp .webp-mb { margin-bottom: 12px; }
	#webp .subtitulo .badge { margin-left: 6px; }
	#webp table td.num { text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
