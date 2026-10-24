<?php
/**
 * views/admin/archivo/index.php — Herramienta «Archivo».
 *
 * Carpetas virtuales donde el usuario aloja y organiza su información:
 * ofimática (Office, LibreOffice y OnlyOffice), PDF, imágenes, comprimidos
 * y modelos de Rhinoceros, Revit, AutoCAD, SketchUp, etc.
 *
 * Sección compartida: cualquier administrador ve y gestiona todo.
 *
 * ?carpeta=ID navega dentro de una carpeta; ?q= busca por nombre.
 */

soloAdmin();

use Classes\ArchivoAlmacen;
use Model\Archivo;
use Model\ArchivoCarpeta;

/* --- Carpeta actual (o raíz) y ruta de migas de pan --- */
$carpetaId = (int) ($_GET['carpeta'] ?? 0);
$carpeta   = null;
$miga      = [];

if ($carpetaId > 0) {
    $carpeta = ArchivoCarpeta::find($carpetaId);
    if (!$carpeta) {
        redirigir('/admin/archivo', 'error', 'La carpeta ya no existe.');
    }
    $miga = $carpeta->ruta();
}

/** URL de una carpeta concreta (null = raíz). */
$uirCarpeta = static function (?int $id): string {
    return '/admin/archivo' . ($id ? '?carpeta=' . (int) $id : '');
};

/* --- Contenido de la carpeta --- */
$busqueda = trim((string) ($_GET['q'] ?? ''));
$subcarpetas = ArchivoCarpeta::enNivel($carpetaId);
$archivos    = Archivo::enCarpeta($carpetaId, $busqueda);

/** Carpetas disponibles como destino al mover un archivo (sin superar la profundidad). */
$profundidad = count($miga);
$destinos = [];
// Recorrido por niveles empezando en la carpeta ACTUAL: sus hijas sí son
// destinos válidos (su padre no, porque allí ya está el archivo).
$porNivel = $carpeta ? [[(int) $carpeta->id, str_repeat('› ', count($miga)) . ' ']] : [[null, '']];
for ($paso = 0; $paso <= ArchivoCarpeta::PROFUNDIDAD_MAX && $porNivel; $paso++) {
    $siguiente = [];
    foreach ($porNivel as [$padre, $prefijo]) {
        foreach (ArchivoCarpeta::enNivel($padre) as $hija) {
            if ($carpeta && (int) $hija->id === (int) $carpeta->id) continue;   // no mover dentro de sí misma
            $rutaHija = $hija->ruta();
            if (count($rutaHija) - 1 > ArchivoCarpeta::PROFUNDIDAD_MAX) continue;   // ya está en el fondo
            $destinos[(int) $hija->id] = trim($prefijo . $hija->nombre);
            $siguiente[] = [(int) $hija->id, str_repeat('› ', count($rutaHija)) . ' '];
        }
    }
    $porNivel = $siguiente;
}
ksort($destinos);

/** Icono Material Symbols de una carpeta. */
$iconoArchivo = static function (Archivo $a): string {
    return $a->icono();
};

/* --- Subida: ¿cuánto pesa lo que admite el servidor? --- */
$maxSubida = ArchivoAlmacen::maxBytes();
$extensiones = ArchivoAlmacen::extensiones();
$extensionesTexto = 'Ofimática: ' . implode(', ', array_slice($extensiones, 0, 30))
    . ' · Modelos: ' . implode(', ', array_slice($extensiones, 30));

// Confirmaciones, carpetas e iconos de las herramientas portadas (modal estilizado).
$GLOBALS['scriptsPagina'][] = '/public/js/herramientas.js';

template('header', [
    'titulo'    => 'Archivo',
    'subtitulo' => 'Herramientas personales',
    'acciones'  => '',
]);
?>

