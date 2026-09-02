<?php
declare(strict_types=1);

/**
 * Clase Usuario
 * Encapsula el acceso a la tabla `usuarios`: registro, autenticación
 * y lectura/actualización del estado de gamificación básico.
 */
class Usuario
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function emailExiste(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        return (bool) $stmt->fetch();
    }

    /** Crea un nuevo usuario con password hasheado. Devuelve el ID insertado. */
    public function registrar(string $nombre, string $email, string $passwordPlano): int
    {
        $hash = password_hash($passwordPlano, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (nombre, email, password) VALUES (:nombre, :email, :password)'
        );
        $stmt->execute([
            'nombre'   => $nombre,
            'email'    => $email,
            'password' => $hash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Verifica credenciales. Devuelve los datos del usuario (sin password) o null. */
    public function verificarCredenciales(string $email, string $passwordPlano): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $usuario = $stmt->fetch();

        if (!$usuario || !password_verify($passwordPlano, $usuario['password'])) {
            return null;
        }

        unset($usuario['password'], $usuario['reset_token'], $usuario['reset_token_expira']);
        return $usuario;
    }

    public function obtenerPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            return null;
        }

        unset($usuario['password'], $usuario['reset_token'], $usuario['reset_token_expira']);
        return $usuario;
    }
}
