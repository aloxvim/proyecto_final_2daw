<?php
require_once '../../php/backup/BackupController.php';
require_once '../../php/usuarios/UserController.php';
require_once '../../php/movimientos/MovementController.php';
require_once '../../php/seguridad.php';

// Comprobar que existe una sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Si no existe una sesión, redirigir al index
if (!isset($_SESSION['username'])) {
    header("Location: ../../index.html");
    exit();
}

// Obtener el ID del usuario
$userController = new UserController();
$userId = $userController->getUserIdByUsername($_SESSION['username']);

if (!$userId) {
    session_destroy();
    header("Location: ../../index.html");
    exit();
}

$message = '';
$messageType = '';

/**
 * Convierte un valor de php.ini ("200M", "8M", "1G"...) a bytes
 */
function iniABytes($valor)
{
    $valor = trim((string) $valor);
    if ($valor === '' || $valor === '-1') {
        return PHP_INT_MAX;
    }
    $numero = (float) $valor;
    switch (strtolower(substr($valor, -1))) {
        case 'g': $numero *= 1024;
        case 'm': $numero *= 1024;
        case 'k': $numero *= 1024;
    }
    return (int) $numero;
}

/**
 * Formatea bytes en MB legibles
 */
function formatearMB($bytes)
{
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

/**
 * Mensaje legible para los códigos de error de subida de PHP
 */
function mensajeErrorSubida($codigo, $limiteTexto)
{
    switch ($codigo) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "El archivo supera el tamaño máximo permitido por el servidor ($limiteTexto).";
        case UPLOAD_ERR_PARTIAL:
            return "El archivo solo se ha subido parcialmente. Inténtalo de nuevo.";
        case UPLOAD_ERR_NO_FILE:
            return "No se ha recibido ningún archivo.";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return "El servidor no ha podido guardar el archivo subido (código $codigo).";
        default:
            return "Error desconocido al subir el archivo (código $codigo).";
    }
}

// Tamaño máximo real que acepta el servidor (el menor de los dos límites)
$limiteSubida = min(iniABytes(ini_get('upload_max_filesize')), iniABytes(ini_get('post_max_size')));
$limiteSubidaTexto = formatearMB($limiteSubida);

// ¿La petición viene del indicador de progreso (JavaScript)?
$esAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $movementController = new MovementController();
    $exito = false;

    // Si el envío supera post_max_size, PHP vacía $_POST y $_FILES sin avisar
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $message = "El archivo (" . formatearMB((int) $_SERVER['CONTENT_LENGTH']) . ") supera el tamaño máximo "
            . "permitido por el servidor ($limiteSubidaTexto). No se ha importado nada.";
        $messageType = 'danger';

    // Validar CSRF
    } elseif (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
        if (!$esAjax) {
            header('Location: import_backup.php?error=csrf');
            exit();
        }
        $message = "<strong>Error de seguridad:</strong> Token CSRF inválido. Por favor, recarga la página e intenta de nuevo.";
        $messageType = 'danger';

    } else {
        // Regenerar token
        regenerarTokenCSRF();

        if (!isset($_FILES['backup_file'])) {
            $message = mensajeErrorSubida(UPLOAD_ERR_NO_FILE, $limiteSubidaTexto);
            $messageType = 'danger';
        } elseif ($_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $message = mensajeErrorSubida($_FILES['backup_file']['error'], $limiteSubidaTexto);
            $messageType = 'danger';
        } else {
            $backupController = new BackupController();
            $result = $backupController->importBackup($userId, $_FILES['backup_file']);

            if ($result['success']) {
                $exito = true;
                $movementController->addMovement(
                    $_SESSION['username'],
                    "ha importado una copia de seguridad ({$result['imported_movies']} películas, {$result['imported_series']} series)",
                    date('Y-m-d H:i:s'),
                    "correcto"
                );

                $message = "Backup importado correctamente. ";
                $message .= "Películas importadas: {$result['imported_movies']}, ";
                $message .= "Series importadas: {$result['imported_series']}";

                if (!empty($result['errors'])) {
                    $message .= "<br><strong>Advertencias:</strong><br>"
                        . implode("<br>", array_map('htmlspecialchars', $result['errors']));
                }

                $messageType = 'success';
            } else {
                $message = htmlspecialchars($result['message'] ?? 'Error al importar el backup');
                $messageType = 'danger';
            }
        }
    }

    if (!$exito) {
        $movementController->addMovement(
            $_SESSION['username'],
            "ha intentado importar una copia de seguridad",
            date('Y-m-d H:i:s'),
            "fallido"
        );
    }

    // Respuesta para el indicador de progreso
    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $exito,
            'type' => $messageType,
            'message' => $message,
            'csrf_token' => generarTokenCSRF()
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Backup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link rel="shortcut icon" href="../../img/iconos_navegador/usuario.png" type="image/x-icon">
</head>

