<?php
// Borrado de cuenta en 3 pasos:
//   1) pedir la contraseña
//   2) primera confirmación, mostrando lo que se va a perder
//   3) confirmación final: escribir el nombre de usuario, avisando de que es irreversible
// El paso superado se guarda en la sesión (y caduca), así que no se puede
// saltar ningún paso enviando el formulario a mano.

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once '../../php/seguridad.php';

// Traer los archivos de usuarios
require_once '../../php/usuarios/UserController.php';
require_once '../../php/usuarios/User.php';

// Traer los archivos de películas
require_once '../../php/peliculas/MovieController.php';
require_once '../../php/peliculas/Movie.php';

// Traer los archivos de series
require_once '../../php/series/SerieController.php';
require_once '../../php/series/Serie.php';

// Traer los archivos de movimientos
require_once '../../php/movimientos/MovementController.php';
require_once '../../php/movimientos/Movement.php';

// Comprobar que existe una sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Si no existe una sesión, redirigir al index
if (!isset($_SESSION['username'])) {
    header("Location: ../../index.html");
    exit();
}

// Tiempo para completar los pasos, en segundos
const TIEMPO_BORRADO = 300;

$username = $_SESSION['username'];

// Crear instancias de los controladores
$userController = new UserController();
$movieController = new MovieController();
$serieController = new SerieController();
$movementController = new MovementController();

$userId = $userController->getUserIdByUsername($username);

if (!$userId) {
    session_destroy();
    header("Location: ../../index.html");
    exit();
}

// El propietario no puede borrar su cuenta
if ($userController->isOwnerByUsername($username)) {
    header("Location: my_profile.php");
    exit();
}

// Devuelve el último paso superado (0 si ninguno, si ha caducado o si es de otro usuario)
function pasoSuperado($userId)
{
    $estado = $_SESSION['borrarCuenta'] ?? null;

    if (!$estado || $estado['expira'] < time() || $estado['id_user'] != $userId) {
        unset($_SESSION['borrarCuenta']);
        return 0;
    }

    return $estado['paso'];
}

// Guardar en la sesión el paso superado
function marcarPaso($paso, $userId)
{
    $_SESSION['borrarCuenta'] = ['paso' => $paso, 'expira' => time() + TIEMPO_BORRADO, 'id_user' => $userId];
}

$pasoMostrar = 1;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validar token CSRF
    if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
        unset($_SESSION['borrarCuenta']);
        $error = "Token de seguridad inválido. Vuelve a empezar.";
    } else {
        regenerarTokenCSRF();
        $paso = (int) ($_POST['paso'] ?? 0);

        if ($paso === 1) {
            // Paso 1: comprobar la contraseña
            if ($userController->checkPassword($username, $_POST['password'] ?? '')) {
                marcarPaso(1, $userId);
                $pasoMostrar = 2;
            } else {
                unset($_SESSION['borrarCuenta']);
                $movementController->addMovement($username, "ha intentado borrar su cuenta (contraseña incorrecta)", date('Y-m-d H:i:s'), "fallido");
                $error = "La contraseña no es correcta.";
            }

        } elseif ($paso === 2 && pasoSuperado($userId) === 1) {
            // Paso 2: primera confirmación
            marcarPaso(2, $userId);
            $pasoMostrar = 3;

        } elseif ($paso === 3 && pasoSuperado($userId) === 2) {
            // Paso 3: confirmación final, hay que escribir el nombre de usuario exacto
            if (($_POST['confirm_username'] ?? '') !== $username) {
                // Se mantiene en el paso 3 (sin renovar el tiempo) para que pueda corregirlo
                $pasoMostrar = 3;
                $error = "El nombre de usuario no coincide. Escríbelo exactamente igual para confirmar.";
            } else {
                try {
                    // Eliminar el usuario
                    $userController->deleteUser($userId);

                    // Eliminar toda la multimedia que no tenga usuario asociado (y sus portadas)
                    $movieController->deleteMoviesWithoutUsers();
                    $serieController->deleteSeriesWithoutUsers();

                    // Anotar que el usuario ha sido borrado
                    $movementController->addMovement($username, "ha borrado su cuenta", date('Y-m-d H:i:s'), "correcto");

                    // Cerrar la sesión del usuario borrado
                    $_SESSION = [];
                    session_destroy();

                    header('Location: ../../index.html');
                    exit();
                } catch (Exception $e) {
                    unset($_SESSION['borrarCuenta']);
                    error_log("Error borrando la cuenta de $username: " . $e->getMessage());
                    $movementController->addMovement($username, "ha intentado borrar su cuenta", date('Y-m-d H:i:s'), "fallido");
                    $error = "Error borrando la cuenta: " . $e->getMessage();
                }
            }

        } else {
            // Paso saltado o caducado: volver a empezar
            unset($_SESSION['borrarCuenta']);
            $error = "La confirmación ha caducado o no es válida. Vuelve a empezar.";
        }
    }
} else {
    // Al entrar en la página siempre se empieza desde el principio
    unset($_SESSION['borrarCuenta']);
}

// Contar lo que se va a perder (para los pasos 2 y 3)
$totalPeliculas = 0;
$totalSeries = 0;
if ($pasoMostrar > 1) {
    $totalPeliculas = count($movieController->getMoviesByUserId($userId));
    $totalSeries = count($serieController->getSeriesByUserId($userId));
}

