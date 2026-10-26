<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

function agora_armaria(): string
{
    return date('Y-m-d H:i:s');
}

function data_form_para_banco_armaria(string $valor): ?string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $valor, new DateTimeZone('America/Sao_Paulo'));
    if ($dt === false || $dt->format('Y-m-d\TH:i') !== $valor) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function data_banco_para_form_armaria(?string $valor): string
{
    if (!$valor) {
        return '';
    }
    return date('Y-m-d\TH:i', strtotime($valor));
}

function data_legivel_armaria(?string $valor): string
{
    return $valor ? date('d/m/Y H:i', strtotime($valor)) : '—';
}

function status_arma_label(string $status): string
{
    return [
        'DISPONIVEL' => 'Disponível',
        'EMPRESTADA' => 'Emprestada',
        'MANUTENCAO' => 'Manutenção',
        'INATIVA' => 'Inativa',
    ][$status] ?? $status;
}

function status_arma_classe(string $status): string
{
    return match ($status) {
        'DISPONIVEL' => 'estado-ativo',
        'EMPRESTADA' => 'estado-alerta',
        'MANUTENCAO' => 'estado-atraso',
        default => 'estado-inativo',
    };
}
