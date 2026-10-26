-- Etapa 7: foto da própria conta administrativa.
-- Rode UMA VEZ sobre o banco CESAR já atualizado até a etapa 6.
-- Nenhum registro existente será apagado.
USE CESAR;

ALTER TABLE USUARIO
    ADD COLUMN FOTO_PERFIL VARCHAR(100) NULL;

SELECT 'Etapa 7 instalada: campo de foto de perfil adicionado.' AS RESULTADO;
