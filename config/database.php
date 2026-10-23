<?php
// Arquivo gerado pelo Instalador Automático
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $db_host = '127.0.0.1';
        $db_port = '3306';
        $db_name = 'api_gateway';
        $db_user = 'root';
        $db_pass = '';

        try {
            $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die("Erro ao conectar ao banco de dados MySQL: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->pdo;
    }
}

function getDB() {
    return Database::getInstance();
}
