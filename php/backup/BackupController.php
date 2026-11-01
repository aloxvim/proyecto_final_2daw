<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../peliculas/MovieController.php';
require_once __DIR__ . '/../series/SerieController.php';
require_once __DIR__ . '/../usuarios/UserController.php';

class BackupController
{
    private $movieController;
    private $serieController;
    private $userController;
    public $lastError = "";

    public function __construct()
    {
        $this->movieController = new MovieController();
        $this->serieController = new SerieController();
        $this->userController = new UserController();
    }

    /**
     * Exportar backup completo del usuario
     *
     * El JSON se envía al navegador por partes (elemento a elemento) en lugar
     * de construirse entero en memoria, para que el consumo de memoria no
     * dependa del número ni del tamaño de las portadas.
     *
     * Devuelve true si la exportación se completó y false si hubo un error.
     */
    public function exportBackup($userId)
    {
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        try {
            // Obtener todas las películas y series del usuario
            $movies = $this->movieController->getMoviesByUserId($userId);
            $series = $this->serieController->getSeriesByUserId($userId);

            // La exportación puede tardar si hay muchas portadas
            set_time_limit(0);

            // Desactivar buffers de salida para que los datos se envíen según se generan
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Crear nombre de archivo
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.json';

            // Enviar headers para descarga (sin Content-Length: el tamaño no se conoce de antemano)
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');

            // Cabecera del backup (misma estructura que antes)
            echo '{';
            echo '"version":' . json_encode('1.0') . ',';
            echo '"export_date":' . json_encode(date('Y-m-d H:i:s')) . ',';
            echo '"user_id":' . json_encode($userId) . ',';

            // Películas
            echo '"movies":[';
            $this->streamItems($movies, $jsonFlags);
            unset($movies);
            echo '],';

            // Series
            echo '"series":[';
            $this->streamItems($series, $jsonFlags);
            unset($series);
            echo ']';

            echo '}';
            flush();

            return true;

        } catch (Exception $e) {
            $this->lastError = "Error al exportar backup: " . $e->getMessage();
            error_log($this->lastError);
            return false;
        }
    }

    /**
     * Codifica y envía cada elemento (película o serie) con su portada en base64,
     * liberando la memoria de cada uno antes de pasar al siguiente.
     */
    private function streamItems($items, $jsonFlags)
    {
        $first = true;

        foreach ($items as $item) {
            $itemData = $item;

            // Leer el archivo de imagen y convertirlo a base64
            if (!empty($item['poster'])) {
                $posterPath = __DIR__ . '/../../' . $item['poster'];
                if (file_exists($posterPath)) {
                    $imageData = file_get_contents($posterPath);
                    $itemData['poster_data'] = base64_encode($imageData);
                    $itemData['poster_extension'] = pathinfo($item['poster'], PATHINFO_EXTENSION);
                    unset($imageData);
                }
            }

            $json = json_encode($itemData, $jsonFlags);
            unset($itemData);

            if ($json === false) {
                throw new Exception("Error al generar el archivo de backup: " . json_last_error_msg()
                    . " (elemento '" . ($item['name'] ?? 'desconocido') . "')");
            }

            if (!$first) {
                echo ',';
            }
            echo $json;
            unset($json);
            flush();

            $first = false;
        }
    }

