<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/proteger.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
validar_csrf();
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range'=>1]]);
if (!$id) { http_response_code(400); exit('Item inválido.'); }
$tipo = (string)($_POST['tipo'] ?? '');
$descricao = trim((string)($_POST['descricao'] ?? ''));
$novoEstadoDocumental = [
    'OBSERVACAO'=>null,
    'DEFEITO_RELATADO'=>'EM_ANALISE',
    'ENVIADO_MANUTENCAO'=>'EM_MANUTENCAO',
    'RETORNOU_MANUTENCAO'=>'MANUTENCAO_CONCLUIDA',
];
if (!array_key_exists($tipo, $novoEstadoDocumental) || strlen($descricao) < 5 || strlen($descricao) > 2000) {
    mensagem_flash('erro','Selecione um evento e informe uma descrição de 5 a 2000 caracteres.');
    header('Location: catalogo-detalhe.php?id='.$id, true, 303); exit;
}
$pdo = conectar();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT ESTADO_DOCUMENTAL, STATUS_ARMA FROM ARMAMENTO WHERE ID_ARMAMENTO=:id FOR UPDATE');
    $stmt->execute([':id'=>$id]);
    $atual = $stmt->fetch();
    if (!$atual) throw new RuntimeException('Registro não encontrado.');
    if ($tipo === 'ENVIADO_MANUTENCAO') {
        if ($atual['STATUS_ARMA'] === 'EMPRESTADA') throw new RuntimeException('Devolva a arma antes de enviá-la para manutenção.');
        if ($atual['STATUS_ARMA'] === 'MANUTENCAO') throw new RuntimeException('Esta arma já está em manutenção.');
    }
    if ($tipo === 'RETORNOU_MANUTENCAO' && $atual['STATUS_ARMA'] !== 'MANUTENCAO') {
        throw new RuntimeException('Só é possível concluir manutenção para uma arma em manutenção.');
    }

    $stmt = $pdo->prepare('INSERT INTO HISTORICO_CATALOGO (ID_ARMAMENTO,ID_USUARIO,TIPO_EVENTO,DESCRICAO)
        VALUES (:item,:autor,:tipo,:descricao)');
    $stmt->execute([':item'=>$id,':autor'=>(int)$_SESSION['id_usuario'],':tipo'=>$tipo,':descricao'=>$descricao]);

    if ($novoEstadoDocumental[$tipo] !== null) {
        $statusArma = $atual['STATUS_ARMA'];
        if ($tipo === 'ENVIADO_MANUTENCAO') $statusArma = 'MANUTENCAO';
        if ($tipo === 'RETORNOU_MANUTENCAO') $statusArma = 'DISPONIVEL';
        $stmt = $pdo->prepare('UPDATE ARMAMENTO SET ESTADO_DOCUMENTAL=:estado, STATUS_ARMA=:status, DISPONIBILIDADE=:disp WHERE ID_ARMAMENTO=:id');
        $stmt->execute([
            ':estado'=>$novoEstadoDocumental[$tipo],
            ':status'=>$statusArma,
            ':disp'=>$statusArma === 'DISPONIVEL' ? 1 : 0,
            ':id'=>$id
        ]);
    }
    registrar_log($pdo, (int)$_SESSION['id_usuario'], 'ARMA_EVENTO', "Evento {$tipo} na arma #{$id}.");
    $pdo->commit();
    mensagem_flash('sucesso','Evento registrado.');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mensagem_flash('erro',$ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível registrar o evento.');
}
header('Location: catalogo-detalhe.php?id='.$id, true, 303); exit;
