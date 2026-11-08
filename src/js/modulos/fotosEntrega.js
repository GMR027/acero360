/**
 * modulos/fotosEntrega.js
 * ---------------------------------------------------------------------------
 * Compresión en el navegador de las fotos del acta de entrega.
 *
 * El servidor también comprime, pero una foto de teléfono puede pesar 15 MB:
 * enviarla completa gasta datos y tarda. Aquí se lee primero el EXIF (fecha y
 * GPS), se redimensiona y se recomprime a JPEG antes de enviarla.
 *
 * Reglas del proyecto:
 *  - let/const, sin alert/confirm/prompt, sin innerHTML.
 *  - Si el navegador no puede comprimir, no se bloquea el formulario: el
 *    servidor recibe el archivo original (hasta 15 MB), lee su EXIF y lo
 *    comprime igual.
 */
(function () {
  'use strict';

  const TIPOS_FOTO = ['image/jpeg', 'image/png', 'image/webp'];
  const LADO_MAXIMO = 1600;
  const OBJETIVO_BYTES = 1536 * 1024; // 1.5 MB

  /**
   * Lee fecha, GPS y orientación EXIF de un JPEG.
   *
   * Se llama ANTES de dibujar en canvas: al recomprimir se pierden los
   * metadatos, así que hay que sacarlos del archivo original.
   *
   * @param {File} archivo
   * @returns {Promise<{tomada_en: ?string, latitud: ?number, longitud: ?number, orientacion: number}>}
   */
  function leerExifFoto(archivo) {
    const vacio = { tomada_en: null, latitud: null, longitud: null, orientacion: 1 };

    return new Promise(function (resolve) {
      const limite = Math.min(archivo.size, 1024 * 1024);
      archivo.slice(0, limite).arrayBuffer().then(function (buffer) {
        try {
          resolve(leerExifDeBuffer(buffer));
        } catch (e) {
          resolve(vacio);
        }
      }).catch(function () {
        resolve(vacio);
      });
    });
  }

  /** Recorre los segmentos JPEG hasta encontrar el APP1 con EXIF. */
  function leerExifDeBuffer(buffer) {
    const vacio = { tomada_en: null, latitud: null, longitud: null, orientacion: 1 };
    const bytes = new Uint8Array(buffer);
    if (bytes.length < 4 || bytes[0] !== 0xFF || bytes[1] !== 0xD8) {
      return vacio;
    }

    let offset = 2;
    while (offset + 4 <= bytes.length) {
      if (bytes[offset] !== 0xFF) {
        break;
      }
      const marcador = bytes[offset + 1];
      if (marcador === 0xD8 || marcador === 0x01 || (marcador >= 0xD0 && marcador <= 0xD7)) {
        offset += 2;
        continue;
      }
      if (marcador === 0xDA || marcador === 0xD9) {
        break;
      }

      const largo = (bytes[offset + 2] << 8) | bytes[offset + 3];
      if (largo < 2) {
        break;
      }

      if (marcador === 0xE1) {
        const inicio = offset + 4;
        const esExif = bytes[inicio] === 0x45 && bytes[inicio + 1] === 0x78
          && bytes[inicio + 2] === 0x69 && bytes[inicio + 3] === 0x66
          && bytes[inicio + 4] === 0x00 && bytes[inicio + 5] === 0x00;
        if (esExif) {
          return leerTiff(bytes, inicio + 6);
        }
      }

      offset += 2 + largo;
    }

    return vacio;
  }

  /** Interpreta el bloque TIFF del EXIF (IFD0, Exif y GPS). */
  function leerTiff(bytes, base) {
    const vacio = { tomada_en: null, latitud: null, longitud: null, orientacion: 1 };
    if (base + 8 > bytes.length) {
      return vacio;
    }

    const little = bytes[base] === 0x49 && bytes[base + 1] === 0x49;
    const big = bytes[base] === 0x4D && bytes[base + 1] === 0x4D;
    if (!little && !big) {
      return vacio;
    }

    const u16 = function (pos) {
      if (pos + 2 > bytes.length) return 0;
      return little ? bytes[pos] | (bytes[pos + 1] << 8) : (bytes[pos] << 8) | bytes[pos + 1];
    };
    const u32 = function (pos) {
      if (pos + 4 > bytes.length) return 0;
      if (little) {
        return bytes[pos] | (bytes[pos + 1] << 8) | (bytes[pos + 2] << 16) | (bytes[pos + 3] << 24);
      }
      return (bytes[pos] << 24) | (bytes[pos + 1] << 16) | (bytes[pos + 2] << 8) | bytes[pos + 3];
    };
    const tamano = function (tipo) {
      const tamanos = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8 };
      return tamanos[tipo] || 0;
    };
    const leerIfd = function (pos) {
      if (pos + 2 > bytes.length) return [];
      const total = u16(pos);
      const entradas = [];
      for (let i = 0; i < total && i < 512; i++) {
        const p = pos + 2 + (i * 12);
        if (p + 12 > bytes.length) break;
        entradas.push({
          tag: u16(p),
          tipo: u16(p + 2),
          cuenta: u32(p + 4),
          posValor: p + 8,
          offsetValor: u32(p + 8),
        });
      }
      return entradas;
    };
    const leerBytes = function (entrada) {
      const tam = tamano(entrada.tipo);
      if (tam === 0 || entrada.cuenta === 0) return null;
      const total = tam * entrada.cuenta;
      const pos = total <= 4 ? entrada.posValor : base + entrada.offsetValor;
      if (pos + total > bytes.length) return null;
      return { pos: pos, total: total };
    };
    const leerAscii = function (entrada) {
      const rango = leerBytes(entrada);
      if (!rango) return '';
      let texto = '';
      for (let i = 0; i < rango.total; i++) {
        const c = bytes[rango.pos + i];
        if (c === 0) break;
        texto += String.fromCharCode(c);
      }
      return texto;
    };
    const leerShort = function (entrada) {
      const rango = leerBytes(entrada);
      return rango ? u16(rango.pos) : 0;
    };
    const leerLong = function (entrada) {
      const rango = leerBytes(entrada);
      return rango ? u32(rango.pos) : 0;
    };
    const leerRacionales = function (entrada) {
      if (entrada.tipo !== 5 && entrada.tipo !== 10) return [];
      const rango = leerBytes(entrada);
      if (!rango) return [];
      const valores = [];
      for (let i = 0; i < entrada.cuenta; i++) {
        const p = rango.pos + (i * 8);
        const num = u32(p);
        const den = u32(p + 4);
        valores.push(den === 0 ? 0 : num / den);
      }
      return valores;
    };

    const ifd0Pos = base + u32(base + 4);
    const ifd0 = leerIfd(ifd0Pos);
    let exifPos = 0;
    let gpsPos = 0;

    ifd0.forEach(function (entrada) {
      if (entrada.tag === 0x0112) {
        vacio.orientacion = leerShort(entrada) || 1;
      } else if (entrada.tag === 0x8769) {
        exifPos = base + leerLong(entrada);
      } else if (entrada.tag === 0x8825) {
        gpsPos = base + leerLong(entrada);
      }
    });

    if (exifPos) {
      const exif = leerIfd(exifPos);
      const fechas = [0x9003, 0x9004, 0x0132];
      for (let i = 0; i < fechas.length; i++) {
        const entrada = exif.find(function (e) { return e.tag === fechas[i]; });
        if (entrada) {
          const fecha = normalizarFechaExif(leerAscii(entrada));
          if (fecha) {
            vacio.tomada_en = fecha;
            break;
          }
        }
      }
    }

    if (gpsPos) {
      const gps = leerIfd(gpsPos);
      const buscar = function (tag) {
        return gps.find(function (e) { return e.tag === tag; }) || null;
      };
      const latRef = buscar(0x0001);
      const lat = buscar(0x0002);
      const lonRef = buscar(0x0003);
      const lon = buscar(0x0004);

      if (lat) {
        const valores = leerRacionales(lat);
        if (valores.length >= 3) {
          const ref = latRef ? leerAscii(latRef) : '';
          vacio.latitud = gradosDecimales(valores, ref);
        }
      }
      if (lon) {
        const valores = leerRacionales(lon);
        if (valores.length >= 3) {
          const ref = lonRef ? leerAscii(lonRef) : '';
          vacio.longitud = gradosDecimales(valores, ref);
        }
      }
    }

    return vacio;
  }

  function normalizarFechaExif(texto) {
    const m = /^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(String(texto || '').trim());
    return m ? m[1] + '-' + m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5] + ':' + m[6] : null;
  }

  function gradosDecimales(valores, referencia) {
    if (!Array.isArray(valores) || valores.length < 3) {
      return null;
    }
    const grados = valores[0] + (valores[1] / 60) + (valores[2] / 3600);
    if (!grados) {
      return null;
    }
    const ref = String(referencia || '').trim().toUpperCase();
    return Math.round((ref === 'S' || ref === 'W' ? -grados : grados) * 1e7) / 1e7;
  }

  /** Carga la foto en algo dibujable y respeta la orientación del EXIF. */
  function cargarFoto(archivo) {
    if (typeof createImageBitmap === 'function') {
      return createImageBitmap(archivo, { imageOrientation: 'from-image' })
        .then(function (bitmap) {
          return {
            imagen: bitmap,
            ancho: bitmap.width,
            alto: bitmap.height,
            orientada: true,
            liberar: function () { if (bitmap.close) bitmap.close(); },
          };
        })
        .catch(function () {
          return cargarConImagen(archivo);
        });
    }

    return cargarConImagen(archivo);
  }

  function cargarConImagen(archivo) {
    return new Promise(function (resolve, reject) {
      const url = URL.createObjectURL(archivo);
      const img = new Image();
      img.onload = function () {
        resolve({
          imagen: img,
          ancho: img.naturalWidth,
          alto: img.naturalHeight,
          orientada: false,
          liberar: function () { URL.revokeObjectURL(url); },
        });
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error('No se pudo leer una de las fotos.'));
      };
      img.src = url;
    });
  }

  /** Dibuja la foto en un canvas, aplicando la orientación cuando hace falta. */
  function dibujarFoto(fuente, ancho, alto, orientacion) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const rotada = orientacion >= 5 && orientacion <= 8;

    canvas.width = rotada ? alto : ancho;
    canvas.height = rotada ? ancho : alto;
    if (!ctx) {
      return canvas;
    }

    // Fondo blanco: un PNG/WebP con transparencia no debe quedar negro al
    // convertirse a JPEG.
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    if (!fuente.orientada && orientacion > 1) {
      switch (orientacion) {
        case 2: ctx.transform(-1, 0, 0, 1, ancho, 0); break;
        case 3: ctx.transform(-1, 0, 0, -1, ancho, alto); break;
        case 4: ctx.transform(1, 0, 0, -1, 0, alto); break;
        case 5: ctx.transform(0, 1, 1, 0, 0, 0); break;
        case 6: ctx.transform(0, 1, -1, 0, alto, 0); break;
        case 7: ctx.transform(0, -1, -1, 0, alto, ancho); break;
        case 8: ctx.transform(0, -1, 1, 0, 0, ancho); break;
        default: break;
      }
    }

    ctx.drawImage(fuente.imagen, 0, 0, ancho, alto);
    return canvas;
  }

  /** Recompime el canvas bajando calidad y, si hace falta, dimensión. */
  function comprimirCanvas(canvas) {
    return new Promise(function (resolve, reject) {
      let lienzo = canvas;
      let calidad = 0.82;
      let intentos = 0;

      const probar = function () {
        lienzo.toBlob(function (blob) {
          if (!blob) {
            reject(new Error('No se pudo comprimir una de las fotos.'));
            return;
          }
          if (blob.size <= OBJETIVO_BYTES || intentos >= 6) {
            resolve(blob);
            return;
          }

          intentos++;
          if (calidad > 0.6) {
            calidad -= 0.08;
            probar();
            return;
          }

          const reducido = document.createElement('canvas');
          reducido.width = Math.max(640, Math.round(lienzo.width * 0.82));
          reducido.height = Math.max(640, Math.round(lienzo.height * 0.82));
          const ctx = reducido.getContext('2d');
          if (ctx) {
            ctx.drawImage(lienzo, 0, 0, reducido.width, reducido.height);
          }
          lienzo = reducido;
          calidad = 0.74;
          probar();
        }, 'image/jpeg', calidad);
      };

      probar();
    });
  }

  /**
   * Comprime una foto y devuelve el archivo nuevo con sus metadatos.
   *
   * @param {File} archivo
   * @returns {Promise<{archivo: File, meta: {tomada_en: ?string, latitud: ?number, longitud: ?number}}>}
   */
  function comprimirArchivoFoto(archivo) {
    return leerExifFoto(archivo).then(function (meta) {
      return cargarFoto(archivo).then(function (fuente) {
        const escala = Math.min(1, LADO_MAXIMO / Math.max(fuente.ancho, fuente.alto));
        const ancho = Math.max(1, Math.round(fuente.ancho * escala));
        const alto = Math.max(1, Math.round(fuente.alto * escala));
        const canvas = dibujarFoto(fuente, ancho, alto, fuente.orientada ? 1 : meta.orientacion);
        fuente.liberar();

        return comprimirCanvas(canvas).then(function (blob) {
          const nombre = String(archivo.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg';
          const nuevo = new File([blob], nombre, {
            type: 'image/jpeg',
            lastModified: archivo.lastModified || Date.now(),
          });
          return {
            archivo: nuevo,
            meta: {
              tomada_en: meta.tomada_en,
              latitud: meta.latitud,
              longitud: meta.longitud,
            },
          };
        });
      });
    });
  }

  function mostrarEstado(estado, texto, error) {
    if (!estado) {
      return;
    }
    estado.hidden = false;
    estado.textContent = texto;
    estado.style.color = error ? 'var(--color-error, #b3261e)' : '';
  }

  /**
   * Procesa el campo fotos[] de un formulario. Devuelve true si se puede
   * enviar (con fotos comprimidas o, si no hay soporte, con las originales).
   *
   * @param {HTMLFormElement} formulario
   * @returns {Promise<boolean>}
   */
  function comprimirFotosDeFormulario(formulario) {
    const input = formulario.querySelector('input[type="file"][name="fotos[]"]');
    if (!input || !input.files || !input.files.length) {
      return Promise.resolve(true);
    }
    if (formulario.dataset.fotosProcesadas === '1') {
      return Promise.resolve(true);
    }

    const puedeComprimir = typeof DataTransfer !== 'undefined'
      && typeof document.createElement('canvas').getContext === 'function';
    if (!puedeComprimir) {
      return Promise.resolve(true);
    }

    const archivos = Array.prototype.slice.call(input.files);
    const estado = formulario.querySelector('[data-estado-fotos]');
    const maximo = parseInt(input.getAttribute('data-max-bytes') || '', 10) || (15 * 1024 * 1024);

    for (let i = 0; i < archivos.length; i++) {
      if (TIPOS_FOTO.indexOf(archivos[i].type) === -1) {
        mostrarEstado(estado, 'Sólo se admiten imágenes JPG, PNG o WebP.', true);
        return Promise.resolve(false);
      }
      if (archivos[i].size > maximo) {
        mostrarEstado(estado, 'La foto ' + archivos[i].name + ' pesa más de ' + Math.round(maximo / 1048576) + ' MB.', true);
        return Promise.resolve(false);
      }
    }

    if (formulario.dataset.optimizando === '1') {
      return Promise.resolve(false);
    }
    formulario.dataset.optimizando = '1';

    const transferencia = new DataTransfer();
    const metadatos = [];
    let cadena = Promise.resolve();

    archivos.forEach(function (archivo, indice) {
      cadena = cadena.then(function () {
        mostrarEstado(estado, 'Optimizando foto ' + (indice + 1) + ' de ' + archivos.length + '…');
        return comprimirArchivoFoto(archivo).then(function (resultado) {
          transferencia.items.add(resultado.archivo);
          metadatos.push(resultado.meta);
        });
      });
    });

    return cadena.then(function () {
      try {
        input.files = transferencia.files;
      } catch (e) {
        // Sin poder reemplazar el FileList se envían las originales y el
        // servidor hace la compresión: no se pierde la entrega.
        return true;
      }

      let oculto = formulario.querySelector('input[name="fotos_meta"]');
      if (!oculto) {
        oculto = document.createElement('input');
        oculto.type = 'hidden';
        oculto.name = 'fotos_meta';
        formulario.appendChild(oculto);
      }
      oculto.value = JSON.stringify(metadatos);
      formulario.dataset.fotosProcesadas = '1';
      mostrarEstado(estado, 'Fotos listas: ' + archivos.length + ' optimizada' + (archivos.length === 1 ? '' : 's') + '.');
      return true;
    }).catch(function (error) {
      mostrarEstado(estado, error && error.message ? error.message : 'No se pudieron optimizar las fotos.', true);
      return false;
    }).then(function (resultado) {
      delete formulario.dataset.optimizando;
      return resultado;
    });
  }

  /**
   * Envía un formulario después de comprimir sus fotos. Se usa tanto en la
   * página suelta como en el modal del hub (que envía con submit() nativo).
   */
  function enviarFormularioConFotos(formulario) {
    const confirmarModal = document.querySelector('[data-confirmar-modal]');
    if (confirmarModal) {
      confirmarModal.disabled = true;
    }

    comprimirFotosDeFormulario(formulario).then(function (ok) {
      if (ok) {
        if (typeof bloquearEnvio === 'function') {
          bloquearEnvio(formulario);
        }
        formulario.submit();
        return;
      }
      if (confirmarModal) {
        confirmarModal.disabled = false;
      }
    });
  }

  /** Liga la compresión a los formularios que ya están en la página. */
  function prepararCompresionFotos() {
    document.querySelectorAll('form[data-comprimir-fotos]').forEach(function (formulario) {
      if (formulario.dataset.compressionLigada === '1') {
        return;
      }
      formulario.dataset.compressionLigada = '1';
      formulario.addEventListener('submit', function (evento) {
        if (evento.defaultPrevented || formulario.dataset.fotosProcesadas === '1') {
          return;
        }
        evento.preventDefault();
        enviarFormularioConFotos(formulario);
      });
    });

    // Si el usuario cambia la selección de fotos, los metadatos anteriores ya
    // no sirven: se vuelve a comprimir al enviar.
    document.addEventListener('change', function (evento) {
      const input = evento.target;
      if (!(input instanceof HTMLInputElement) || input.name !== 'fotos[]' || !input.form) {
        return;
      }
      delete input.form.dataset.fotosProcesadas;
      const meta = input.form.querySelector('input[name="fotos_meta"]');
      if (meta) {
        meta.value = '';
      }
      const estado = input.form.querySelector('[data-estado-fotos]');
      if (estado) {
        estado.hidden = true;
        estado.textContent = '';
      }
    });

    // Al cambiar de entrega en el detalle de la venta, se muestra su ubicación.
    const selector = document.getElementById('fotos-entrega-destino');
    const ubicacion = document.getElementById('fotos-entrega-ubicacion');
    if (selector && ubicacion) {
      const aplicar = function () {
        const opcion = selector.options[selector.selectedIndex];
        ubicacion.value = opcion ? (opcion.getAttribute('data-ubicacion') || '') : '';
      };
      selector.addEventListener('change', aplicar);
      aplicar();
    }
  }

  window.leerExifFoto = leerExifFoto;
  window.comprimirArchivoFoto = comprimirArchivoFoto;
  window.comprimirFotosDeFormulario = comprimirFotosDeFormulario;
  window.enviarFormularioConFotos = enviarFormularioConFotos;
  window.prepararCompresionFotos = prepararCompresionFotos;
})();
