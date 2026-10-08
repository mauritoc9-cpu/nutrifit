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

            // Alinea la sesión de MySQL con la zona horaria de PHP (fijada en
            // bootstrap.php vía APP_TIMEZONE). Se pasa el OFFSET calculado por
            // PHP desde la zona IANA — no un número hardcodeado: si la zona
            // cambiara su offset (p. ej. reintroducción de horario de verano),
            // PHP lo refleja y MySQL lo sigue. Usar el offset en vez del
            // nombre de zona evita depender de las tablas de timezone de MySQL,
            // que en XAMPP/MariaDB no suelen estar cargadas.
            $offset = (new DateTime('now'))->format('P'); // ej. "-03:00"
            $this->conn->exec("SET time_zone = '{$offset}'");

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
