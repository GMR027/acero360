# Acero Entregas — app Android

App nativa (Kotlin + Jetpack Compose) para la parte de entregas de Acero 360.
Usa los mismos usuarios y la misma base de ventas de la plataforma, a través
de la API `/api/entregas/v1`. No se conecta directo a MySQL y no muestra
precios, importes, IVA ni datos fiscales.

## Funciones

- Login con el correo y la contraseña actuales.
- **Animación de bienvenida tipo bóveda**: dos paneles metálicos se abren,
  aparece el logotipo y una ilustración de entregas recoloreada a la paleta de
  la app. Se puede tocar la pantalla para saltarla.
- **Acceso con huella digital** opcional: se activa en Perfil y la app la pide al
  abrirse o al volver después de un minuto en segundo plano. Si la huella falla,
  siempre queda «Entrar con contraseña».
- **Modo claro, oscuro o automático** desde Perfil; la elección se conserva en
  el teléfono.
- Todos los usuarios ven todos los pedidos confirmados con piezas pendientes.
- Agrupación por cliente, búsqueda y filtro por negocio.
- **Miniaturas de los productos** en la lista de pendientes y en las pantallas
  de detalle, entrega e incidencia.
- **Botón «Ver pedido»** en cada venta, además del toque sobre la fila.
- Entrega total o parcial, con cantidades precargadas.
- Nombre de quien recibe y notas opcionales.
- Fotos opcionales con cámara o galería; se guardan fecha/hora y GPS.
- Visitas sin entrega (cliente ausente, dirección incorrecta, rechazo, etc.).
- Incidencias de producto.
- Historial de entregas y visitas de todo el equipo.
- Hoja de entrega en **dos copias** dentro de una hoja carta horizontal.
- Acta de entrega de la venta completa y lista de preparación.
- Cola offline: las operaciones se guardan y se suben al recuperar internet.

## Servidor

La app abre por defecto con `https://cotizaciones.iguzman.com.mx`.
Para pruebas locales, en el login usa “Cambiar servidor” y escribe la dirección
del servidor de desarrollo. En producción debe ser HTTPS.

Para probar desde un teléfono en la misma red:

```bash
php -S 0.0.0.0:8090 index.php
```

y en el login escribe `http://IP_DE_LA_COMPUTADORA:8090`.

Antes de usar la app, aplica la migración en el servidor:

```bash
php scripts/migrar.php
```

La API vive en:

- `views/api/entregas.php`
- `clases/EntregasApp.php`
- `clases/IntentosEntrega.php`
- migración `mysql/migraciones/060_entregas_app.sql`

## Compilar

Requisitos: JDK 17, Android SDK con platform 36 y el wrapper incluido.

```bash
cd android-entregas
./compilar.sh
```

El APK firmado queda en `dist/acero360-entregas_1.2.0.apk`.
Para publicar una actualización hay que subir `versionCode` y `versionName` en
`app/build.gradle.kts`; Android no instala un APK con un `versionCode` menor.

Para instalarlo en un teléfono conectado por ADB:

```bash
./compilar.sh --instalar
```

## Firma

La llave de publicación vive fuera del repositorio:

```
~/.config/acero360-entregas/acero360-entregas.jks
~/.config/acero360-entregas/keystore.properties
```

**Respáldala.** Sin esa llave no se pueden publicar actualizaciones que Android
acepte sobre la app ya instalada. El script admite `ACERO360_ENTREGAS_FIRMA`
para apuntar a otro archivo de propiedades.

## Notas

- El acceso con huella sólo protege la sesión ya iniciada: no guarda la
  contraseña. Si el teléfono se queda sin huellas registradas, la app no se
  bloquea para no dejar a nadie fuera.
- La pantalla de arranque y la bóveda usan el mismo verde profundo
  (`#07120E`) para que la entrada se sienta como una sola animación.
- La ilustración de la bienvenida vive en
  `app/src/main/res/drawable/entrega_intro.xml` y su fuente editable en
  `arte/entrega_intro.svg`. Es un vector limpio (vehiculo, teléfono y cajas)
  con la paleta de la app: verdes, naranjas del logotipo y gris acero del
  camión; así se ve nítida en cualquier pantalla y no arrastra los bordes
  blancos del WebP original.
- La apariencia (`sistema`, `claro` u `oscuro`) se guarda en las preferencias
  del teléfono y se aplica también a las barras del sistema.
- La primera versión usa `usesCleartextTraffic="true"` para pruebas locales.
  Antes de publicar en producción se debe servir todo por HTTPS y desactivar
  el tráfico sin cifrar.
- Las fotos se comprimen a 1600 px antes de subirlas; los metadatos de fecha y
  GPS se envían aparte, porque la compresión elimina el EXIF.
- El PDF de entrega se genera en el servidor con las plantillas actuales:
  no se duplica la lógica de documentos en la app.
