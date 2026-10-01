/**
 * Pantalla de miniaturas WebP.
 *
 * La generacion se pide por tandas cortas: cada respuesta dice donde se quedo y
 * el navegador encadena la siguiente. Asi el trabajo largo no depende de que
 * una sola peticion aguante media hora, que es justo lo que no puede hacer.
 */
(function () {
  "use strict";

  // El back office inyecta este fichero en el <head>, antes de que exista el
  // contenido de la pagina: hay que esperar al DOM o no habria nada a lo que
  // enganchar los botones.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  function init() {
    var root = document.getElementById("webp");
    if (!root) {
      return;
    }

    var token = root.getAttribute("data-token");
    var url = window.location.href.split("#")[0];
    var running = false;
    var stopRequested = false;
    var total = 0;
    var acumulado = {
      creadas: 0,
      pendientes: 0,
      existentes: 0,
      fallidas: 0,
      origen: 0,
    };

    function post(action, params) {
      var body = new URLSearchParams(params || {});
      body.set("ajax", "1");
      body.set("action", action);
      body.set("token", token);

      return fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: body.toString(),
        credentials: "same-origin",
      }).then(function (r) {
        return r.json();
      });
    }

    function el(id) {
      return document.getElementById(id);
    }

    function log(text) {
      var box = el("webp-log");
      box.textContent += text + "\n";
      box.scrollTop = box.scrollHeight;
    }

    /* ---------------------------------------------------------------- */
    /* Informe                                                           */
    /* ---------------------------------------------------------------- */

    el("webp-report-run").addEventListener("click", function () {
      var btn = this;
      btn.disabled = true;
      btn.innerHTML = '<i class="icon-refresh icon-spin"></i> Analizando...';

      post("Report", {
        verify: el("webp-report-verify").checked ? 1 : 0,
        inactivos: el("webp-report-inactive").checked ? 1 : 0,
      })
        .then(function (data) {
          btn.disabled = false;
          btn.innerHTML = '<i class="icon-refresh"></i> Analizar catálogo';

          if (!data.ok) {
            log(data.error || "Error al analizar.");
            return;
          }

          var s = data.summary;
          el("webp-stat-completos").textContent = s.completos;
          el("webp-stat-medias").textContent = s.a_medias;
          el("webp-stat-ninguna").textContent = s.sin_ninguna;
          el("webp-stat-faltan").textContent = s.faltan;

          var notas = [];
          notas.push(
            '<p class="text-muted">' +
              s.productos +
              " productos con imágenes" +
              (s.incluye_inactivos ? "" : " (solo activos)") +
              ", " +
              s.tipos_imagen +
              " tipos de imagen y " +
              s.por_imagen +
              " miniaturas por cada imagen de producto.</p>",
          );
          if (s.sin_original > 0) {
            notas.push(
              '<div class="alert alert-warning">' +
                s.sin_original +
                " imágenes están registradas en la base de datos pero no tienen su archivo " +
                "en disco. Esas no se pueden generar hasta volver a subirlas.</div>",
            );
          }
          if (s.verificado && s.corruptas > 0) {
            notas.push(
              '<div class="alert alert-danger">' +
                s.corruptas +
                " miniaturas existen pero no se pueden abrir. Se rehacen marcando la casilla " +
                "de revisión en el bloque de abajo y pulsando Generar.</div>",
            );
          }
          el("webp-report-notes").innerHTML = notas.join("");

          var tbody = el("webp-report-table").querySelector("tbody");
          tbody.innerHTML = "";
          data.worst.forEach(function (p) {
            var tr = document.createElement("tr");
            [
              p.id,
              p.referencia,
              p.nombre,
              p.imagenes,
              p.existentes,
              p.faltan,
            ].forEach(function (v, i) {
              var td = document.createElement("td");
              td.textContent = v;
              if (i >= 3) {
                td.className = "text-center";
              }
              tr.appendChild(td);
            });
            tbody.appendChild(tr);
          });

          el("webp-report-result").hidden = false;
        })
        .catch(function () {
          btn.disabled = false;
          btn.innerHTML = '<i class="icon-refresh"></i> Analizar catálogo';
          alert("No se pudo completar el análisis.");
        });
    });

    /* ---------------------------------------------------------------- */
    /* Ranking de pesos                                                  */
    /* ---------------------------------------------------------------- */

    function fila(celdas) {
      var tr = document.createElement("tr");
      celdas.forEach(function (c) {
        var td = document.createElement("td");
        if (c && c.html !== undefined) {
          td.innerHTML = c.html;
        } else {
          td.textContent = c && c.txt !== undefined ? c.txt : c;
        }
        if (c && c.num) {
          td.className = "num";
        }
        tr.appendChild(td);
      });
      return tr;
    }

    el("webp-weight-run").addEventListener("click", function () {
      var btn = this;
      btn.disabled = true;
      btn.innerHTML = '<i class="icon-refresh icon-spin"></i> Buscando...';

      post("Weight", { limit: 50 })
        .then(function (data) {
          btn.disabled = false;
          btn.innerHTML =
            '<i class="icon-sort-by-attributes-alt"></i> Buscar las más pesadas';

          if (!data.ok) {
            alert(data.error || "No se pudo completar la búsqueda.");
            return;
          }

          var cat = data.catalogo,
            sub = data.subidas,
            t = data.textos;

          el("webp-w-base").textContent = t.catalogo_peso;
          el("webp-w-ahorro").textContent = t.catalogo_ahorro;
          el("webp-w-png").textContent = t.catalogo_png;
          el("webp-w-subidas").textContent = t.subidas_peso;
          el("webp-w-tipo").textContent =
            cat.summary.tipo + " (" + cat.summary.ancho + " px)";

          var notas = [];
          if (cat.summary.png_disfrazado > 0) {
            notas.push(
              '<div class="alert alert-warning">' +
                cat.summary.png_disfrazado +
                " miniaturas son PNG guardados dentro de un archivo .jpg, que pesan del orden de tres veces más. " +
                "Se corrigen poniendo el formato base en JPEG (Parámetros de la tienda › Imágenes) y regenerándolas.</div>",
            );
          }
          if (cat.summary.sin_webp > 0) {
            notas.push(
              '<div class="alert alert-info">' +
                cat.summary.sin_webp +
                " de estas miniaturas todavía no tienen su versión WebP. Se generan desde el bloque de abajo.</div>",
            );
          }
          notas.push(
            '<p class="text-muted">Las cifras de arriba son el total del catálogo: ' +
              cat.summary.imagenes +
              " fotos en tamaño " +
              cat.summary.tipo +
              ". " +
              "Originales guardados en disco: " +
              t.originales +
              ", que no se sirven a los visitantes: solo ocupan espacio y sirven para regenerar.</p>",
          );
          el("webp-weight-notes").innerHTML = notas.join("");

          // Sin convertir: lo que hay que atender.
          var tp = el("webp-weight-pending").querySelector("tbody");
          tp.innerHTML = "";
          cat.pendientes.forEach(function (i) {
            tp.appendChild(
              fila([
                {
                  html:
                    '<a href="' +
                    i.enlace +
                    '" target="_blank">' +
                    (i.nombre || "#" + i.id_product) +
                    "</a>",
                },
                i.referencia,
                { txt: i.ancho ? i.ancho + "×" + i.alto : "—", num: true },
                { txt: i.formato, num: true },
                { txt: i.peso_txt, num: true },
                {
                  html: i.png
                    ? '<span class="aviso-png">PNG en .jpg</span>'
                    : "",
                },
              ]),
            );
          });
          el("webp-w-pend-n").textContent =
            cat.total_pendientes +
            (cat.total_pendientes > cat.pendientes.length
              ? " (se muestran " + cat.pendientes.length + ")"
              : "");
          el("webp-w-pend-vacio").hidden = cat.total_pendientes > 0;
          el("webp-weight-pending").hidden = cat.total_pendientes === 0;
          el("webp-weight-convert").hidden = cat.total_pendientes === 0;

          // Ya convertidas: informativo.
          var tb = el("webp-weight-catalog").querySelector("tbody");
          tb.innerHTML = "";
          cat.convertidas.forEach(function (i) {
            tb.appendChild(
              fila([
                {
                  html:
                    '<a href="' +
                    i.enlace +
                    '" target="_blank">' +
                    (i.nombre || "#" + i.id_product) +
                    "</a>",
                },
                i.referencia,
                { txt: i.ancho ? i.ancho + "×" + i.alto : "—", num: true },
                { txt: i.peso_webp_txt + " WebP", num: true },
                {
                  html: i.png
                    ? '<span class="aviso-png">PNG en .jpg</span>'
                    : "",
                },
              ]),
            );
          });
          el("webp-w-conv-n").textContent =
            cat.total_convertidas +
            (cat.total_convertidas > cat.convertidas.length
              ? " (se muestran " + cat.convertidas.length + ")"
              : "");

          var tu = el("webp-weight-uploads").querySelector("tbody");
          tu.innerHTML = "";
          sub.items.forEach(function (i) {
            tu.appendChild(
              fila([
                i.ruta,
                { txt: i.formato, num: true },
                { txt: i.ancho ? i.ancho + "×" + i.alto : "—", num: true },
                { txt: i.peso_txt, num: true },
                {
                  html: i.sin_webp
                    ? '<span class="aviso-png">sin versión WebP</span>'
                    : "",
                },
              ]),
            );
          });

          el("webp-weight-result").hidden = false;
        })
        .catch(function () {
          btn.disabled = false;
          btn.innerHTML =
            '<i class="icon-sort-by-attributes-alt"></i> Buscar las más pesadas';
          alert("No se pudo completar la búsqueda.");
        });
    });

    /* ---------------------------------------------------------------- */
    /* Generacion por tandas                                             */
    /* ---------------------------------------------------------------- */

    function setProgress(done) {
      var pct = total > 0 ? Math.min(100, Math.round((done * 100) / total)) : 0;
      var bar = el("webp-progress");
      bar.style.width = pct + "%";
      bar.textContent = pct + "%";
    }

    function finish(message) {
      running = false;
      el("webp-run-stop").hidden = true;
      el("webp-run-apply").disabled = false;
      el("webp-run-simulate").disabled = false;
      el("webp-progress-text").textContent = message;
    }

    function batch(apply, verify, state) {
      if (stopRequested) {
        finish(
          "Detenido. Lo generado se conserva: al volver a empezar no se repite.",
        );
        return;
      }

      post("Batch", {
        apply: apply ? 1 : 0,
        verify: verify ? 1 : 0,
        entity: state.entity || "",
        offset: state.offset || 0,
      })
        .then(function (data) {
          if (!data.ok) {
            log(data.error || "Error en la tanda.");
            finish("Detenido por un error.");
            return;
          }

          Object.keys(acumulado).forEach(function (k) {
            acumulado[k] += data.totals[k] || 0;
          });

          setProgress(acumulado.origen);
          el("webp-progress-text").textContent = apply
            ? acumulado.creadas +
              " miniaturas creadas · " +
              acumulado.existentes +
              " ya estaban · " +
              acumulado.fallidas +
              " fallidas · " +
              acumulado.origen +
              " de " +
              total +
              " imágenes revisadas"
            : acumulado.pendientes +
              " miniaturas por crear · " +
              acumulado.existentes +
              " ya están · " +
              acumulado.origen +
              " de " +
              total +
              " imágenes revisadas";

          (data.errors || []).forEach(log);

          if (data.stop === "disco") {
            log(
              "Se detuvo: queda poco espacio libre en el disco del servidor.",
            );
            finish("Detenido por falta de espacio en disco.");
            return;
          }

          if (data.done) {
            finish(
              apply
                ? "Terminado. No queda ninguna miniatura pendiente."
                : "Simulación terminada. Pulsa Generar para crearlas de verdad.",
            );
            return;
          }

          batch(apply, verify, data.state);
        })
        .catch(function () {
          log("Se perdió la conexión con el servidor.");
          finish(
            "Interrumpido. Puedes volver a pulsar Generar: se continúa donde iba.",
          );
        });
    }

    function start(apply) {
      if (running) {
        return;
      }
      running = true;
      stopRequested = false;
      acumulado = {
        creadas: 0,
        pendientes: 0,
        existentes: 0,
        fallidas: 0,
        origen: 0,
      };

      el("webp-run-apply").disabled = true;
      el("webp-run-simulate").disabled = true;
      el("webp-run-stop").hidden = false;
      el("webp-progress-wrap").hidden = false;
      el("webp-log").textContent = "";
      setProgress(0);
      el("webp-progress-text").textContent = "Contando imágenes...";

      var verify = el("webp-run-verify").checked;

      post("Count", {})
        .then(function (data) {
          total = data.total || 0;
          batch(apply, verify, { entity: "", offset: 0 });
        })
        .catch(function () {
          finish("No se pudo contar las imágenes.");
        });
    }

    el("webp-run-apply").addEventListener("click", function () {
      start(true);
    });
    el("webp-weight-convert").addEventListener("click", function () {
      // Es la misma generacion del bloque de abajo: se lleva alli la vista
      // para que se vea el avance.
      el("webp-progress-wrap").scrollIntoView({
        behavior: "smooth",
        block: "center",
      });
      start(true);
    });
    el("webp-run-simulate").addEventListener("click", function () {
      start(false);
    });
    el("webp-run-stop").addEventListener("click", function () {
      stopRequested = true;
      this.disabled = true;
      el("webp-progress-text").textContent = "Terminando la tanda en curso...";
    });

    window.addEventListener("beforeunload", function (e) {
      if (running) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }
})();
