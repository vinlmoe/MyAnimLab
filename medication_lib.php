<?php

/**
 * Administrations of medications prescribed (prescriptions): animals still to
 * treat, recording (one trace per animal, the lot debited once) and the
 * cancellation of an administration recorded by mistake, with its reason
 * (kept, the quantity re-credited to the lot, the animal to treat again).
 * Administrations given during an experimental act are cancelled by the
 * correction of the act (experiment_acts_lib.php). Requires animal_lib.php.
 */

/** Return the still-eligible animals of an active prescription, optionally only from one cage. */
function pending_prescription_animals(mysqli $con, int $prescriptionId, string $cageId = ''): array {
    $sql = "SELECT pa.id AS prescription_animal_id, pa.animal_id, pa.planned_administrations, pr.product_id, pr.route, p.default_unit,
                   m.animal_uid, m.mouse_id, m.cage_id
            FROM prescription_animals pa
            JOIN prescriptions pr ON pr.id=pa.prescription_id AND pr.status='active'
            JOIN medication_products p ON p.id=pr.product_id
            JOIN mice m ON m.id=pa.animal_id AND m.status='active'
            WHERE pa.prescription_id=?" . ($cageId !== '' ? ' AND m.cage_id=?' : '') . ' FOR UPDATE';
    $stmt = $con->prepare($sql);
    if ($cageId !== '') $stmt->bind_param('is', $prescriptionId, $cageId); else $stmt->bind_param('i', $prescriptionId);
    $stmt->execute();
    $eligible = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $count = $con->prepare('SELECT COUNT(*) AS given_count FROM medication_administrations WHERE prescription_id=? AND animal_id=? AND cancelled_at IS NULL');
        $count->bind_param('ii', $prescriptionId, $row['animal_id']);
        $count->execute();
        $given = (int) $count->get_result()->fetch_assoc()['given_count'];
        $count->close();
        if ($given < (int) $row['planned_administrations']) {
            $row['given_count'] = $given;
            $eligible[] = $row;
        }
    }
    $stmt->close();
    return $eligible;
}

/** Insert a separate trace for every animal and debit the lot once for the total. Must run in a transaction. */
function record_administration(mysqli $con, int $prescriptionId, array $animals, int $lotId, float $quantityEach, string $administeredAt, string $route, string $notes, int $userId, string $userName, string $reason): int {
    if (!$animals || !$lotId || $quantityEach <= 0 || !$administeredAt) throw new Exception('Aucun animal éligible, lot, quantité ou date manquant.');
    $productId = (int) $animals[0]['product_id'];
    $unit = $animals[0]['default_unit'];
    $lotStmt = $con->prepare('SELECT product_id,remaining_quantity,expiry_date FROM medication_lots WHERE id=? FOR UPDATE');
    $lotStmt->bind_param('i', $lotId); $lotStmt->execute(); $lot = $lotStmt->get_result()->fetch_assoc(); $lotStmt->close();
    $total = $quantityEach * count($animals);
    if (!$lot || (int) $lot['product_id'] !== $productId) throw new Exception('Lot incompatible avec l’ordonnance.');
    if ($lot['expiry_date'] < substr($administeredAt, 0, 10)) throw new Exception('Lot périmé : administration bloquée.');
    if ((float) $lot['remaining_quantity'] < $total) throw new Exception('Stock insuffisant pour les ' . count($animals) . ' animaux concernés.');

    $add = $con->prepare('INSERT INTO medication_administrations (prescription_id,animal_id,medication_lot_id,administered_at,quantity_used,unit,route,notes,administered_by,administered_by_name) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $lastAdministrationId = null;
    foreach ($animals as $animal) {
        $animalId = (int) $animal['animal_id'];
        $add->bind_param('iiisdsssis', $prescriptionId, $animalId, $lotId, $administeredAt, $quantityEach, $unit, $route, $notes, $userId, $userName);
        $add->execute();
        $lastAdministrationId = $con->insert_id;
    }
    $add->close();
    $update = $con->prepare('UPDATE medication_lots SET remaining_quantity=remaining_quantity-?, in_use_since=COALESCE(in_use_since, ?) WHERE id=?');
    $useDate = substr($administeredAt, 0, 10);
    $update->bind_param('dsi', $total, $useDate, $lotId); $update->execute(); $update->close();
    $movement = $con->prepare("INSERT INTO medication_stock_movements (medication_lot_id,movement_type,quantity_delta,reason,administration_id,performed_by) VALUES (?,'administration',?,?,?,?)");
    $delta = -$total;
    $movement->bind_param('idsii', $lotId, $delta, $reason, $lastAdministrationId, $userId); $movement->execute(); $movement->close();
    return count($animals);
}

/**
 * Cancel an administration of a prescription recorded by mistake, once, with
 * its reason: the trace is kept (marked cancelled), the quantity is
 * re-credited to the lot and the administration no longer counts among those
 * planned. Only its author or an administrator may cancel it. Must run in a
 * transaction. Returns the animal id.
 */
function cancel_prescription_administration(mysqli $con, int $administrationId, string $reason, int $userId, string $userName, bool $isAdmin): int
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new Exception('Indiquez le motif de l’annulation.');
    }
    $stmt = $con->prepare('SELECT a.*, l.lot_number, COALESCE(m.animal_uid, m.mouse_id) AS animal_label
                           FROM medication_administrations a JOIN medication_lots l ON l.id = a.medication_lot_id JOIN mice m ON m.id = a.animal_id
                           WHERE a.id = ? FOR UPDATE');
    $stmt->bind_param('i', $administrationId);
    $stmt->execute();
    $a = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$a || $a['prescription_id'] === null) {
        throw new Exception('Administration sur ordonnance introuvable (celle d’un acte expérimental s’annule en corrigeant l’acte).');
    }
    if ($a['cancelled_at'] !== null) {
        throw new Exception('Cette administration est déjà annulée.');
    }
    if (!$isAdmin && (int) $a['administered_by'] !== $userId) {
        throw new Exception('Seul l’auteur de l’administration ou un administrateur peut l’annuler.');
    }
    $cancel = $con->prepare('UPDATE medication_administrations SET cancelled_at = NOW(), cancelled_by_name = ?, cancel_reason = ? WHERE id = ?');
    $cancel->bind_param('ssi', $userName, $reason, $administrationId);
    $cancel->execute();
    $cancel->close();
    $credit = $con->prepare('UPDATE medication_lots SET remaining_quantity = remaining_quantity + ? WHERE id = ?');
    $credit->bind_param('di', $a['quantity_used'], $a['medication_lot_id']);
    $credit->execute();
    $credit->close();
    $why = 'Administration annulée (ordonnance #' . $a['prescription_id'] . ' — ' . $a['animal_label'] . ') : ' . $reason;
    $movement = $con->prepare("INSERT INTO medication_stock_movements (medication_lot_id, movement_type, quantity_delta, reason, administration_id, performed_by) VALUES (?, 'correction', ?, ?, ?, ?)");
    $movement->bind_param('idsii', $a['medication_lot_id'], $a['quantity_used'], $why, $administrationId, $userId);
    $movement->execute();
    $movement->close();
    return (int) $a['animal_id'];
}
