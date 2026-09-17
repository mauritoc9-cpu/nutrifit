#!/bin/bash
#
# Test POST directo a analizar_foto.php vía ngrok
# Simula lo que hace el frontend: envía imagen base64 y verifica respuesta
#

# URL del escáner vía ngrok
NGROK_URL="https://bats-dislocate-ahead.ngrok-free.dev/nutrifittwo/backend/api/nutricion/analizar_foto.php"

# Generar una imagen JPEG pequeña y convertir a base64
# (Usando Python porque bash no tiene imagencreatetruecolor)
IMAGEN_BASE64=$(python3 << 'PYTHON'
from PIL import Image, ImageDraw
import base64
import io

# Crear imagen de comida (pizza simple)
img = Image.new('RGB', (300, 300), color='orange')
draw = ImageDraw.Draw(img)
draw.text((50, 140), "Pizza de Prueba", fill='white')

# Guardar a buffer
buffer = io.BytesIO()
img.save(buffer, format='JPEG', quality=85)
buffer.seek(0)

# Convertir a base64
b64 = base64.b64encode(buffer.read()).decode('utf-8')
print(b64)
PYTHON
)

if [ -z "$IMAGEN_BASE64" ]; then
    echo "ERROR: No se pudo generar imagen"
    exit 1
fi

echo "=== TEST POST VÍA NGROK ==="
echo "URL: $NGROK_URL"
echo "Imagen base64 length: ${#IMAGEN_BASE64}"
echo ""

# Hacer POST
echo "Enviando POST..."
RESPONSE=$(curl -s -X POST "$NGROK_URL" \
  -H "Content-Type: application/json" \
  -b "PHPSESSID=" \
  --data "{\"imagen_base64\": \"$IMAGEN_BASE64\"}" 2>&1)

echo "=== RESPUESTA ==="
echo "$RESPONSE" | jq . 2>/dev/null || echo "$RESPONSE"