<section class="herr" id="archivo">
    <div class="encabezado-pagina">
        <div>
            <p class="texto-secundario">
                Carpetas virtuales para alojar y organizar tu información: documentos de
                Office, LibreOffice y OnlyOffice, PDF, imágenes y modelos de Rhinoceros,
                Revit, AutoCAD o SketchUp. Archivo compartido: todos los administradores
                ven lo mismo.
            </p>
        </div>
        <div class="encabezado-pagina__acciones">
            <button type="button" class="btn btn--primario" data-arch-nueva-carpeta>
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">create_new_folder</span>
                <span>Nueva carpeta</span>
            </button>
        </div>
    </div>

    <!-- =========================== Migas de pan =========================== -->
    <nav class="ar-miga" aria-label="Ruta de carpetas">
        <a href="/admin/archivo" class="ar-miga__enlace<?= !$carpeta ? ' ar-miga__enlace--actual' : '' ?>">
            <span class="material-symbols-outlined" aria-hidden="true">folder</span> Archivo
        </a>
        <?php foreach ($miga as $i => $nivel) :
            $ultimo = $i === count($miga) - 1; ?>
            <span class="ar-miga__sep" aria-hidden="true">›</span>
            <?php if ($ultimo) : ?>
                <span class="ar-miga__enlace ar-miga__enlace--actual" aria-current="page"><?= s($nivel['nombre']) ?></span>
            <?php else : ?>
                <a class="ar-miga__enlace" href="<?= $uirCarpeta($nivel['id']) ?>"><?= s($nivel['nombre']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <!-- ======================== Barra de búsqueda y subida ======================== -->
    <div class="ar-barra">
        <form method="GET" action="/admin/archivo" class="ar-buscar">
            <?php if ($carpetaId) : ?>
                <input type="hidden" name="carpeta" value="<?= (int) $carpetaId ?>">
            <?php endif; ?>
            <span class="material-symbols-outlined ar-buscar__ico" aria-hidden="true">search</span>
            <input type="search" name="q" value="<?= s($busqueda) ?>"
                   placeholder="Buscar por nombre o descripción…" aria-label="Buscar archivos">
            <?php if ($busqueda !== '') : ?>
                <a class="ar-buscar__limpiar" href="<?= $uirCarpeta($carpetaId) ?>"
                   title="Quitar la búsqueda" aria-label="Quitar la búsqueda">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </a>
            <?php endif; ?>
        </form>

        <form method="POST" action="/admin/archivo/subir" class="ar-subir" id="ar-subir"
              enctype="multipart/form-data" aria-label="Subir archivos">
            <?php if ($carpetaId) : ?>
                <input type="hidden" name="carpeta_id" value="<?= (int) $carpetaId ?>">
            <?php endif; ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) $maxSubida ?>">
            <span class="ar-subir__grupo">
                <label class="btn btn--secundario ar-subir__btn">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">upload_file</span>
                    <span>Elegir archivos</span>
                    <input type="file" name="archivos[]" id="ar-input" multiple
                           aria-label="Archivos a subir (puedes elegir varios)">
                </label>
                <button type="submit" class="btn btn--primario" id="ar-enviar" hidden>
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">cloud_upload</span>
                    <span id="ar-enviar-texto">Subir</span>
                </button>
            </span>
        </form>
    </div>

    <!-- Zona de arrastre: suelta aquí los archivos para subirlos a esta carpeta. -->
    <div class="ar-soltar" id="ar-soltar" hidden>
        <span class="material-symbols-outlined" aria-hidden="true">cloud_upload</span>
        <strong>Suelta aquí para subirlos</strong>
        <span class="text-sm text-muted">a <?= $carpeta ? '«' . s($carpeta->nombre) . '»' : 'la carpeta Archivo' ?></span>
    </div>

    <p class="text-sm text-muted ar-ayuda">
        Admite <?= count($extensiones) ?> formatos · hasta <?= formatear_tamano($maxSubida) ?> por archivo ·
        <span title="<?= s($extensionesTexto) ?>">Office, LibreOffice, OnlyOffice, PDF, imágenes,
        comprimidos y modelos (Rhinoceros, Revit, AutoCAD, SketchUp…)</span>.
        <?php if ($profundidad >= ArchivoCarpeta::PROFUNDIDAD_MAX) : ?>
            <em>(Esta carpeta ya está en la profundidad máxima: no admite subcarpetas.)</em>
        <?php endif; ?>
    </p>

    <?php if ($busqueda !== '') : ?>
        <p class="text-sm">
            <strong><?= count($archivos) ?></strong> resultado(s) para «<?= s($busqueda) ?>»
            <?php if ($carpeta) : ?>en «<?= s($carpeta->nombre) ?>»<?php endif; ?>.
            <a href="<?= $uirCarpeta($carpetaId) ?>">Ver todo</a>
        </p>
    <?php endif; ?>

    <!-- =========================== Subcarpetas ============================ -->
    <?php if ($subcarpetas && $busqueda === '') : ?>
        <div class="ar-carpetas">
            <?php foreach ($subcarpetas as $sc) : $n = $sc->totalArchivos(); ?>
                <article class="ar-carpeta">
                    <a class="ar-carpeta__cuerpo" href="<?= $uirCarpeta((int) $sc->id) ?>">
                        <span class="material-symbols-outlined ar-carpeta__ico" aria-hidden="true">folder</span>
                        <span class="ar-carpeta__nombre" title="<?= s($sc->nombre) ?>"><?= s($sc->nombre) ?></span>
                        <span class="ar-carpeta__n"><?= $n ?> archivo<?= $n === 1 ? '' : 's' ?></span>
                    </a>
                    <div class="ar-carpeta__ops">
                        <button type="button" class="ar-op" data-arch-renombrar="<?= (int) $sc->id ?>"
                                data-nombre="<?= s($sc->nombre) ?>" title="Renombrar carpeta" aria-label="Renombrar <?= s($sc->nombre) ?>">
                            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                        </button>
                        <form method="POST" action="/admin/archivo/eliminar-carpeta" class="en-linea"
                              data-confirm="¿Eliminar la carpeta «<?= s($sc->nombre) ?>»?|Sus archivos y subcarpetas pasarán a la carpeta contenedora: no se borra ningún archivo.">
                            <input type="hidden" name="id" value="<?= (int) $sc->id ?>">
                            <button type="submit" class="ar-op ar-op--peligro" title="Eliminar carpeta"
                                    aria-label="Eliminar <?= s($sc->nombre) ?>">
                                <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ============================= Archivos ============================= -->
    <?php if (!$archivos) : ?>
        <div class="card">
            <p class="text-muted">
                <?php if ($busqueda !== '') : ?>
                    Ningún archivo coincide con la búsqueda.
                <?php elseif ($subcarpetas) : ?>
                    Esta carpeta no tiene archivos todavía: sube algunos con «Subir archivos» o
                    suéltalos sobre esta zona.
                <?php else : ?>
                    Aún no hay archivos. Crea una carpeta con «Nueva carpeta» y sube documentos
                    con «Subir archivos»: puedes elegir varios a la vez.
                <?php endif; ?>
            </p>
        </div>
    <?php else : ?>
        <div class="card">
            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Tamaño</th>
                            <th>Subido</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($archivos as $a) : ?>
                        <tr>
                            <td>
                                <span class="material-symbols-outlined ar-ico-tipo" aria-hidden="true"><?= $iconoArchivo($a) ?></span>
                                <strong><?= s($a->titulo()) ?></strong>
                                <?php if ($a->descripcion !== '') : ?>
                                    <br><small class="text-muted"><?= s($a->descripcion) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?= strtoupper($a->extension()) !== '' ? s(strtoupper($a->extension())) : '—' ?></td>
                            <td class="mono"><?= $a->tamanoLegible() ?></td>
                            <td class="mono"><?= formatear_fecha($a->subido, 'd/m/Y H:i') ?></td>
                            <td class="herramientas-acciones">
                                <a class="btn btn--primario btn--sm" href="/admin/archivo/descargar?id=<?= (int) $a->id ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">download</span> Descargar
                                </a>
                                <form method="POST" action="/admin/archivo/mover" class="en-linea ar-mover">
                                    <input type="hidden" name="archivo_id" value="<?= (int) $a->id ?>">
                                    <select name="carpeta_id" class="ar-select-mover" aria-label="Mover «<?= s($a->titulo()) ?>» a…"
                                            onchange="this.form.submit()">
                                        <option value="">Mover a…</option>
                                        <?php if ($carpetaId) : ?>
                                            <option value="0">Raíz del Archivo</option>
                                        <?php endif; ?>
                                        <?php foreach ($destinos as $idDestino => $rutaDestino) : ?>
                                            <option value="<?= (int) $idDestino ?>"><?= s($rutaDestino) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <form method="POST" action="/admin/archivo/eliminar-archivo" class="en-linea"
                                      data-confirm="¿Eliminar «<?= s($a->titulo()) ?>»?|Esta acción no se puede deshacer: el archivo se borra del servidor.">
                                    <input type="hidden" name="id" value="<?= (int) $a->id ?>">
                                    <button type="submit" class="btn btn--peligro btn--sm">
                                        <span class="material-symbols-outlined" aria-hidden="true">delete</span> Eliminar
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- ===================== Diálogos: crear y renombrar ===================== -->
<dialog class="ar-dialogo" id="ar-dlg-carpeta">
    <form method="POST" action="/admin/archivo/crear-carpeta">
        <?php if ($carpetaId) : ?>
            <input type="hidden" name="carpeta_padre_id" value="<?= (int) $carpetaId ?>">
        <?php endif; ?>
        <h3>
            <span class="material-symbols-outlined" aria-hidden="true">create_new_folder</span>
            Nueva carpeta
        </h3>
        <p class="text-sm text-muted">
            Dentro de <?= $carpeta ? '«' . s($carpeta->nombre) . '»' : 'la raíz del Archivo' ?>.
        </p>
        <label class="campo">
            <span>Nombre</span>
            <input type="text" name="nombre" maxlength="100" required autofocus
                   placeholder="Por ejemplo: Planos, Contratos, Modelos 3D">
        </label>
        <div class="ar-dialogo__ops">
            <button type="button" class="btn btn--texto" data-arch-cancelar>Cancelar</button>
            <button type="submit" class="btn btn--primario">Crear carpeta</button>
        </div>
    </form>
