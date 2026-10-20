// Copia la versión del archivo VERSION (única fuente) al package.json de la app de escritorio.
// Se ejecuta solo antes de empaquetar (npm run empaquetar, en desktop/).
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const version = readFileSync(join(raiz, 'VERSION'), 'utf8').trim();
const ruta = join(raiz, 'desktop', 'package.json');
const paquete = JSON.parse(readFileSync(ruta, 'utf8'));
if (paquete.version !== version) {
  paquete.version = version;
  writeFileSync(ruta, JSON.stringify(paquete, null, 2) + '\n');
  console.log('desktop/package.json -> ' + version);
}
