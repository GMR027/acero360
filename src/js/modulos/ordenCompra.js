/**
 * ordenCompra.js — formulario de compra.
 *
 * Hay un bloque de partidas por tipo de compra (semiterminado o insumo); solo el del tipo elegido se envía
 * (el otro queda oculto y con sus campos deshabilitados). Agrega y quita renglones (clonando el primero, sin
 * innerHTML) y recalcula el importe de cada uno, el subtotal y el total con el IVA capturado. El servidor vuelve a
 * calcular todo: esto es solo una ayuda visual.
 *
 * Semiterminados: al elegir al proveedor cada renglón ofrece SU catálogo activo (JSON embebido en la página); al elegir
 * un producto se rellenan nombre, código y costo (el costo queda bloqueado hasta «Cambiar costo») y, si el producto del
 * proveedor está relacionado con un único producto propio, se propone ese producto propio.
 */
function iniciarOrdenCompra() {
  const bloques = document.querySelectorAll('[data-bloque-tipo]');
  if (!bloques.length) {
    return;
  }

  const formatoMoneda = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
  const totalCompra = document.querySelector('[data-total-compra]');
  const campoIva = document.getElementById('iva');
  const selectorProveedor = document.getElementById('proveedor_id');

  let catalogos = {};
  const datos = document.getElementById('catalogo-proveedores');
  if (datos) {
    try {
      catalogos = JSON.parse(datos.textContent) || {};
    } catch (error) {
      catalogos = {};
    }
  }
  const itemsDe = function (proveedorId) {
    return Array.isArray(catalogos[proveedorId]) ? catalogos[proveedorId] : [];
  };
  const buscarItem = function (proveedorId, itemId) {
    return itemsDe(proveedorId).find(function (item) {
      return String(item.id) === String(itemId);
    });
  };

  const campoTasa = document.getElementById('iva_tasa');
  const baseGravable = document.querySelector('[data-base-gravable]');
  // El IVA es automático mientras esté vacío o no se haya escrito a mano; «Recalcular IVA» vuelve al cálculo.
  let ivaManual = campoIva ? campoIva.value !== '' : false;

  /** Base gravable (centavos) de las partidas «Con IVA» del bloque visible. */
  const gravableActual = function () {
    let base = 0;
    bloques.forEach(function (bloque) {
      if (!bloque.hidden) {
        base += Number(bloque.dataset.gravable || 0);
      }
    });
    return base;
  };

  /** IVA = base gravable × tasa, redondeado al centavo (mitad hacia arriba), como en el servidor. */
  const calcularIvaCentavos = function () {
    const tasa = Number.parseFloat(campoTasa ? campoTasa.value : '');
    return Number.isFinite(tasa) ? Math.round(gravableActual() * tasa / 100) : null;
  };

  const actualizarIvaAutomatico = function () {
    if (baseGravable) {
      baseGravable.textContent = formatoMoneda.format(gravableActual() / 100);
    }
    if (campoIva && !ivaManual) {
      const iva = calcularIvaCentavos();
      campoIva.value = iva === null ? '' : (iva / 100).toFixed(2);
    }
  };

  /** Subtotal (centavos) del bloque visible + IVA capturado = total de la compra. */
  const actualizarTotalCompra = function () {
    if (!totalCompra) {
      return;
    }
    let subtotal = 0;
    bloques.forEach(function (bloque) {
      if (!bloque.hidden) {
        subtotal += Number(bloque.dataset.subtotal || 0);
      }
    });
    const iva = Math.round((Number.parseFloat(campoIva ? campoIva.value : '') || 0) * 100);
    totalCompra.textContent = formatoMoneda.format((subtotal + iva) / 100);
  };

  /** Muestra el bloque del tipo elegido y deshabilita los campos del otro. */
  const aplicarTipo = function () {
    const elegido = document.querySelector('[data-tipo-compra]:checked');
    const tipo = elegido ? elegido.value : 'semiterminado';
    bloques.forEach(function (bloque) {
      const activo = bloque.getAttribute('data-bloque-tipo') === tipo;
      bloque.hidden = !activo;
      bloque.querySelectorAll('input, select, textarea, button').forEach(function (campo) {
        campo.disabled = !activo;
      });
    });
    actualizarTotalCompra();
  };

  /** Rellena (o libera) los campos de un renglón según el producto del catálogo elegido. */
  const aplicarItem = function (fila, reiniciar) {
    const selector = fila.querySelector('[data-catalogo]');
    if (!selector) {
      return;
    }
    const descripcion = fila.querySelector('[data-descripcion]');
    const costo = fila.querySelector('[data-costo]');
    const codigo = fila.querySelector('[data-codigo]');
    const boton = fila.querySelector('[data-cambiar-costo]');
    const aviso = fila.querySelector('[data-aviso-costo]');
    const propio = fila.querySelector('select[name="producto_id[]"]');
    const item = selector.value !== '0' && selector.value !== ''
      ? buscarItem(selectorProveedor ? selectorProveedor.value : '', selector.value) : null;

    if (item) {
      descripcion.value = item.nombre;
      descripcion.readOnly = true;
      costo.value = item.costo;
      costo.readOnly = true;
      codigo.textContent = item.codigo ? 'Código ' + item.codigo : '';
      aviso.textContent = '';
      boton.hidden = false;
      if (propio && Array.isArray(item.propios) && item.propios.length === 1) {
        propio.value = String(item.propios[0]);
      }
    } else {
      descripcion.readOnly = false;
      costo.readOnly = false;
      codigo.textContent = '';
      aviso.textContent = '';
      boton.hidden = true;
      if (reiniciar) {
        descripcion.value = '';
        costo.value = '0.00';
      }
    }
  };

  /** Rearma las opciones del catálogo de un renglón con las del proveedor elegido. */
  const normalizar = function (texto) {
    return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  };

  const llenarCatalogo = function (fila, proveedorId) {
    const selector = fila.querySelector('[data-catalogo]');
    if (!selector) {
      return;
    }
    const previo = selector.value;
    const buscar = fila.querySelector('[data-buscar-catalogo]');
    const texto = normalizar(buscar ? buscar.value : '');
    while (selector.options.length > 1) {
      selector.remove(1);
    }
    itemsDe(proveedorId).forEach(function (item) {
      if (!item.activo && String(item.id) !== previo) {
        return;
      }
      // La búsqueda filtra por nombre o código; lo ya elegido siempre se conserva.
      if (texto !== '' && String(item.id) !== previo && normalizar(item.codigo + ' ' + item.nombre).indexOf(texto) === -1) {
        return;
      }
      const opcion = document.createElement('option');
      opcion.value = String(item.id);
      opcion.textContent = (item.codigo ? item.codigo + ' · ' : '') + item.nombre + (item.activo ? '' : ' (inactivo)');
      selector.appendChild(opcion);
    });
    selector.value = Array.from(selector.options).some(function (o) { return o.value === previo; }) ? previo : '0';
  };

  const iniciarBloque = function (bloque) {
    const cuerpo = bloque.querySelector('[data-partidas-cuerpo]');
    const total = bloque.querySelector('[data-total]');

    const recalcular = function () {
      let centavos = 0;
      cuerpo.querySelectorAll('[data-partida]').forEach(function (fila) {
        const milesimas = Math.round((Number.parseFloat(fila.querySelector('[data-cantidad]').value) || 0) * 1000);
        const costo = Math.round((Number.parseFloat(fila.querySelector('[data-costo]').value) || 0) * 100);
        const importe = Math.round((milesimas * costo) / 1000);
        centavos += importe;
        fila.querySelector('[data-importe]').textContent = formatoMoneda.format(importe / 100);
      });
      total.textContent = formatoMoneda.format(centavos / 100);
      bloque.dataset.subtotal = String(centavos);
      let gravable = 0;
      cuerpo.querySelectorAll('[data-partida]').forEach(function (fila) {
        const aplica = fila.querySelector('[data-aplica-iva]');
        if (aplica && aplica.value === '1') {
          const milesimas = Math.round((Number.parseFloat(fila.querySelector('[data-cantidad]').value) || 0) * 1000);
          const costo = Math.round((Number.parseFloat(fila.querySelector('[data-costo]').value) || 0) * 100);
          gravable += Math.round((milesimas * costo) / 1000);
        }
      });
      bloque.dataset.gravable = String(gravable);
      actualizarIvaAutomatico();
      actualizarTotalCompra();
    };

    /** El nombre del material solo se pide cuando la partida se controla en inventario. */
    const mostrarMaterial = function (fila) {
      const control = fila.querySelector('[data-controla]');
      const material = fila.querySelector('[data-material]');
      if (control && material) {
        material.hidden = control.value !== '1';
      }
    };

    bloque.querySelector('[data-agregar-partida]').addEventListener('click', function (evento) {
      evento.preventDefault();
      const nueva = cuerpo.querySelector('[data-partida]').cloneNode(true);
      nueva.querySelectorAll('input').forEach(function (campo) {
        campo.readOnly = false;
        campo.value = campo.hasAttribute('data-cantidad') ? '1' : (campo.hasAttribute('data-costo') ? '0.00' : '');
      });
      nueva.querySelectorAll('select').forEach(function (campo) {
        campo.value = campo.options.length ? campo.options[0].value : '';
      });
      nueva.querySelectorAll('[data-codigo], [data-aviso-costo]').forEach(function (nodo) {
        nodo.textContent = '';
      });
      cuerpo.appendChild(nueva);
      if (selectorProveedor) {
        llenarCatalogo(nueva, selectorProveedor.value);
      }
      aplicarItem(nueva, false);
      mostrarMaterial(nueva);
      recalcular();
    });

    cuerpo.addEventListener('click', function (evento) {
      const cambiar = evento.target.closest('[data-cambiar-costo]');
      if (cambiar) {
        evento.preventDefault();
        const fila = cambiar.closest('[data-partida]');
        const costo = fila.querySelector('[data-costo]');
        costo.readOnly = false;
        costo.focus();
        return;
      }
      const boton = evento.target.closest('[data-quitar-partida]');
      if (!boton) {
        return;
      }
      evento.preventDefault();
      if (cuerpo.querySelectorAll('[data-partida]').length > 1) {
        boton.closest('[data-partida]').remove();
        recalcular();
      }
    });

    cuerpo.addEventListener('input', function (evento) {
      if (evento.target.hasAttribute('data-buscar-catalogo')) {
        llenarCatalogo(evento.target.closest('[data-partida]'), selectorProveedor ? selectorProveedor.value : '');
        return;
      }
      // Si el costo se edita y ya no coincide con el del catálogo, se avisa cuál era.
      if (evento.target.hasAttribute('data-costo')) {
        const fila = evento.target.closest('[data-partida]');
        const selector = fila.querySelector('[data-catalogo]');
        const aviso = fila.querySelector('[data-aviso-costo]');
        const item = selector && selector.value !== '0' ? buscarItem(selectorProveedor ? selectorProveedor.value : '', selector.value) : null;
        if (item && aviso) {
          aviso.textContent = Number.parseFloat(evento.target.value) !== Number.parseFloat(item.costo) ? 'Catálogo: ' + Number.parseFloat(item.costo).toFixed(2) : '';
        }
      }
      recalcular();
    });

    cuerpo.addEventListener('change', function (evento) {
      const fila = evento.target.closest('[data-partida]');
      if (!fila) {
        return;
      }
      if (evento.target.hasAttribute('data-controla')) {
        mostrarMaterial(fila);
        return;
      }
      if (evento.target.hasAttribute('data-catalogo')) {
        aplicarItem(fila, true);
        recalcular();
      }
    });

    cuerpo.querySelectorAll('[data-partida]').forEach(mostrarMaterial);
    recalcular();
  };

  bloques.forEach(iniciarBloque);
  document.querySelectorAll('[data-tipo-compra]').forEach(function (radio) {
    radio.addEventListener('change', aplicarTipo);
  });

  // Al cambiar de proveedor, cada renglón ofrece el catálogo del nuevo; lo ya elegido que no sea suyo se libera.
  if (selectorProveedor) {
    selectorProveedor.addEventListener('change', function () {
      document.querySelectorAll('[data-bloque-tipo="semiterminado"] [data-partida]').forEach(function (fila) {
        const eraDelCatalogo = (fila.querySelector('[data-catalogo]') || {}).value !== '0';
        llenarCatalogo(fila, selectorProveedor.value);
        const sigue = (fila.querySelector('[data-catalogo]') || {}).value !== '0';
        aplicarItem(fila, eraDelCatalogo && !sigue);
      });
      bloques.forEach(function (bloque) {
        const cuerpo = bloque.querySelector('[data-partidas-cuerpo]');
        if (cuerpo) {
          cuerpo.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    });
  }

  const calcularIva = document.querySelector('[data-calcular-iva]');
  if (calcularIva && campoIva) {
    calcularIva.addEventListener('click', function (evento) {
      evento.preventDefault();
      ivaManual = false;
      actualizarIvaAutomatico();
      actualizarTotalCompra();
    });
  }
  if (campoIva) {
    campoIva.addEventListener('input', function () {
      ivaManual = campoIva.value !== '';
      actualizarTotalCompra();
    });
  }
  if (campoTasa) {
    campoTasa.addEventListener('input', function () {
      actualizarIvaAutomatico();
      actualizarTotalCompra();
    });
  }
  document.querySelectorAll('[data-partidas-cuerpo]').forEach(function (cuerpo) {
    cuerpo.addEventListener('change', function (evento) {
      if (evento.target.hasAttribute('data-aplica-iva')) {
        cuerpo.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  });

  aplicarTipo();
}

document.addEventListener('DOMContentLoaded', iniciarOrdenCompra);
