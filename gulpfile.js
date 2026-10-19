/**
 * gulpfile.js — Compilación de assets del proyecto de cotizaciones.
 *
 * Tareas:
 *   gulp build   -> compila SCSS a build/css/app.css y concatena JS en build/js/app.js
 *   gulp watch   -> observa cambios en src/scss y src/js y recompila
 *
 * Nota: a diferencia de canastillasWep, la tarea "build" COMPILA de verdad
 * (en el proyecto original "build" solo levantaba los watch).
 */
import { src, dest, watch, series, parallel } from 'gulp';
import * as dartSass from 'sass';
import gulpSass from 'gulp-sass';
import concat from 'gulp-concat';

const sass = gulpSass(dartSass);

/** Rutas base del proyecto. */
const rutas = {
  scss: 'src/scss/app.scss',
  scssWatch: 'src/scss/**/*.scss',
  js: 'src/js/**/*.js',
  cssOut: 'build/css',
  jsOut: 'build/js'
};

/** Compila los archivos SCSS a CSS. */
export function css(done) {
  src(rutas.scss)
    .pipe(sass({ outputStyle: 'expanded' }).on('error', sass.logError))
    .pipe(dest(rutas.cssOut));
  done();
}

/** Concatena los módulos JS en un único archivo app.js. */
export function js(done) {
  src(rutas.js)
    .pipe(concat('app.js'))
    .pipe(dest(rutas.jsOut));
  done();
}

/** Observa los archivos fuente y recompila ante cambios. */
export function watchArchivos() {
  watch(rutas.scssWatch, css);
  watch(rutas.js, js);
}

/** Compilación completa (lo que ejecuta `npm run build`). */
export const build = series(parallel(css, js));

/** Tarea por defecto: compila y queda observando. */
export default series(build, watchArchivos);
