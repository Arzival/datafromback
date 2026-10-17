#!/usr/bin/env python3
"""Copia reducida de una imagen, sin cargarla en la memoria de PHP.

Uso: resize.py <origen> <destino> <ancho|lado> <píxeles> <calidad>

- ancho: limita el ancho (miniaturas del catálogo).
- lado: limita el lado largo (copia que se le pasa a la IA).

El formato sale de la extensión del destino. Nunca amplía. Sale con código
distinto de 0 si no pudo.
"""
import sys

from PIL import Image

# Las capturas de Dribbble pasan de los 40 Mpx y Pillow las tomaría por una
# bomba de descompresión. Vienen de nuestra propia bandeja, son de fiar.
Image.MAX_IMAGE_PIXELS = None

source, target, mode, limit, quality = sys.argv[1:6]
limit = int(limit)

with Image.open(source) as image:
    image.load()
    width, height = image.size
    scale = limit / (width if mode == 'ancho' else max(width, height))

    if scale < 1:
        image = image.resize((max(1, round(width * scale)), max(1, round(height * scale))), Image.LANCZOS)

    if target.lower().endswith(('.jpg', '.jpeg')) and image.mode != 'RGB':
        # JPEG no tiene transparencia: se aplana sobre blanco.
        flat = Image.new('RGB', image.size, (255, 255, 255))
        rgba = image.convert('RGBA')
        flat.paste(rgba, mask=rgba.getchannel('A'))
        image = flat

    image.save(target, quality=int(quality))
