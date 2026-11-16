-- Carga inicial de usuários com hash Bcrypt para compatibilidade com password_verify()
-- Senha padrão para todos os registros abaixo: 123456

INSERT INTO USUARIO (NOME, ENTRADA, SENHA, CARGO, ATIVO)
VALUES
('Carlos Henrique', 'carlos.henrique', '$2y$10$sqYD.hkikF3bvbF1HXddXOIQC5lacJ7zMyhwI56lGkrONqVE/laq.', 'ADMINISTRADOR', 1),
('João Pedro', 'joao.pedro', '$2y$10$sqYD.hkikF3bvbF1HXddXOIQC5lacJ7zMyhwI56lGkrONqVE/laq.', 'ARMEIRO', 1),
('Marcos Vinicius', 'marcos.vinicius', '$2y$10$sqYD.hkikF3bvbF1HXddXOIQC5lacJ7zMyhwI56lGkrONqVE/laq.', 'ARMEIRO', 1),
('Roberto Alves', 'roberto.alves', '$2y$10$sqYD.hkikF3bvbF1HXddXOIQC5lacJ7zMyhwI56lGkrONqVE/laq.', 'ARMEIRO', 1),
('Fernando Souza', 'fernando.souza', '$2y$10$sqYD.hkikF3bvbF1HXddXOIQC5lacJ7zMyhwI56lGkrONqVE/laq.', 'ADMINISTRADOR', 1),
('Lucas Martins', 'lucas.martins', '$2y$10$sqYD.hkikF3bvbF1HXddXOIQC5lacJ7zMyhwI56lGkrONqVE/laq.', 'ARMEIRO', 1),
('Rafael Oliveira', 'rafael.oliveira', '$2y$10$sqYD.hkikF3bvbF1HXddXOIQC5lacJ7zMyhwI56lGkrONqVE/laq.', 'ARMEIRO', 0);