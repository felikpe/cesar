-- Execute DEPOIS de sql/schema.sql no phpMyAdmin.
-- Complementa o projeto CESAR sem apagar registros existentes.
USE CESAR;

-- Auditoria administrativa (a integracao com as telas sera posterior).
CREATE TABLE IF NOT EXISTS LOG_SISTEMA (
    ID_LOG BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ID_USUARIO INT NULL,
    ACAO VARCHAR(100) NOT NULL,
    DESCRICAO TEXT NULL,
    IP_ORIGEM VARCHAR(45) NULL,
    CRIADO_EM DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT FK_LOG_USUARIO FOREIGN KEY (ID_USUARIO)
        REFERENCES USUARIO(ID_USUARIO) ON DELETE SET NULL,
    INDEX IDX_LOG_DATA (CRIADO_EM)
) ENGINE=InnoDB;

-- Os hashes abaixo foram gerados com PHP password_hash(PASSWORD_BCRYPT).
-- Conta de demonstracao: altere as senhas antes de qualquer implantacao.
-- INSERT IGNORE evita sobrescrever usuarios com o mesmo login.
INSERT IGNORE INTO USUARIO (NOME, ENTRADA, SENHA, CARGO) VALUES
('Administrador 1', 'armaria@adm1',
 '$2y$12$pLySqiYab9LlNmi5tNloY.IXRjzFqpVhV5wpVb9vw84r65CQD83f6',
 'ADMINISTRADOR'),
('Administrador 2', 'armaria@adm2',
 '$2y$12$57NLuDspd2VfGCRd6kSxnu9PleNDY37k8VfMGyXn3fBwTTkGB1rNG',
 'ADMINISTRADOR');

SELECT ID_USUARIO, NOME, ENTRADA, CARGO
FROM USUARIO
WHERE ENTRADA IN ('armaria@adm1', 'armaria@adm2');
