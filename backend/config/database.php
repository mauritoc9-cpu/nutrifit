 <?php

/**
 * NutriFit — Conexión PDO
 * Producción: JawsDB en Heroku
 * Local: MySQL de XAMPP
 */

class Database
{
    private ?PDO $conn = null;

    public function getConnection(): PDO
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        // Heroku / JawsDB
        $jawsUrl = getenv('JAWSDB_URL');

        if ($jawsUrl) {
            $db = parse_url($jawsUrl);

            $host = $db['host'];
            $port = $db['port'] ?? 3306;
            $username = $db['user'];
            $password = $db['pass'];
            $dbName = ltrim($db['path'], '/');
        } else {
            // Desarrollo local con XAMPP
            $host = '127.0.0.1';
            $port = 3306;
            $username = 'root';
            $password = '';
            $dbName = 'nutrifit_db';
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

        try {
            $this->conn = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            return $this->conn;

        } catch (PDOException $e) {
            http_response_code(500);

            echo json_encode([
                'success' => false,
                'message' => 'Error de conexión a la base de datos.'
            ]);

            exit;
        }
    }
}
