#OPENCODE.md

Proyecto: App web de para realizar generar la cotizacion de los productos de los dos negocios,

Rol de agente: Desarrollador web 12 anos de exp

Objetivo: crear app web de cotizacion de productos y con ello poder enviar via correo y exportar/descargar en pdf

Funcionalidades de la app:
-login y registro 
los usuarios que se registren seran admin
crud de usuarios
curd de clientes
crud de empresas o negocios
crud de productos de cada negocio
formulario con imagenes de los productos, seleccion de cantidad para contabilizar los productos de la cotizacion
precio de los productos y posibilidad de asignar un nuevo costo en caso que haya un costo diferente o de promocion para cierto cliente
listado de los productos solicitados y monto total de la cotizacion, adicional a esto considerar que se pueda agregar iva del 16%  y descuentos para ciertos clientes
botones para poder enviar la cotizacion por correo y/o export
tener un apartado de registros de cada pedido de acuerdo a los clientes de tal forma que se pueda tener un historico de los pedidos hechos por los clientes
en cada cotizacion debe haber indicaciones adicionales (condiciones de envio, compra) con respecto a la entrega y venta de productos y un textarea para comentarios adicionales en caso que se requieran

SECCION AÑADIDA (no estaba en la especificación original; viene del diseño de la carpeta design)
- seccion de notas personales: bitacora interna y privada de cada usuario
  * categorias: recordatorio de cliente, acuerdo de precios, tarea pendiente, nota general
  * cada nota tiene titulo, contenido, fecha/recordatorio opcional y referencia o cliente asociado
  * se puede fijar arriba del tablero y marcar como hecha
  * buscador por palabra, cliente o etiqueta y chips de filtro (todas, fijadas, recordatorios, acuerdos, tareas)
  * resumen con notas activas, fijadas y acuerdos
  * acciones: editar, fijar/desfijar, marcar hecha y eliminar (esta ultima con ventana modal de confirmacion)
  * una nota es de TODOS los administradores: el tablero de Notas es una sección compartida
    (una bitácora de equipo), y `usuario_id` sólo REGISTRA QUIÉN LA ESCRIBIÓ. Por eso la
    relación con usuarios es `ON DELETE RESTRICT`: borrar a un autor nunca borra la bitácora
  * entrada en el menu lateral (junto a Productos) y en la barra inferior de movil

los usuarios deberan tener como minimo 
Nombre
RFC
telefono
email
ubicacion
vip (esto para saber cuales son los clientes mas frecuentes)
tener en cuenta la proteccion de rutas

Todas las ventanas deben ser responsive
los usuarios deberan poder editar sus datos
las ventanas modales deben tener el mismo tipo de diseno



stack de tecnologias:
html
css3
sass
php
java script (puro)
base de datos y backend (indicame que datos adicionales necesitas para que pueda hacer la base de datos en mysql para usarse en php) o en su caso vamos por partes para la creacion de la base de datos en mysql para usarse en php
base de datos mysql
intervention image para subir fotos de los productos (formato cuadrado en el guardado de la imagen)


Preferencias generales:
textos en espanol
usar estructura y backend del proyecto /home/edgar/Documentos/programacion/canastillasWep
los estilos deben ser a criterio de la ia


Preferencias diseno:
basate en el documento html del diseno que tienes en la carpeta design del proyecto

Preferencias estilos:
colores del diseno
usar medidas en rem
uso buenas practicas de maquetacion, uso de flexbox, grid layout
que la web sea responsiva

Preferencias codigo: 
html semantico
usar let y const no usar var
no usar alert, confirm o prompt dodo el feedback debe ser visual en el dom
toda alerta o ventana modal que aparezca debe tener el mismo estilo en la web
no usar innerhtml, usar appendchild o previamente creado un elemento con document.create element
cuidado con olvidar prevenir el default en los eventos submit o click
prioriza el codigo que sea legible y mantenible
documenta el codigo
si el agente tiene dudas, que revise las especificaciones del proyecto y si no que pregunte al usuario.

