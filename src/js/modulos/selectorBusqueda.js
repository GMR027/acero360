/**
 * modulos/selectorBusqueda.js
 * ---------------------------------------------------------------------------
 * Convierte un <select data-buscador> en un campo de búsqueda por texto con
 * lista desplegable filtrada (combobox), pensado para listas largas como la
 * de clientes del cotizador.
 *
 * Cómo funciona:
 *  - El <select> permanece en el DOM (oculto) como fuente de la verdad: el
 *    formulario sigue enviando su name, el servidor valida igual y los demás
 *    módulos no cambian (cotizador.js escucha el 'change' del cliente).
 *  - Al elegir una opción se escribe su valor en el <select> y se dispara
 *    'change', de modo que el descuento y los precios del cliente se
 *    actualizan como siempre.
 *  - Sin JavaScript el <select> se muestra y funciona como siempre: la mejora
 *    sólo se construye cuando hay JS.
 *
 * Reglas del proyecto: createElement en lugar de innerHTML, ni alert, ni
 * confirm, ni prompt.
 */

/** Máximo de opciones pintadas en la lista desplegable (afina con el teclado). */
const BUSCADOR_LIMITE_OPCIONES = 60;

/** Pinta en el input el texto de la opción elegida (o nada si no hay). */
function sincronizarEntradaBusqueda(select, entrada) {
  const opcion = select.options[select.selectedIndex];
  const texto = opcion && opcion.value !== '' ? opcion.text : '';
  entrada.value = texto;
}