<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3><i class="fas fa-upload me-2"></i>Importar Copia de Seguridad</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($message)): ?>
                            <div class="alert alert-<?php echo $messageType; ?>" role="alert">
                                <?php echo $message; ?>
                            </div>
                        <?php elseif (isset($_GET['error']) && $_GET['error'] === 'csrf'): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Error de seguridad:</strong> Token CSRF inválido. Por favor, recarga la página e
                                intenta de nuevo.
                            </div>
                        <?php endif; ?>

                        <?php if ($messageType === 'success'): ?>
                            <script>
                                // Redirigir después de 5 segundos si la operación fue exitosa
                                let seconds = 5;
                                const alertDiv = document.querySelector('.alert-success');
                                const countdownSpan = document.createElement('span');
                                countdownSpan.innerHTML = `<br><small>Redirigiendo a Mi Perfil en <strong id="countdown">${seconds}</strong> segundos...</small>`;
                                alertDiv.appendChild(countdownSpan);

                                const interval = setInterval(() => {
                                    seconds--;
                                    document.getElementById('countdown').textContent = seconds;

                                    if (seconds <= 0) {
                                        clearInterval(interval);
                                        window.location.href = 'my_profile.php';
                                    }
                                }, 1000);
                            </script>
                        <?php endif; ?>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Información importante:</strong>
                            <ul class="mb-0 mt-2">
                                <li>El archivo debe ser un backup exportado previamente en formato JSON</li>
                                <li>Se importarán TODAS las películas y series del backup</li>
                                <li>Las películas y series se añadirán a tu biblioteca actual</li>
                                <li>Las imágenes de pósters se restaurarán automáticamente</li>
                                <li>Ten en cuenta, que lo que exista en la copia de seguridad se añadirá a tus datos ya
                                    existentes
                                    <ul>
                                        <li>Esto quiere decir, que si ya tienes en la web "Película A" y la tienes
                                            también en la copia de seguridad, aparecerá duplicada tantas veces restaures
                                            la copia de seguridad.</li>
                                    </ul>
                                </li>
                                <li>
                                    Si tenías muchos registros guardados, tanto la exportación como la importación
                                    pueden ser lentas,
                                    incluso parecer que la aplicación se ha quedado colgada.
                                    <ul>
                                        <li>Mientras no veas un mensaje de error, no hagas nada durante el proceso.</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>

                        <form method="POST" enctype="multipart/form-data" id="importForm">
                            <?php echo campoTokenCSRF(); ?>
                            <div class="mb-3">
                                <label for="backup_file" class="form-label">
                                    <i class="fas fa-file-import me-2"></i>Seleccionar archivo de backup (.json)
                                </label>
                                <input type="file" class="form-control" id="backup_file" name="backup_file"
                                    accept=".json" required>
                                <div class="form-text">
                                    Solo se aceptan archivos JSON de backup. Tamaño máximo: <?php echo $limiteSubidaTexto; ?>
                                </div>
                            </div>

                            <!-- Indicador de estado de la importación -->
                            <div id="importStatus" class="mb-3 d-none" aria-live="polite">
                                <div id="importStatusText" class="mb-2"></div>
                                <div class="progress" style="height: 1.5rem;" id="importProgressWrap">
                                    <div id="importProgressBar" class="progress-bar" role="progressbar"
                                        style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg" id="importSubmitBtn">
                                    <i class="fas fa-upload me-2"></i>Importar Backup
                                </button>
                                <a href="my_profile.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver a Mi Perfil
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Modal de confirmación específico para importar backup
        document.getElementById('importForm').addEventListener('submit', function (e) {
            e.preventDefault(); // Prevenir envío inmediato

            const file = document.getElementById('backup_file').files[0];

            if (!file) {
                // Si no hay archivo, mostrar validación HTML5
                this.reportValidity();
                return;
            }

            const form = this;

            // Comprobar el tamaño antes de subir nada
            if (file.size > LIMITE_SUBIDA) {
                mostrarEstado('danger',
                    `<i class="fas fa-times-circle me-2"></i>El archivo (${formatearMB(file.size)}) supera el tamaño máximo ` +
                    `permitido por el servidor (${formatearMB(LIMITE_SUBIDA)}). No se ha importado nada.`);
                return;
            }

            // Crear el modal
            const modalHtml = `
            <div class="modal fade" id="importConfirmModal" tabindex="-1" aria-labelledby="importConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title" id="importConfirmModalLabel">
                                <i class="fas fa-exclamation-triangle me-2"></i>Confirmación de Importación
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p><strong>¿Estás seguro de que deseas importar este backup?</strong></p>
                            <p class="mb-2">Archivo: <code>${file.name}</code></p>
                            <p class="mb-0 text-muted">
                                <i class="fas fa-info-circle me-2"></i>
                                Esto añadirá todas las películas y series del backup a tu biblioteca.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </button>
                            <button type="button" class="btn btn-primary" id="confirmImportBtn">
                                <i class="fas fa-upload me-2"></i>Importar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

            // Remover modal anterior si existe
            const existingModal = document.getElementById('importConfirmModal');
            if (existingModal) {
                existingModal.remove();
            }

            // Añadir modal al body
            document.body.insertAdjacentHTML('beforeend', modalHtml);

            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('importConfirmModal'));
            modal.show();

            // Manejar confirmación
            document.getElementById('confirmImportBtn').addEventListener('click', function () {
                modal.hide();
                // Enviar el formulario con indicador de progreso
                enviarConProgreso(form);
            });

            // Limpiar modal cuando se cierre
            document.getElementById('importConfirmModal').addEventListener('hidden.bs.modal', function () {
                this.remove();
            });
        });

        // ---------- Indicador de estado de la importación ----------

        const LIMITE_SUBIDA = <?php echo json_encode($limiteSubida); ?>;
        const statusBox = document.getElementById('importStatus');
        const statusText = document.getElementById('importStatusText');
        const progressWrap = document.getElementById('importProgressWrap');
        const progressBar = document.getElementById('importProgressBar');
        const submitBtn = document.getElementById('importSubmitBtn');
        const fileInput = document.getElementById('backup_file');
        let importEnCurso = false;

        function formatearMB(bytes) {
            return (bytes / 1048576).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' MB';
        }

        // tipo: 'info' (en curso), 'success' o 'danger'
        function mostrarEstado(tipo, html, progreso = null) {
            statusBox.classList.remove('d-none');
            statusText.className = 'alert mb-2 alert-' + tipo;
            statusText.innerHTML = html;

            if (progreso === null) {
                progressWrap.classList.add('d-none');
                return;
            }
            progressWrap.classList.remove('d-none');
            progressBar.className = 'progress-bar';

            if (progreso === 'indeterminado') {
                progressBar.classList.add('progress-bar-striped', 'progress-bar-animated');
                progressBar.style.width = '100%';
                progressBar.textContent = 'Procesando...';
            } else {
                progressBar.style.width = progreso + '%';
                progressBar.setAttribute('aria-valuenow', progreso);
                progressBar.textContent = progreso + '%';
            }
        }

        function bloquearFormulario(bloquear) {
            importEnCurso = bloquear;
            submitBtn.disabled = bloquear;
            fileInput.disabled = bloquear;
            submitBtn.innerHTML = bloquear
                ? '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Importando...'
                : '<i class="fas fa-upload me-2"></i>Importar Backup';
        }

        function finalizarConError(html) {
            bloquearFormulario(false);
            mostrarEstado('danger', '<i class="fas fa-times-circle me-2"></i>' + html);
        }

        function enviarConProgreso(form) {
            // FormData se crea antes de deshabilitar el input (los campos deshabilitados no se envían)
            const datos = new FormData(form);
            const xhr = new XMLHttpRequest();

            bloquearFormulario(true);
            mostrarEstado('info', '<i class="fas fa-cloud-upload-alt me-2"></i>Preparando la subida...', 0);

            xhr.upload.addEventListener('progress', function (e) {
                if (!e.lengthComputable) return;
                const pct = Math.floor((e.loaded / e.total) * 100);
                mostrarEstado('info',
                    `<i class="fas fa-cloud-upload-alt me-2"></i>Subiendo archivo... ` +
                    `(${formatearMB(e.loaded)} de ${formatearMB(e.total)})`, pct);
            });

            xhr.upload.addEventListener('load', function () {
                mostrarEstado('info',
                    '<i class="fas fa-cog fa-spin me-2"></i>Archivo subido. Importando películas y series en el servidor, ' +
                    'esto puede tardar un rato. <strong>No cierres ni recargues la página.</strong>', 'indeterminado');
            });

            xhr.addEventListener('load', function () {
                let respuesta = null;
                try {
                    respuesta = JSON.parse(xhr.responseText);
                } catch (err) {
                    // Respuesta no JSON: error fatal de PHP, tiempo agotado, etc.
                }

                if (!respuesta) {
                    finalizarConError(`El servidor ha devuelto un error inesperado (HTTP ${xhr.status}). ` +
                        'Revisa el log de errores de Apache. Puede que parte del backup se haya importado.');
                    return;
                }

                // Actualizar el token CSRF para poder reintentar sin recargar
                const campoToken = form.querySelector('input[name="csrf_token"]');
                if (campoToken && respuesta.csrf_token) {
                    campoToken.value = respuesta.csrf_token;
                }

                if (!respuesta.success) {
                    finalizarConError(respuesta.message || 'Error al importar el backup');
                    return;
                }

                importEnCurso = false;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Importado';

                let segundos = 5;
                mostrarEstado('success',
                    '<i class="fas fa-check-circle me-2"></i>' + respuesta.message +
                    `<br><small>Redirigiendo a Mi Perfil en <strong id="countdown">${segundos}</strong> segundos...</small>`);

                const intervalo = setInterval(() => {
                    segundos--;
                    document.getElementById('countdown').textContent = segundos;
                    if (segundos <= 0) {
                        clearInterval(intervalo);
                        window.location.href = 'my_profile.php';
                    }
                }, 1000);
            });

            xhr.addEventListener('error', function () {
                finalizarConError('Se ha perdido la conexión con el servidor durante la importación. ' +
                    'Comprueba la red; puede que parte del backup se haya importado.');
            });

            xhr.addEventListener('abort', function () {
                finalizarConError('La importación se ha cancelado.');
            });

            xhr.open('POST', window.location.pathname);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(datos);
        }

        // Avisar si se intenta salir de la página durante la importación
        window.addEventListener('beforeunload', function (e) {
            if (importEnCurso) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>

</html>