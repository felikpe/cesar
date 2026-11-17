<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/funcoes.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/biometria.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function biometria_responder(int $status, array $resposta): void
{
    http_response_code($status);
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    biometria_responder(405, ['ok' => false, 'code' => 'METODO_INVALIDO', 'message' => 'Método não permitido.']);
}
if (empty($_SESSION['id_usuario'])) {
    biometria_responder(401, ['ok' => false, 'code' => 'LOGIN_NECESSARIO', 'message' => 'Entre no sistema novamente.']);
}

try {
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        throw new BiometriaErro('FORMATO_INVALIDO', 'Envie os dados em JSON.', 415);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        throw new BiometriaErro('DADOS_EXCESSIVOS', 'A solicitação é maior que o limite permitido.', 413);
    }
    $corpo = file_get_contents('php://input', false, null, 0, 16385);
    if ($corpo === false || strlen($corpo) > 16384) {
        throw new BiometriaErro('DADOS_EXCESSIVOS', 'A solicitação é maior que o limite permitido.', 413);
    }
    $dados = json_decode($corpo, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($dados)) {
        throw new BiometriaErro('DADOS_INVALIDOS', 'A solicitação deve ser um objeto JSON.', 400);
    }
    $token = $dados['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        throw new BiometriaErro('CSRF_INVALIDO', 'Formulário expirado. Atualize a página.', 419);
    }
    $pdo = conectar();
    $stmt = $pdo->prepare('SELECT ID_USUARIO, NOME, CARGO, ATIVO FROM USUARIO WHERE ID_USUARIO = :id');
    $stmt->execute([':id' => (int) $_SESSION['id_usuario']]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!conta_tem_acesso($pdo, $conta)) {
        $_SESSION = [];
        throw new BiometriaErro('LOGIN_NECESSARIO', 'Sua conta não possui mais acesso. Entre no sistema novamente.', 401);
    }
    $acao = $dados['acao'] ?? '';
    if (!is_string($acao)) {
        throw new BiometriaErro('ACAO_INVALIDA', 'Ação inválida.', 400);
    }
    if ($acao === 'reservar') {
        $resultado = biometria_reservar($pdo, $dados);
    } elseif ($acao === 'recuperar_reserva') {
        $resultado = biometria_recuperar_reserva($pdo, $dados);
    } elseif (in_array($acao, ['concluir', 'cancelar_reserva', 'estado_reserva'], true)) {
        $resultado = biometria_finalizar($pdo, $dados, $acao);
    } elseif ($acao === 'identificar') {
        $resultado = biometria_identificar($pdo, $dados);
    } else {
        throw new BiometriaErro('ACAO_INVALIDA', 'Ação inválida.', 400);
    }
    biometria_responder(200, ['ok' => true] + $resultado);
} catch (BiometriaErro $erro) {
    biometria_responder($erro->status, ['ok' => false, 'code' => $erro->codigo, 'message' => $erro->getMessage()]);
} catch (JsonException $erro) {
    biometria_responder(400, ['ok' => false, 'code' => 'JSON_INVALIDO', 'message' => 'JSON inválido.']);
} catch (PDOException $erro) {
    $codigo = (string) $erro->getCode();
    if ($codigo === '23000') {
        biometria_responder(409, ['ok' => false, 'code' => 'CONFLITO_DE_CADASTRO', 'message' => 'A digital ou o atirador já está reservado ou vinculado. Consulte o leitor e tente novamente.']);
    }
    if (in_array($codigo, ['42S02', '42S22'], true)) {
        biometria_responder(503, ['ok' => false, 'code' => 'MIGRACAO_PENDENTE', 'message' => 'Execute sql/schema.sql no banco do sistema.']);
    }
    biometria_responder(503, ['ok' => false, 'code' => 'BANCO_INDISPONIVEL', 'message' => 'Não foi possível acessar o banco de dados. Tente novamente.']);
} catch (Throwable $erro) {
    biometria_responder(500, ['ok' => false, 'code' => 'FALHA_INTERNA', 'message' => 'Não foi possível concluir a operação de biometria.']);
}
