#!/usr/bin/env python3
"""
extraer_anexo8.py — genera mysql/catalogo_fiscal/isr_pp_art106_<año>.json a partir del
texto del Anexo 8 de la RMF publicado en el DOF (pdftotext -layout).

Uso:  python3 scripts/extraer_anexo8.py anexo8.txt anexo8.pdf 2026 <url-fuente> <salida.json>

Toma SOLO las tarifas mensuales del artículo 106 LISR (sección VI del Anexo 8),
las contrasta contra la propiedad conocida «tarifa del mes n = tarifa del mes 1 × n»
y anota la fuente (documento, URL, DOF, SHA-256 del PDF). No inventa ningún dato.
"""
import hashlib, json, re, sys
from decimal import Decimal

texto_ruta, pdf_ruta, anio, url, salida = sys.argv[1:6]
MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']
fila = re.compile(r'^\s*([\d,]+\.\d\d)\s+([\d,]+\.\d\d|En adelante)\s+([\d,]+(?:\.\d\d)?)\s+(\d+\.\d\d)\s*$')
dinero = lambda t: str(Decimal(t.replace(',', '')))

lineas = open(texto_ruta, encoding='utf-8').read().split('\n')
# La sección VI empieza en la primera «Tarifa del mes de enero» que va seguida de una tabla.
inicio = next(i for i, l in enumerate(lineas) if re.search(r'Tarifa del mes de enero de %s, para efectuar' % anio, l) and i > 100)
tarifas, mes_actual = {}, None
for linea in lineas[inicio:]:
    m = re.search(r'Tarifa del mes de (\w+) de %s, para efectuar' % anio, linea)
    if m:
        mes_actual = MESES.index(m.group(1)) + 1
        tarifas[mes_actual] = []
        continue
    if mes_actual is None:
        continue
    f = fila.match(linea)
    if f:
        tarifas[mes_actual].append({
            'limite_inferior': dinero(f.group(1)),
            'limite_superior': None if f.group(2) == 'En adelante' else dinero(f.group(2)),
            'cuota_fija': dinero(f.group(3)),
            'porcentaje': dinero(f.group(4)),
        })
    if mes_actual == 12 and len(tarifas[12]) == 11:
        break

assert sorted(tarifas) == list(range(1, 13)), 'faltan meses: %s' % sorted(tarifas)
assert all(len(t) == 11 for t in tarifas.values()), {m: len(t) for m, t in tarifas.items()}

# Contraste: mes n = mes 1 × n (límites y cuotas; los porcentajes no cambian).
for n in range(2, 13):
    for base, fila_n in zip(tarifas[1], tarifas[n]):
        assert fila_n['porcentaje'] == base['porcentaje']
        for campo in ('limite_superior', 'cuota_fija'):
            if base[campo] is not None:
                esperado = Decimal(base[campo]) * n
                assert abs(Decimal(fila_n[campo]) - esperado) <= Decimal('0.15') * n, (n, campo, fila_n[campo], esperado)

# Casos de prueba CALCULADOS AQUÍ con Decimal (independientes del motor en PHP):
# impuesto = cuota fija + (utilidad − límite inferior) × % , redondeado a centavos (mitad hacia arriba).
from decimal import ROUND_HALF_UP
def impuesto(mes, utilidad):
    u = Decimal(utilidad)
    for r in tarifas[mes]:
        li, ls = Decimal(r['limite_inferior']), r['limite_superior']
        if u >= li and (ls is None or u <= Decimal(ls)):
            return str((Decimal(r['cuota_fija']) + (u - li) * Decimal(r['porcentaje']) / 100).quantize(Decimal('0.01'), ROUND_HALF_UP))
    return '0.00'
casos = [{'mes': m, 'utilidad': u, 'impuesto': impuesto(m, u)}
         for m in (1, 2, 5, 10, 12)
         for u in ('1.00', '1000.00', '10000.00', '50000.00', '100000.00', '300000.00', '1000000.00', '5000000.00')]

doc = {
    'tipo': 'isr_pagos_provisionales_art106',
    'ejercicio': int(anio),
    'regimen': 'Actividades Empresariales y Profesionales (Título IV, Capítulo II, Sección I LISR)',
    'impuesto': 'ISR',
    'nombre': 'Tarifas mensuales acumuladas del artículo 106 LISR — Anexo 8 de la RMF %s' % anio,
    'fuente': {
        'documento': 'Anexo 8 de la Resolución Miscelánea Fiscal para %s, sección VI' % anio,
        'publicacion': 'Diario Oficial de la Federación, 28 de diciembre de 2025',
        'url': url,
        'sha256_pdf': hashlib.sha256(open(pdf_ruta, 'rb').read()).hexdigest(),
    },
    'vigencia_desde': '%s-01-01' % anio,
    'vigencia_hasta': '%s-12-31' % anio,
    'tarifas': {str(m): tarifas[m] for m in range(1, 13)},
    'casos_prueba': casos,
}
json.dump(doc, open(salida, 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
print('OK', salida, {m: len(t) for m, t in tarifas.items()})
