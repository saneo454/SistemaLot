# Cómo echar a andar el proyecto

Guía rápida para dejar funcionando el sistema de asistencia en tu computadora.
Toma unos 15 minutos. Si algo falla, hasta abajo están los problemas más comunes.

---

## Lo que necesitas

- **XAMPP** (trae Apache + MySQL + PHP juntos) → https://www.apachefriends.org
- **Arduino IDE**, solo si vas a programar las cajas ESP32

---

## Paso 1 — Poner la carpeta en su lugar

Copia la carpeta `esp32-attendance-system` completa dentro de la carpeta
`htdocs` de XAMPP:

- **Windows:** `C:\xampp\htdocs\esp32-attendance-system`
- **Mac:** `/Applications/XAMPP/htdocs/esp32-attendance-system`

## Paso 2 — Prender XAMPP

Abre el panel de XAMPP y dale **Start** a **Apache** y a **MySQL**.
Los dos tienen que quedar en verde.

## Paso 3 — Crear la base de datos

1. Entra a http://localhost/phpmyadmin
2. Clic en **Nueva** (izquierda) y crea una base llamada exactamente:
   ```
   esp32_attendance
   ```
   Con cotejamiento `utf8mb4_unicode_ci`.
3. Con esa base seleccionada, ve a la pestaña **Importar** y sube:
   ```
   database/esp32_attendance.sql
   ```
4. **Sin salirte**, ve otra vez a **Importar** y ahora sube:
   ```
   database/migracion_v2.sql
   ```

> Los dos archivos, y **en ese orden**. El segundo agrega las tablas de
> dispositivos, inscripciones y alta de tarjetas. Sin él, el sistema no abre.

## Paso 4 — Comprobar que quedó bien

Abre en el navegador:

```
http://localhost/esp32-attendance-system/verificacion.php
```

Esa página revisa todo solita: la conexión, la hora, si ya corrió la
migración, cuántos usuarios hay y si el bot de Telegram responde. Si algo
está mal, ahí mismo te dice cómo arreglarlo.

Cuando todo salga en verde, **borra ese archivo** (`verificacion.php`).

## Paso 5 — Entrar al sistema

```
http://localhost/esp32-attendance-system/login.php
```

Si no sabes la contraseña del administrador, pégale esto a phpMyAdmin en la
pestaña **SQL** (deja la contraseña en `admin123`):

```sql
UPDATE usuarios
SET contrasena = '$2y$12$YZYhwFZmtMi1q5iWFKZ6iOyJZE372uArYlEsX0Q3LanWeovDgPxhe'
WHERE matricula = 'ADMIN01';
```

Entras con usuario `ADMIN01` y contraseña `admin123`. Cámbiala desde
Usuarios en cuanto puedas.

---

## Paso 6 — Programar la caja ESP32 (solo si tienes una)

Abre `esp32_code/esp32_code.ino` en el Arduino IDE.

**Instala primero estas tres bibliotecas** (Herramientas → Administrar
bibliotecas): `MFRC522`, `Keypad` y `LiquidCrystal I2C`. También necesitas
el soporte para placas ESP32.

Luego cambia estas cuatro líneas de hasta arriba:

```cpp
const char* ssid     = "NOMBRE_DE_TU_WIFI";
const char* password = "CONTRASENA_DEL_WIFI";

// La IP de la computadora que corre XAMPP (no "localhost")
const String serverBaseUrl = "http://192.168.1.15/esp32-attendance-system/api";

// IMPORTANTE: cada caja debe tener un ID DIFERENTE
const String DEVICE_ID = "ESP32-A214-01";
```

Para saber la IP de tu computadora:
- **Windows:** abre `cmd` y escribe `ipconfig` → busca "Dirección IPv4"
- **Mac:** Ajustes → Red → Wi-Fi → Detalles

La computadora con XAMPP y las cajas deben estar en **la misma red WiFi**.

### Cómo se usa la caja

| Para qué | Qué hacer |
|---|---|
| Pasar lista con tarjeta | Acercarla al lector |
| Pasar lista con matrícula | Teclearla y picar `#` |
| Meter el código de Telegram | Los 4 dígitos y `#` |
| Cancelar | Picar `*` |
| Dar de alta una tarjeta | `*` en reposo → tarjeta del maestro → matrícula + `#` → tarjeta nueva |

---

## Problemas comunes

**MySQL no arranca en XAMPP.**
Ya hay otro MySQL usando el puerto 3306. En Mac suele ser el MySQL de Oracle
(Ajustes del Sistema → MySQL → *Stop MySQL Server*). En Windows, revisa si
tienes otro XAMPP o un MySQL instalado aparte.

**Apache no arranca.**
Algo ocupa el puerto 80: Skype, IIS u otro servidor. Ciérralo, o cambia el
puerto de Apache a 8080 desde el panel de XAMPP (y entonces las direcciones
serían `http://localhost:8080/...`).

**"No se pudo conectar con la base de datos".**
Tu MySQL tiene contraseña para `root`. Ábrela en `includes/conexion.php`,
línea 7:
```php
$pass = 'aqui_tu_contrasena';
```

**Las páginas dicen "¿ya ejecutaste migracion_v2.sql?".**
Falta el paso 3.4. Importa ese archivo.

**Las asistencias se guardan con la hora equivocada.**
Cambia la zona horaria en `includes/config.php`:
```php
define('APP_TIMEZONE', 'America/Mexico_City');
```

**La caja dice "SIN SERVIDOR".**
No alcanza a la computadora. Revisa que estén en el mismo WiFi, que la IP de
`serverBaseUrl` sea la correcta y que Apache esté prendido. Pruébalo desde el
celular abriendo esa misma dirección en el navegador.

**No llegan los códigos de Telegram.**
El alumno necesita tener su *chat ID* de Telegram capturado en su usuario. Si
no lo tiene, el sistema pide la tarjeta de un maestro para autorizarlo, y eso
es normal.

---

## Cómo está armado

| Carpeta / archivo | Qué es |
|---|---|
| `login.php`, `index.php` | Entrada y panel principal |
| `mis_clases.php` | Vista del alumno |
| `pase_lista.php` | Vista del docente |
| `usuarios.php`, `clases.php`, `inscripciones.php`, `dispositivos.php` | Panel del administrador |
| `historial.php` | Historial con filtros y exportación a CSV |
| `api/` | Lo que consumen las cajas ESP32 |
| `includes/` | Configuración y conexión a la base |
| `database/` | Los dos archivos SQL |
| `esp32_code/` | El programa de la caja |
| `INSTRUCCIONES.md` | Explicación a detalle de cómo funciona todo |

---

## Una advertencia

El token del bot de Telegram viene escrito dentro de `includes/config.php`.
Sirve para que funcionen los códigos, pero **no subas esta carpeta a un
repositorio público de GitHub**: cualquiera que lo vea podría controlar el bot.
Si lo van a subir, saquen ese token antes.
