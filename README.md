# Webhook de citas (Appointments) — Laravel 12

Mini-módulo que recibe un webhook firmado con HMAC-SHA256 en `POST /api/appointments/callback`,
guarda la cita en MySQL de forma idempotente y despacha un job en cola que simula una notificación.

**Stack:** PHP 8.3 · Laravel 12 · MySQL 8 · Queue (driver `database`) · Docker Compose

## Instalación con Docker (recomendado)

```bash
git clone <repo> && cd prueba-backend-nexum
cp .env.example .env

# Generar APP_KEY y pegarla en .env
docker compose run --rm app php artisan key:generate --show

# Definir el secreto del webhook en .env
# APPOINTMENTS_WEBHOOK_SECRET=<un-secreto-largo>

docker compose up -d --build
```

Se levantan tres contenedores:

| Servicio | Contenedor    | Función                                                          |
|----------|---------------|------------------------------------------------------------------|
| `app`    | `nexum_app`   | Ejecuta `migrate --force` y sirve la API en `http://localhost:8000` |
| `queue`  | `nexum_queue` | `php artisan queue:work`: procesa los jobs                        |
| `db`     | `nexum_db`    | MySQL 8 (puerto 3306, volumen `mysql_data`)                       |

### Comandos útiles

```bash
docker compose ps                              # estado de los contenedores
docker compose logs -f queue                   # ver las notificaciones simuladas
docker compose exec app php artisan test       # correr los tests
docker compose exec app php artisan migrate    # migrar manualmente (ya se hace al arrancar)
docker compose exec app php artisan db:seed    # poblar con el seeder por defecto (usuario de prueba)
docker compose down                            # detener (agregar -v para borrar los datos de MySQL)
```

> Tras cambiar código o `.env`, reconstruir con `docker compose up -d --build`
> (el código se copia dentro de la imagen, no se monta como volumen).

## Instalación local (sin Docker)

Requiere PHP 8.2+ con `pdo_mysql`, `pdo_sqlite` y `zip`, Composer y un MySQL accesible.

```bash
composer install
cp .env.example .env              # ajustar DB_HOST=127.0.0.1 y el resto de credenciales
php artisan key:generate
php artisan migrate
php artisan serve                 # terminal 1
php artisan queue:work            # terminal 2
php artisan test
```

## Uso del endpoint

**Request**

```
POST /api/appointments/callback
Content-Type: application/json
X-Signature: <hex de HMAC-SHA256(body crudo, APPOINTMENTS_WEBHOOK_SECRET)>

{"external_id":"APT-1001","rfc":"GODE561231GR8","scheduled_at":"2026-10-20 09:00:00"}
```

**Ejemplo para poblar la BD vía webhook** (bash / Git Bash):

```bash
SECRET=$(grep APPOINTMENTS_WEBHOOK_SECRET .env | cut -d= -f2)
BODY='{"external_id":"APT-1001","rfc":"GODE561231GR8","scheduled_at":"2026-10-20 09:00:00"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

curl -i -X POST http://localhost:8000/api/appointments/callback \
  -H "Content-Type: application/json" -H "X-Signature: $SIG" -d "$BODY"
```

**Respuestas**

| Código | Cuándo                                                                     |
|--------|----------------------------------------------------------------------------|
| `201`  | Cita nueva creada; se despacha el job de notificación                      |
| `200`  | El `external_id` ya existía; se devuelve la cita existente, sin crear ni despachar nada |
| `401`  | Falta `X-Signature` o es incorrecta; no se guarda nada                     |
| `422`  | Firma válida, pero los datos no pasan la validación                        |

## Estructura

| Archivo | Responsabilidad |
|---|---|
| `routes/api.php` | Define la ruta y le aplica el middleware de firma |
| `app/Http/Middleware/VerifyWebhookSignature.php` | Valida el HMAC; responde 401 antes de llegar al controlador |
| `app/Http/Requests/StoreAppointmentRequest.php` | Reglas de validación del payload |
| `app/Http/Controllers/AppointmentCallbackController.php` | Guarda de forma idempotente y despacha el job |
| `app/Models/Appointment.php` | Modelo Eloquent |
| `app/Jobs/SendAppointmentNotification.php` | Job en cola: log de notificación, 3 intentos con backoff |
| `database/migrations/..._create_appointments_table.php` | Tabla con índice `unique` en `external_id` |
| `tests/Feature/AppointmentCallbackTest.php` | Tests de firma, idempotencia y validación |

## Decisiones de diseño

- **Firma HMAC en un middleware.** La seguridad queda separada de la lógica de negocio y se ejecuta
  antes que la validación. Así, una petición no firmada recibe 401 sin revelar qué campos espera la API.
- **La firma se calcula sobre el body crudo** (`$request->getContent()`), no sobre el JSON
  re-serializado: cualquier cambio de espacios u orden de claves cambiaría el hash.
  El header lleva el hash en **hex en minúsculas**, sin prefijo.
