/**
 * gulpfile.js — Compilación de assets del proyecto de cotizaciones.
 *
 * Tareas:
 *   gulp build   -> compila SCSS a build/css/app.css, concatena JS en build/js/app.js y copia ECharts
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
export function css() {
  // Devolver el stream es obligatorio: si se llama a done() antes de que
  // termine la escritura, Gulp da la tarea por acabada y el proceso puede
  // salir dejando el CSS anterior (fallo silencioso en el despliegue).
  return src(rutas.scss)
    .pipe(sass({ outputStyle: 'expanded' }).on('error', sass.logError))
    .pipe(dest(rutas.cssOut));
}

/** Concatena los módulos JS en un único archivo app.js. */
export function js() {
  return src(rutas.js)
    .pipe(concat('app.js'))
    .pipe(dest(rutas.jsOut));
}

/** Copia Apache ECharts a build/js (solo lo carga la página de estadísticas). */
export function graficas() {
  return src('node_modules/echarts/dist/echarts.min.js', { encoding: false })
    .pipe(dest(rutas.jsOut));
}

/** Copia Leaflet a build/ (solo lo carga el mapa de ventas de estadísticas). */
export function mapas() {
  const js = src('node_modules/leaflet/dist/leaflet.js', { encoding: false })
    .pipe(dest(rutas.jsOut));
  const css = src('node_modules/leaflet/dist/leaflet.css', { encoding: false })
    .pipe(dest(rutas.cssOut));
  const imagenes = src('node_modules/leaflet/dist/images/*', { encoding: false })
    .pipe(dest(rutas.cssOut + '/images'));
  return Promise.all([streamDone(js), streamDone(css), streamDone(imagenes)]);
}

/** Envuelve un stream en una promesa para poder esperarlo en tareas paralelas. */
function streamDone(stream) {
  return new Promise((resolve, reject) => {
    stream.on('finish', resolve);
    stream.on('error', reject);
  });
}

/** Observa los archivos fuente y recompila ante cambios. */
export function watchArchivos() {
  watch(rutas.scssWatch, css);
  watch(rutas.js, js);
}

/** Compilación completa (lo que ejecuta `npm run build`). */
export const build = series(parallel(css, js, graficas, mapas));

/** Tarea por defecto: compila y queda observando. */
export default series(build, watchArchivos);
