#!/usr/bin/env python3
"""dHash de 64 bits para cada imagen recibida por argumento.

Algoritmo acordado con el homelab, que calcula lo mismo de su lado para
comparar: escala de grises, redimensionar a 9x8 con LANCZOS y, por cada fila
de arriba a abajo, bit = 1 si pixel[x] > pixel[x+1] para x de 0 a 7. Los bits
van MSB primero y salen 16 dígitos hex.

Salida: una línea "ruta<TAB>hash" por imagen, o "ruta<TAB>" si falla.
Tiene que ser Pillow: el gris y el Lanczos de GD o ImageMagick no coinciden
bit a bit con los suyos.
"""
import sys

from PIL import Image


def dhash(path: str) -> str:
    with Image.open(path) as image:
        small = image.convert('L').resize((9, 8), Image.LANCZOS)
        pixels = small.load()

    value = 0
    for y in range(8):
        for x in range(8):
            value = (value << 1) | (1 if pixels[x, y] > pixels[x + 1, y] else 0)

    return f'{value:016x}'


for path in sys.argv[1:]:
    try:
        print(f'{path}\t{dhash(path)}')
    except Exception:  # noqa: BLE001 — una imagen rota no debe tumbar el resto
        print(f'{path}\t')
