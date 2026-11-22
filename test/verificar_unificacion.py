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

Uso:  python test/verificar_unificacion.py [carpeta] [--minimo-zoho N]
"""
# ============================================================
#  Notas que no entran en la ayuda de arriba:
#
#  · No forma parte de las baterias que corre `deploy.py test`: se corre a
#    mano en la maquina de desarrollo, despues del unificador, contra los CSV
#    de «Documentacion a consultar/CSV/» (datos reales, fuera de git).
#  · Codigo de salida: 0 si todo paso, 1 si algo fallo o falta la salida.
#  · Las reglas (plano, misma_persona, la lista de provincias, la deteccion
#    de codificacion y separador) se importan del unificador: si se cambian
#    alla, la verificacion juzga con la misma regla nueva.
#  · Precision sobre el punto 3 de la ayuda: lo que se comprueba es que
#    ningun CORREO de Zoho se pierda. Un contacto de Zoho sin correo no se
#    controla.
# ============================================================
from __future__ import annotations

import csv
import io
import os
import sys
from collections import Counter, defaultdict

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(RAIZ, "bin"))
# Las mismas reglas del unificador. Al importarlo ya se agranda el limite de
# campo del modulo csv y la salida pasa a UTF-8.
import unificar_listas as U  # noqa: E402

CSV_DIR = U.CSV_DIR
SALIDA = U.DIR_SALIDA
BASE_ZOHO = U.BASE_ZOHO

PROVINCIAS_OK = set(U.PROVINCIAS)
RE_EMAIL = U.RE_EMAIL
plano = U.plano

# Piso de contactos de Zoho que tienen que quedar marcados como existentes.
# 13000 sale de la base de la migracion (13.573 contactos menos sus
# duplicados internos); con otra base se cambia con --minimo-zoho N.
MINIMO_ZOHO = 13000

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


def misma_persona(a: dict, b: dict) -> bool:
    """La regla del unificador, aplicada a dos filas del CSV de salida."""
    return U.misma_persona({"nombre": a["Nombre"], "apellido": a["Apellidos"]},
                           {"nombre": b["Nombre"], "apellido": b["Apellidos"]})


# Lee un CSV de salida del unificador (UTF-8 con BOM, separador «;»).
def leer(ruta: str) -> list[dict]:
    with io.open(ruta, encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f, delimiter=";"))


# Todos los correos validos de la base de Zoho (principal, secundario y
# terciario), normalizados igual que en el unificador (norm_email). La
# codificacion y el separador se detectan con la misma funcion que usa el
# unificador: antes se asumia «,» y, con un archivo separado por «;», el
# conjunto quedaba vacio y «no se perdio nada» pasaba sin controlar nada.
# Devuelve None si no hay ninguna columna de correo, para que el control
# falle en vez de pasar en falso.
def leer_zoho_emails(ruta: str) -> set[str] | None:
    cod, sep = U.detectar_formato(ruta)
    cols = ("Correo electrónico", "Correo electrónico secundario", "Correo electrónico terciario")
    with io.open(ruta, encoding=cod, newline="") as f:
        r = csv.DictReader(f, delimiter=sep)
        if not any(c in (r.fieldnames or []) for c in cols):
            return None
        out = set()
        for fila in r:
            for col in cols:
                v = U.norm_email(fila.get(col) or "")
                if v:
                    out.add(v)
        return out


# Corre todas las comprobaciones sobre la carpeta de salida (la del
# argumento o la por defecto) e imprime el resumen. La base de Zoho se lee
# siempre de la ruta por defecto, aunque se pase otra carpeta.
def main() -> int:
    args = sys.argv[1:]
    minimo = MINIMO_ZOHO
    if "--minimo-zoho" in args:
        i = args.index("--minimo-zoho")
        minimo = int(args[i + 1])
        del args[i:i + 2]
    carpeta = args[0] if args else SALIDA
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
    emails_zoho = leer_zoho_emails(BASE_ZOHO) if os.path.isfile(BASE_ZOHO) else None
    ok("se pudieron leer los correos de la base de Zoho", bool(emails_zoho), f"({BASE_ZOHO})")
    emails_zoho = emails_zoho or set()
    en_final = set(cuenta)
    perdidos = emails_zoho - en_final
    ok("todo correo que estaba en Zoho sigue en la base final", bool(emails_zoho) and not perdidos,
       f"({len(perdidos)} perdidos de {len(emails_zoho)}) {list(perdidos)[:3]}")
    marcados = sum(1 for r in filas if r["Estado en el CRM"] == "Ya estaba en el CRM")
    ok("los contactos de Zoho quedaron marcados como existentes", marcados >= minimo,
       f"({marcados}, piso {minimo})")

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
