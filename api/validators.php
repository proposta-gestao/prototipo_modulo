<?php
/**
 * validators.php — Funções de Validação de Input
 */

declare(strict_types=1);

/**
 * Valida os parâmetros de pesquisa recebidos do front-end.
 * Retorna array de erros (vazio = válido).
 */
function validateSearchParams(array $input): array {
    $errors = [];
    $allowed_dias = [15, 30, 45, 60, 180, 360];

    if ($input['ultimo_acesso'] === 0 || !in_array($input['ultimo_acesso'], $allowed_dias, true)) {
        $errors[] = 'Prazo de último acesso inválido. Valores aceitos: ' . implode(', ', $allowed_dias);
    }

    if (empty($input['data_inicial'])) {
        $errors[] = 'Data inicial é obrigatória.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['data_inicial'])) {
        $errors[] = 'Formato de data inicial inválido. Use YYYY-MM-DD.';
    }

    if (empty($input['data_final'])) {
        $errors[] = 'Data final é obrigatória.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['data_final'])) {
        $errors[] = 'Formato de data final inválido. Use YYYY-MM-DD.';
    }

    if (empty($errors)) {
        $ini = new DateTime($input['data_inicial']);
        $fim = new DateTime($input['data_final']);

        if ($fim < $ini) {
            $errors[] = 'A data final não pode ser anterior à data inicial.';
        } else {
            $diff = $ini->diff($fim);
            $months = ($diff->y * 12) + $diff->m + ($diff->d > 0 ? 1 : 0);
            if ($months > MAX_MONTHS) {
                $errors[] = "O intervalo de datas não pode ultrapassar " . MAX_MONTHS . " meses.";
            }
        }
    }

    if (!empty($input['tipo_documento'])) {
        if (strlen($input['tipo_documento']) > 100) {
            $errors[] = 'Tipo de documento: máximo 100 caracteres.';
        }
        if (!preg_match('/^[\p{L}\p{N}\s\-\.]+$/u', $input['tipo_documento'])) {
            $errors[] = 'Tipo de documento contém caracteres inválidos.';
        }
    }

    return $errors;
}