</dialog>

<dialog class="ar-dialogo" id="ar-dlg-renombrar">
    <form method="POST" action="/admin/archivo/renombrar-carpeta">
        <input type="hidden" name="id" id="ar-ren-id">
        <h3>
            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
            Renombrar carpeta
        </h3>
        <label class="campo">
            <span>Nombre</span>
            <input type="text" name="nombre" id="ar-ren-nombre" maxlength="100" required autofocus>
        </label>
        <div class="ar-dialogo__ops">
            <button type="button" class="btn btn--texto" data-arch-cancelar>Cancelar</button>
            <button type="submit" class="btn btn--primario">Guardar</button>
        </div>
    </form>
</dialog>

<script>
/* Herramienta Archivo: diálogo de carpetas, arrastrar y soltar y aviso de progreso. */
(function () {
  'use strict';

  var carpetaPadre = <?= json_encode($carpetaId) ?>;
  var maxSubida = <?= json_encode($maxSubida) ?>;
  var dlgCarpeta = document.getElementById('ar-dlg-carpeta');
  var dlgRenombrar = document.getElementById('ar-dlg-renombrar');
  var formSubir = document.getElementById('ar-subir');
  var input = document.getElementById('ar-input');
  var zona = document.getElementById('ar-soltar');

  function abrir(dialogo) {
    if (dialogo.showModal) dialogo.showModal();
    else dialogo.setAttribute('open', '');
  }
  function cerrar(dialogo) {
    if (dialogo.close) dialogo.close();
    else dialogo.removeAttribute('open');
  }

  /* --- Nueva carpeta / renombrar --- */
  document.querySelectorAll('[data-arch-nueva-carpeta]').forEach(function (b) {
    b.addEventListener('click', function () { abrir(dlgCarpeta); });
  });
  document.querySelectorAll('[data-arch-renombrar]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.getElementById('ar-ren-id').value = b.getAttribute('data-arch-renombrar');
      document.getElementById('ar-ren-nombre').value = b.getAttribute('data-nombre') || '';
      abrir(dlgRenombrar);
    });
  });
  document.querySelectorAll('[data-arch-cancelar]').forEach(function (b) {
    b.addEventListener('click', function () {
      cerrar(b.closest('dialog'));
    });
  });
  [dlgCarpeta, dlgRenombrar].forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d) cerrar(d); });
  });

  /* Renombrar responde JSON: se recarga para que cambien migas y tarjetas. */
  var formRen = dlgRenombrar.querySelector('form');
  if (formRen) {
    formRen.addEventListener('submit', function (e) {
      e.preventDefault();
      var cuerpo = new URLSearchParams(new FormData(formRen));
      fetch(formRen.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'archivo', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: cuerpo, credentials: 'same-origin'
      }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
        .then(function (d) {
          if (d && d.success) { location.reload(); return; }
          avisar((d && d.error) || 'No se pudo renombrar la carpeta.', true);
          cerrar(dlgRenombrar);
        }).catch(function () { avisar('No se pudo renombrar la carpeta.', true); });
    });
  }

  /* --- Arrastrar y soltar sobre la zona --- */
  if (zona && formSubir) {
    var dentro = 0;
    ['dragenter', 'dragover'].forEach(function (tipo) {
      document.addEventListener(tipo, function (e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return;
        e.preventDefault();
        if (tipo === 'dragenter') dentro++;
        zona.hidden = false;
      });
    });
    document.addEventListener('dragleave', function () {
      dentro = Math.max(0, dentro - 1);
      if (dentro === 0) zona.hidden = true;
    });
    document.addEventListener('drop', function (e) {
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      e.preventDefault();
      dentro = 0;
      zona.hidden = true;
      try {
        input.files = e.dataTransfer.files;   // el form normal ya lleva su _csrf
      } catch (err) {
        return;
      }
      var total = 0, grande = null;
      Array.prototype.forEach.call(e.dataTransfer.files, function (f) {
        total += f.size;
        if (f.size > maxSubida) grande = f;
      });
      preparar(e.dataTransfer.files.length, total, grande);
    });
  }

  /* --- El input avisa de lo elegido y habilita el envío --- */
  var btnEnviar = document.getElementById('ar-enviar');
  var textoEnviar = document.getElementById('ar-enviar-texto');

  function preparar(nFiles, total, grande) {
    if (grande) {
      avisar('«' + grande.name + '» pesa más de ' + formato(maxSubida) + ': elige un archivo más ligero.', true);
      if (btnEnviar) btnEnviar.hidden = true;
      return false;
    }
    if (btnEnviar) btnEnviar.hidden = false;
    if (textoEnviar) textoEnviar.textContent = 'Subir ' + nFiles + (nFiles === 1 ? ' archivo' : ' archivos');
    avisar(nFiles + ' archivo(s) listos para subir (' + formato(total) + '). Pulsa «Subir».');
    return true;
  }

  if (input) {
    input.addEventListener('change', function () {
      if (!this.files.length) {
        if (btnEnviar) btnEnviar.hidden = true;
        return;
      }
      var total = 0, grande = null;
      Array.prototype.forEach.call(this.files, function (f) {
        total += f.size;
        if (f.size > maxSubida) grande = f;
      });
      if (!preparar(this.files.length, total, grande)) this.value = '';
    });
  }

  function formato(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
    return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
  }

  var aviso;
  function avisar(texto, error) {
    if (!aviso) {
      aviso = document.createElement('p');
      aviso.className = 'text-sm ar-aviso';
      if (formSubir) formSubir.parentNode.appendChild(aviso);
    }
    aviso.textContent = texto;
    aviso.classList.toggle('ar-aviso--error', !!error);
  }
})();
</script>

<?php template('footer'); ?>
