<?php
require_once '../../php/backup/BackupController.php';
require_once '../../php/usuarios/UserController.php';
require_once '../../php/movimientos/MovementController.php';

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

$movementController = new MovementController();
$username = $_SESSION['username'];

// Resultado de la exportación: null mientras no ha terminado.
// Si el script muere a mitad (p. ej. un error fatal), se queda en null
// y la función de cierre lo registra como fallido.
$exportResult = null;

register_shutdown_function(function () use (&$exportResult, $movementController, $username) {
    $ok = ($exportResult === true);
    $movementController->addMovement(
        $username,
        $ok ? "ha exportado una copia de seguridad completa"
            : "ha intentado exportar una copia de seguridad completa",
        date('Y-m-d H:i:s'),
        $ok ? "correcto" : "fallido"
    );
});

// Crear instancia del controlador de backup
$backupController = new BackupController();

// Exportar backup (el movimiento se registra al terminar, según el resultado)
$exportResult = $backupController->exportBackup($userId);

if ($exportResult === false && !headers_sent()) {
    http_response_code(500);
    echo "No se ha podido exportar la copia de seguridad.";
}
exit();
?>