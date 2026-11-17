<?php
declare(strict_types=1);

function perfis_admin(): bool
{
    return ($_SESSION['cargo'] ?? '') === 'ADMINISTRADOR';
}

function perfis_usuario(PDO $pdo, ?int $id, bool $bloquear = false): ?array
{
    if ($id === null) return null;
    $stmt = $pdo->prepare('SELECT ID_USUARIO, NOME, ENTRADA, CARGO, ATIVO FROM USUARIO WHERE ID_USUARIO = :id' . ($bloquear ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function perfis_bloquear_ficha(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT ID_USUARIO FROM ATIRADOR WHERE ID_ATIRADOR = :id');
    $stmt->execute([':id' => $id]);
    $antes = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$antes) throw new RuntimeException('Atirador não encontrado.');
    $usuarioId = $antes['ID_USUARIO'] === null ? null : (int) $antes['ID_USUARIO'];
    if ($usuarioId !== null) perfis_usuario($pdo, $usuarioId, true);
    $stmt = $pdo->prepare('SELECT ID_ATIRADOR, NOME, NOME_GUERRA, FOTO, ID_DIGITAL, ID_ARMAMENTO, ID_USUARIO, PERFIL, ATIVO FROM ATIRADOR WHERE ID_ATIRADOR = :id FOR UPDATE');
    $stmt->execute([':id' => $id]);
    $ficha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ficha || ($ficha['ID_USUARIO'] === null ? null : (int) $ficha['ID_USUARIO']) !== $usuarioId) {
        throw new RuntimeException('O vínculo de acesso foi alterado. Atualize a ficha e tente novamente.');
    }
    return $ficha;
}

function perfis_vinculo_exclusivo(PDO $pdo, int $usuarioId, int $pessoaId): void
{
    $stmt = $pdo->prepare('SELECT ID_ATIRADOR FROM ATIRADOR WHERE ID_USUARIO = :usuario AND ID_ATIRADOR <> :pessoa LIMIT 1 FOR UPDATE');
    $stmt->execute([':usuario' => $usuarioId, ':pessoa' => $pessoaId]);
    if ($stmt->fetchColumn()) throw new RuntimeException('Esta conta já está vinculada a outra ficha. Confira os vínculos existentes antes de continuar.');
}

function perfis_senha(string $senha, string $confirmacao, bool $obrigatoria): void
{
    if ($senha === '' && !$obrigatoria && $confirmacao === '') return;
    if (strlen($senha) < 8 || strlen($senha) > 72 || $senha !== $confirmacao) {
        throw new RuntimeException('A senha deve ter de 8 a 72 caracteres e ser igual à confirmação.');
    }
}

function perfis_gravar_acesso(PDO $pdo, array $ficha, string $perfil, string $nome, array $dados, ?int $legado = null): ?int
{
    if (!in_array($perfil, ['ATIRADOR', 'ARMEIRO'], true)) throw new RuntimeException('Selecione Atirador ou Armeiro.');
    $atual = (string) ($ficha['PERFIL'] ?? 'ATIRADOR');
    $usuarioId = $ficha['ID_USUARIO'] === null ? null : (int) $ficha['ID_USUARIO'];
    foreach (['login_acesso', 'senha_acesso', 'confirmar_senha_acesso'] as $campo) {
        if (isset($dados[$campo]) && !is_string($dados[$campo])) throw new RuntimeException('Os dados de acesso devem ser preenchidos como texto.');
    }
    $loginRecebido = $dados['login_acesso'] ?? null;
    $senha = (string) ($dados['senha_acesso'] ?? '');
    $confirmacao = (string) ($dados['confirmar_senha_acesso'] ?? '');
    $login = $loginRecebido === null ? null : strtolower(trim((string) $loginRecebido));
    if (isset($dados['id_usuario']) && (string) $dados['id_usuario'] !== '' && (string) $dados['id_usuario'] !== (string) $usuarioId) {
        throw new RuntimeException('O vínculo de acesso não pode ser escolhido por um identificador enviado pelo formulário.', 403);
    }
    if (!perfis_admin()) {
        if ($perfil !== $atual || $legado !== null || $loginRecebido !== null || $senha !== '' || $confirmacao !== '') {
            throw new RuntimeException('Somente administradores podem criar ou alterar acesso ao sistema.', 403);
        }
        return $usuarioId;
    }
    if ($legado !== null) {
        if ((int) $ficha['ID_ATIRADOR'] !== 0 || $usuarioId !== null || $perfil !== 'ARMEIRO') {
            throw new RuntimeException('A conta antiga só pode ser vinculada ao completar uma ficha nova.');
        }
        $usuarioId = $legado;
    }
    $conta = perfis_usuario($pdo, $usuarioId, true);
    if ($usuarioId !== null && !$conta) throw new RuntimeException('A conta vinculada não foi encontrada.');
    if ($conta && $conta['CARGO'] === 'ADMINISTRADOR') {
        if ($perfil !== 'ATIRADOR' || $legado !== null || $loginRecebido !== null || $senha !== '' || $confirmacao !== '') {
            throw new RuntimeException('Contas de administrador não são alteradas pelo cadastro de atiradores.', 403);
        }
        return $usuarioId;
    }
    if ($conta && $conta['CARGO'] !== 'ARMEIRO') throw new RuntimeException('O cargo da conta vinculada não é válido.');
    if ($conta) perfis_vinculo_exclusivo($pdo, $usuarioId, (int) $ficha['ID_ATIRADOR']);
    if ($perfil === 'ATIRADOR') {
        if ($loginRecebido !== null || $senha !== '' || $confirmacao !== '') throw new RuntimeException('Dados de acesso só devem ser preenchidos para o perfil Armeiro.');
        if ($conta) {
            $stmt = $pdo->prepare("UPDATE USUARIO SET ATIVO = 0 WHERE ID_USUARIO = :id AND CARGO = 'ARMEIRO'");
            $stmt->execute([':id' => $usuarioId]);
            if ((int) $conta['ATIVO'] !== 0) registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ARMEIRO_DESATIVADO', 'Acesso de ' . $conta['NOME'] . ' desativado ao alterar o perfil para Atirador.');
        }
        return $usuarioId;
    }
    $login = $login ?? (string) ($conta['ENTRADA'] ?? '');
    if ($login === '' || strlen($login) > 150 || preg_match('/[\x00-\x1F\x7F]/', $login)) throw new RuntimeException('Informe um login válido de até 150 caracteres.');
    perfis_senha($senha, $confirmacao, $conta === null);
    $ativo = (int) $ficha['ATIVO'] === 1 ? 1 : 0;
    if (!$conta) {
        $stmt = $pdo->prepare("INSERT INTO USUARIO (NOME, ENTRADA, SENHA, CARGO, ATIVO) VALUES (:nome, :entrada, :senha, 'ARMEIRO', :ativo)");
        $stmt->execute([':nome' => $nome, ':entrada' => $login, ':senha' => password_hash($senha, PASSWORD_DEFAULT), ':ativo' => $ativo]);
        $usuarioId = (int) $pdo->lastInsertId();
        registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ARMEIRO_CRIADO', 'Armeiro ' . $nome . ' cadastrado com ficha de atirador.');
    } else {
        $sql = 'UPDATE USUARIO SET NOME = :nome, ENTRADA = :entrada, ATIVO = :ativo';
        $params = [':nome' => $nome, ':entrada' => $login, ':ativo' => $ativo, ':id' => $usuarioId];
        if ($senha !== '') {
            $sql .= ', SENHA = :senha';
            $params[':senha'] = password_hash($senha, PASSWORD_DEFAULT);
        }
        $stmt = $pdo->prepare($sql . " WHERE ID_USUARIO = :id AND CARGO = 'ARMEIRO'");
        $stmt->execute($params);
        if ((int) $conta['ATIVO'] !== $ativo) registrar_log($pdo, (int) $_SESSION['id_usuario'], $ativo ? 'ARMEIRO_ATIVADO' : 'ARMEIRO_DESATIVADO', 'Acesso de ' . $nome . ($ativo ? ' ativado.' : ' desativado.'));
        if ($senha !== '' || $login !== $conta['ENTRADA']) registrar_log($pdo, (int) $_SESSION['id_usuario'], 'ARMEIRO_ACESSO_ATUALIZADO', 'Dados de acesso de ' . $nome . ' atualizados.');
    }
    return $usuarioId;
}

function perfis_sincronizar_status(PDO $pdo, array $ficha, int $novo): void
{
    if ($ficha['ID_USUARIO'] === null || $ficha['PERFIL'] !== 'ARMEIRO') return;
    $conta = perfis_usuario($pdo, (int) $ficha['ID_USUARIO'], true);
    if (!$conta || $conta['CARGO'] !== 'ARMEIRO') return;
    if (!perfis_admin()) throw new RuntimeException('Somente administradores podem alterar a situação de atiradores com acesso de armeiro.', 403);
    perfis_vinculo_exclusivo($pdo, (int) $ficha['ID_USUARIO'], (int) $ficha['ID_ATIRADOR']);
    $stmt = $pdo->prepare("UPDATE USUARIO SET ATIVO = :ativo WHERE ID_USUARIO = :id AND CARGO = 'ARMEIRO'");
    $stmt->execute([':ativo' => $novo, ':id' => (int) $ficha['ID_USUARIO']]);
    if ((int) $conta['ATIVO'] !== $novo) registrar_log($pdo, (int) $_SESSION['id_usuario'], $novo ? 'ARMEIRO_ATIVADO' : 'ARMEIRO_DESATIVADO', 'Acesso de ' . $conta['NOME'] . ($novo ? ' ativado.' : ' desativado.'));
}