    /**
     * Importar backup completo
     */
    public function importBackup($userId, $file)
    {
        try {
            // Validar archivo
            if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Error al subir el archivo de backup");
            }

            // Verificar que sea un archivo JSON
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($fileExtension !== 'json') {
                throw new Exception("El archivo debe ser un JSON");
            }

            // Leer contenido del archivo
            $jsonContent = file_get_contents($file['tmp_name']);
            $backupData = json_decode($jsonContent, true);

            if ($backupData === null) {
                throw new Exception("El archivo JSON no es válido");
            }

            // Validar estructura del backup
            if (!isset($backupData['version']) || !isset($backupData['movies']) || !isset($backupData['series'])) {
                throw new Exception("El archivo de backup no tiene la estructura correcta");
            }

            $importedMovies = 0;
            $importedSeries = 0;
            $errors = [];

            // Importar películas
            foreach ($backupData['movies'] as $movieData) {
                try {
                    // Restaurar imagen
                    $posterPath = null;
                    if (isset($movieData['poster_data']) && isset($movieData['poster_extension'])) {
                        $imageData = base64_decode($movieData['poster_data']);
                        
                        $targetDir = __DIR__ . "/../../img/portadas_peliculas/";
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }

                        $nombreUnicoArchivo = uniqid("poster_") . "_imported." . $movieData['poster_extension'];
                        $rutaPoster = $targetDir . $nombreUnicoArchivo;

                        if (file_put_contents($rutaPoster, $imageData) !== false) {
                            $posterPath = "img/portadas_peliculas/" . $nombreUnicoArchivo;
                        }
                    }

                    // Crear película (sin el ID original)
                    $result = $this->movieController->getMovie(0); // Acceder al modelo
                    
                    // Usar el modelo directamente para crear la película
                    global $pdo;
                    $movieModel = new Movie($pdo);
                    
                    $rating = isset($movieData['rating']) && $movieData['rating'] !== '' ? (float)$movieData['rating'] : null;
                    
                    $created = $movieModel->createMovie(
                        $movieData['name'],
                        $movieData['synopsis'] ?? '',
                        $posterPath ?? '',
                        $movieData['director'],
                        $movieData['gender'],
                        $movieData['languages'],
                        $movieData['subtitles'] ?? null,
                        $movieData['size'],
                        $movieData['year'],
                        $movieData['id_quality'],
                        $movieData['backup'] ?? null,
                        $movieData['server'],
                        $rating
                    );

                    if ($created) {
                        // Asociar con el usuario
                        $lastMovieId = $movieModel->getLastInsertedId();
                        $movieModel->associateMovieWithUser($lastMovieId, $userId);
                        $importedMovies++;
                    }

                } catch (Exception $e) {
                    $errors[] = "Error importando película '" . ($movieData['name'] ?? 'desconocida') . "': " . $e->getMessage();
                    error_log($e->getMessage());
                }
            }

            // Importar series
            foreach ($backupData['series'] as $serieData) {
                try {
                    // Restaurar imagen
                    $posterPath = null;
                    if (isset($serieData['poster_data']) && isset($serieData['poster_extension'])) {
                        $imageData = base64_decode($serieData['poster_data']);
                        
                        $targetDir = __DIR__ . "/../../img/portadas_series/";
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }

                        $nombreUnicoArchivo = uniqid("poster_") . "_imported." . $serieData['poster_extension'];
                        $rutaPoster = $targetDir . $nombreUnicoArchivo;

                        if (file_put_contents($rutaPoster, $imageData) !== false) {
                            $posterPath = "img/portadas_series/" . $nombreUnicoArchivo;
                        }
                    }

                    // Crear serie
                    global $pdo;
                    $serieModel = new Serie($pdo);
                    
                    $rating = isset($serieData['rating']) && $serieData['rating'] !== '' ? (float)$serieData['rating'] : null;
                    
                    $created = $serieModel->createSerie(
                        $serieData['name'],
                        $posterPath ?? '',
                        $serieData['gender'],
                        $serieData['languages'],
                        $serieData['subtitles'] ?? null,
                        $serieData['seasons'],
                        $serieData['complete'] ?? 'no',
                        $serieData['year'],
                        $serieData['id_quality'],
                        $serieData['backup'] ?? null,
                        $rating,
                        $serieData['server'],
                        $serieData['size']
                    );

                    if ($created) {
                        // Asociar con el usuario
                        $lastSerieId = $serieModel->getLastInsertedId();
                        $serieModel->associateSerieWithUser($lastSerieId, $userId);
                        $importedSeries++;
                    }

                } catch (Exception $e) {
                    $errors[] = "Error importando serie '" . ($serieData['name'] ?? 'desconocida') . "': " . $e->getMessage();
                    error_log($e->getMessage());
                }
            }

            return [
                'success' => true,
                'imported_movies' => $importedMovies,
                'imported_series' => $importedSeries,
                'errors' => $errors
            ];

        } catch (Exception $e) {
            $this->lastError = "Error al importar backup: " . $e->getMessage();
            error_log($this->lastError);
            return [
                'success' => false,
                'message' => $this->lastError
            ];
        }
    }
}
?>