Estructura de archivos:
carpeta (design)
OPENCODE.md

---

APENDICE — cómo quedó interpretada la especificación (fases 4 y 5)

Cotizador (formulario de cotizacion)
  * El catálogo de productos se muestra con la FOTO de cada producto y se filtra en pantalla;
    al elegir un producto se agrega un renglón con cantidad.
  * `productos.precio` es el PRECIO DE LISTA, fijo y de sólo lectura. Cada renglón trae un
    "precio de la cotización" editable, que por defecto viene con el precio acordado con ese
    cliente (si existe) o con el de lista.
  * Precio por cliente (lo pedido en "nuevo costo en caso de costo diferente o de promoción"):
    si el precio escrito es distinto del de lista y NO se marca la casilla
    "No recordar este precio", el importe queda guardado para ESE cliente y la próxima cotización
    viene prellenada. Si se marca la casilla, el precio sólo vale para esa cotización.
    Un importe IGUAL al de lista no se guarda: eso no es un precio especial. Los precios
    especiales nunca se borran solos.
  * Descuentos: descuento por renglón (cada producto) y descuento global % de la cotización,
    precargado con el descuento de la ficha del cliente y editable en el momento.
  * I.V.A. federal del 16 % opcional con un casilla; el resumen sigue el orden del diseño:
    subtotal bruto → descuentos → base gravable → I.V.A. → total + importe con letra.
  * Folio automático `FOL-<año>-<NNNN>`, asignado dentro de la misma transacción que guarda la
    cotización, calculado con el folio más alto del año (así, si se borra una cotización, el
    siguiente folio sigue siendo correcto).
  * Condiciones de envío, condiciones de compra y un textarea de comentarios adicionales.

Salida de la cotización (PDF)
  * "Exportar PDF" genera el archivo con dompdf a partir de `templates/pdfCotizaciones.php`.
    (El envío por correo con PHPMailer existió en versiones anteriores y fue retirado: hoy la
    única salida del documento es el PDF descargable.)
  * El histórico es la lista de las cotizaciones de TODOS los clientes, con filtros por
    folio/cliente/alias/RFC, negocio, cliente, estado y rango de fechas, más estadísticas.

---

SECCION AÑADIDA (no estaba en la especificación original; grupo «Herramientas personales» del menú lateral)
- herramienta «Calendario»: recordatorios y eventos
  * calendario del MES ACTIVO con botones para navegar entre meses y entre años
    (‹‹ año, ‹ mes, mes ›, año ››) y botón «Hoy» para regresar al mes en curso
  * al hacer clic sobre un día, ese día queda seleccionado y se muestran SUS eventos
    y el formulario para capturar uno nuevo (la fecha viene precargada con el día elegido)
  * datos del recordatorio o evento: fecha (por defecto la del día seleccionado),
    nombre del evento (obligatorio), descripción en textarea (opcional),
    negocio (opcional, de las empresas del CRUD), nivel de importancia
    (bajo / medio / alto) y hora del evento (opcional: vacía = todo el día)
  * botón «Guardar»; cada evento se edita y se elimina (ésta, con ventana modal
    de confirmación, nunca window.confirm)
  * la rejilla marca el día de hoy, el día elegido y, con un punto de color,
    los días que ya tienen eventos (el color = nivel más alto de ese día)
  * resumen del mes (eventos, con hora, importancia alta) y agenda completa
    agrupada por día, que lleva a cada fecha con un clic
  * es COMPARTIDA como el resto de herramientas personales: `usuario_id` sólo
    REGISTRA QUIÉN lo capturó; la relación con usuarios es `ON DELETE RESTRICT`
  * tabla nueva `calendario_eventos` (migración 028); entrada en el menú lateral
    junto a «Notas personales» y en el cajón del celular; en el celular se usa
    completa (no en modo informativo), para poder capturar desde el teléfono
