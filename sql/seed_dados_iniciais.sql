-- Carga opcional de dados iniciais para testes do sistema C.E.S.A.R.
-- Armas de teste (Mosquefal e Pressão) com seriais de 5 caracteres
INSERT INTO ARMAMENTO (SERIAL, MODELO, CONDICAO, DISPONIBILIDADE) VALUES
('MQ001', 'MOSQUEFAL', 'Excelente estado de conservação', 1),
('MQ002', 'MOSQUEFAL', 'Bom estado, coronha com pequenas marcas', 1),
('MQ003', 'MOSQUEFAL', 'Em perfeito funcionamento', 1),
('MQ004', 'MOSQUEFAL', 'Revisado pela manutenção', 1),
('PR001', 'PRESSAO', 'Arma de pressão para instrução básica', 1),
('PR002', 'PRESSAO', 'Arma de pressão 4.5mm', 1)
ON DUPLICATE KEY UPDATE SERIAL=SERIAL;

-- Atiradores de teste vinculados às armas principais
INSERT INTO ATIRADOR (RA, NOME, NOME_GUERRA, NUMERO, ATIVO, ID_ARMAMENTO) VALUES
('202600000001', 'Lucas Gabriel dos Santos', 'SANTOS', '01', 1, 1),
('202600000002', 'Matheus Felipe Barbosa', 'BARBOSA', '02', 1, 2),
('202600000003', 'Guilherme Henrique Lima', 'LIMA', '03', 1, 3),
('202600000004', 'Felipe Augusto Rocha', 'ROCHA', '04', 1, 4),
('202600000005', 'Bruno Cesar Nogueira', 'NOGUEIRA', '05', 1, NULL)
ON DUPLICATE KEY UPDATE RA=RA;