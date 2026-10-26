<?php
declare(strict_types=1);
require_once __DIR__ . '/sessao.php';

function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_painel'])) {
        $_SESSION['csrf_painel'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_painel'];
}

function validar_csrf(): void
{
    $recebido = (string) ($_POST['csrf_token'] ?? '');
    if ($recebido === '' || !hash_equals(csrf_token(), $recebido)) {
        http_response_code(419);
        exit('Formulário inválido ou expirado. Volte, atualize a página e tente novamente.');
    }
}

/* Preserva integralmente o comportamento existente de gravação de auditoria. */
function registrar_log(PDO $pdo, ?int $autorId, string $acao, string $descricao): void
{
    $sql = 'INSERT INTO LOG_SISTEMA (ID_USUARIO, ACAO, DESCRICAO, IP_ORIGEM)
            VALUES (:autor, :acao, :descricao, :ip)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':autor' => $autorId,
        ':acao' => $acao,
        ':descricao' => $descricao,
        ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

function mensagem_flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function recuperar_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

/** Rótulos de auditoria legíveis, inclusive para eventos históricos. */
function texto_auditoria(?string $texto): string
{
    $texto = (string) $texto;
    $rotulos = [
        'PESSOA_CADASTRADA' => 'Atirador cadastrado',
        'PESSOA_ATUALIZADA' => 'Atirador atualizado',
        'PESSOA_REATIVADA' => 'Atirador reativado',
        'PESSOA_DESATIVADA' => 'Atirador desativado',
        'CATALOGO_CADASTRADO' => 'Arma cadastrada',
        'CATALOGO_ATUALIZADO' => 'Arma atualizada',
        'CATALOGO_EVENTO' => 'Evento no histórico da arma',
        'ARMA_CADASTRADA' => 'Arma cadastrada',
        'ARMA_ATUALIZADA' => 'Arma atualizada',
        'ARMA_EVENTO' => 'Evento no histórico da arma',
        'EMPRESTIMO_ARMA' => 'Empréstimo registrado',
        'DEVOLUCAO_ARMA' => 'Devolução registrada',
        'PRAZO_ALTERADO' => 'Prazo alterado',
        'OPERADOR_CRIADO' => 'Auxiliar cadastrado',
        'OPERADOR_ATIVADO' => 'Auxiliar ativado',
        'OPERADOR_DESATIVADO' => 'Auxiliar desativado',
        'AUXILIAR_CRIADO' => 'Auxiliar cadastrado',
        'AUXILIAR_ATIVADO' => 'Auxiliar ativado',
        'AUXILIAR_DESATIVADO' => 'Auxiliar desativado',
        'FOTO_PERFIL_ATUALIZADA' => 'Foto de perfil atualizada',
        'FOTO_PERFIL_REMOVIDA' => 'Foto de perfil removida',
        'SENHA_ALTERADA' => 'Senha alterada',
        'LOGIN' => 'Entrada no sistema',
        'LOGOUT' => 'Saída do sistema',
        'TI_CADASTRADO' => 'Registro legado',
        'TI_EMPRESTIMO_CRIADO' => 'Registro legado',
        'TI_PRAZO_ALTERADO' => 'Registro legado',
        'TI_DEVOLVIDO' => 'Registro legado',
    ];
    if (isset($rotulos[$texto])) {
        return $rotulos[$texto];
    }
    if (preg_match('/\A[A-Z0-9_]+\z/D', $texto)) {
        return ucfirst(strtolower(str_replace('_', ' ', $texto)));
    }
    return str_ireplace(
        ['da pessoa ', 'a pessoa ', 'Pessoa ', 'pessoas ', 'do operador ', 'o operador ', 'Operador ', 'operadores '],
        ['do atirador ', 'o atirador ', 'Atirador ', 'atiradores ', 'do auxiliar ', 'o auxiliar ', 'Auxiliar ', 'auxiliares '],
        $texto
    );
}

/** Consulta apenas o nome atual dos cadastros. Nunca altera os dados auditados. */
function auditoria_nome_atual(PDO $pdo, string $entidade, int $id): ?string
{
    if ($id < 1) return null;
    static $cache = [];
    $chave = $entidade . ':' . $id;
    if (array_key_exists($chave, $cache)) return $cache[$chave];

    $consultas = [
        'atirador' => 'SELECT NOME FROM PESSOA WHERE ID_PESSOA = :id LIMIT 1',
        'arma' => 'SELECT SERIAL FROM ARMAMENTO WHERE ID_ARMAMENTO = :id LIMIT 1',
        'auxiliar' => 'SELECT NOME FROM USUARIO WHERE ID_USUARIO = :id LIMIT 1',
    ];
    if (!isset($consultas[$entidade])) return null;
    $stmt = $pdo->prepare($consultas[$entidade]);
    $stmt->execute([':id' => $id]);
    $nome = $stmt->fetchColumn();
    $cache[$chave] = is_string($nome) && trim($nome) !== '' ? trim($nome) : null;
    return $cache[$chave];
}

/** Detalhes de um empréstimo já registrado, somente para consulta administrativa. */
function auditoria_dados_registro(PDO $pdo, int $id): ?array
{
    if ($id < 1) return null;
    static $cache = [];
    if (array_key_exists($id, $cache)) return $cache[$id];
    $stmt = $pdo->prepare('SELECT A.SERIAL AS SERIE, P.NOME AS ATIRADOR
        FROM EMPRESTIMO_ARMAMENTO E
        JOIN ARMAMENTO A ON A.ID_ARMAMENTO = E.ID_ARMAMENTO
        JOIN PESSOA P ON P.ID_PESSOA = E.ID_PESSOA
        WHERE E.ID_EMPRESTIMO_ARMAMENTO = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
    $cache[$id] = is_array($dados) ? $dados : null;
    return $cache[$id];
}

/**
 * Converte textos legados com IDs em descrições compreensíveis.
 * Não inventa nomes quando o cadastro original já não existe.
 */
function descricao_log_visivel(PDO $pdo, string $acao, ?string $descricao): string
{
    $original = texto_auditoria($descricao);

    $cadastros = [
        'PESSOA_CADASTRADA' => ['atirador', 'Atirador', 'cadastrado'],
        'PESSOA_ATUALIZADA' => ['atirador', 'Atirador', 'atualizado'],
        'PESSOA_REATIVADA' => ['atirador', 'Atirador', 'reativado'],
        'PESSOA_DESATIVADA' => ['atirador', 'Atirador', 'desativado'],
        'CATALOGO_CADASTRADO' => ['arma', 'Arma', 'cadastrada'],
        'CATALOGO_ATUALIZADO' => ['arma', 'Arma', 'atualizada'],
        'ARMA_CADASTRADA' => ['arma', 'Arma', 'cadastrada'],
        'ARMA_ATUALIZADA' => ['arma', 'Arma', 'atualizada'],
        'OPERADOR_CRIADO' => ['auxiliar', 'Auxiliar', 'cadastrado'],
        'OPERADOR_ATIVADO' => ['auxiliar', 'Auxiliar', 'ativado'],
        'OPERADOR_DESATIVADO' => ['auxiliar', 'Auxiliar', 'desativado'],
        'AUXILIAR_CRIADO' => ['auxiliar', 'Auxiliar', 'cadastrado'],
        'AUXILIAR_ATIVADO' => ['auxiliar', 'Auxiliar', 'ativado'],
        'AUXILIAR_DESATIVADO' => ['auxiliar', 'Auxiliar', 'desativado'],
    ];

    if (isset($cadastros[$acao])) {
        [$tipo, $rotulo, $verbo] = $cadastros[$acao];
        // Exemplos anteriores: "Atirador #3", "Auxiliar ID 4" e "Registro do catálogo #2".
        if (preg_match('/(?:#|\bID\s+)(\d+)\b/ui', $original, $m)) {
            $id = (int) $m[1];
            $nome = auditoria_nome_atual($pdo, $tipo, $id);
            if ($nome !== null) return $rotulo . ' ' . $nome . ' ' . $verbo . '.';
            return $rotulo . ' de ID ' . $id . ' ' . $verbo . ' (cadastro não encontrado).';
        }
        return $original;
    }

    if (in_array($acao, ['EMPRESTIMO_ARMA', 'DEVOLUCAO_ARMA', 'PRAZO_ALTERADO'], true)) {
        if (!preg_match('/empr[eé]stimo\s*#(\d+)\b/ui', $original, $m)) return $original;
        $id = (int) $m[1];
        $dados = auditoria_dados_registro($pdo, $id);
        if (!$dados) return 'Registro de movimentação de ID ' . $id . ' não encontrado; consulte o histórico original.';
        $referencia = 'arma ' . (string) $dados['SERIE'] . ' — atirador ' . (string) $dados['ATIRADOR'];
        return match ($acao) {
            'EMPRESTIMO_ARMA' => 'Empréstimo registrado: ' . $referencia . '.',
            'DEVOLUCAO_ARMA' => 'Devolução registrada: ' . $referencia . '.',
            default => 'Prazo de devolução alterado: ' . $referencia . '.',
        };
    }

    if (in_array($acao, ['ARMA_EVENTO', 'CATALOGO_EVENTO'], true)) {
        if (!preg_match('/(?:arma|registro(?:\s+do\s+cat[aá]logo)?)\s*#(\d+)\b/ui', $original, $m)) return $original;
        $id = (int) $m[1];
        $serie = auditoria_nome_atual($pdo, 'arma', $id);
        $rotulos = [
            'OBSERVACAO' => 'Observação registrada',
            'DEFEITO_RELATADO' => 'Defeito relatado',
            'ENVIADO_MANUTENCAO' => 'Encaminhamento à manutenção registrado',
            'RETORNOU_MANUTENCAO' => 'Retorno da manutenção registrado',
        ];
        preg_match('/Evento\s+([A-Z_]+)\b/u', (string) $descricao, $evento);
        $descricaoEvento = $rotulos[$evento[1] ?? ''] ?? 'Evento registrado';
        $arma = $serie !== null ? 'arma ' . $serie : 'arma de ID ' . $id . ' (cadastro não encontrado)';
        return $descricaoEvento . ': ' . $arma . '.';
    }

    return $original;
}
