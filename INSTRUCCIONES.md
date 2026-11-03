# Sistema de Asistencia IoT con ESP32

Control de asistencia escolar con cajas ESP32 (lector RFID + teclado matricial + LCD),
verificación en dos pasos por Telegram y un panel web en PHP/MySQL.

---

## 1. Estructura del proyecto

| Ruta | Qué es |
|---|---|
| `index.php` | Panel principal (cambia según el rol: alumno, docente, administrativo) |
| `login.php` / `logout.php` | Acceso al sistema |
| `historial.php` | Historial filtrable + exportación a CSV |
| `mis_clases.php` | **Alumno**: asistencia materia por materia, faltas y porcentajes |
| `pase_lista.php` | **Docente**: lista de la sesión, con corrección manual |
| `usuarios.php` | **Admin**: alta, edición, baja y registro de tarjetas RFID |
| `clases.php` | **Admin**: alta y edición de materias y horarios |
| `inscripciones.php` | **Admin**: asignación de clases a alumnos |
| `dispositivos.php` | **Admin**: cajas ESP32 y su ID único |
| `api/attendance.php` | Recibe las lecturas de la caja (RFID / teclado / firma docente) |
| `api/verify_code.php` | Valida el código de 4 dígitos de Telegram |
| `api/rfid_enroll.php` | Alta de tarjetas desde la web y desde la caja |
| `api/attendance_helper.php` | Horarios, estados, dispositivos y respuestas JSON |
| `api/rfid_helper.php` | Lógica de enrolamiento de tarjetas |
| `includes/config.php` | Zona horaria, token de Telegram, tolerancias |
| `includes/conexion.php` | Conexión PDO (alinea la hora de MySQL con la de PHP) |
| `database/esp32_attendance.sql` | Base de datos inicial |
| `database/migracion_v2.sql` | **Migración obligatoria** con las tablas nuevas |
| `esp32_code/esp32_code.ino` | Firmware de la caja |

> La carpeta `public/`, `server.js` y `data/` son el prototipo anterior en Node.js.
> El sistema actual es el de PHP; puedes conservarlos como referencia.

---

## 2. Instalación

### 2.1 Base de datos

1. Abre **phpMyAdmin**.
2. Importa `database/esp32_attendance.sql` (si aún no tienes la base creada).
3. Abre la base `esp32_attendance` → pestaña **SQL** → pega y ejecuta el contenido de
   **`database/migracion_v2.sql`**.

La migración agrega:

- `dispositivos` — el ID único de cada caja ESP32.
- `asistencias.dispositivo_id` — desde qué caja se registró cada asistencia.
- `inscripciones` — qué alumno pertenece a qué clase.
- `enrolamientos_rfid` — solicitudes de alta de tarjeta.

Además rellena las inscripciones automáticamente con las coincidencias actuales de
carrera + grupo + semestre, para no perder la configuración previa.

### 2.2 Configuración del servidor

Edita `includes/config.php`:

```php
define('APP_TIMEZONE', 'America/Mexico_City'); // Zona horaria de la escuela
define('TELEGRAM_BOT_TOKEN', 'tu_token_de_bot');
define('TOLERANCIA_RETARDO', 10);  // minutos → después de esto, retardo
define('TOLERANCIA_FALTA', 20);    // minutos → después de esto, falta
```

Y `includes/conexion.php` con los datos de MySQL (usuario, contraseña, host).

### 2.3 Firmware del ESP32

Abre `esp32_code/esp32_code.ino` en el Arduino IDE y ajusta el bloque de configuración:

```cpp
const char* ssid     = "TU_SSID_WIFI";
const char* password = "TU_PASSWORD_WIFI";

// IP del servidor + carpeta del proyecto + /api
const String serverBaseUrl = "http://192.168.100.77/esp32-attendance-system/api";

// ¡IMPORTANTE! Un ID DISTINTO para cada caja
const String DEVICE_ID = "ESP32-A214-01";
```