// Minutos restantes para completar el proceso
$minutosRestantes = isset($_SESSION['borrarCuenta'])
    ? max(1, (int) ceil(($_SESSION['borrarCuenta']['expira'] - time()) / 60))
    : 0;

$usernameHtml = htmlspecialchars($username);
$resumenPerdida = "<strong>$totalPeliculas " . ($totalPeliculas === 1 ? 'película' : 'películas') . "</strong> y "
    . "<strong>$totalSeries " . ($totalSeries === 1 ? 'serie' : 'series') . "</strong>";
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrar cuenta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link rel="shortcut icon" href="../../img/iconos_navegador/usuario.png" type="image/x-icon">
</head>

<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h3 class="mb-0"><i class="fas fa-user-slash me-2"></i>Borrar cuenta</h3>
                        <span class="badge bg-light text-danger">Paso <?php echo $pasoMostrar; ?> de 3</span>
                    </div>

                    <div class="card-body">
                        <!-- Progreso de los pasos -->
                        <div class="progress mb-4" style="height: 0.5rem;">
                            <div class="progress-bar bg-danger" role="progressbar"
                                style="width: <?php echo round($pasoMostrar / 3 * 100); ?>%;"
                                aria-valuenow="<?php echo $pasoMostrar; ?>" aria-valuemin="0" aria-valuemax="3"></div>
                        </div>

                        <?php if ($error !== null): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($pasoMostrar === 1): ?>
                            <!-- Paso 1: contraseña -->
                            <form method="POST" action="delete_user.php">
                                <?php echo campoTokenCSRF(); ?>
                                <input type="hidden" name="paso" value="1">

                                <p>Para borrar la cuenta <strong><?php echo $usernameHtml; ?></strong>, primero confirma que eres tú.</p>

                                <div class="mb-3">
                                    <label for="password" class="form-label">Tu contraseña</label>
                                    <input type="password" class="form-control" name="password" id="password"
                                        placeholder="Escribe tu contraseña" autocomplete="current-password" required autofocus>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-arrow-right me-2"></i>Continuar
                                    </button>
                                    <a href="my_profile.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Cancelar
                                    </a>
                                </div>
                            </form>

                        <?php elseif ($pasoMostrar === 2): ?>
                            <!-- Paso 2: primera confirmación -->
                            <form method="POST" action="delete_user.php">
                                <?php echo campoTokenCSRF(); ?>
                                <input type="hidden" name="paso" value="2">

                                <p><strong>¿Seguro que quieres borrar tu cuenta <?php echo $usernameHtml; ?>?</strong></p>
                                <p>Se borrarán también tus <?php echo $resumenPerdida; ?>, junto con sus portadas.</p>

                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Si quieres conservarlas, <a href="export_backup.php" class="alert-link">descarga antes una
                                    copia de seguridad</a>. Podrás importarla en otra cuenta más adelante.
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-warning">
                                        Sí, quiero borrar mi cuenta
                                    </button>
                                    <a href="my_profile.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Cancelar
                                    </a>
                                </div>

                                <p class="form-text mt-3 mb-0">
                                    Tienes <?php echo $minutosRestantes; ?> minutos para completar el proceso; después habrá que empezar de nuevo.
                                </p>
                            </form>

                        <?php else: ?>
                            <!-- Paso 3: confirmación final -->
                            <form method="POST" action="delete_user.php">
                                <?php echo campoTokenCSRF(); ?>
                                <input type="hidden" name="paso" value="3">

                                <div class="alert alert-danger">
                                    <p class="mb-1"><strong><i class="fas fa-skull-crossbones me-2"></i>Esta acción es irreversible.</strong></p>
                                    <p class="mb-0">Tu cuenta, tus <?php echo $resumenPerdida; ?> y sus portadas se borrarán
                                        para siempre y no se podrán recuperar.</p>
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_username" class="form-label">
                                        Para confirmar, escribe tu nombre de usuario: <strong><?php echo $usernameHtml; ?></strong>
                                    </label>
                                    <input type="text" class="form-control" name="confirm_username" id="confirm_username"
                                        autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                        required autofocus>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-danger" id="finalDeleteBtn" disabled>
                                        <i class="fas fa-trash-alt me-2"></i>Borrar mi cuenta definitivamente
                                    </button>
                                    <a href="my_profile.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Cancelar y volver a mi perfil
                                    </a>
                                </div>

                                <p class="form-text mt-3 mb-0">
                                    Tienes <?php echo $minutosRestantes; ?> minutos para completar el proceso; después habrá que empezar de nuevo.
                                </p>
                            </form>

                            <script>
                                // Activar el botón solo cuando el nombre coincide (el servidor lo vuelve a comprobar)
                                const esperado = <?php echo json_encode($username, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                                const campo = document.getElementById('confirm_username');
                                const boton = document.getElementById('finalDeleteBtn');
                                campo.addEventListener('input', () => {
                                    boton.disabled = campo.value !== esperado;
                                });
                                // Evitar pegar el nombre: hay que escribirlo
                                campo.addEventListener('paste', (e) => e.preventDefault());
                            </script>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
