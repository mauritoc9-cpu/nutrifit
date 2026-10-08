<?php
declare(strict_types=1);
/** Tokens para una futura entrega privada por correo. No contiene transport ni logs. */
final class PasswordResetTokens
{
    public function __construct(private PDO $db) {}
    // Sólo invocar una vez que haya un proveedor de correo configurado.
    // El token devuelto debe entregarse privadamente, nunca en respuestas HTTP/logs.
    public function emitirParaEntregaPrivada(int $usuarioId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('UPDATE usuarios SET reset_token_hash = :hash, reset_token = NULL, reset_token_expira = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = :id');
        $stmt->execute(['hash' => hash('sha256', $token), 'id' => $usuarioId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('No se pudo emitir la recuperación.');
        return $token;
    }
    public function consumir(string $token, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('UPDATE usuarios SET password = :password, reset_token_hash = NULL, reset_token = NULL, reset_token_expira = NULL WHERE reset_token_hash = :hash AND reset_token_expira > NOW()');
        $stmt->execute(['password' => $passwordHash, 'hash' => hash('sha256', $token)]);
        return $stmt->rowCount() === 1;
    }
}
