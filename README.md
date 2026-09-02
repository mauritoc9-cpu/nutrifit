# 🥗 NutriFit — App Gamificada de Nutrición, Hidratación y Entrenamiento

Stack: **PHP 8+ (OOP/PDO)** · **MySQL/MariaDB** · **HTML5/CSS3/JS Vanilla** · **XAMPP**

---

## 📁 Estructura del proyecto

```
nutrifit/
├── database/
│   └── nutrifit_db.sql          ← Importar en phpMyAdmin
├── backend/
│   ├── config/
│   │   ├── database.php         ← Credenciales de conexión PDO
│   │   └── bootstrap.php        ← Sesión, headers, helpers JSON
│   ├── classes/
│   │   ├── Usuario.php          ← Registro / login / lectura de usuario
│   │   ├── MotorMetabolico.php  ← Mifflin-St Jeor, TMB, GET, macros
│   │   └── SistemaXP.php        ← XP, niveles, rachas, multiplicador
│   └── api/
│       ├── auth/                ← registro, login, logout, sesion, onboarding
│       ├── nutricion/           ← comidas, dashboard, buscador, cámara IA, hidratación, lista de compras
│       ├── entrenamiento/       ← rutina recomendada, completar entrenamiento, progreso de peso
│       └── gamificacion/        ← ranking, amigos, tienda
└── frontend/
    ├── index.html               ← Login, registro, onboarding y shell SPA
    ├── css/styles.css
    └── js/
        ├── api.js                ← Cliente Fetch hacia la API PHP
        ├── auth.js                ← Login/registro/onboarding/modo oscuro
        └── app.js                 ← Lógica de las 4 pestañas
```

---

## ⚙️ Instalación paso a paso (XAMPP)

1. **Copia la carpeta `nutrifit/` completa** dentro de `C:\xampp\htdocs\` (Windows) o `/Applications/XAMPP/htdocs/` (Mac).
2. Abre **XAMPP Control Panel** y arranca los módulos **Apache** y **MySQL**.
3. Ve a `http://localhost/phpmyadmin`, crea una nueva pestaña **SQL** (o usa "Importar") y ejecuta el archivo `database/nutrifit_db.sql`. Esto crea la base `nutrifit_db` con las 10 tablas y datos semilla (alimentos, rutinas, tienda).
4. Abre `backend/config/database.php` y confirma que `usuario`/`password` coincidan con tu instalación de MySQL (por defecto XAMPP usa `root` sin contraseña, ya configurado).
5. Abre en el navegador: **`http://localhost/nutrifit/frontend/index.html`**

> Si tu carpeta del proyecto tiene otro nombre distinto a `nutrifit`, actualiza la constante `API_BASE` en `frontend/js/api.js` para que apunte a la ruta correcta (`/tu-carpeta/backend/api`).

---

## 🔑 Flujo de uso

1. **Registro** → crea una cuenta (password se guarda con `password_hash()` BCRYPT).
2. **Onboarding (3 pasos)** → biometría, objetivo principal, estilo de vida.
   - El backend aplica **Mifflin-St Jeor** para calcular TMB → GET → meta calórica y macros.
3. **App principal** con 4 pestañas:
   - **Mi Progreso & Avatar 3D**: XP, nivel, racha, rutina del día (+25 XP al completar).
   - **Nutrición**: anillo calórico, macros, hidratación (+5 XP cada 3 vasos), diario de comidas (+10 XP por registro), recomendación inteligente de próxima comida.
   - **Escáner IA**: simula análisis de cámara (`getUserMedia`) + confirmación manual de gramos antes de registrar. Incluye buscador de respaldo.
   - **Ranking & Tienda**: ligas Bronce/Plata/Oro/Diamante por XP, sistema de amigos, canje de NutriCoins por cosméticos de avatar.

---

## 🧠 Notas técnicas

- Todas las consultas usan **PDO con sentencias preparadas** (protección contra inyección SQL).
- La sesión PHP (`$_SESSION`) protege los endpoints vía `requireAuth()`.
- El **modo oscuro** persiste en `localStorage` y usa variables CSS (`--bg-primary`, `--color-xp`, etc.) según la paleta definida en el brief.
- La navegación es una **SPA real**: las 4 pestañas cambian de vista sin recargar la página (fetch + render de HTML dinámico).
- El **Escáner IA** está simulado (elige un alimento aleatorio del catálogo con gramos estimados). Para producción, reemplaza `backend/api/nutricion/analizar_foto.php` por una llamada real a una API de visión computacional.
- La cámara casca de foto (`analizar_foto.php`) no requiere subir la imagen real — genera una simulación reproducible del flujo de "detección → confirmación manual → registro".

---

## 🗄️ Próximos pasos sugeridos

- Reemplazar la simulación de cámara IA por una API real (Google Vision, Clarifai, o un modelo propio).
- Agregar subida de imágenes de perfil / avatar personalizado (actualmente el avatar es CSS 3D).
- Agregar notificaciones push para recordatorios de hidratación y rachas en riesgo.
