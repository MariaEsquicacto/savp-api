<?php

class Database
{
    private string $host = 'localhost';
    private string $database = 'savp';
    private string $username = 'root';
    private string $password = '';

    public function connect(): PDO
    {
        try {
            $pdo = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return $pdo;

        } catch (PDOException $e) {
            http_response_code(500);

            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Erro ao conectar ao banco de dados.'
            ]);

            exit;
        }
    }
}