- **Comparación con `hash_equals`**: compara en tiempo constante y evita timing attacks.
- **Fail-closed:** si `APPOINTMENTS_WEBHOOK_SECRET` está vacío, todas las peticiones reciben 401.
- **El secreto se lee vía `config/services.php`** y no con `env()` directo, para que siga funcionando con `config:cache`.
- **No se usa Sanctum:** la autenticación del webhook es el HMAC. Aunque el proyecto inicialmente contó con el scaffolding de API de Laravel, este módulo no utiliza Sanctum porque el mecanismo de autenticación requerido para el webhook es HMAC.
- **Idempotencia en dos capas:**
  1. Índice `unique` en `external_id`: la base de datos lo garantiza aunque haya concurrencia.
  2. `Appointment::createOrFirst()`: intenta insertar y, si choca con el índice único, devuelve el
     registro existente. A diferencia de `firstOrCreate` (SELECT y luego INSERT), no tiene una
     condición de carrera entre dos peticiones simultáneas.
  El job solo se despacha si `wasRecentlyCreated` es `true`.
- **Un duplicado devuelve 200 con el registro existente y no lo actualiza.** Los emisores de
  webhooks reintentan ante errores; responder 2xx evita reintentos infinitos.
- **Validación:**
  - `rfc` debe cumplir el formato de RFC mexicano: 3 o 4 letras + fecha AAMMDD + homoclave
    de 3 caracteres, en mayúsculas.
  - `scheduled_at` acepta cualquier fecha parseable y se guarda en **UTC**.
- **Errores en JSON:** las rutas `api/*` siempre responden JSON (`shouldRenderJsonWhen`), aunque el
  emisor no envíe `Accept: application/json`. Sin esto, un error de validación devolvería un redirect 302.
- **Cola con driver `database`:** no requiere servicios extra (Redis). El worker corre en su propio
  contenedor (`queue`) y escribe sus logs a `stderr`, visibles con `docker compose logs queue`.
- **Reintentos del job:**
  - `$tries = 3` con `$backoff = [10, 30]` segundos.
  - Al agotar los intentos, Laravel guarda el job en `failed_jobs` y `failed()` registra el error.
- **Migraciones automáticas:** `migrate --force` corre al arrancar `app`, así `docker compose up -d --build`
  deja todo listo.
- **El `.env` no entra en la imagen** (`.dockerignore`): las variables llegan por `env_file`.
  - En la imagen se crea un `.env` vacío para que las herramientas de Laravel no emitan avisos.
  - `serve` usa `--no-reload` para que tome las variables del contenedor en vez de releer ese archivo vacío.
- **Los tests usan SQLite en memoria** y no tocan la base MySQL de desarrollo.
  - En `phpunit.xml` se usan `<server>` en lugar de `<env>`: Docker inyecta `DB_CONNECTION=mysql`
    como variable real, y Laravel lee `$_SERVER` primero.
  - `Queue::fake()` permite verificar que el job se despacha (y cuántas veces) sin ejecutarlo.

## Mejoras futuras

- **Protección contra replay:** incluir un timestamp firmado (p. ej. `X-Timestamp`) y rechazar
  peticiones antiguas.
- **Servidor de producción:** Nginx + PHP-FPM en lugar de `artisan serve`, que es un servidor de desarrollo.
- **Colas:** Redis para la cola y Supervisor u Horizon para gestionar los workers.
- **Auditoría:** guardar el payload crudo de cada webhook recibido para depurar y reprocesar.
- **Ciclo de vida de la cita:** manejar actualizaciones o cancelaciones de una cita existente si el
  proveedor las envía.
- **Notificación real:** reemplazar el log por un canal real (email, SMS, etc.) usando Notifications de Laravel.
- **Seguridad:** rotación del secreto (aceptar dos secretos durante la transición) y rate limiting del endpoint.
- **CI:** pipeline que ejecute los tests en cada push.

## Cuestionario

## Cuestionario

### 1. ¿Cuándo preferirías una cola (queue) en vez de hacerlo síncrono? ¿Qué ganas y qué pierdes?

Preferiría una cola cuando la operación no necesita completarse antes de responder al cliente, especialmente si puede tardar, fallar o requerir reintentos.

En este caso, la recepción del webhook y el guardado de la cita deben resolverse rápidamente, mientras que la notificación es una tarea secundaria. Por eso la notificación se procesa mediante un Job en cola.

La principal ventaja es que el webhook puede responder rápidamente y el trabajo puede reintentarse si falla. La desventaja es que agrega complejidad operativa y la notificación ya no ocurre necesariamente antes de responder al cliente.

---

### 2. ¿Cómo harías idempotente este endpoint? ¿Qué pasa si falla justo a la mitad?

Usaría `external_id` como identificador único de la cita.