Sube el programa a cada ESP32 cambiando `DEVICE_ID` en cada uno.
No hace falta darlas de alta a mano: la caja aparece sola en **Cajas ESP32**
la primera vez que envía una lectura, y ahí le pones nombre y aula.

---

## 3. Cómo se usa la caja

| Acción | Qué hacer |
|---|---|
| Pasar lista con tarjeta | Acercar la tarjeta al lector |
| Pasar lista con matrícula | Teclear la matrícula y pulsar `#` |
| Escribir el código de Telegram | Teclear los 4 dígitos y pulsar `#` |
| Cancelar lo que sea | Pulsar `*` |
| **Dar de alta una tarjeta** | Pulsar `*` en reposo → pasar la tarjeta del docente → teclear la matrícula del alumno + `#` → acercar la tarjeta nueva |

### Los tres flujos de asistencia

1. **Tarjeta + Telegram**: se lee la tarjeta, llega un código de 4 dígitos al Telegram
   del alumno y lo escribe en el teclado.
2. **Matrícula + Telegram**: igual, pero tecleando la matrícula en vez de la tarjeta.
3. **Firma del docente**: si el alumno no tiene Telegram configurado, la caja pide la
   tarjeta de un docente o administrativo para autorizar el registro.

---

## 4. Registro de tarjetas RFID

Se puede hacer de dos maneras:

**Desde la página** (Usuarios → botón de la tarjeta 🪪 en el alumno):
se abre una ventana, se elige la caja, se pulsa "Iniciar lectura" y se acerca la tarjeta.
La caja consulta al servidor cada 4 segundos, así que reacciona sola.
La ventana avisa cuando la tarjeta queda guardada.

**Desde la caja**: pulsar `*` en reposo y seguir los pasos de la tabla de arriba.
Siempre pide la tarjeta de un docente o administrativo antes de continuar, para que
nadie pueda asignarse una tarjeta por su cuenta.

En ambos casos, si la tarjeta ya pertenece a otra persona el sistema lo avisa y no
la reasigna.

---

## 5. Clases, inscripciones y pase de lista

1. **Clases** (`clases.php`): das de alta la materia, el docente, el día y el horario.
   El sistema avisa si el docente ya tiene otra clase encimada a esa hora.
2. **Inscripciones** (`inscripciones.php`): eliges la clase y agregas alumnos, ya sea
   uno por uno (con buscador) o en bloque con el botón
   *"Inscribir a todo el grupo configurado"*, que usa la carrera, grupo y semestre de la clase.
3. **Pase de lista** (`pase_lista.php`): el docente elige la clase y la fecha, y ve a
   todos sus inscritos con su estado. Puede marcar manualmente
   presente / retardo / falta / justificado, o borrar un registro equivocado.

Cuando alguien pasa lista en la caja, el sistema busca su clase activa **primero en las
inscripciones** y, si no encuentra nada, cae al método anterior por carrera/grupo/semestre.

### Qué se puede editar y desde dónde

| Qué | Dónde | Quién |
|---|---|---|
| Usuarios (datos, rol, estado, contraseña, tarjeta) | `usuarios.php` | Administrativo |
| Clases y horarios | `clases.php` | Administrativo |
| Inscripciones (alta y baja de alumnos) | `inscripciones.php` | Administrativo |
| Cajas ESP32 (nombre, aula, activa/inactiva) | `dispositivos.php` | Administrativo |
| Asistencias de sus propias clases | `pase_lista.php` | Docente |
| **Cualquier asistencia** (estado y materia) o borrarla | `historial.php` | Administrativo |

En el historial, el administrativo tiene una columna **Acciones** con el lápiz para
corregir el registro (estado y materia) y el bote para eliminarlo. Los filtros que
tengas puestos se conservan al editar.

---

## 6. Cálculo de asistencia

- **Presente**: llegó dentro de los primeros `TOLERANCIA_RETARDO` minutos.
- **Retardo**: llegó entre `TOLERANCIA_RETARDO` y `TOLERANCIA_FALTA` minutos.
- **Falta**: llegó después de `TOLERANCIA_FALTA` minutos.
- **Justificado**: solo lo marca el docente desde el pase de lista.

