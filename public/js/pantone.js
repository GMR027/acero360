/* ==========================================================================
   pantone.js — Paleta de colores Pantone para Redactor, SheetFlow y Editor.

   Datos extraídos de la tabla Pantone de logorapid.com/pantone
   (nombre de la muestra + valor RGB en hexadecimal). 985 colores únicos.

   Al cargarse añade un botón de paleta junto a cada input[type=color] de la
   página y abre un selector con búsqueda y filtro por familia. Al elegir una
   muestra fija el valor del input y dispara 'input'/'change', así que las
   herramientas existentes aplican el color sin cambios adicionales.
   ========================================================================== */
(function () {
    'use strict';

    /* ==========================================================================
       Paleta Pantone — datos y selector compartidos por Redactor, SheetFlow y Editor.

       · Al cargar, coloca un botón de paleta junto a cada input[type=color] y
         abre un selector con las 985 muestras de la tabla Pantone de
         logorapid.com/pantone (nombre + hex), con búsqueda y filtro por familia.
       · Al elegir una muestra se fija el valor del input y se disparan los
         eventos 'input' y 'change', de modo que las herramientas que ya escuchan
         esos eventos aplican el color sin más cambios.
       · El botón también se engancha a los inputs creados después (MutationObserver),
         por si una herramienta abre un diálogo con colores nuevos.
       ========================================================================== */
    (function () {
        'use strict';

        var COLORES = [{"n":"Black","h":"#3d332b"},{"n":"Black 2","h":"#3a3321"},{"n":"Black 2 2X","h":"#111111"},{"n":"Black 3","h":"#282d26"},{"n":"Black 3 2X","h":"#111114"},{"n":"Black 4","h":"#3d3023"},{"n":"Black 4 2X","h":"#0f0f0f"},{"n":"Black 5","h":"#422d2d"},{"n":"Black 5 2X","h":"#110c0f"},{"n":"Black 6","h":"#1c2630"},{"n":"Black 6 2X","h":"#070c0f"},{"n":"Black 7","h":"#443d38"},{"n":"Black 7 2X","h":"#33302b"},{"n":"Blue 072","h":"#380096"},{"n":"Cool Gray 1","h":"#e8e2d6"},{"n":"Cool Gray 2","h":"#ddd8ce"},{"n":"Cool Gray 3","h":"#d3cec4"},{"n":"Cool Gray 4","h":"#c4c1ba"},{"n":"Cool Gray 5","h":"#bab7af"},{"n":"Cool Gray 6","h":"#b5b2aa"},{"n":"Cool Gray 7","h":"#a5a39e"},{"n":"Cool Gray 8","h":"#9b9993"},{"n":"Cool Gray 9","h":"#8c8984"},{"n":"Cool Gray 10","h":"#777772"},{"n":"Cool Gray 11","h":"#686663"},{"n":"Green","h":"#00af93"},{"n":"Green 2X","h":"#009677"},{"n":"Orange 021","h":"#ef6b00"},{"n":"Pantone Yellow","h":"#ad9b0c"},{"n":"PMS 100","h":"#f4ed7c"},{"n":"PMS 101","h":"#f4ed47"},{"n":"PMS 102","h":"#f9e814"},{"n":"PMS 103","h":"#c6ad0f"},{"n":"PMS 105","h":"#82750f"},{"n":"PMS 106","h":"#f7e859"},{"n":"PMS 107","h":"#f9e526"},{"n":"PMS 108","h":"#f7dd16"},{"n":"PMS 109","h":"#f9d616"},{"n":"PMS 110","h":"#d8b511"},{"n":"PMS 111","h":"#aa930a"},{"n":"PMS 112","h":"#99840a"},{"n":"PMS 113","h":"#f9e55b"},{"n":"PMS 114","h":"#f9e24c"},{"n":"PMS 115","h":"#f9e04c"},{"n":"PMS 116","h":"#fcd116"},{"n":"PMS 116 2X","h":"#f7b50c"},{"n":"PMS 117","h":"#c6a00c"},{"n":"PMS 118","h":"#aa8e0a"},{"n":"PMS 119","h":"#897719"},{"n":"PMS 120","h":"#f9e27f"},{"n":"PMS 121","h":"#f9e070"},{"n":"PMS 122","h":"#fcd856"},{"n":"PMS 123","h":"#ffc61e"},{"n":"PMS 124","h":"#e0aa0f"},{"n":"PMS 125","h":"#b58c0a"},{"n":"PMS 127","h":"#f4e287"},{"n":"PMS 128","h":"#f4db60"},{"n":"PMS 129","h":"#f2d13d"},{"n":"PMS 130","h":"#eaaf0f"},{"n":"PMS 130 2X","h":"#e29100"},{"n":"PMS 131","h":"#c6930a"},{"n":"PMS 132","h":"#9e7c0a"},{"n":"PMS 133","h":"#705b0a"},{"n":"PMS 134","h":"#ffd87f"},{"n":"PMS 135","h":"#fcc963"},{"n":"PMS 136","h":"#fcbf49"},{"n":"PMS 137","h":"#fca311"},{"n":"PMS 138","h":"#d88c02"},{"n":"PMS 139","h":"#af7505"},{"n":"PMS 140","h":"#7a5b11"},{"n":"PMS 141","h":"#f2ce68"},{"n":"PMS 142","h":"#f2bf49"},{"n":"PMS 143","h":"#efb22d"},{"n":"PMS 144","h":"#e28c05"},{"n":"PMS 145","h":"#c67f07"},{"n":"PMS 146","h":"#9e6b05"},{"n":"PMS 147","h":"#725e26"},{"n":"PMS 148","h":"#ffd69b"},{"n":"PMS 149","h":"#fccc93"},{"n":"PMS 150","h":"#fcad56"},{"n":"PMS 151","h":"#f77f00"},{"n":"PMS 152","h":"#dd7500"},{"n":"PMS 153","h":"#bc6d0a"},{"n":"PMS 154","h":"#995905"},{"n":"PMS 155","h":"#f4dbaa"},{"n":"PMS 156","h":"#f2c68c"},{"n":"PMS 157","h":"#eda04f"},{"n":"PMS 158","h":"#e87511"},{"n":"PMS 159","h":"#c66005"},{"n":"PMS 160","h":"#9e540a"},{"n":"PMS 161","h":"#633a11"},{"n":"PMS 162","h":"#f9c6aa"},{"n":"PMS 163","h":"#fc9e70"},{"n":"PMS 164","h":"#fc7f3f"},{"n":"PMS 165","h":"#f96302"},{"n":"PMS 165 2X","h":"#ea4f00"},{"n":"PMS 166","h":"#dd5900"},{"n":"PMS 167","h":"#bc4f07"},{"n":"PMS 168","h":"#6d3011"},{"n":"PMS 169","h":"#f9baaa"},{"n":"PMS 170","h":"#f98972"},{"n":"PMS 171","h":"#f9603a"},{"n":"PMS 172","h":"#f74902"},{"n":"PMS 173","h":"#d14414"},{"n":"PMS 174","h":"#933311"},{"n":"PMS 175","h":"#6d3321"},{"n":"PMS 176","h":"#f9afad"},{"n":"PMS 177","h":"#f9827f"},{"n":"PMS 178","h":"#f95e59"},{"n":"PMS 179","h":"#e23d28"},{"n":"PMS 180","h":"#c13828"},{"n":"PMS 181","h":"#7c2d23"},{"n":"PMS 182","h":"#f9bfc1"},{"n":"PMS 183","h":"#fc8c99"},{"n":"PMS 184","h":"#fc5e72"},{"n":"PMS 185","h":"#e8112d"},{"n":"PMS 186","h":"#ce1126"},{"n":"PMS 187","h":"#af1e2d"},{"n":"PMS 188","h":"#7c2128"},{"n":"PMS 189","h":"#ffa3b2"},{"n":"PMS 190","h":"#fc758e"},{"n":"PMS 191","h":"#f4476b"},{"n":"PMS 192","h":"#e5053a"},{"n":"PMS 193","h":"#bf0a30"},{"n":"PMS 194","h":"#992135"},{"n":"PMS 195","h":"#772d35"},{"n":"PMS 196","h":"#f4c9c9"},{"n":"PMS 197","h":"#ef99a3"},{"n":"PMS 198","h":"#e5566d"},{"n":"PMS 199","h":"#d81c3f"},{"n":"PMS 200","h":"#c41e3a"},{"n":"PMS 201","h":"#a32638"},{"n":"PMS 202","h":"#8c2633"},{"n":"PMS 203","h":"#f2afc1"},{"n":"PMS 204","h":"#ed7a9e"},{"n":"PMS 205","h":"#e54c7c"},{"n":"PMS 206","h":"#d30547"},{"n":"PMS 207","h":"#af003d"},{"n":"PMS 208","h":"#8e2344"},{"n":"PMS 209","h":"#75263d"},{"n":"PMS 210","h":"#ffa0bf"},{"n":"PMS 211","h":"#ff77a8"},{"n":"PMS 212","h":"#f94f8e"},{"n":"PMS 213","h":"#ea0f6b"},{"n":"PMS 214","h":"#cc0256"},{"n":"PMS 215","h":"#a50544"},{"n":"PMS 216","h":"#7c1e3f"},{"n":"PMS 217","h":"#f4bfd1"},{"n":"PMS 218","h":"#ed72aa"},{"n":"PMS 219","h":"#e22882"},{"n":"PMS 220","h":"#aa004f"},{"n":"PMS 221","h":"#930042"},{"n":"PMS 222","h":"#70193d"},{"n":"PMS 223","h":"#f993c4"},{"n":"PMS 224","h":"#f46baf"},{"n":"PMS 225","h":"#ed2893"},{"n":"PMS 226","h":"#d60270"},{"n":"PMS 227","h":"#ad005b"},{"n":"PMS 228","h":"#8c004c"},{"n":"PMS 229","h":"#6d213f"},{"n":"PMS 230","h":"#ffa0cc"},{"n":"PMS 231","h":"#fc70ba"},{"n":"PMS 232","h":"#f43fa5"},{"n":"PMS 233","h":"#ce007c"},{"n":"PMS 234","h":"#aa0066"},{"n":"PMS 235","h":"#8e0554"},{"n":"PMS 236","h":"#f9afd3"},{"n":"PMS 237","h":"#f484c4"},{"n":"PMS 238","h":"#ed4faf"},{"n":"PMS 239","h":"#e0219e"},{"n":"PMS 239 2X","h":"#c4057c"},{"n":"PMS 240","h":"#c40f89"},{"n":"PMS 241","h":"#ad0075"},{"n":"PMS 242","h":"#7c1c51"},{"n":"PMS 243","h":"#f2bad8"},{"n":"PMS 244","h":"#eda0d3"},{"n":"PMS 245","h":"#e87fc9"},{"n":"PMS 246","h":"#cc00a0"},{"n":"PMS 247","h":"#b7008e"},{"n":"PMS 248","h":"#a3057f"},{"n":"PMS 249","h":"#7f2860"},{"n":"PMS 250","h":"#edc4dd"},{"n":"PMS 251","h":"#e29ed6"},{"n":"PMS 252","h":"#d36bc6"},{"n":"PMS 253","h":"#af23a5"},{"n":"PMS 254","h":"#a02d96"},{"n":"PMS 255","h":"#772d6b"},{"n":"PMS 256","h":"#e5c4d6"},{"n":"PMS 257","h":"#d3a5c9"},{"n":"PMS 258","h":"#9b4f96"},{"n":"PMS 259","h":"#72166b"},{"n":"PMS 260","h":"#681e5b"},{"n":"PMS 261","h":"#5e2154"},{"n":"PMS 262","h":"#542344"},{"n":"PMS 263","h":"#e0cee0"},{"n":"PMS 264","h":"#c6aadb"},{"n":"PMS 265","h":"#9663c4"},{"n":"PMS 266","h":"#6d28aa"},{"n":"PMS 267","h":"#59118e"},{"n":"PMS 268","h":"#4f2170"},{"n":"PMS 269","h":"#442359"},{"n":"PMS 270","h":"#baafd3"},{"n":"PMS 271","h":"#9e91c6"},{"n":"PMS 272","h":"#8977ba"},{"n":"PMS 273","h":"#38197a"},{"n":"PMS 274","h":"#2b1166"},{"n":"PMS 275","h":"#260f54"},{"n":"PMS 276","h":"#2b2147"},{"n":"PMS 277","h":"#b5d1e8"},{"n":"PMS 278","h":"#99badd"},{"n":"PMS 279","h":"#6689cc"},{"n":"PMS 280","h":"#002b7f"},{"n":"PMS 281","h":"#002868"},{"n":"PMS 282","h":"#002654"},{"n":"PMS 283","h":"#9bc4e2"},{"n":"PMS 284","h":"#75aadb"},{"n":"PMS 285","h":"#3a75c4"},{"n":"PMS 286","h":"#0038a8"},{"n":"PMS 287","h":"#003893"},{"n":"PMS 288","h":"#00337f"},{"n":"PMS 289","h":"#002649"},{"n":"PMS 290","h":"#c4d8e2"},{"n":"PMS 291","h":"#a8cee2"},{"n":"PMS 292","h":"#75b2dd"},{"n":"PMS 293","h":"#0051ba"},{"n":"PMS 294","h":"#003f87"},{"n":"PMS 295","h":"#00386b"},{"n":"PMS 296","h":"#002d47"},{"n":"PMS 297","h":"#82c6e2"},{"n":"PMS 298","h":"#51b5e0"},{"n":"PMS 299","h":"#00a3dd"},{"n":"PMS 299 2X","h":"#007fcc"},{"n":"PMS 300","h":"#0072c6"},{"n":"PMS 301","h":"#005b99"},{"n":"PMS 302","h":"#004f6d"},{"n":"PMS 303","h":"#003f54"},{"n":"PMS 304","h":"#a5dde2"},{"n":"PMS 305","h":"#70cee2"},{"n":"PMS 306","h":"#00bce2"},{"n":"PMS 306 2X","h":"#00a3d1"},{"n":"PMS 307","h":"#007aa5"},{"n":"PMS 308","h":"#00607c"},{"n":"PMS 309","h":"#003f49"},{"n":"PMS 310","h":"#72d1dd"},{"n":"PMS 311","h":"#28c4d8"},{"n":"PMS 312","h":"#00adc6"},{"n":"PMS 313","h":"#0099b5"},{"n":"PMS 314","h":"#00829b"},{"n":"PMS 315","h":"#006b77"},{"n":"PMS 316","h":"#00494f"},{"n":"PMS 317","h":"#c9e8dd"},{"n":"PMS 318","h":"#93dddb"},{"n":"PMS 319","h":"#4cced1"},{"n":"PMS 320","h":"#009ea0"},{"n":"PMS 320 2X","h":"#007f82"},{"n":"PMS 321","h":"#008789"},{"n":"PMS 322","h":"#007272"},{"n":"PMS 323","h":"#006663"},{"n":"PMS 324","h":"#aaddd6"},{"n":"PMS 325","h":"#56c9c1"},{"n":"PMS 326","h":"#00b2aa"},{"n":"PMS 327","h":"#008c82"},{"n":"PMS 327 2X","h":"#008977"},{"n":"PMS 328","h":"#007770"},{"n":"PMS 329","h":"#006d66"},{"n":"PMS 330","h":"#005951"},{"n":"PMS 331","h":"#baead6"},{"n":"PMS 332","h":"#a0e5ce"},{"n":"PMS 333","h":"#5eddc1"},{"n":"PMS 334","h":"#00997c"},{"n":"PMS 335","h":"#007c66"},{"n":"PMS 336","h":"#006854"},{"n":"PMS 337","h":"#9bdbc1"},{"n":"PMS 338","h":"#7ad1b5"},{"n":"PMS 339","h":"#00b28c"},{"n":"PMS 340","h":"#009977"},{"n":"PMS 341","h":"#007a5e"},{"n":"PMS 342","h":"#006b54"},{"n":"PMS 343","h":"#00563f"},{"n":"PMS 344","h":"#b5e2bf"},{"n":"PMS 345","h":"#96d8af"},{"n":"PMS 346","h":"#70ce9b"},{"n":"PMS 347","h":"#009e60"},{"n":"PMS 348","h":"#008751"},{"n":"PMS 349","h":"#006b3f"},{"n":"PMS 350","h":"#234f33"},{"n":"PMS 351","h":"#b5e8bf"},{"n":"PMS 352","h":"#99e5b2"},{"n":"PMS 353","h":"#84e2a8"},{"n":"PMS 354","h":"#00b760"},{"n":"PMS 354 2X","h":"#009944"},{"n":"PMS 355","h":"#009e49"},{"n":"PMS 356","h":"#007a3d"},{"n":"PMS 357","h":"#215b33"},{"n":"PMS 358","h":"#aadd96"},{"n":"PMS 359","h":"#a0db8e"},{"n":"PMS 360","h":"#60c659"},{"n":"PMS 361","h":"#1eb53a"},{"n":"PMS 362","h":"#339e35"},{"n":"PMS 363","h":"#3d8e33"},{"n":"PMS 364","h":"#3a7728"},{"n":"PMS 365","h":"#d3e8a3"},{"n":"PMS 366","h":"#c4e58e"},{"n":"PMS 367","h":"#aadd6d"},{"n":"PMS 368","h":"#5bbf21"},{"n":"PMS 368 2X","h":"#009e0f"},{"n":"PMS 369","h":"#56aa1c"},{"n":"PMS 370","h":"#568e14"},{"n":"PMS 371","h":"#566b21"},{"n":"PMS 372","h":"#d8ed96"},{"n":"PMS 373","h":"#ceea82"},{"n":"PMS 374","h":"#bae860"},{"n":"PMS 375","h":"#8cd600"},{"n":"PMS 375 2X","h":"#54bc00"},{"n":"PMS 376","h":"#7fba00"},{"n":"PMS 377","h":"#709302"},{"n":"PMS 378","h":"#566314"},{"n":"PMS 379","h":"#e0ea68"},{"n":"PMS 380","h":"#d6e542"},{"n":"PMS 381","h":"#cce226"},{"n":"PMS 382","h":"#bad80a"},{"n":"PMS 382 2X","h":"#9ec400"},{"n":"PMS 383","h":"#a3af07"},{"n":"PMS 384","h":"#939905"},{"n":"PMS 385","h":"#707014"},{"n":"PMS 386","h":"#e8ed60"},{"n":"PMS 387","h":"#e0ed44"},{"n":"PMS 388","h":"#d6e80f"},{"n":"PMS 389","h":"#cee007"},{"n":"PMS 390","h":"#bac405"},{"n":"PMS 391","h":"#9e9e07"},{"n":"PMS 392","h":"#848205"},{"n":"PMS 393","h":"#f2ef87"},{"n":"PMS 394","h":"#eaed35"},{"n":"PMS 395","h":"#e5e811"},{"n":"PMS 396","h":"#e0e20c"},{"n":"PMS 397","h":"#c1bf0a"},{"n":"PMS 398","h":"#afa80a"},{"n":"PMS 399","h":"#998e07"},{"n":"PMS 400","h":"#d1c6b5"},{"n":"PMS 401","h":"#c1b5a5"},{"n":"PMS 402","h":"#afa593"},{"n":"PMS 403","h":"#998c7c"},{"n":"PMS 404","h":"#827566"},{"n":"PMS 405","h":"#6b5e4f"},{"n":"PMS 406","h":"#cec1b5"},{"n":"PMS 407","h":"#baaa9e"},{"n":"PMS 408","h":"#a8998c"},{"n":"PMS 409","h":"#99897c"},{"n":"PMS 410","h":"#7c6d63"},{"n":"PMS 411","h":"#66594c"},{"n":"PMS 412","h":"#3d3028"},{"n":"PMS 413","h":"#c6c1b2"},{"n":"PMS 414","h":"#b5afa0"},{"n":"PMS 415","h":"#a39e8c"},{"n":"PMS 416","h":"#8e8c7a"},{"n":"PMS 417","h":"#777263"},{"n":"PMS 418","h":"#605e4f"},{"n":"PMS 419","h":"#282821"},{"n":"PMS 420","h":"#d1ccbf"},{"n":"PMS 421","h":"#bfbaaf"},{"n":"PMS 422","h":"#afaaa3"},{"n":"PMS 423","h":"#96938e"},{"n":"PMS 424","h":"#827f77"},{"n":"PMS 425","h":"#60605b"},{"n":"PMS 426","h":"#2b2b28"},{"n":"PMS 427","h":"#dddbd1"},{"n":"PMS 428","h":"#d1cec6"},{"n":"PMS 429","h":"#adafaa"},{"n":"PMS 430","h":"#919693"},{"n":"PMS 431","h":"#666d70"},{"n":"PMS 432","h":"#444f51"},{"n":"PMS 433","h":"#30383a"},{"n":"PMS 433 2X","h":"#0a0c11"},{"n":"PMS 434","h":"#e0d1c6"},{"n":"PMS 435","h":"#d3bfb7"},{"n":"PMS 436","h":"#bca59e"},{"n":"PMS 437","h":"#8c706b"},{"n":"PMS 438","h":"#593f3d"},{"n":"PMS 439","h":"#493533"},{"n":"PMS 440","h":"#3f302b"},{"n":"PMS 441","h":"#d1d1c6"},{"n":"PMS 442","h":"#babfb7"},{"n":"PMS 443","h":"#a3a8a3"},{"n":"PMS 444","h":"#898e8c"},{"n":"PMS 445","h":"#565959"},{"n":"PMS 446","h":"#494c49"},{"n":"PMS 447","h":"#3f3f38"},{"n":"PMS 448","h":"#54472d"},{"n":"PMS 449","h":"#544726"},{"n":"PMS 450","h":"#60542b"},{"n":"PMS 451","h":"#ada07a"},{"n":"PMS 452","h":"#c4b796"},{"n":"PMS 453","h":"#d6ccaf"},{"n":"PMS 454","h":"#e2d8bf"},{"n":"PMS 455","h":"#665614"},{"n":"PMS 456","h":"#998714"},{"n":"PMS 457","h":"#b59b0c"},{"n":"PMS 458","h":"#ddcc6b"},{"n":"PMS 459","h":"#e2d67c"},{"n":"PMS 460","h":"#eadd96"},{"n":"PMS 461","h":"#ede5ad"},{"n":"PMS 462","h":"#5b4723"},{"n":"PMS 463","h":"#755426"},{"n":"PMS 464","h":"#876028"},{"n":"PMS 464 2X","h":"#704214"},{"n":"PMS 465","h":"#c1a875"},{"n":"PMS 466","h":"#d1bf91"},{"n":"PMS 467","h":"#ddcca5"},{"n":"PMS 468","h":"#e2d6b5"},{"n":"PMS 469","h":"#603311"},{"n":"PMS 471 2X","h":"#a34402"},{"n":"PMS 476","h":"#593d2b"},{"n":"PMS 477","h":"#633826"},{"n":"PMS 478","h":"#7a3f28"},{"n":"PMS 479","h":"#af8970"},{"n":"PMS 480","h":"#d3b7a3"},{"n":"PMS 481","h":"#e0ccba"},{"n":"PMS 482","h":"#e5d3c1"},{"n":"PMS 483","h":"#6b3021"},{"n":"PMS 484","h":"#9b301c"},{"n":"PMS 485","h":"#d81e05"},{"n":"PMS 485 2X","h":"#cc0c00"},{"n":"PMS 486","h":"#ed9e84"},{"n":"PMS 487","h":"#efb5a0"},{"n":"PMS 488","h":"#f2c4af"},{"n":"PMS 489","h":"#f2d1bf"},{"n":"PMS 490","h":"#5b2626"},{"n":"PMS 491","h":"#752828"},{"n":"PMS 492","h":"#913338"},{"n":"PMS 493","h":"#db828c"},{"n":"PMS 494","h":"#f2adb2"},{"n":"PMS 495","h":"#f4bcbf"},{"n":"PMS 496","h":"#f7c9c6"},{"n":"PMS 497","h":"#512826"},{"n":"PMS 498","h":"#6d332b"},{"n":"PMS 499","h":"#7a382d"},{"n":"PMS 500","h":"#ce898c"},{"n":"PMS 501","h":"#eab2b2"},{"n":"PMS 502","h":"#f2c6c4"},{"n":"PMS 503","h":"#f4d1cc"},{"n":"PMS 504","h":"#511e26"},{"n":"PMS 505","h":"#661e2b"},{"n":"PMS 506","h":"#7a2638"},{"n":"PMS 507","h":"#d8899b"},{"n":"PMS 508","h":"#e8a5af"},{"n":"PMS 509","h":"#f2babf"},{"n":"PMS 510","h":"#f4c6c9"},{"n":"PMS 511","h":"#602144"},{"n":"PMS 512","h":"#84216b"},{"n":"PMS 513","h":"#9e2387"},{"n":"PMS 514","h":"#d884bc"},{"n":"PMS 515","h":"#e8a3c9"},{"n":"PMS 516","h":"#f2bad3"},{"n":"PMS 517","h":"#f4ccd8"},{"n":"PMS 518","h":"#512d44"},{"n":"PMS 519","h":"#63305e"},{"n":"PMS 520","h":"#703572"},{"n":"PMS 521","h":"#b58cb2"},{"n":"PMS 522","h":"#c6a3c1"},{"n":"PMS 523","h":"#d3b7cc"},{"n":"PMS 524","h":"#e2ccd3"},{"n":"PMS 525","h":"#512654"},{"n":"PMS 526","h":"#68217a"},{"n":"PMS 527","h":"#7a1e99"},{"n":"PMS 528","h":"#af72c1"},{"n":"PMS 529","h":"#cea3d3"},{"n":"PMS 530","h":"#d6afd6"},{"n":"PMS 531","h":"#e5c6db"},{"n":"PMS 532","h":"#353842"},{"n":"PMS 533","h":"#353f5b"},{"n":"PMS 534","h":"#3a4972"},{"n":"PMS 535","h":"#9ba3b7"},{"n":"PMS 536","h":"#adb2c1"},{"n":"PMS 537","h":"#c4c6ce"},{"n":"PMS 538","h":"#d6d3d6"},{"n":"PMS 539","h":"#003049"},{"n":"PMS 540","h":"#00335b"},{"n":"PMS 541","h":"#003f77"},{"n":"PMS 542","h":"#6693bc"},{"n":"PMS 543","h":"#93b7d1"},{"n":"PMS 544","h":"#b7ccdb"},{"n":"PMS 545","h":"#c4d3dd"},{"n":"PMS 546","h":"#0c3844"},{"n":"PMS 548","h":"#004459"},{"n":"PMS 549","h":"#5e99aa"},{"n":"PMS 550","h":"#87afbf"},{"n":"PMS 551","h":"#a3c1c9"},{"n":"PMS 552","h":"#c4d6d6"},{"n":"PMS 553","h":"#234435"},{"n":"PMS 554","h":"#195e47"},{"n":"PMS 555","h":"#076d54"},{"n":"PMS 556","h":"#7aa891"},{"n":"PMS 557","h":"#a3c1ad"},{"n":"PMS 558","h":"#b7cebc"},{"n":"PMS 559","h":"#c6d6c4"},{"n":"PMS 560","h":"#2b4c3f"},{"n":"PMS 561","h":"#266659"},{"n":"PMS 562","h":"#1e7a6d"},{"n":"PMS 563","h":"#7fbcaa"},{"n":"PMS 564","h":"#a0cebc"},{"n":"PMS 565","h":"#bcdbcc"},{"n":"PMS 566","h":"#d1e2d3"},{"n":"PMS 568","h":"#d6d6c6"},{"n":"PMS 569","h":"#05705e"},{"n":"PMS 570","h":"#008772"},{"n":"PMS 571","h":"#7fc6b2"},{"n":"PMS 572","h":"#aadbc6"},{"n":"PMS 573","h":"#bce2ce"},{"n":"PMS 574","h":"#cce5d6"},{"n":"PMS 575","h":"#495928"},{"n":"PMS 576","h":"#547730"},{"n":"PMS 577","h":"#608e3a"},{"n":"PMS 578","h":"#b5cc8e"},{"n":"PMS 579","h":"#c6d6a0"},{"n":"PMS 580","h":"#c9d6a3"},{"n":"PMS 581","h":"#e0ddbc"},{"n":"PMS 582","h":"#605e11"},{"n":"PMS 583","h":"#878905"},{"n":"PMS 584","h":"#aaba0a"},{"n":"PMS 585","h":"#dbe06b"},{"n":"PMS 586","h":"#e2e584"},{"n":"PMS 587","h":"#e8e89b"},{"n":"PMS 600","h":"#f4edaf"},{"n":"PMS 601","h":"#f2ed9e"},{"n":"PMS 602","h":"#f2ea87"},{"n":"PMS 603","h":"#ede85b"},{"n":"PMS 604","h":"#e8dd21"},{"n":"PMS 605","h":"#ddce11"},{"n":"PMS 606","h":"#d3bf11"},{"n":"PMS 607","h":"#f2eabc"},{"n":"PMS 608","h":"#efe8ad"},{"n":"PMS 609","h":"#eae596"},{"n":"PMS 610","h":"#e2db72"},{"n":"PMS 611","h":"#d6ce49"},{"n":"PMS 612","h":"#c4ba00"},{"n":"PMS 613","h":"#afa00c"},{"n":"PMS 614","h":"#eae2b7"},{"n":"PMS 615","h":"#e2dbaa"},{"n":"PMS 616","h":"#ddd69b"},{"n":"PMS 617","h":"#ccc47c"},{"n":"PMS 618","h":"#b5aa59"},{"n":"PMS 619","h":"#968c28"},{"n":"PMS 620","h":"#847711"},{"n":"PMS 621","h":"#d8ddce"},{"n":"PMS 622","h":"#c1d1bf"},{"n":"PMS 623","h":"#a5bfaa"},{"n":"PMS 624","h":"#7fa08c"},{"n":"PMS 625","h":"#5b8772"},{"n":"PMS 626","h":"#21543f"},{"n":"PMS 627","h":"#0c3026"},{"n":"PMS 628","h":"#cce2dd"},{"n":"PMS 629","h":"#b2d8d8"},{"n":"PMS 630","h":"#8cccd3"},{"n":"PMS 631","h":"#54b7c6"},{"n":"PMS 632","h":"#00a0ba"},{"n":"PMS 633","h":"#007f99"},{"n":"PMS 634","h":"#00667f"},{"n":"PMS 635","h":"#bae0e0"},{"n":"PMS 636","h":"#99d6dd"},{"n":"PMS 637","h":"#6bc9db"},{"n":"PMS 638","h":"#00b5d6"},{"n":"PMS 639","h":"#00a0c4"},{"n":"PMS 640","h":"#008cb2"},{"n":"PMS 642","h":"#d1d8d8"},{"n":"PMS 643","h":"#c6d1d6"},{"n":"PMS 644","h":"#9bafc4"},{"n":"PMS 645","h":"#7796b2"},{"n":"PMS 646","h":"#5e82a3"},{"n":"PMS 647","h":"#26547c"},{"n":"PMS 648","h":"#00305e"},{"n":"PMS 649","h":"#d6d6d8"},{"n":"PMS 650","h":"#bfc6d1"},{"n":"PMS 651","h":"#9baabf"},{"n":"PMS 652","h":"#6d87a8"},{"n":"PMS 653","h":"#335687"},{"n":"PMS 654","h":"#0f2b5b"},{"n":"PMS 655","h":"#0c1c47"},{"n":"PMS 656","h":"#d6dbe0"},{"n":"PMS 657","h":"#c1c9dd"},{"n":"PMS 658","h":"#a5afd6"},{"n":"PMS 659","h":"#7f8cbf"},{"n":"PMS 660","h":"#5960a8"},{"n":"PMS 661","h":"#2d338e"},{"n":"PMS 662","h":"#0c1975"},{"n":"PMS 663","h":"#e2d3d6"},{"n":"PMS 664","h":"#d8ccd1"},{"n":"PMS 665","h":"#c6b5c4"},{"n":"PMS 666","h":"#a893ad"},{"n":"PMS 667","h":"#7f6689"},{"n":"PMS 668","h":"#664975"},{"n":"PMS 669","h":"#472b59"},{"n":"PMS 670","h":"#f2d6d8"},{"n":"PMS 671","h":"#efc6d3"},{"n":"PMS 672","h":"#eaaac4"},{"n":"PMS 673","h":"#e08cb2"},{"n":"PMS 674","h":"#d36b9e"},{"n":"PMS 675","h":"#bc3877"},{"n":"PMS 676","h":"#a00054"},{"n":"PMS 677","h":"#edd6d6"},{"n":"PMS 678","h":"#eaccce"},{"n":"PMS 679","h":"#e5bfc6"},{"n":"PMS 680","h":"#d39eaf"},{"n":"PMS 681","h":"#b7728e"},{"n":"PMS 682","h":"#a05175"},{"n":"PMS 683","h":"#7f284f"},{"n":"PMS 684","h":"#efccce"},{"n":"PMS 685","h":"#eabfc4"},{"n":"PMS 686","h":"#e0aaba"},{"n":"PMS 687","h":"#c9899e"},{"n":"PMS 688","h":"#b26684"},{"n":"PMS 689","h":"#934266"},{"n":"PMS 690","h":"#702342"},{"n":"PMS 691","h":"#efd1c9"},{"n":"PMS 692","h":"#e8bfba"},{"n":"PMS 693","h":"#dba8a5"},{"n":"PMS 694","h":"#c98c8c"},{"n":"PMS 695","h":"#b26b70"},{"n":"PMS 696","h":"#8e4749"},{"n":"PMS 697","h":"#7f383a"},{"n":"PMS 698","h":"#f7d1cc"},{"n":"PMS 699","h":"#f7bfbf"},{"n":"PMS 700","h":"#f2a5aa"},{"n":"PMS 701","h":"#e8878e"},{"n":"PMS 702","h":"#d6606d"},{"n":"PMS 703","h":"#b73844"},{"n":"PMS 704","h":"#9e2828"},{"n":"PMS 705","h":"#f9ddd6"},{"n":"PMS 706","h":"#fcc9c6"},{"n":"PMS 707","h":"#fcadaf"},{"n":"PMS 708","h":"#f98e99"},{"n":"PMS 709","h":"#f26877"},{"n":"PMS 710","h":"#e04251"},{"n":"PMS 711","h":"#d12d33"},{"n":"PMS 712","h":"#ffd3aa"},{"n":"PMS 713","h":"#f9c9a3"},{"n":"PMS 714","h":"#f9ba82"},{"n":"PMS 715","h":"#fc9e49"},{"n":"PMS 716","h":"#f28411"},{"n":"PMS 717","h":"#d36d00"},{"n":"PMS 718","h":"#bf5b00"},{"n":"PMS 719","h":"#f4d1af"},{"n":"PMS 720","h":"#efc49e"},{"n":"PMS 721","h":"#e8b282"},{"n":"PMS 722","h":"#d18e54"},{"n":"PMS 723","h":"#ba7530"},{"n":"PMS 724","h":"#8e4905"},{"n":"PMS 725","h":"#753802"},{"n":"PMS 726","h":"#edd3b5"},{"n":"PMS 727","h":"#e2bf9b"},{"n":"PMS 728","h":"#d3a87c"},{"n":"PMS 729","h":"#c18e60"},{"n":"PMS 730","h":"#aa753f"},{"n":"PMS 731","h":"#723f0a"},{"n":"PMS 732","h":"#60330a"},{"n":"PMS 801","h":"#00aacc"},{"n":"PMS 801 2X","h":"#0089af"},{"n":"PMS 802","h":"#60dd49"},{"n":"PMS 802 2X","h":"#1cce28"},{"n":"PMS 803","h":"#c6c6a5"},{"n":"PMS 803","h":"#ffed38"},{"n":"PMS 803 2X","h":"#ffd816"},{"n":"PMS 804","h":"#ff9338"},{"n":"PMS 804 2X","h":"#ff7f1e"},{"n":"PMS 805","h":"#f95951"},{"n":"PMS 805 2X","h":"#f93a2b"},{"n":"PMS 806","h":"#ff0093"},{"n":"PMS 806 2X","h":"#f7027c"},{"n":"PMS 807","h":"#d6009e"},{"n":"PMS 807 2X","h":"#bf008c"},{"n":"PMS 808","h":"#00b59b"},{"n":"PMS 808 2X","h":"#00a087"},{"n":"PMS 809","h":"#dde00f"},{"n":"PMS 809 2X","h":"#d6d60c"},{"n":"PMS 810","h":"#ffcc1e"},{"n":"PMS 810 2X","h":"#ffbc21"},{"n":"PMS 811","h":"#ff7247"},{"n":"PMS 811 2X","h":"#ff5416"},{"n":"PMS 812","h":"#fc2366"},{"n":"PMS 812 2X","h":"#fc074f"},{"n":"PMS 813","h":"#e50099"},{"n":"PMS 813 2X","h":"#d10084"},{"n":"PMS 814","h":"#8c60c1"},{"n":"PMS 814 2X","h":"#703faf"},{"n":"PMS 1205","h":"#f7e8aa"},{"n":"PMS 1215","h":"#f9e08c"},{"n":"PMS 1225","h":"#ffcc49"},{"n":"PMS 1235","h":"#fcb514"},{"n":"PMS 1245","h":"#bf910c"},{"n":"PMS 1255","h":"#a37f14"},{"n":"PMS 1265","h":"#7c6316"},{"n":"PMS 1345","h":"#ffd691"},{"n":"PMS 1355","h":"#fcce87"},{"n":"PMS 1365","h":"#fcba5e"},{"n":"PMS 1375","h":"#f99b0c"},{"n":"PMS 1385","h":"#cc7a02"},{"n":"PMS 1395","h":"#996007"},{"n":"PMS 1405","h":"#6b4714"},{"n":"PMS 1485","h":"#ffb777"},{"n":"PMS 1495","h":"#ff993f"},{"n":"PMS 1505","h":"#f47c00"},{"n":"PMS 1525","h":"#b55400"},{"n":"PMS 1535","h":"#8c4400"},{"n":"PMS 1545","h":"#4c280f"},{"n":"PMS 1555","h":"#f9bf9e"},{"n":"PMS 1565","h":"#fca577"},{"n":"PMS 1575","h":"#fc8744"},{"n":"PMS 1585","h":"#f96b07"},{"n":"PMS 1595","h":"#d15b05"},{"n":"PMS 1605","h":"#a04f11"},{"n":"PMS 1615","h":"#843f0f"},{"n":"PMS 1625","h":"#f9a58c"},{"n":"PMS 1635","h":"#f98e6d"},{"n":"PMS 1645","h":"#f97242"},{"n":"PMS 1655","h":"#f95602"},{"n":"PMS 1675","h":"#a53f0f"},{"n":"PMS 1685","h":"#843511"},{"n":"PMS 1765","h":"#f99ea3"},{"n":"PMS 1767","h":"#f9b2b7"},{"n":"PMS 1775","h":"#f9848e"},{"n":"PMS 1777","h":"#fc6675"},{"n":"PMS 1785","h":"#fc4f59"},{"n":"PMS 1787","h":"#f43f4f"},{"n":"PMS 1788","h":"#ef2b2d"},{"n":"PMS 1788 2X","h":"#d62100"},{"n":"PMS 1795","h":"#d62828"},{"n":"PMS 1797","h":"#cc2d30"},{"n":"PMS 1805","h":"#af2626"},{"n":"PMS 1807","h":"#a03033"},{"n":"PMS 1815","h":"#7c211e"},{"n":"PMS 1817","h":"#5b2d28"},{"n":"PMS 1852 2X","h":"#d11600"},{"n":"PMS 1895","h":"#fcbfc9"},{"n":"PMS 1905","h":"#fc9bb2"},{"n":"PMS 1915","h":"#f4547c"},{"n":"PMS 1925","h":"#e00747"},{"n":"PMS 1935","h":"#c10538"},{"n":"PMS 1945","h":"#a80c35"},{"n":"PMS 1955","h":"#931638"},{"n":"PMS 2365","h":"#f7c4d8"},{"n":"PMS 2375","h":"#ea6bbf"},{"n":"PMS 2385","h":"#db28a5"},{"n":"PMS 2395","h":"#c4008c"},{"n":"PMS 2405","h":"#a8007a"},{"n":"PMS 2415","h":"#9b0070"},{"n":"PMS 2425","h":"#87005b"},{"n":"PMS 2562","h":"#d8a8d8"},{"n":"PMS 2563","h":"#d1a0cc"},{"n":"PMS 2567","h":"#bf93cc"},{"n":"PMS 2572","h":"#c687d1"},{"n":"PMS 2573","h":"#ba7cbc"},{"n":"PMS 2577","h":"#aa72bf"},{"n":"PMS 2582","h":"#aa47ba"},{"n":"PMS 2583","h":"#9e4fa5"},{"n":"PMS 2587","h":"#8e47ad"},{"n":"PMS 2592","h":"#930fa5"},{"n":"PMS 2592 2X","h":"#720082"},{"n":"PMS 2593","h":"#872b93"},{"n":"PMS 2597","h":"#66008c"},{"n":"PMS 2602","h":"#820c8e"},{"n":"PMS 2603","h":"#70147a"},{"n":"PMS 2607","h":"#5b027a"},{"n":"PMS 2612","h":"#701e72"},{"n":"PMS 2613","h":"#66116d"},{"n":"PMS 2617","h":"#560c70"},{"n":"PMS 2622","h":"#602d59"},{"n":"PMS 2623","h":"#5b195e"},{"n":"PMS 2627","h":"#4c145e"},{"n":"PMS 2635","h":"#c9add8"},{"n":"PMS 2645","h":"#b591d1"},{"n":"PMS 2655","h":"#9b6dc6"},{"n":"PMS 2665","h":"#894fbf"},{"n":"PMS 2685","h":"#56008c"},{"n":"PMS 2695","h":"#44235e"},{"n":"PMS 2705","h":"#ad9ed3"},{"n":"PMS 2706","h":"#d1cedd"},{"n":"PMS 2707","h":"#bfd1e5"},{"n":"PMS 2708","h":"#afbcdb"},{"n":"PMS 2715","h":"#937acc"},{"n":"PMS 2716","h":"#a5a0d6"},{"n":"PMS 2717","h":"#a5bae0"},{"n":"PMS 2718","h":"#5b77cc"},{"n":"PMS 2725","h":"#7251bc"},{"n":"PMS 2726","h":"#6656bc"},{"n":"PMS 2727","h":"#5e68c4"},{"n":"PMS 2728","h":"#3044b5"},{"n":"PMS 2735","h":"#4f0093"},{"n":"PMS 2736","h":"#4930ad"},{"n":"PMS 2738","h":"#2d008e"},{"n":"PMS 2745","h":"#3f0077"},{"n":"PMS 2746","h":"#3f2893"},{"n":"PMS 2747","h":"#1c146b"},{"n":"PMS 2748","h":"#1e1c77"},{"n":"PMS 2755","h":"#35006d"},{"n":"PMS 2756","h":"#332875"},{"n":"PMS 2757","h":"#141654"},{"n":"PMS 2758","h":"#192168"},{"n":"PMS 2765","h":"#2b0c56"},{"n":"PMS 2766","h":"#2b265b"},{"n":"PMS 2767","h":"#14213d"},{"n":"PMS 2768","h":"#112151"},{"n":"PMS 2905","h":"#93c6e0"},{"n":"PMS 2915","h":"#60afdd"},{"n":"PMS 2925","h":"#008ed6"},{"n":"PMS 2935","h":"#005bbf"},{"n":"PMS 2945","h":"#0054a0"},{"n":"PMS 2955","h":"#003d6b"},{"n":"PMS 2965","h":"#00334c"},{"n":"PMS 2975","h":"#bae0e2"},{"n":"PMS 2985","h":"#51bfe2"},{"n":"PMS 2995","h":"#00a5db"},{"n":"PMS 3005","h":"#0084c9"},{"n":"PMS 3015","h":"#00709e"},{"n":"PMS 3025","h":"#00546b"},{"n":"PMS 3035","h":"#004454"},{"n":"PMS 3105","h":"#7fd6db"},{"n":"PMS 3115","h":"#2dc6d6"},{"n":"PMS 3125","h":"#00b7c6"},{"n":"PMS 3135","h":"#009baa"},{"n":"PMS 3145","h":"#00848e"},{"n":"PMS 3155","h":"#006d75"},{"n":"PMS 3165","h":"#00565b"},{"n":"PMS 3242","h":"#87ddd1"},{"n":"PMS 3245","h":"#8ce0d1"},{"n":"PMS 3248","h":"#7ad3c1"},{"n":"PMS 3252","h":"#56d6c9"},{"n":"PMS 3255","h":"#47d6c1"},{"n":"PMS 3258","h":"#35c4af"},{"n":"PMS 3262","h":"#00c1b5"},{"n":"PMS 3265","h":"#00c6b2"},{"n":"PMS 3268","h":"#00af99"},{"n":"PMS 3272","h":"#00aa9e"},{"n":"PMS 3275","h":"#00b2a0"},{"n":"PMS 3278","h":"#009b84"},{"n":"PMS 3285","h":"#009987"},{"n":"PMS 3288","h":"#008270"},{"n":"PMS 3292","h":"#006056"},{"n":"PMS 3295","h":"#008272"},{"n":"PMS 3298","h":"#006b5b"},{"n":"PMS 3302","h":"#00493f"},{"n":"PMS 3305","h":"#004f42"},{"n":"PMS 3308","h":"#004438"},{"n":"PMS 3375","h":"#8ee2bc"},{"n":"PMS 3385","h":"#54d8a8"},{"n":"PMS 3395","h":"#00c993"},{"n":"PMS 3405","h":"#00b27a"},{"n":"PMS 3415","h":"#007c59"},{"n":"PMS 3425","h":"#006847"},{"n":"PMS 3435","h":"#024930"},{"n":"PMS 3935","h":"#f2ed6d"},{"n":"PMS 3945","h":"#efea07"},{"n":"PMS 3955","h":"#ede211"},{"n":"PMS 3965","h":"#e8dd11"},{"n":"PMS 3975","h":"#b5a80c"},{"n":"PMS 3985","h":"#998c0a"},{"n":"PMS 3995","h":"#6d6002"},{"n":"PMS 4485","h":"#604c11"},{"n":"PMS 4495","h":"#877530"},{"n":"PMS 4505","h":"#a09151"},{"n":"PMS 4515","h":"#bcad75"},{"n":"PMS 4525","h":"#ccbf8e"},{"n":"PMS 4535","h":"#dbcea5"},{"n":"PMS 4545","h":"#e5dbba"},{"n":"PMS 4625","h":"#472311"},{"n":"PMS 4635","h":"#8c5933"},{"n":"PMS 4645","h":"#b28260"},{"n":"PMS 4655","h":"#c49977"},{"n":"PMS 4665","h":"#d8b596"},{"n":"PMS 4675","h":"#e5c6aa"},{"n":"PMS 4685","h":"#edd3bc"},{"n":"PMS 4695","h":"#51261c"},{"n":"PMS 4705","h":"#7c513d"},{"n":"PMS 4715","h":"#99705b"},{"n":"PMS 4725","h":"#b5917c"},{"n":"PMS 4735","h":"#ccaf9b"},{"n":"PMS 4745","h":"#d8bfaa"},{"n":"PMS 4755","h":"#e2ccba"},{"n":"PMS 4975","h":"#441e1c"},{"n":"PMS 4985","h":"#844949"},{"n":"PMS 4995","h":"#a56b6d"},{"n":"PMS 5005","h":"#bc8787"},{"n":"PMS 5015","h":"#d8ada8"},{"n":"PMS 5025","h":"#e2bcb7"},{"n":"PMS 5035","h":"#edcec6"},{"n":"PMS 5115","h":"#4f213a"},{"n":"PMS 5125","h":"#754760"},{"n":"PMS 5135","h":"#936b7f"},{"n":"PMS 5145","h":"#ad8799"},{"n":"PMS 5155","h":"#ccafb7"},{"n":"PMS 5165","h":"#e0c9cc"},{"n":"PMS 5175","h":"#e8d6d1"},{"n":"PMS 5185","h":"#472835"},{"n":"PMS 5195","h":"#593344"},{"n":"PMS 5205","h":"#8e6877"},{"n":"PMS 5215","h":"#b5939b"},{"n":"PMS 5225","h":"#ccadaf"},{"n":"PMS 5235","h":"#ddc6c4"},{"n":"PMS 5245","h":"#e5d3cc"},{"n":"PMS 5255","h":"#35264f"},{"n":"PMS 5265","h":"#493d63"},{"n":"PMS 5275","h":"#605677"},{"n":"PMS 5285","h":"#8c8299"},{"n":"PMS 5295","h":"#b2a8b5"},{"n":"PMS 5305","h":"#ccc1c6"},{"n":"PMS 5315","h":"#dbd3d3"},{"n":"PMS 5395","h":"#02283a"},{"n":"PMS 5405","h":"#3f6075"},{"n":"PMS 5415","h":"#607c8c"},{"n":"PMS 5425","h":"#8499a5"},{"n":"PMS 5435","h":"#afbcbf"},{"n":"PMS 5445","h":"#c4cccc"},{"n":"PMS 5455","h":"#d6d8d3"},{"n":"PMS 5463","h":"#00353a"},{"n":"PMS 5467","h":"#193833"},{"n":"PMS 5473","h":"#26686d"},{"n":"PMS 5477","h":"#3a564f"},{"n":"PMS 5483","h":"#609191"},{"n":"PMS 5487","h":"#667c72"},{"n":"PMS 5493","h":"#8cafad"},{"n":"PMS 5497","h":"#91a399"},{"n":"PMS 5503","h":"#aac4bf"},{"n":"PMS 5507","h":"#afbab2"},{"n":"PMS 5513","h":"#ced8d1"},{"n":"PMS 5517","h":"#c9cec4"},{"n":"PMS 5523","h":"#d6ddd6"},{"n":"PMS 5527","h":"#ced1c6"},{"n":"PMS 5535","h":"#213d30"},{"n":"PMS 5545","h":"#4f6d5e"},{"n":"PMS 5555","h":"#779182"},{"n":"PMS 5565","h":"#96aa99"},{"n":"PMS 5575","h":"#afbfad"},{"n":"PMS 5585","h":"#c4cebf"},{"n":"PMS 5595","h":"#d8dbcc"},{"n":"PMS 5605","h":"#233a2d"},{"n":"PMS 5615","h":"#546856"},{"n":"PMS 5625","h":"#728470"},{"n":"PMS 5635","h":"#9eaa99"},{"n":"PMS 5645","h":"#bcc1b2"},{"n":"PMS 5655","h":"#c6ccba"},{"n":"PMS 5743","h":"#d8ddb5"},{"n":"PMS 5747","h":"#d8d6b7"},{"n":"PMS 5753","h":"#3f4926"},{"n":"PMS 5757","h":"#424716"},{"n":"PMS 5763","h":"#5e663a"},{"n":"PMS 5767","h":"#6b702b"},{"n":"PMS 5773","h":"#777c4f"},{"n":"PMS 5777","h":"#8c914f"},{"n":"PMS 5783","h":"#9b9e72"},{"n":"PMS 5787","h":"#aaad75"},{"n":"PMS 5793","h":"#b5b58e"},{"n":"PMS 5797","h":"#c6c699"},{"n":"PMS 5807","h":"#d3d1aa"},{"n":"PMS 5815","h":"#494411"},{"n":"PMS 5825","h":"#75702b"},{"n":"PMS 5835","h":"#9e9959"},{"n":"PMS 5845","h":"#b2aa70"},{"n":"PMS 5855","h":"#ccc693"},{"n":"PMS 5865","h":"#d6cea3"},{"n":"PMS 5875","h":"#e0dbb5"},{"n":"Process Blue","h":"#0091c9"},{"n":"Process Blue 2X","h":"#0077bf"},{"n":"Process Yellow","h":"#f7e214"},{"n":"Purple","h":"#bf30b5"},{"n":"Purple 2X","h":"#aa0096"},{"n":"Reflex Blue","h":"#0c1c8c"},{"n":"Reflex Blue 2X","h":"#1c007a"},{"n":"Rhodamin Red 2X","h":"#d10572"},{"n":"Rhodamine Red","h":"#ed0091"},{"n":"Rubine Red","h":"#d10056"},{"n":"Rubine Red 2X","h":"#c6003d"},{"n":"Violet","h":"#6607a5"},{"n":"Violet 2X","h":"#59008e"},{"n":"Warm Gray 1","h":"#e5dbcc"},{"n":"Warm Gray 2","h":"#ddd1c1"},{"n":"Warm Gray 3","h":"#ccc1b2"},{"n":"Warm Gray 5","h":"#b5a899"},{"n":"Warm Gray 6","h":"#afa393"},{"n":"Warm Gray 7","h":"#a39687"},{"n":"Warm Gray 8","h":"#96897a"},{"n":"Warm Gray 9","h":"#8c7f70"},{"n":"Warm Gray 10","h":"#827263"},{"n":"Warm Gray 11","h":"#6d5e51"},{"n":"Warm Red","h":"#f93f26"},{"n":"Warm Red 2X","h":"#e03a00"},{"n":"Yellow 2X","h":"#fce216"}];
        var MAX_VISIBLE = 300;

        // ---------- familias ----------
        var FAMILIAS = {
            amarillo: { t: 'Amarillos', c: '#f4ed47', o: 1 },
            naranja:  { t: 'Naranjas',  c: '#f47c00', o: 2 },
            rojo:     { t: 'Rojos',     c: '#ef2b2d', o: 3 },
            rosa:     { t: 'Rosas',     c: '#fc758e', o: 4 },
            morado:   { t: 'Morados',   c: '#8e23a3', o: 5 },
            azul:     { t: 'Azules',    c: '#1f4e9c', o: 6 },
            verde:    { t: 'Verdes',    c: '#2e8b57', o: 7 },
            neutro:   { t: 'Grises y neutros', c: '#64748b', o: 8 },
            marron:   { t: 'Marrones',  c: '#7c4a1e', o: 9 }
        };

        function norm(s) {
            return String(s || '').toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        function hexARgb(hex) {
            var h = hex.replace('#', '');
            if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
            return {
                r: parseInt(h.substr(0, 2), 16),
                g: parseInt(h.substr(2, 2), 16),
                b: parseInt(h.substr(4, 2), 16)
            };
        }

        function rgbAHex(r, g, b) {
            function p(v) { v = Math.max(0, Math.min(255, Math.round(v))).toString(16); return v.length < 2 ? '0' + v : v; }
            return '#' + p(r) + p(g) + p(b);
        }

        function rgbAHsl(r, g, b) {
            r /= 255; g /= 255; b /= 255;
            var max = Math.max(r, g, b), min = Math.min(r, g, b);
            var h = 0, s = 0, l = (max + min) / 2;
            if (max !== min) {
                var d = max - min;
                s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
                else if (max === g) h = (b - r) / d + 2;
                else h = (r - g) / d + 4;
                h /= 6;
            }
            return { h: h * 360, s: s, l: l };
        }

        function hslAHex(h, s, l) {
            h = ((h % 360) + 360) % 360 / 360;
            var r, g, b;
            if (s === 0) { r = g = b = l; }
            else {
                var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
                var p = 2 * l - q;
                function hue(p, q, t) {
                    if (t < 0) t += 1;
                    if (t > 1) t -= 1;
                    if (t < 1 / 6) return p + (q - p) * 6 * t;
                    if (t < 1 / 2) return q;
                    if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
                    return p;
                }
                r = hue(p, q, h + 1 / 3);
                g = hue(p, q, h);
                b = hue(p, q, h - 1 / 3);
            }
            return rgbAHex(r * 255, g * 255, b * 255);
        }

        function contraste(hex) {
            var c = hexARgb(hex);
            var l = (0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b) / 255;
            return l > 0.62 ? '#111827' : '#ffffff';
        }

        /** Familia de un color: por el nombre si lo dice, si no por el tono (HSL). */
        function clasificar(nombre, hex) {
            var n = norm(nombre);
            if (/amarill|yellow|gold|ocre/.test(n)) return 'amarillo';
            if (/naranj|orange/.test(n)) return 'naranja';
            if (/\broj|red|scarlet|carmine|bermell/.test(n)) return 'rojo';
            if (/rosa|pink|salmon|coral|magenta|fucsia/.test(n)) return 'rosa';
            if (/morad|purple|violet|lila|lavend|plum|uve|muret/.test(n)) return 'morado';
            if (/azul|blue|cyan|turqu|teal|navy|petrol|denim|indigo/.test(n)) return 'azul';
            if (/verde|green/.test(n)) return 'verde';
            if (/gris|gray|grey|negro|black|blanco|white|platino|plata|silver|hueso|perla/.test(n)) return 'neutro';
            if (/cafe|marron|brown|beige|arena|camel|topo|tierra|cuero|caramelo|nude/.test(n)) return 'marron';
            var hsl = rgbAHsl(hexARgb(hex).r, hexARgb(hex).g, hexARgb(hex).b);
            if (hsl.l < 0.08 || hsl.l > 0.94) return 'neutro';
            if (hsl.s < 0.12) return 'neutro';
            var h = hsl.h;
            // Naranja oscuro (y ocre oscuro) = marrón; los tonos vivos siguen siendo naranjas.
            if (hsl.l < 0.42 && h >= 16 && h < 68) return 'marron';
            if (h < 16 || h >= 345) return 'rojo';
            if (h < 42) return 'naranja';
            if (h < 68) return 'amarillo';
            if (h < 160) return 'verde';
            if (h < 200) return 'azul';
            if (h < 255) return 'azul';
            if (h < 292) return 'morado';
            if (h < 345) return 'rosa';
            return 'neutro';
        }

        COLORES.forEach(function (c) { c.f = clasificar(c.n, c.h); c.fa = FAMILIAS[c.f].t; });

        /* Sinónimos en español: la tabla Pantone nombra las muestras en inglés,
           así que «rojo», «celeste» o «gris claro» deben encontrar resultados. */
        var SINONIMOS = {
            amarillo: 'amarill oro gold ocre mostaza',
            naranja:  'naranj',
            rojo:     'roj carmesi escarlata bermellon',
            rosa:     'rosa coral salmon magenta fucsia',
            morado:   'morad violeta lila lavanda purpura uva',
            azul:     'azul celeste turquesa cyan aqua turqui denim anil',
            verde:    'verd',
            neutro:   'gris negr blanc hueso perla plata platino arena beige',
            marron:   'marron cafe tierra camel caramelo cuero nude'
        };

        // ======================================================================
        //  Selector (un único popover compartido)
        // ======================================================================
        var pop = null, inputActual = null, asuntoActual = null;
        var filtro = { q: '', f: 'todas' };

        var ESTILO =
            '<style>' +
            '.ptn-paleta{position:fixed;z-index:1500;width:min(34rem,94vw);background:var(--surface,#fff);color:var(--text,#111827);' +
            'border:1px solid var(--border,#d8dee9);border-radius:1rem;box-shadow:0 1.2rem 3rem rgba(0,0,0,.32);overflow:hidden;' +
            'font-family:inherit;font-size:1.3rem;display:flex;flex-direction:column;max-height:70vh}' +
            '.ptn-paleta[hidden]{display:none}' +
            '.ptn-cab{display:flex;align-items:center;gap:.6rem;padding:.7rem .8rem;border-bottom:1px solid var(--border,#d8dee9);background:var(--bg-secondary,#f8fafc)}' +
            '.ptn-cab strong{flex:1;font-size:1.2rem;letter-spacing:.02em;text-transform:uppercase;color:var(--text-muted,#64748b)}' +
            '.ptn-cerrar{border:0;background:transparent;color:var(--text-muted,#64748b);cursor:pointer;border-radius:.5rem;line-height:1;padding:.3rem .5rem;font-size:1.5rem}' +
            '.ptn-cerrar:hover{background:var(--border,#d8dee9);color:var(--text,#111827)}' +
            '.ptn-filtros{padding:.7rem .8rem .3rem;display:flex;flex-direction:column;gap:.5rem}' +
            '.ptn-busca{width:100%;box-sizing:border-box;padding:.45rem .6rem;font-size:1.3rem;border:1px solid var(--border,#d8dee9);' +
            'border-radius:.6rem;background:var(--bg-secondary,#f8fafc);color:inherit;outline:none}' +
            '.ptn-busca:focus{border-color:var(--primary,#2563eb)}' +
            '.ptn-fams{display:flex;flex-wrap:wrap;gap:.35rem}' +
            '.ptn-fam{border:1px solid var(--border,#d8dee9);background:var(--bg-secondary,#f8fafc);color:inherit;border-radius:99rem;' +
            'padding:.22rem .6rem;font-size:1.1rem;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem;line-height:1.4}' +
            '.ptn-fam i{width:.85rem;height:.85rem;border-radius:50%;display:inline-block;background:var(--fc,#94a3b8);border:1px solid rgba(0,0,0,.18)}' +
            '.ptn-fam:hover{border-color:var(--primary,#2563eb)}' +
            '.ptn-fam[aria-pressed="true"]{background:var(--primary,#2563eb);border-color:var(--primary,#2563eb);color:#fff}' +
            '.ptn-cuenta{font-size:1.1rem;color:var(--text-muted,#64748b);padding:.2rem .8rem}' +
            '.ptn-zona{overflow:auto;padding:.4rem .8rem .8rem;flex:1;min-height:8rem}' +
            '.ptn-rejilla{display:grid;grid-template-columns:repeat(auto-fill,minmax(2rem,1fr));gap:.35rem}' +
            '.ptn-sw{position:relative;aspect-ratio:1/1;border:1px solid rgba(0,0,0,.18);border-radius:.4rem;cursor:pointer;padding:0;min-width:0}' +
            '.ptn-sw:hover,.ptn-sw:focus-visible{outline:2px solid var(--primary,#2563eb);outline-offset:1px;z-index:1}' +
            '.ptn-sw[aria-pressed="true"]{outline:2px solid var(--primary,#2563eb);outline-offset:1px}' +
            '.ptn-sin{color:var(--text-muted,#64748b);font-size:1.2rem;padding:1.4rem .4rem;text-align:center}' +
            '.ptn-pie{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.55rem .8rem;border-top:1px solid var(--border,#d8dee9);' +
            'background:var(--bg-secondary,#f8fafc);font-size:1.1rem;color:var(--text-muted,#64748b)}' +
            '.ptn-hoy{display:inline-flex;align-items:center;gap:.4rem}' +
            '.ptn-hoy b{width:1.4rem;height:1.4rem;border-radius:.35rem;border:1px solid rgba(0,0,0,.25);display:inline-block}' +
            '.ptn-hoy code{font-family:ui-monospace,monospace;color:inherit}' +
            '.ptn-fuente{margin-left:auto}' +
            '.ptn-fuente a{color:var(--primary,#2563eb);text-decoration:none}' +
            '.ptn-fuente a:hover{text-decoration:underline}' +
            '.ptn-gotorra{display:none;border:0;background:transparent;color:var(--text-muted,#64748b);cursor:pointer;' +
            'border-radius:.5rem;padding:.15rem .4rem;line-height:1}' +
            '.ptn-gotorra:hover{background:var(--border,#d8dee9);color:var(--text,#111827)}' +
            '.ptn-disparador[aria-expanded="true"] .ptn-gotorra{display:inline-block}' +
            '.ptn-disparador[aria-expanded="true"] .ptn-ico{display:none}' +
            '@media (max-width:760px){.ptn-disparador{display:none}}' +
            '</style>';

        function svgGota() {
            return '<svg class="ptn-ico" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false">' +
                '<path fill="currentColor" d="M12 2.7s6 6.4 6 11a6 6 0 0 1-12 0c0-4.6 6-11 6-11z"/></svg>';
        }

        function crearPop() {
            if (pop) return pop;
            var env = document.createElement('div');
            env.innerHTML = ESTILO +
                '<div class="ptn-paleta" role="dialog" aria-label="Paleta de colores Pantone" hidden>' +
                '  <div class="ptn-cab">' +
                '    <strong>Paleta Pantone</strong>' +
                '    <button type="button" class="ptn-cerrar" title="Cerrar" aria-label="Cerrar">&#10005;</button>' +
                '  </div>' +
                '  <div class="ptn-filtros">' +
                '    <input type="search" class="ptn-busca" placeholder="Buscar por nombre o código (p. ej. 186, rojo, #ce1126)" aria-label="Buscar color">' +
                '    <div class="ptn-fams" role="group" aria-label="Filtrar por familia"></div>' +
                '  </div>' +
                '  <div class="ptn-cuenta" aria-live="polite"></div>' +
                '  <div class="ptn-zona"><div class="ptn-rejilla"></div></div>' +
                '  <div class="ptn-pie">' +
                '    <span class="ptn-hoy"><b></b><code></code></span>' +
                '    <button type="button" class="ptn-gotorra" title="Elegir otro color" aria-label="Elegir otro color">&#127912;</button>' +
                '    <span class="ptn-fuente">Fuente: <a href="https://www.logorapid.com/pantone" target="_blank" rel="noopener">logorapid.com/pantone</a></span>' +
                '  </div>' +
                '</div>';
            while (env.firstChild) document.body.appendChild(env.firstChild);

            pop = document.querySelector('.ptn-paleta');
            pop._busca = pop.querySelector('.ptn-busca');
            pop._fams = pop.querySelector('.ptn-fams');
            pop._cuenta = pop.querySelector('.ptn-cuenta');
            pop._rejilla = pop.querySelector('.ptn-rejilla');
            pop._hoyB = pop.querySelector('.ptn-hoy b');
            pop._hoyC = pop.querySelector('.ptn-hoy code');
            pop._goterra = pop.querySelector('.ptn-gotorra');

            pop.querySelector('.ptn-cerrar').addEventListener('click', cerrar);
            pop._busca.addEventListener('input', function () { filtro.q = this.value.trim(); render(); });
            pop._goterra.addEventListener('click', function () {
                // Abre el selector nativo si el navegador lo permite.
                if (inputActual && typeof inputActual.showPicker === 'function') {
                    try { inputActual.showPicker(); return; } catch (e) { /* sin soporte */ }
                }
                if (inputActual) inputActual.click();
            });

            // El resto de escuchadores (clics, Escape, resize) están registrados
            // a nivel de módulo, más abajo, para funcionar desde el primer clic.
            return pop;
        }

        /* Un único escuchador de clics en fase de captura: aplica la muestra,
           cierra al pulsar fuera o atiende los botones de paleta. Se registra
           desde el arranque (no al crear el popover) para que el primer clic
           sobre un botón ya funcione. */
        document.addEventListener('click', function (e) {
            // Botón de paleta: abrir, cambiar de input o alternar el cierre.
            var disp = e.target.closest && e.target.closest('.ptn-disparador');
            if (disp) {
                e.preventDefault();
                e.stopPropagation();
                var ref = masCercano(disp);
                if (ref && ref.input) {
                    if (pop && !pop.hidden && inputActual === ref.input) cerrar();
                    else abrir(ref.input, ref.asunto);
                }
                return;
            }
            if (!pop || pop.hidden) return;
            var sw = e.target.closest && e.target.closest('.ptn-sw');
            if (sw) {
                var lista = pop._rejilla._lista || [];
                var c = lista[Number(sw.dataset.i)];
                if (c) aplicar(c.h);
                return;
            }
            if (pop.contains(e.target)) return;
            if (asuntoActual && asuntoActual.contains(e.target)) return;
            cerrar();
        }, true);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && pop && !pop.hidden) { e.stopPropagation(); cerrar(); }
        });

        window.addEventListener('resize', function () { if (pop && !pop.hidden) ubicar(); });

        function familiasDisponibles() {
            var cuenta = {};
            COLORES.forEach(function (c) { cuenta[c.f] = (cuenta[c.f] || 0) + 1; });
            var lista = Object.keys(FAMILIAS).filter(function (k) { return cuenta[k]; });
            lista.sort(function (a, b) { return FAMILIAS[a].o - FAMILIAS[b].o; });
            return { lista: lista, cuenta: cuenta };
        }

        function filtrados() {
            var q = norm(filtro.q);
            return COLORES.filter(function (c) {
                if (filtro.f !== 'todas' && c.f !== filtro.f) return false;
                if (!q) return true;
                if (norm(c.n).indexOf(q) >= 0 || c.h.indexOf(q) >= 0) return true;
                if (norm(c.fa).indexOf(q) >= 0) return true;
                var s = SINONIMOS[c.f];
                if (s && norm(s).indexOf(q) >= 0) return true;
                // Varias palabras: todas deben aparecer («gris claro», «azul oscuro»…).
                var palabras = q.split(/\s+/).filter(Boolean);
                if (palabras.length < 2) return false;
                var todo = norm(c.n + ' ' + c.fa + ' ' + (s || ''));
                return palabras.every(function (p) { return todo.indexOf(p) >= 0; });
            });
        }

        function render() {
            var p = crearPop();
            var dispo = familiasDisponibles();
            var visibles = filtrados();

            // Chips de familia.
            var html = '<button type="button" class="ptn-fam" data-f="todas" aria-pressed="' +
                (filtro.f === 'todas') + '"><i style="--fc:#94a3b8"></i>Todas</button>';
            dispo.lista.forEach(function (k) {
                html += '<button type="button" class="ptn-fam" data-f="' + k + '" aria-pressed="' + (filtro.f === k) +
                    '"><i style="--fc:' + FAMILIAS[k].c + '"></i>' + FAMILIAS[k].t + '</button>';
            });
            p._fams.innerHTML = html;

            p._cuenta.textContent = visibles.length === COLORES.length
                ? COLORES.length + ' colores'
                : visibles.length + ' de ' + COLORES.length + ' colores';

            if (!visibles.length) {
                p._rejilla.innerHTML = '<div class="ptn-sin" style="grid-column:1/-1">Ningún color coincide con la búsqueda.</div>';
                return;
            }
            var lista = visibles.slice(0, MAX_VISIBLE);
            var g = '';
            lista.forEach(function (c, i) {
                var sel = inputActual && inputActual.value && inputActual.value.toLowerCase() === c.h;
                g += '<button type="button" class="ptn-sw" data-i="' + i + '" style="background:' + c.h +
                    ';color:' + contraste(c.h) + '" title="' + esc(c.n) + ' · ' + c.h + '" aria-label="' +
                    esc(c.n) + ' ' + c.h + '" aria-pressed="' + (!!sel) + '">' + (sel ? '&#10003;' : '') + '</button>';
            });
            if (visibles.length > MAX_VISIBLE) {
                g += '<div class="ptn-sin" style="grid-column:1/-1">Mostrando los primeros ' + MAX_VISIBLE +
                    ' · afina la búsqueda para ver el resto</div>';
            }
            p._rejilla.innerHTML = g;
            p._rejilla._lista = lista;

            if (inputActual) mostrarActual();
        }

        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function mostrarActual() {
            if (!pop || !inputActual) return;
            var v = inputActual.value || '';
            pop._hoyB.style.background = v;
            pop._hoyC.textContent = v.toUpperCase();
        }

        function aplicar(hex) {
            if (!inputActual) return;
            fijar(inputActual, hex);
            mostrarActual();
            var p = crearPop();
            var lista = p._rejilla._lista || [];
            Array.prototype.forEach.call(p._rejilla.querySelectorAll('.ptn-sw'), function (b, i) {
                var c = lista[i];
                var sel = !!c && c.h === hex;
                b.setAttribute('aria-pressed', String(sel));
                b.innerHTML = sel ? '&#10003;' : '';
            });
        }

        /** Fija el color y avisa a la herramienta con los eventos de siempre. */
        function fijar(input, hex) {
            input.value = hex;
            if (typeof input._ptnSetter === 'function') input._ptnSetter(hex);
            try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) { /* IE */ }
            try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) { /* IE */ }
        }

        function ubicar() {
            if (!pop || !asuntoActual) return;
            var r = asuntoActual.getBoundingClientRect();
            pop.style.left = '0px'; pop.style.top = '0px';
            var pw = pop.offsetWidth, ph = pop.offsetHeight;
            var izq = Math.min(Math.max(8, r.left), window.innerWidth - pw - 8);
            var arr = r.bottom + 6;
            if (arr + ph > window.innerHeight - 8) arr = Math.max(8, r.top - ph - 6);
            pop.style.left = Math.round(izq) + 'px';
            pop.style.top = Math.round(arr) + 'px';
        }

        function refrescarBoton() {
            if (!inputActual) return;
            var b = inputActual.nextElementSibling;
            if (b && b.classList.contains('ptn-disparador')) {
                b.setAttribute('aria-expanded', String(!!(pop && !pop.hidden)));
            }
        }

        function abrir(input, asunto) {
            crearPop();
            inputActual = input;
            asuntoActual = asunto || input;
            filtro = { q: '', f: 'todas' };
            pop.hidden = false;
            pop._busca.value = '';
            render();
            ubicar();
            refrescarBoton();
            pop._busca.focus();
        }

        function cerrar() {
            if (!pop || pop.hidden) return;
            pop.hidden = true;
            refrescarBoton();
            inputActual = null;
            asuntoActual = null;
            if (document.activeElement && pop.contains(document.activeElement)) document.activeElement.blur();
        }

        // ======================================================================
        //  Enganche a los inputs[type=color]
        // ======================================================================
        function masCercano(btn) {
            var p = btn.parentNode;
            var i = p && p.querySelector ? p.querySelector('input[type="color"]') : null;
            if (i) return { input: i, asunto: p };
            var anc = btn.closest('label, .ed-campo, .rd-color, .sf-color, fieldset, .ed-prop-grupo');
            i = anc && anc.querySelector ? anc.querySelector('input[type="color"]') : null;
            if (i) return { input: i, asunto: anc };
            var nodo = btn.parentElement, nivel = 0;
            while (nodo && nivel < 3) {
                var her = nodo.querySelector ? nodo.querySelector('input[type="color"]') : null;
                if (her) return { input: her, asunto: nodo };
                nodo = nodo.parentElement;
                nivel++;
            }
            return null;
        }

        function enganchar(input) {
            if (input.dataset.ptnListo) return;
            input.dataset.ptnListo = '1';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ptn-disparador';
            btn.title = 'Paleta de colores Pantone';
            btn.setAttribute('aria-label', 'Paleta de colores Pantone');
            btn.setAttribute('aria-haspopup', 'dialog');
            btn.setAttribute('aria-expanded', 'false');
            btn.innerHTML = svgGota() + '<span class="ptn-gotorra" aria-hidden="true">&#127912;</span>';
            // El clic lo gestiona el escuchador global (fase de captura): así se
            // evita que el <label> contenedor abra el selector nativo del input.
            input.insertAdjacentElement('afterend', btn);
        }

        function buscarTodos() {
            document.querySelectorAll('input[type="color"]').forEach(enganchar);
        }

        function alCargar() {
            buscarTodos();
            if (window.MutationObserver) {
                var t = false;
                new MutationObserver(function () {
                    if (t) return;
                    t = true;
                    requestAnimationFrame(function () { buscarTodos(); t = false; });
                }).observe(document.body, { childList: true, subtree: true });
            }
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', alCargar);
        else alCargar();

        window.PANTONE = { colores: COLORES, familias: FAMILIAS, abrir: abrir, cerrar: cerrar, clasificar: clasificar };
    })();

})();
