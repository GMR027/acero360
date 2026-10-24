import { chromium } from 'playwright';
const BASE='http://127.0.0.1:8030';
const hoy=new Date();
const n=await chromium.launch();
const c=await n.newContext({viewport:{width:1440,height:1000}});
const p=await c.newPage();
const errores=[];
p.on('pageerror',e=>errores.push('pageerror: '+e.message));
p.on('console',m=>{if(m.type()==='error')errores.push('console: '+m.text().slice(0,150));});
await p.goto(`${BASE}/login`,{waitUntil:'domcontentloaded'});
await p.fill('#email','calendario.test@test.local'); await p.fill('#contrasena','Prueba1234!');
await Promise.all([p.waitForURL('**/admin**'),p.click('button[type="submit"]')]);
await p.goto(`${BASE}/admin/calendario`,{waitUntil:'networkidle'});

const dia=15;
const casilla=()=>p.locator(`a.calendario__dia[data-fecha$="-${String(dia).padStart(2,'0')}"]`).first();

console.log('--- 1) primer clic: selecciona y recarga ---');
await casilla().click(); await p.waitForLoadState('networkidle');
console.log('   url dia =', new URL(p.url()).searchParams.get('dia'), '· seleccionada =', await casilla().getAttribute('aria-current'));
console.log('   formulario en el panel:', await p.locator('.calendario-dia [data-formulario-alta]').count(), '(debe ser 0)');
console.log('   botón en el panel:', await p.locator('[data-alta-calendario]').count());

console.log('--- 2) segundo clic: abre el modal sin recargar ---');
const urlAntes=p.url();
await casilla().click();
await p.waitForSelector('#modal-principal:not([hidden])',{timeout:8000});
console.log('   modal abierto =', !(await p.locator('#modal-principal').isHidden()));
console.log('   sin recarga =', p.url()===urlAntes, '| url =', new URL(p.url()).searchParams.get('dia'));
console.log('   título =', (await p.locator('#modal-titulo').textContent()).trim());
console.log('   fecha cargada =', await p.locator('#modal-cuerpo #fecha').inputValue());
console.log('   botones propios del form ocultos =', !(await p.locator('#modal-cuerpo .formulario__acciones').isVisible()));
console.log('   pie del modal =', (await p.locator('#modal-pie').textContent()).replace(/\s+/g,' ').trim());
await p.screenshot({path:'/tmp/opencode/cal-modal.png'});

console.log('--- 3) guardar desde el modal ---');
await p.fill('#modal-cuerpo #nombre','T Modal Evento');
await p.fill('#modal-cuerpo #descripcion','Creado desde el modal.');
const nav=p.waitForNavigation({waitUntil:'load',timeout:15000}).catch(()=>null);
await p.click('#modal-pie [data-confirmar-modal]');
await nav; await p.waitForTimeout(400);
const aviso=(await p.locator('.alerta').allTextContents().catch(()=>[])).join(' | ');
console.log('   aviso =', aviso.trim()||'(ninguno)');
console.log('   evento en el panel =', await p.locator('.calendario-dia .evento-tarjeta:has-text("T Modal Evento")').count());

console.log('--- 4) cancelar devuelve el formulario ---');
await casilla().click();
await p.waitForSelector('#modal-principal:not([hidden])',{timeout:8000});
await p.click('#modal-pie button[data-cerrar-modal]');
await p.waitForTimeout(300);
console.log('   modal cerrado =', await p.locator('#modal-principal').isHidden());
console.log('   form regresó al contenedor =', await p.locator('[data-alta-calendario-contenedor] [data-formulario-alta]').count());
console.log('   nombre limpio =', JSON.stringify(await p.locator('[data-alta-calendario-contenedor] #nombre').inputValue()));
await casilla().click();
await p.waitForSelector('#modal-principal:not([hidden])',{timeout:8000});
console.log('   se reabre bien =', !(await p.locator('#modal-principal').isHidden()));

console.log('--- 5) botón del panel ---');
await p.click('#modal-pie button[data-cerrar-modal]'); await p.waitForTimeout(200);
await p.click('[data-alta-calendario]');
await p.waitForSelector('#modal-principal:not([hidden])',{timeout:8000});
console.log('   abre el modal =', !(await p.locator('#modal-principal').isHidden()), '· fecha =', await p.locator('#modal-cuerpo #fecha').inputValue());

console.log('\nerrores de consola:', errores.length?errores:'(ninguno)');
await n.close();