En las vistas del alumno, el porcentaje se calcula sobre las **sesiones realmente
impartidas** (los días en que se pasó lista en esa materia), así que los días en que el
alumno no apareció cuentan como falta aunque no exista un registro suyo.

Un alumno no puede registrar dos veces la asistencia de la misma clase el mismo día:
la caja responde "Ya registrado".

---

## 7. Zona horaria

Antes, PHP usaba la hora de México pero MySQL guardaba con `CURDATE()`/`CURTIME()`,
que toman la hora del servidor (UTC en AWS). Las asistencias salían con horas de
diferencia y el cálculo de retardos fallaba.

Ahora:

- La zona se define en un solo lugar: `APP_TIMEZONE` en `includes/config.php`.
- `includes/conexion.php` le manda a MySQL el mismo desplazamiento al conectarse.
- Las asistencias se insertan con la fecha y hora calculadas en PHP, no en MySQL.

Si el proyecto se mueve a otro estado o país, basta con cambiar `APP_TIMEZONE`.

---

## 8. Notas del despliegue en AWS

```bash
ssh -i "ruta/a/KeyPair.pem" ubuntu@44.211.83.193
```

> ⚠️ El archivo `KeyPair.pem` es una llave privada: no lo subas a ningún repositorio
> ni lo compartas. El `.gitignore` del proyecto ya lo excluye.

Si el navegador bloquea la página por no usar HTTPS, estas banderas de Chrome ayudan
durante las pruebas locales:

- `chrome://flags/#unsafely-treat-insecure-origin-as-secure`
- `chrome://flags/#enable-webrtc-hide-local-ips-with-mdns`

---

## Catálogo de carreras

Las carreras ya no se escriben a mano: se eligen de una lista desplegable en
**Usuarios** (alumnos) y **Clases** (grupo objetivo). El catálogo vive en un solo
lugar: `includes/carreras.php` (constante `CARRERAS`).

Carreras disponibles:

1. Ingeniería en Tecnologías de la Información e Innovación Digital
2. Ingeniería Mecatrónica
3. Ingeniería en Sistemas Automotrices
4. Ingeniería en Manufactura Avanzada
5. Licenciatura en Administración y Gestión Empresarial / PyMES
6. Licenciatura en Comercio Internacional y Aduanas

**Al actualizar un sistema ya instalado**, ejecuta una sola vez
`database/migracion_v3_carreras.sql` para que los registros existentes usen el nombre
oficial de la carrera de TI. La inscripción por grupo compara el texto de la carrera
entre alumno y clase, por eso ambos deben coincidir exactamente.

Para agregar otra carrera: añádela a `CARRERAS` en `includes/carreras.php`.
El filtro de carrera del historial (administrador) se actualiza solo.

---

## Vistas nuevas para docentes

El menú lateral del docente ahora incluye dos secciones (no requieren migración de base de datos):

**Mi Asistencia** (`mi_asistencia.php`) — su propia asistencia en las clases que imparte.
- Pestañas: *Todas*, *Asistencias*, *Inasistencias* y *Resumen por clase*.
- Filtros por clase y por rango de fechas.
- Tarjetas con porcentaje de asistencia, sesiones asistidas, retardos e inasistencias.
- Una sesión cuenta como impartida cuando hay registros de asistencia en esa clase ese día
  (de cualquier persona). Si ese día el docente no registró la suya, se marca como inasistencia
  ("falta (sin registro)"). Es el mismo criterio que usa *Mis Clases* con los alumnos.

**Mis Alumnos** (`mis_alumnos.php`) — información de sus alumnos, con una pestaña por grupo.
- Cada pestaña es una combinación carrera + grupo + semestre de las clases que tiene asignadas.
- Por alumno: matrícula, carrera/grupo, materias que cursa con él, % de asistencia con barra,
  faltas, retardos, correo y si tiene tarjeta RFID y Telegram vinculados.
