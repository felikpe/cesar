<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
require_once __DIR__ . '/../includes/emprestimo-armamento-funcoes.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
validar_csrf();
$acao = (string) ($_POST['acao'] ?? '');
$pdo = conectar();

try {
    if ($acao === 'novo') {
        $idArmamento = filter_var($_POST['id_armamento'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $idPessoa = filter_var($_POST['id_pessoa'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $dataEmprestimo = data_form_para_banco_armaria((string) ($_POST['data_emprestimo'] ?? ''));
        $dataPrevista = data_form_para_banco_armaria((string) ($_POST['data_prevista'] ?? ''));
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        if (!$idArmamento || !$idPessoa || !$dataEmprestimo || !$dataPrevista || strlen($observacao) > 500 || $dataPrevista <= $dataEmprestimo) {
            throw new RuntimeException('Preencha os dados do empréstimo corretamente.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT ID_ARMAMENTO, STATUS_ARMA, ESTADO_DOCUMENTAL FROM ARMAMENTO WHERE ID_ARMAMENTO = :id FOR UPDATE");
        $stmt->execute([':id' => $idArmamento]);
        $arma = $stmt->fetch();
        if (!$arma) throw new RuntimeException('Arma não encontrada.');
        if ($arma['STATUS_ARMA'] !== 'DISPONIVEL') throw new RuntimeException('Essa arma não está disponível para empréstimo.');
        if ($arma['ESTADO_DOCUMENTAL'] === 'EM_MANUTENCAO') throw new RuntimeException('Essa arma está em manutenção.');

        $stmt = $pdo->prepare('SELECT ID_PESSOA FROM PESSOA WHERE ID_PESSOA = :id AND ATIVO = 1 LIMIT 1');
        $stmt->execute([':id' => $idPessoa]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('O atirador selecionado não está ativo.');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM EMPRESTIMO_ARMAMENTO WHERE ID_ARMAMENTO = :id AND DATA_DEVOLUCAO IS NULL');
        $stmt->execute([':id' => $idArmamento]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('Já existe empréstimo em aberto para essa arma.');

        $stmt = $pdo->prepare('INSERT INTO EMPRESTIMO_ARMAMENTO
            (ID_ARMAMENTO, ID_PESSOA, ID_USUARIO_RESPONSAVEL, DATA_EMPRESTIMO, DATA_PREVISTA_DEVOLUCAO, OBSERVACAO)
            VALUES (:arma, :pessoa, :usuario, :emprestimo, :prevista, :observacao)');
        $stmt->execute([
            ':arma' => $idArmamento,
            ':pessoa' => $idPessoa,
            ':usuario' => (int) $_SESSION['id_usuario'],
            ':emprestimo' => $dataEmprestimo,
            ':prevista' => $dataPrevista,
            ':observacao' => $observacao,
        ]);
        $idEmprestimo = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("UPDATE ARMAMENTO SET STATUS_ARMA = 'EMPRESTADA', DISPONIBILIDADE = 0 WHERE ID_ARMAMENTO = :id");
        $stmt->execute([':id' => $idArmamento]);
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'EMPRESTIMO_ARMA', "Empréstimo #{$idEmprestimo} registrado para a arma #{$idArmamento}.");
        $pdo->commit();
        mensagem_flash('sucesso', 'Empréstimo registrado.');
        header('Location: emprestimos.php', true, 303); exit;
    }

    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new RuntimeException('Empréstimo inválido.');

    if ($acao === 'alterar_prazo') {
        $dataPrevista = data_form_para_banco_armaria((string) ($_POST['data_prevista'] ?? ''));
        $justificativa = trim((string) ($_POST['justificativa'] ?? ''));
        if (!$dataPrevista || strlen($justificativa) < 3 || strlen($justificativa) > 500) {
            throw new RuntimeException('Informe a nova data e a justificativa da alteração.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT ID_ARMAMENTO, DATA_EMPRESTIMO, DATA_DEVOLUCAO, DATA_PREVISTA_DEVOLUCAO FROM EMPRESTIMO_ARMAMENTO WHERE ID_EMPRESTIMO_ARMAMENTO = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $registro = $stmt->fetch();
        if (!$registro) throw new RuntimeException('Empréstimo não encontrado.');
        if ($registro['DATA_DEVOLUCAO'] !== null) throw new RuntimeException('Não é possível alterar prazo de um empréstimo já devolvido.');
        if ($dataPrevista <= $registro['DATA_EMPRESTIMO']) throw new RuntimeException('A previsão deve ser posterior à data do empréstimo.');
        $stmt = $pdo->prepare('UPDATE EMPRESTIMO_ARMAMENTO SET DATA_PREVISTA_DEVOLUCAO = :prevista, JUSTIFICATIVA_ALTERACAO = :justificativa WHERE ID_EMPRESTIMO_ARMAMENTO = :id');
        $stmt->execute([':prevista' => $dataPrevista, ':justificativa' => $justificativa, ':id' => $id]);
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'PRAZO_ALTERADO', "Prazo do empréstimo #{$id} alterado.");
        $pdo->commit();
        mensagem_flash('sucesso', 'Prazo atualizado.');
        header('Location: emprestimo-form.php?id=' . $id, true, 303); exit;
    }

    if ($acao === 'devolver') {
        $dataDevolucao = data_form_para_banco_armaria((string) ($_POST['data_devolucao'] ?? ''));
        $observacao = trim((string) ($_POST['observacao_devolucao'] ?? ''));
        if (!$dataDevolucao || strlen($observacao) > 500) {
            throw new RuntimeException('Informe a data de devolução corretamente.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT M.ID_ARMAMENTO, M.DATA_EMPRESTIMO, M.DATA_DEVOLUCAO, A.ESTADO_DOCUMENTAL
            FROM EMPRESTIMO_ARMAMENTO M
            JOIN ARMAMENTO A ON A.ID_ARMAMENTO = M.ID_ARMAMENTO
            WHERE M.ID_EMPRESTIMO_ARMAMENTO = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $registro = $stmt->fetch();
        if (!$registro) throw new RuntimeException('Empréstimo não encontrado.');
        if ($registro['DATA_DEVOLUCAO'] !== null) throw new RuntimeException('A devolução já foi registrada.');
        if ($dataDevolucao < $registro['DATA_EMPRESTIMO']) throw new RuntimeException('A devolução não pode ser anterior ao empréstimo.');
        $stmt = $pdo->prepare('UPDATE EMPRESTIMO_ARMAMENTO SET DATA_DEVOLUCAO = :devolucao, OBSERVACAO_DEVOLUCAO = :obs, ID_USUARIO_DEVOLUCAO = :usuario WHERE ID_EMPRESTIMO_ARMAMENTO = :id');
        $stmt->execute([':devolucao' => $dataDevolucao, ':obs' => $observacao, ':usuario' => (int) $_SESSION['id_usuario'], ':id' => $id]);
        $statusRetorno = $registro['ESTADO_DOCUMENTAL'] === 'EM_MANUTENCAO' ? 'MANUTENCAO' : 'DISPONIVEL';
        $stmt = $pdo->prepare('UPDATE ARMAMENTO SET STATUS_ARMA = :status, DISPONIBILIDADE = :disp WHERE ID_ARMAMENTO = :id');
        $stmt->execute([':status' => $statusRetorno, ':disp' => $statusRetorno === 'DISPONIVEL' ? 1 : 0, ':id' => (int) $registro['ID_ARMAMENTO']]);
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'DEVOLUCAO_ARMA', "Devolução registrada no empréstimo #{$id}.");
        $pdo->commit();
        mensagem_flash('sucesso', 'Devolução registrada.');
        header('Location: emprestimos.php', true, 303); exit;
    }

    throw new RuntimeException('Ação inválida.');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mensagem_flash('erro', $ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível salvar a operação.');
    if ($acao === 'novo') {
        header('Location: emprestimo-form.php', true, 303);
    } else {
        header('Location: emprestimo-form.php?id=' . (int) ($_POST['id'] ?? 0), true, 303);
    }
    exit;
}
