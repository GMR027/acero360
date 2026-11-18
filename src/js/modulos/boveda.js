/**
 * modulos/boveda.js
 * ---------------------------------------------------------------------------
 * Arranca y retira la animación de bienvenida (templates/boveda.php, _boveda.scss).
 *
 * La bóveda llega opaca y en pausa (clase boveda--espera): así no asoma el panel y la
 * animación no se «gasta» donde nadie la ve. Se quita la pausa cuando:
 *  - la página está visible (no en otra pestaña ni con la app en segundo plano), y
 *  - en la app Android, ya no está la pantalla de bloqueo con huella
 *    (Acero360Android.appBloqueada(); la app avisa con el evento acero360:desbloqueada).
 * Al terminar la animación (o 5 s después de arrancar, por si no llega animationend)
 * se retira el nodo.
 */
(function () {
  'use strict';

  function appBloqueada() {
    const app = window.Acero360Android;
    try {
      return !!(app && typeof app.appBloqueada === 'function' && app.appBloqueada());
    } catch (e) {
      return false;
    }
  }

  function prepararBoveda() {
    const boveda = document.querySelector('[data-boveda]');
    if (!boveda) {
      return;
    }

    const retirar = function () {
      if (boveda.isConnected) {
        boveda.remove();
      }
    };
    boveda.addEventListener('animationend', function (evento) {
      if (evento.target === boveda && evento.animationName === 'boveda-salida') {
        retirar();
      }
    });

    let arrancada = false;
    const intentar = function () {
      if (arrancada || document.visibilityState === 'hidden' || appBloqueada()) {
        return;
      }
      arrancada = true;
      document.removeEventListener('visibilitychange', intentar);
      window.removeEventListener('acero360:desbloqueada', intentar);
      // Un cuadro de espera: que el navegador pinte la bóveda cerrada antes de moverla.
      window.requestAnimationFrame(function () {
        boveda.classList.remove('boveda--espera');
        window.setTimeout(retirar, 5000);
      });
    };

    document.addEventListener('visibilitychange', intentar);
    window.addEventListener('acero360:desbloqueada', intentar);
    intentar();
  }

  // Se arranca sola (no desde app.js): si otro módulo fallara, la bóveda no debe quedarse tapando.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', prepararBoveda);
  } else {
    prepararBoveda();
  }
})();