- Buscador por nombre o matrícula dentro de cada pestaña.
- Resumen del grupo: total de alumnos, asistencia promedio y alumnos bajo el 80 %
  (el umbral se cambia en la constante `UMBRAL_ASISTENCIA` de `mis_alumnos.php`).
- Los alumnos salen de **Inscripciones**: si un grupo aparece vacío, el administrador debe inscribirlos.

Los estilos de pestañas se agregaron al final de `public/dashboard.css`.

---

## Historial del docente (asistencia + calificaciones)

Cuando un docente entra a **Historial** ve una vista propia (`historial_docente.php`); administrador y alumno
conservan la de siempre.

**Requiere migración:** ejecutar una sola vez `database/migracion_v4_calificaciones.sql`
(crea la tabla `calificaciones`). Sin ella, la pestaña *Asistencia* funciona y *Calificaciones* avisa
que falta habilitarla.

- **Grupos a la derecha:** una pestaña por cada grupo (carrera + grupo + semestre) con su número de alumnos.
  Si el grupo tiene varias materias del mismo docente, aparece un selector de materia arriba.
- **Pestaña Asistencia:** tabla alumno × sesión con ✓ asistió, reloj retardo, ✗ no asistió, justificado y «·»
  cuando el alumno no está inscrito en esa sesión. Incluye asistencias, faltas y % por alumno, filtro por fechas
  (se muestran las últimas 40 sesiones) y exportación a CSV.
- **Pestaña Calificaciones:** parcial 1, 2 y 3 por alumno, escala 0–100, con promedio y tarjetas
  (total de alumnos, promedio grupal, promedio ≥ 90 y < 70) que se recalculan al escribir.
  *Guardar calificaciones* guarda todo de una vez; una casilla vacía borra esa nota; si hay un valor
  inválido no se guarda nada. *Generar Reporte* descarga un CSV.
- Las calificaciones se guardan por **materia** (no por clase), porque una materia puede tener una fila
  en `clases` por cada día de la semana.
- Los umbrales (`NOTA_EXCELENCIA`, `NOTA_REPROBATORIA`, `UMBRAL_ASISTENCIA_DOC`) están al inicio de
  `historial_docente.php`.

---

## Rediseño visual y menú de administrador

Todo el sistema usa ahora un solo tema guinda/crema (barra lateral guinda con acento dorado, fondo crema, tarjetas
blancas, tablas con encabezado beige, títulos en tipografía serif *Fraunces*, texto en *Outfit* y códigos en
*JetBrains Mono*). Los colores y fuentes viven como variables al inicio de `public/dashboard.css`
(`--wine-700`, `--gold`, `--bg-page`, `--font-display`…): cambiando ahí se cambia todo el sistema.

**Menú del administrador:** Estudiantes · Profesores · Materias · Inscripciones · Pase de Lista · Historial · Cajas ESP32.

- **Estudiantes / Profesores** (`usuarios.php?rol=alumno|docente`): listado con buscador y tabla (código, nombre y correo,
  programa y semestre —o materias que imparte—, vinculación RFID/Telegram, estado, acciones). *Registrar* y *Editar* abren
  un formulario en tarjeta aparte. Los administrativos se ven con el filtro «Administrativos».
- **Materias** (`clases.php`): es el horario de siempre (una fila por materia, día y hora) con el mismo estilo de listado.
  No se creó un catálogo de materias aparte porque el horario ya cumple esa función.
- Menú del docente: Pase de Lista · Mis Alumnos · Historial y Notas · Mi Asistencia.
- Menú del alumno: Inicio · Mis Clases · Historial.
- `alumno.php` (inicio del alumno) se reescribió: la versión anterior usaba estilos y columnas que no existen.
  Ahora cuenta presente, retardo y justificado como asistencia, igual que el resto del sistema.
- Las fuentes se cargan desde Google Fonts y los iconos desde cdnjs: se necesita internet en el navegador.
