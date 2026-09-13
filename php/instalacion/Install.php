<?php

require_once __DIR__ . '/../config.php';

class Install
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Función para crear la base de datos
    public function createDatabase()
    {
        $stmt = "CREATE DATABASE IF NOT EXISTS BibliotecaMultimedia";
        $this->pdo->exec($stmt);
    }

    // Función para crear la tabla de usuarios
    public function createTableUsers()
    {
        $stmt = "CREATE TABLE IF NOT EXISTS Users(
    id_user INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    salt VARCHAR(255) NOT NULL,
    password VARCHAR(300) NOT NULL,
    profile VARCHAR(255),
    two_factor BOOLEAN DEFAULT FALSE,
    rol VARCHAR(15) DEFAULT 'normal' CHECK (rol IN ('root', 'normal', 'solicita', 'propietario'))
)";
        $this->pdo->exec($stmt);
    }

    // Función para crear la tabla de calidades
    public function createTableQualities()
    {
        $stmt = "CREATE TABLE IF NOT EXISTS Qualities(
    id_quality INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(15) NOT NULL
)";
        $this->pdo->exec($stmt);
    }

    // Función para insertar valores por defecto en la tabla de calidades
    public function insertValuesQualities()
    {
        $stmt = "INSERT INTO Qualities(name) VALUES('4K');
            INSERT INTO Qualities(name) VALUES('1440p');
            INSERT INTO Qualities(name) VALUES('1080p');
            INSERT INTO Qualities(name) VALUES('720p');
            INSERT INTO Qualities(name) VALUES('480p');
            INSERT INTO Qualities(name) VALUES('otro');";
        $this->pdo->exec($stmt);
    }

    // Función para crear la tabla de películas
    public function createTableMovies()
    {
        $stmt = "CREATE TABLE IF NOT EXISTS Movies(
    id_movie INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(300) NOT NULL,
    synopsis VARCHAR(1500),
    poster VARCHAR(255) NOT NULL,
    director VARCHAR(100) NOT NULL,
    gender VARCHAR(200) NOT NULL,
    languages VARCHAR(200) NOT NULL,
    subtitles VARCHAR(200),
    size FLOAT NOT NULL,
    year INT NOT NULL,
    id_quality INT NOT NULL,
    FOREIGN KEY (id_quality) REFERENCES Qualities(id_quality),
    backup VARCHAR(200),
    server VARCHAR(2) NOT NULL CHECK (server IN ('si', 'no')),
    rating FLOAT
)";
        $this->pdo->exec($stmt);
    }

    // Función para crear la tabla series
    public function createTableSeries()
    {
        $stmt = "CREATE TABLE IF NOT EXISTS Series(
    id_serie INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(300) NOT NULL,
    poster VARCHAR(255) NOT NULL,
    gender VARCHAR(100) NOT NULL,
    languages VARCHAR(100) NOT NULL,
    subtitles VARCHAR(200),
    seasons INT NOT NULL,
    complete VARCHAR(2) NOT NULL CHECK (complete IN ('si', 'no')),
    year INT NOT NULL,
    id_quality INT NOT NULL,
    FOREIGN KEY (id_quality) REFERENCES Qualities(id_quality),
    backup VARCHAR(200),
    size FLOAT NOT NULL,
    server VARCHAR(2) NOT NULL CHECK (server IN ('si', 'no')),
    rating FLOAT
)";
        $this->pdo->exec($stmt);
    }

    // Función para crear la tabla que sale de unir usuarios y películas
    public function createTableUsersMovies()
    {
        $stmt = "CREATE TABLE IF NOT EXISTS Users_Movies(
    id_user INT,
    id_movie INT,
    PRIMARY KEY (id_user, id_movie),
    FOREIGN KEY (id_user) REFERENCES Users(id_user) ON DELETE CASCADE,
    FOREIGN KEY (id_movie) REFERENCES Movies(id_movie) ON DELETE CASCADE
)";
        $this->pdo->exec($stmt);
    }

    // Función para crear la tabla que sale de la unión de usuarios y series
    public function createTableUsersSeries()
    {
        $stmt = "CREATE TABLE IF NOT EXISTS Users_Series(
    id_user INT,
    id_serie INT,
    PRIMARY KEY (id_user, id_serie),
    FOREIGN KEY (id_user) REFERENCES Users(id_user) ON DELETE CASCADE,
    FOREIGN KEY (id_serie) REFERENCES Series(id_serie) ON DELETE CASCADE
)";
        $this->pdo->exec($stmt);
    }

    // Crear la tabla del registro de movimientos
    public function createTableMovements()
    {
        $stmt = "CREATE TABLE IF NOT EXISTS Movements(
    id_movement INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    he_did VARCHAR(500) NOT NULL,
    moment DATETIME NOT NULL,
    with_result VARCHAR(200) NOT NULL
)";
        $this->pdo->exec($stmt);
    }

    // Crear un usuario propietario
    public function createOwner($username, $email, $salt, $password, $profile, $two_factor, $rol){
        try {
            $stmt = $this->pdo->prepare("INSERT INTO Users (username, email, salt, password, profile, two_factor, rol) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?)");
            return $stmt->execute([$username, $email, $salt, $password, $profile, $two_factor, $rol]);
        } catch (PDOException $e) {
            error_log("Error creando al propietario: " . $e->getMessage());
            return false;
        }
    }
}

?>