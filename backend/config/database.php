<?php
/**
 * NutriFit — Conexión a la base de datos (PDO)
 * Ajusta estas credenciales según tu entorno XAMPP.
 */

class Database
{
    private string $host = '127.0.0.1';
    private string $dbName = 'nutrifit_db';
    private string $username = 'root';
    private string $password = ''; // XAMPP por defecto no tiene password
    private string $charset = 'utf8mb4';
    private ?PDO $conn = null;

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
                PDO::ATTR_EMULATE_PREPARES   => false, // fuerza consultas preparadas reales
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
