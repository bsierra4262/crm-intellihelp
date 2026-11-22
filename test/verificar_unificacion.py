#!/usr/bin/env python3
"""
Verificación de la base unificada.

Comprueba lo que tiene que ser cierto sí o sí después de correr
`bin/unificar_listas.py`:

  1. Ningún correo queda repetido **en dos fichas de la misma persona**. Una casilla
     compartida por dos personas distintas (`info@`, `ventas@`) sí puede repetirse: son
     dos contactos de verdad, y el CRM ya impide mandarle dos veces a la misma dirección
     dentro de una campaña.
  2. Ninguna persona (nombre + empresa) aparece dos veces.
  3. Todos los contactos de Zoho siguen estando: la unificación no pierde nada.
  4. Todo correo de la base final es sintácticamente válido.
  5. Provincias y países quedaron normalizados.
  6. Los archivos parciales suman exactamente el total.

Uso:  python test/verificar_unificacion.py [carpeta]
"""
# ============================================================
#  Notas que no entran en la ayuda de arriba:
#
#  · No forma parte de las baterias que corre `deploy.py test`: se corre a
#    mano en la maquina de desarrollo, despues del unificador, contra los CSV
#    de «Documentacion a consultar/CSV/» (datos reales, fuera de git).
#  · Codigo de salida: 0 si todo paso, 1 si algo fallo o falta la salida.
#  · Repite a proposito algunas reglas del unificador (plano, _tokens,
#    misma_persona, la lista de provincias) en vez de importarlas: si se
#    cambian alla, hay que cambiarlas aca, o la verificacion juzga con otra
#    regla.
#  · Precision sobre el punto 3 de la ayuda: lo que se comprueba es que
#    ningun CORREO de Zoho se pierda. Un contacto de Zoho sin correo no se
#    controla.
# ============================================================
from __future__ import annotations

import csv
import io
import os
import re
import sys
import unicodedata
from collections import Counter, defaultdict

csv.field_size_limit(10_000_000)

for _f in (sys.stdout, sys.stderr):
    try:
        _f.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:
        pass

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSV_DIR = os.path.join(RAIZ, "Documentacion a consultar", "CSV")
SALIDA = os.path.join(CSV_DIR, "Normalizada completa")
BASE_ZOHO = os.path.join(CSV_DIR, "DB Zoho.csv")

PROVINCIAS_OK = {
    "Ciudad de Buenos Aires", "Buenos Aires", "Catamarca", "Chaco", "Chubut", "Córdoba",
    "Corrientes", "Entre Ríos", "Formosa", "Jujuy", "La Pampa", "La Rioja", "Mendoza",
    "Misiones", "Neuquén", "Río Negro", "Salta", "San Juan", "San Luis", "Santa Cruz",
    "Santa Fe", "Santiago del Estero", "Tierra del Fuego", "Tucumán",
}
RE_EMAIL = re.compile(r"^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$")

# Contadores globales de comprobaciones (T) y fallidas (F), y los nombres de
# las que fallaron para el resumen final.
T = F = 0
fallos: list[str] = []


# Registra una comprobacion: imprime [OK] o [XX] con el detalle y suma a los
# contadores. No corta la ejecucion: se corren todas y se informa al final.
def ok(nombre: str, cond: bool, detalle: str = "") -> None:
    global T, F
    T += 1
    if cond:
        print(f"  [OK] {nombre}" + (f"  {detalle}" if detalle else ""))
    else:
        F += 1
        fallos.append(nombre)
        print(f"  [XX] {nombre}  {detalle}")


# Copia de plano() y _tokens() del unificador: minusculas, sin acentos, sin
# espacios de mas; y las palabras significativas de un nombre.
def plano(s: str) -> str:
    s = unicodedata.normalize("NFKD", (s or "").strip().lower())
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"\s+", " ", s)


def _tokens(v: str) -> set[str]:
    p = re.sub(r"[^\wáéíóúñü ]", " ", plano(v))
    fuera = {"sr", "sra", "srta", "dr", "dra", "ing", "lic", "cr", "cra", "clor", "mg", "arq"}
    return {t for t in p.split() if len(t) > 1 and t not in fuera}