/** Quita acentos y minúsculas para que «Jose» encuentre a «José». */
function normalizarBusqueda(texto) {
  return (texto || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Lee las opciones reales del <select> junto a su texto de búsqueda. */
function leerOpcionesBusqueda(select) {
  const opciones = [];

  Array.prototype.forEach.call(select.options, function (opcion) {
    if (opcion.value === '') {
      return; // el marcador «Selecciona…» no se busca
    }

    const rfc = opcion.getAttribute('data-rfc') || '';
    const alias = opcion.getAttribute('data-alias') || '';

    opciones.push({
      valor: opcion.value,
      texto: opcion.text,
      rfc: rfc,
      buscar: normalizarBusqueda(opcion.text + ' ' + alias + ' ' + rfc),
    });
  });

  return opciones;
}

/** Muestra el error de «escribió pero no eligió» bajo el campo, con el foco. */
function marcarErrorBusqueda(entrada, mensaje) {
  const campo = entrada.closest('.campo');
  if (!campo) {
    return;
  }

  campo.classList.add('campo--con-error');

  const previo = campo.querySelector('.campo__error');
  if (previo && previo.parentNode) {
    previo.parentNode.removeChild(previo);
  }

  const error = document.createElement('p');
  error.className = 'campo__error';
  error.textContent = mensaje;
  campo.appendChild(error);

  entrada.focus();
}

/**
 * Construye el buscador de un <select> concreto.
 *
 * @param {HTMLSelectElement} select
 */
function crearSelectorBusqueda(select) {
  if (select.getAttribute('data-buscador-listo') === '1') {
    return; // idempotente: no se construye dos veces el mismo combobox
  }
  select.setAttribute('data-buscador-listo', '1');

  const opciones = leerOpcionesBusqueda(select);
  if (opciones.length === 0) {
    return; // sin opciones no hay nada que buscar
  }

  /* De qué se busca («cliente», «proveedor»…): lo dicen los avisos, para que
   * el mensaje no le hable a un cotizador cuando se busca un proveedor. */
  const entidad = select.getAttribute('data-buscador-entidad') || 'cliente';

  const base = select.id || 'selector';
  const idEntrada = base + '-busqueda';
  const idLista = base + '-lista';

  /* Envoltorio que da posición a la lista desplegable. */
  const envoltorio = document.createElement('div');
  envoltorio.className = 'selector-busqueda';

  const entrada = document.createElement('input');
  entrada.type = 'search';
  entrada.className = 'control';
  entrada.id = idEntrada;
  entrada.autocomplete = 'off';
  entrada.spellcheck = false;
  entrada.placeholder =
    select.getAttribute('data-buscador-placeholder') || 'Escribe para buscar…';
  entrada.setAttribute('role', 'combobox');
  entrada.setAttribute('aria-autocomplete', 'list');
  entrada.setAttribute('aria-expanded', 'false');
  entrada.setAttribute('aria-controls', idLista);
  if (select.required) {
    entrada.required = true;
  }

  const lista = document.createElement('ul');
  lista.className = 'selector-busqueda__lista';
  lista.id = idLista;
  lista.setAttribute('role', 'listbox');
  lista.setAttribute('aria-label', 'Opciones');
  lista.hidden = true;

  envoltorio.appendChild(entrada);
  envoltorio.appendChild(lista);

  /* El buscador va ANTES del <select> (que se oculta pero se queda): el orden
   * del DOM importa para la validación, que así señala primero el campo que la
   * persona ve y puede corregir. */
  select.parentNode.insertBefore(envoltorio, select);
  select.hidden = true;

  /* La etiqueta apunta al control visible (sin JS vuelve a apuntar al select,
   * que es el que se ve en ese caso). */
  const etiqueta = document.querySelector('label[for="' + select.id + '"]');
  if (etiqueta) {
    etiqueta.setAttribute('for', idEntrada);
  }

  sincronizarEntradaBusqueda(select, entrada);

  /** Índice de la opción resaltada con el teclado (-1 = ninguna). */
  let activa = -1;

  /** Opciones que se pintaron en la lista en la última filtración. */
  let visibles = [];

  /** Elige una opción: escribe el <select> y avisa del cambio. */
  const elegir = function (opcion) {
    select.value = opcion.valor;
    sincronizarEntradaBusqueda(select, entrada);
    cerrarLista();
    // Se retira el aviso de «texto sin coincidencia» si lo había: ya hay ficha.
    const campo = entrada.closest('.campo');
    if (campo) {
      campo.classList.remove('campo--con-error');
      const error = campo.querySelector('.campo__error');
      if (error && error.parentNode) {
        error.parentNode.removeChild(error);
      }
    }
    // El 'change' es el que ya escuchan los demás módulos (cotizador: descuento
    // y precios del cliente).
    select.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const cerrarLista = function () {
    lista.hidden = true;
    entrada.setAttribute('aria-expanded', 'false');
    entrada.removeAttribute('aria-activedescendant');
    activa = -1;
  };

  /** Pinta la lista con las opciones que coinciden con el texto del input. */
  const pintarLista = function () {
    const termino = normalizarBusqueda(entrada.value);

    while (lista.firstChild) {
      lista.removeChild(lista.firstChild);
    }

    visibles = opciones.filter(function (opcion) {
      return termino === '' || opcion.buscar.indexOf(termino) !== -1;
    });

    const pintadas = Math.min(visibles.length, BUSCADOR_LIMITE_OPCIONES);

    for (let i = 0; i < pintadas; i += 1) {
      const opcion = visibles[i];
      const elegida = select.value === opcion.valor;

      const item = document.createElement('li');
      item.className = 'selector-busqueda__opcion' + (elegida ? ' selector-busqueda__opcion--elegida' : '');
      item.id = idLista + '-opcion-' + i;
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', elegida ? 'true' : 'false');

      const principal = document.createElement('span');
      principal.className = 'selector-busqueda__nombre';
      principal.textContent = opcion.texto;
      item.appendChild(principal);

      if (opcion.rfc !== '') {
        const rfc = document.createElement('span');
        rfc.className = 'selector-busqueda__rfc';
        rfc.textContent = opcion.rfc;
        item.appendChild(rfc);
      }

      item.addEventListener('pointerdown', function (evento) {
        // Sin esto el foco salta del input a la lista y se cerraría antes de
        // poder elegir: el clic se resuelve aquí, con el foco en su sitio.
        evento.preventDefault();
        elegir(opcion);
      });

      lista.appendChild(item);
    }

    if (pintadas === 0) {
      const vacia = document.createElement('li');
      vacia.className = 'selector-busqueda__opcion selector-busqueda__opcion--vacia';
      vacia.setAttribute('role', 'option');
      vacia.setAttribute('aria-disabled', 'true');
      vacia.textContent = 'Sin coincidencias' + (entrada.value.trim() !== '' ? ' para «' + entrada.value.trim() + '»' : '');
      lista.appendChild(vacia);
    } else if (visibles.length > pintadas) {
      const resto = document.createElement('li');
      resto.className = 'selector-busqueda__pie';
      resto.textContent = (visibles.length - pintadas) + ' más: sigue escribiendo para afinar';
      lista.appendChild(resto);
    }

    const conteo = document.createElement('li');
    conteo.className = 'selector-busqueda__pie';
    conteo.textContent = pintadas + ' de ' + opciones.length + ' opciones';
    lista.appendChild(conteo);

    activa = -1;

    /* Aviso para otros módulos (gastoProveedor.js): cuántas opciones coinciden
     * con lo escrito y qué se escribió, para que puedan ofrecer el alta de una
     * ficha que todavía no existe en el directorio. */
    entrada.dispatchEvent(new CustomEvent('buscador:resultado', {
      bubbles: true,
      detail: { total: visibles.length, texto: entrada.value },
    }));
  };

  const abrirLista = function () {
    pintarLista();
    lista.hidden = false;
    entrada.setAttribute('aria-expanded', 'true');
  };

  /** Marca la opción activa del teclado (y la deja a la vista). */
  const resaltar = function (indice) {
    const items = lista.querySelectorAll('.selector-busqueda__opcion:not(.selector-busqueda__opcion--vacia)');
    if (items.length === 0) {
      return;
    }

    // La resaltada es sólo visual: lo elegido lo marca aria-selected y lo
    // activo por teclado, aria-activedescendant.
    items.forEach(function (item) {
      item.classList.remove('selector-busqueda__opcion--activa');
    });

    activa = (indice + items.length) % items.length;
    const item = items[activa];
    if (item) {
      item.classList.add('selector-busqueda__opcion--activa');
      entrada.setAttribute('aria-activedescendant', item.id);
      item.scrollIntoView({ block: 'nearest' });
    }
  };

  entrada.addEventListener('focus', abrirLista);
  entrada.addEventListener('click', abrirLista);

  entrada.addEventListener('input', function () {
    // Si lo escrito deja de ser la etiqueta de la ficha elegida, la elección
    // queda en suspenso: el <select> vuelve a vacío (y se avisa con 'change')
    // hasta que se elige otra opción de la lista.
    if (select.value !== '') {
      const opcion = select.options[select.selectedIndex];
      if (normalizarBusqueda(entrada.value) !== normalizarBusqueda(opcion ? opcion.text : '')) {
        select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    abrirLista();
  });

  entrada.addEventListener('keydown', function (evento) {
    if (evento.key === 'ArrowDown') {
      evento.preventDefault();
      if (lista.hidden) {
        abrirLista();
      }
      resaltar(activa + 1);
      return;
    }

    if (evento.key === 'ArrowUp') {
      evento.preventDefault();
      if (lista.hidden) {
        abrirLista();
      }
      resaltar(activa - 1);
      return;
    }

    if (evento.key === 'Enter') {
      // Con la lista abierta, Intro elige la opción resaltada (o la primera
      // coincidencia si no se movió con el teclado); sin coincidencias, avisa
      // en el momento que ese texto no corresponde a ninguna ficha; cerrada,
      // Intro hace lo de siempre (enviar el formulario).
      if (!lista.hidden) {
        evento.preventDefault();
        const items = lista.querySelectorAll('.selector-busqueda__opcion:not(.selector-busqueda__opcion--vacia)');
        if (items.length > 0) {
          const indice = activa >= 0 && activa < items.length ? activa : 0;
          elegir(visibles[indice]);
        } else {
          marcarErrorBusqueda(
            entrada,
            'Ninguna ficha coincide con «' + entrada.value.trim() + '»: corrige el texto o elige un ' + entidad + ' de la lista.'
          );
        }
      }
      return;
    }

    if (evento.key === 'Escape' && !lista.hidden) {
      evento.preventDefault();
      cerrarLista();
    }
  });

  entrada.addEventListener('blur', function () {
    // Al salir del campo se cierra la lista y el texto vuelve a reflejar lo
    // elegido de verdad en el <select> (nada si no hay elección).
    cerrarLista();
    sincronizarEntradaBusqueda(select, entrada);
  });

  /* Envío con texto suelto: si la persona escribió algo que no corresponde a
   * una ficha elegida, se le avisa aquí (con foco incluido) y no se envía.
   * Corre ANTES de la validación general de app.js y por eso detiene también
   * su paso; si el input está vacío, esa validación señala el campo igual. */
  const formulario = select.closest('form');
  if (formulario) {
    formulario.addEventListener('submit', function (evento) {
      if (evento.defaultPrevented) {
        return;
      }
      if (select.value !== '' || entrada.value.trim() === '') {
        return;
      }

      evento.preventDefault();
      evento.stopPropagation();
      marcarErrorBusqueda(
        entrada,
        'Elige un ' + entidad + ' de la lista: lo que escribiste no coincide con ninguna ficha.'
      );
    });
  }
}

/**
 * Prepara todos los <select data-buscador> de la página.
 * Se llama desde inicializarAplicacion() (src/js/app.js).
 */
function prepararSelectoresBusqueda() {
  document.querySelectorAll('select[data-buscador]').forEach(crearSelectorBusqueda);
}
