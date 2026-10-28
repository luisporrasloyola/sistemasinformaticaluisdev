<?php
declare(strict_types=1);

function pmi_project_id(int $clientId, array $input): int
{
    $raw = $input['proyecto_id'] ?? '0';
    if (!is_scalar($raw) || !ctype_digit((string) $raw)) { throw new DomainException('Proyecto inválido.'); }
    $projectId = (int) $raw;
    if ($projectId === 0) { return 0; }
    $stmt = db()->prepare('SELECT 1 FROM client_project_assignments WHERE client_id = ? AND project_id = ?');
    $stmt->execute([$clientId, $projectId]);
    if (!$stmt->fetchColumn()) { throw new DomainException('El proyecto no pertenece a este cliente.'); }
    return $projectId;
}