def misma_persona(a: dict, b: dict) -> bool:
    """Misma regla que usa el unificador, para juzgar un correo repetido."""
    ap1 = re.sub(r"[^\w ]", "", plano(a["Apellidos"])).strip()
    ap2 = re.sub(r"[^\w ]", "", plano(b["Apellidos"])).strip()
    if ap1 and ap2 and not (ap1.startswith(ap2) or ap2.startswith(ap1)):
        return False
    n1, n2 = _tokens(a["Nombre"]), _tokens(b["Nombre"])
    if not n1 or not n2:
        return True
    if n1 & n2:
        return True
    return any(x.startswith(y) or y.startswith(x) for x in n1 for y in n2)


# Lee un CSV de salida del unificador (UTF-8 con BOM, separador «;»).
def leer(ruta: str) -> list[dict]:
    with io.open(ruta, encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f, delimiter=";"))


# Todos los correos validos de la base de Zoho (principal, secundario y
# terciario). Asume separador «,»: el unificador detecta el separador y este
# no, asi que con un DB Zoho.csv separado por «;» devolveria un conjunto vacio
# y la comprobacion «no se perdio nada» pasaria sin controlar nada.
# A diferencia de norm_email(), no saca <> ni comillas antes de validar: esas
# direcciones quedan afuera del control.
# Si ninguna codificacion sirve, devuelve un conjunto vacio (mismo efecto).
def leer_zoho_emails(ruta: str) -> set[str]:
    for cod in ("utf-8-sig", "cp1252"):
        try:
            with io.open(ruta, encoding=cod, newline="") as f:
                r = csv.DictReader(f)
                out = set()
                for fila in r:
                    for col in ("Correo electrónico", "Correo electrónico secundario",
                                "Correo electrónico terciario"):
                        v = (fila.get(col) or "").strip().lower()
                        if RE_EMAIL.match(v):
                            out.add(v)
                return out
        except UnicodeDecodeError:
            continue
    return set()


