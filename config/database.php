<?php
declare(strict_types=1);

/**
 * Retorna uma instância única de conexão PDO utilizando os parâmetros do banco.
 */
function conectar(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // Credenciais diretas do banco (evita erros de caminho de arquivos externos)
        $host = 'localhost';
        $db   = 'cesar'; // Altere para o nome exato do seu banco de dados se for diferente
        $user = 'root';  // Usuário padrão do XAMPP
        $pass = '';      // Senha padrão do XAMPP (geralmente vazia)
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}