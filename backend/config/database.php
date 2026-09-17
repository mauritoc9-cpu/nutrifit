<?php
/**
 * NutriFit — Conexión a la base de datos (PDO)
 * Compatible con desarrollo local (XAMPP) y producción (Heroku).
 */

class Database
{
    private string $host;
    private string $dbName;
    private string $username;
    private string $password;
    private string $charset = 'utf8mb4';
    private ?PDO $conn = null;

    public function __construct()
    {
        // En Heroku usa variables de entorno.
        // En local mantiene automáticamente la configuración de XAMPP.
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->dbName = getenv('DB_NAME') ?: 'nutrifit_db';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASSWORD') ?: '';
    }

    public function getConnection(): PDO
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        $dsn = "mysql:host={$this->host};dbname={$this->dbName};charset={$this->charset}";

        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error de conexión a la base de datos.',
            ]);
            exit;
        }

        return $this->conn;
    }
}