# Corre todas las comprobaciones sobre la carpeta de salida (la del
# argumento o la por defecto) e imprime el resumen. La base de Zoho se lee
# siempre de la ruta por defecto, aunque se pase otra carpeta.
def main() -> int:
    carpeta = sys.argv[1] if len(sys.argv) > 1 else SALIDA
    unificados = os.path.join(carpeta, "contactos-unificados.csv")
    if not os.path.isfile(unificados):
        print(f"No existe {unificados}. Corré primero: python bin/unificar_listas.py")
        return 1

    filas = leer(unificados)
    nuevos = leer(os.path.join(carpeta, "contactos-nuevos.csv"))
    en_zoho = leer(os.path.join(carpeta, "contactos-en-zoho.csv"))

    print("== Integridad de la base unificada ==")
    ok("la base final tiene contactos", len(filas) > 0, f"({len(filas)})")
    ok("los parciales suman el total", len(nuevos) + len(en_zoho) == len(filas),
       f"({len(nuevos)} nuevos + {len(en_zoho)} en Zoho = {len(nuevos)+len(en_zoho)} vs {len(filas)})")

    print("\n== Correos: sin duplicados de la misma persona ==")
    cuenta = Counter()
    fichas_por_email = defaultdict(list)
    cols_mail = ["Correo electrónico", "Correo electrónico secundario",
                 "Correo electrónico terciario", "Otros correos"]
    # Cada correo se cuenta una vez por fila; se guardan hasta 8 fichas por
    # correo, que alcanzan para juzgar si son la misma persona.
    for r in filas:
        vistos_fila = set()
        for c in cols_mail:
            for em in (r.get(c) or "").split(","):
                em = em.strip().lower()
                if em and em not in vistos_fila:
                    vistos_fila.add(em)
                    cuenta[em] += 1
                    if len(fichas_por_email[em]) < 8:
                        fichas_por_email[em].append(r)

    # Un correo repetido sólo es un ERROR si las dos fichas son la misma persona.
    mismas, compartidos = [], []
    for em, n in cuenta.items():
        if n < 2:
            continue
        grupo = fichas_por_email[em]
        base = grupo[0]
        if all(misma_persona(base, otra) for otra in grupo[1:]):
            mismas.append((em, [f'{g["Nombre"]} {g["Apellidos"]} · {g["Nombre de Cuenta"]}'
                                for g in grupo]))
        else:
            compartidos.append(em)

    muestra = "; ".join(f"{e} → {v}" for e, v in mismas[:3])
    ok("ningún correo queda repetido en dos fichas de la misma persona", not mismas,
       f"({len(mismas)} sin fusionar) {muestra[:280]}")
    ok("hay correos cargados", len(cuenta) > 0, f"({len(cuenta)} direcciones únicas)")
    print(f"   ·  casillas compartidas por personas distintas (esperable): {len(compartidos)}")

    print("\n== Sin personas repetidas ==")
    # Clave nombre + empresa con plano(), mas estricta que la del unificador
    # (que ademas saca el tipo societario): «Acme SA» y «Acme» cuentan aca
    # como empresas distintas, asi que esta comprobacion no da falsos errores
    # por eso, pero tampoco detecta esos duplicados.
    personas = Counter()
    solo_nombre = Counter()
    for r in filas:
        nom = plano(f'{r["Nombre"]} {r["Apellidos"]}')
        emp = plano(r["Nombre de Cuenta"])
        if nom and emp:
            personas[f"{nom}|{emp}"] += 1
        elif nom:
            solo_nombre[nom] += 1
    dup_personas = [k for k, n in personas.items() if n > 1]
    ok("ninguna persona (nombre + empresa) se repite", not dup_personas,
       f"({len(dup_personas)} repetidas) {dup_personas[:3]}")
    sin_emp = len([k for k, n in solo_nombre.items() if n > 1])
    print(f"   ·  homónimos sin empresa cargada, no se fusionan por precaución: {sin_emp}")

    print("\n== No se perdió nada de Zoho ==")
    emails_zoho = leer_zoho_emails(BASE_ZOHO)
    en_final = set(cuenta)
    perdidos = emails_zoho - en_final
    ok("todo correo que estaba en Zoho sigue en la base final", not perdidos,
       f"({len(perdidos)} perdidos de {len(emails_zoho)}) {list(perdidos)[:3]}")
    # 13000 es un piso fijo, sacado del tamano de la base de Zoho de la
    # migracion (13.573 contactos menos sus duplicados internos). Con otra
    # base hay que ajustarlo.
    marcados = sum(1 for r in filas if r["Estado en el CRM"] == "Ya estaba en el CRM")
    ok("los contactos de Zoho quedaron marcados como existentes", marcados >= 13000,
       f"({marcados})")

    print("\n== Calidad de los datos ==")
    invalidos = [e for e in cuenta if not RE_EMAIL.match(e)]
    ok("todos los correos son sintácticamente válidos", not invalidos,
       f"({len(invalidos)}) {invalidos[:3]}")

    prov_malas = Counter(r["Provincia"] for r in filas
                         if r["Provincia"] and r["Provincia"] not in PROVINCIAS_OK)
    # Se aceptan provincias extranjeras: la base tiene contactos de otros países.
    ar = sum(1 for r in filas if r["País para correspondencia"] == "Argentina"
             and r["Provincia"] and r["Provincia"] not in PROVINCIAS_OK)
    ok("las provincias argentinas están normalizadas", ar == 0,
       f"({ar} sin normalizar) {list(prov_malas)[:5]}")

    paises_raros = [r["País para correspondencia"] for r in filas
                    if r["País para correspondencia"].startswith("http")
                    or len(r["País para correspondencia"]) > 40]
    ok("ningún país trae basura (URLs, textos largos)", not paises_raros,
       f"({len(paises_raros)}) {paises_raros[:2]}")

    localidades_raras = [r["Localidad"] for r in filas
                         if r["Localidad"] and (len(r["Localidad"]) > 60
                                                or r["Localidad"].count(",") >= 2)]
    ok("ninguna localidad trae basura de LinkedIn", not localidades_raras,
       f"({len(localidades_raras)}) {localidades_raras[:2]}")

    sin_apellido = sum(1 for r in filas if not r["Apellidos"].strip())
    ok("casi todos tienen apellido (lo exige el CRM)", sin_apellido < len(filas) * 0.02,
       f"({sin_apellido} sin apellido de {len(filas)})")

    print("\n== Trazabilidad ==")
    sin_origen = sum(1 for r in filas if not r["Origen de la lista"].strip())
    ok("cada contacto dice de qué lista salió", sin_origen == 0, f"({sin_origen} sin origen)")
    con_estado = sum(1 for r in filas if r["Estado del correo"])
    ok("los correos tienen su estado de verificación", con_estado > 0, f"({con_estado})")

    print("\n" + "=" * 60)
    print(f"  {T} comprobaciones · {T - F} correctas · {F} fallidas")
    if fallos:
        print("\n  Fallaron:")
        for x in fallos:
            print(f"   - {x}")
    print("=" * 60)
    return 1 if F else 0


if __name__ == "__main__":
    sys.exit(main())