En la base de datos existe un índice `unique` sobre `external_id`, y en la aplicación utilizo `Appointment::createOrFirst()`. De esta forma, si el mismo webhook llega nuevamente, se devuelve el registro existente en lugar de crear otro.

Además, el Job de notificación solamente se despacha cuando `wasRecentlyCreated` es `true`, evitando enviar nuevamente la notificación por un webhook duplicado.

Si el proceso falla después de guardar la cita pero antes de completar la notificación, la cola permite manejar la notificación de forma independiente mediante reintentos. En una implementación de producción también consideraría transacciones y mecanismos como dispatch después del commit para controlar mejor este tipo de escenarios.

---

### 3. Si algo falla de forma intermitente en producción, ¿cuáles son tus 3 primeros pasos?

Primero revisaría los logs y métricas para identificar exactamente qué está fallando y con qué frecuencia.

Segundo, intentaría correlacionar los errores con una petición, Job, usuario o identificador concreto para encontrar un patrón y reproducir el problema.

Tercero, revisaría las dependencias involucradas, como base de datos, servicios externos, workers o red, y comprobaría si hubo algún cambio o despliegue reciente que pudiera estar relacionado.

---

### 4. ¿Dónde pondrías la lógica de negocio y por qué?

Intentaría mantener el controlador pequeño y enfocado en recibir la petición, validar los datos y coordinar la operación.

En este módulo, la validación está en `StoreAppointmentRequest`, la verificación de seguridad está en el middleware HMAC, la persistencia está relacionada con el modelo `Appointment` y la notificación se encuentra en `SendAppointmentNotification`.

Para un módulo pequeño como este no considero necesario introducir muchas capas o patrones adicionales, porque aumentarían la complejidad sin aportar un beneficio claro. Si la lógica creciera, podría extraerla a un servicio de dominio o aplicación.

---

### 5. ¿Cómo manejarías secretos, API keys, email, HMAC, etc.?

Los secretos no deben estar hardcodeados en el código ni almacenarse en el repositorio.

En este proyecto, el secreto HMAC se configura mediante `APPOINTMENTS_WEBHOOK_SECRET` en `.env` y se accede mediante `config/services.php`.

También evitaría registrar secretos o información sensible en logs. En producción utilizaría un mecanismo seguro de gestión de secretos y contemplaría su rotación periódica.

---

### 6. Mañana llega otro tipo de cita con campos diferentes. ¿Cómo lo adaptarías?

Primero analizaría qué campos son realmente comunes entre los diferentes tipos de cita y cuáles son específicos.

Si las diferencias fueran pequeñas, podría extender el modelo y las reglas de validación de forma controlada. Si existieran varios tipos con comportamientos y estructuras muy diferentes, consideraría separar los datos específicos o utilizar diferentes estrategias de procesamiento.

Evitaría crear desde el inicio una estructura excesivamente compleja sin conocer las necesidades reales del dominio.

---

### 7. A mitad del proyecto cambian el requisito. ¿Qué haces técnica y humanamente?

Primero aclararía exactamente qué cambió y cuál es el nuevo comportamiento esperado.

Después evaluaría el impacto técnico: código afectado, base de datos, pruebas, tiempos y posibles riesgos.

Finalmente comunicaría el impacto y las alternativas al equipo antes de implementar el cambio. Técnicamente intentaría hacer cambios pequeños y mantener las pruebas actualizadas para reducir regresiones.

---

### 8. ¿Qué fue lo último que aprendiste por tu cuenta y cómo lo aprendiste?

Durante este proyecto profundicé en el funcionamiento de webhooks firmados con HMAC, idempotencia y procesamiento mediante colas en Laravel.

Lo aprendí combinando documentación técnica con la implementación práctica del módulo y comprobando el comportamiento mediante pruebas automatizadas y pruebas manuales.

---

### 9. ¿Cuándo NO escribirías un test?

No escribiría necesariamente un test para código trivial que no contiene lógica propia o comportamiento relevante, especialmente si se trata de funcionalidad proporcionada directamente por el framework.

Sí priorizaría pruebas para reglas de negocio, seguridad, validaciones, casos límite, integraciones importantes e idempotencia.

En este proyecto consideré especialmente importantes las pruebas de firma inválida, creación de una cita, idempotencia y validación del payload.

---

### 10. Cuéntame un error que hayas cometido en producción y qué cambió en tu forma de trabajar.

Un error que puede ocurrir en un proyecto es asumir que una operación aparentemente sencilla siempre se completará correctamente y no contemplar suficientemente los casos de fallo o reintento.

A partir de este tipo de situaciones, aprendí a considerar desde el inicio los casos de error, validar entradas y agregar pruebas para escenarios negativos, en lugar de probar únicamente el camino exitoso.

En proyectos posteriores procuro revisar también qué sucede cuando una operación falla a la mitad y cómo evitar duplicados o estados inconsistentes.
