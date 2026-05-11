<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class WorkReportRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findByInvoice(int $invoiceId): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM work_reports WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        $wr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($wr === false) return null;

        $wr['total_hours']  = (float) $wr['total_hours'];
        $wr['total_amount'] = (float) $wr['total_amount'];
        $wr['items'] = $this->itemsFor((int) $wr['id']);
        return $wr;
    }

    /** @return list<array<string,mixed>> */
    private function itemsFor(int $workReportId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, description, work_date, hours, rate, total_amount, order_index
               FROM work_report_items
              WHERE work_report_id = ?
           ORDER BY order_index, id'
        );
        $stmt->execute([$workReportId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['hours']        = (float) $r['hours'];
            $r['rate']         = (float) $r['rate'];
            $r['total_amount'] = (float) $r['total_amount'];
            $r['order_index']  = (int) $r['order_index'];
            $r['id']           = (int) $r['id'];
            $r['work_date']    = $r['work_date'] ?: null;
        }
        return $rows;
    }

    /**
     * Uloží work_report (upsert) + nahradí items.
     * Vrací id work_reportu.
     */
    public function save(int $invoiceId, ?int $projectId, string $title, array $items): int
    {
        $pdo = $this->db->pdo();
        $existing = $this->findByInvoice($invoiceId);

        $totalHours  = 0.0;
        $totalAmount = 0.0;
        foreach ($items as $it) {
            $totalHours  += (float) ($it['hours'] ?? 0);
            $totalAmount += (float) ($it['hours'] ?? 0) * (float) ($it['rate'] ?? 0);
        }

        // project_id je nullable — faktura nemusí mít zakázku.
        $projectIdParam = ($projectId !== null && $projectId > 0) ? $projectId : null;

        if ($existing) {
            $id = (int) $existing['id'];
            $pdo->prepare(
                'UPDATE work_reports SET project_id=?, title=?, total_hours=?, total_amount=? WHERE id=?'
            )->execute([$projectIdParam, $title, $totalHours, $totalAmount, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO work_reports (invoice_id, project_id, title, total_hours, total_amount)
                 VALUES (?,?,?,?,?)'
            )->execute([$invoiceId, $projectIdParam, $title, $totalHours, $totalAmount]);
            $id = (int) $pdo->lastInsertId();
        }

        // Nahradit items
        $pdo->prepare('DELETE FROM work_report_items WHERE work_report_id = ?')->execute([$id]);
        $insert = $pdo->prepare(
            'INSERT INTO work_report_items (work_report_id, description, work_date, hours, rate, total_amount, order_index)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($items as $idx => $it) {
            $hours = (float) ($it['hours'] ?? 0);
            $rate  = (float) ($it['rate'] ?? 0);
            $workDate = isset($it['work_date']) ? trim((string) $it['work_date']) : '';
            $insert->execute([
                $id,
                (string) ($it['description'] ?? ''),
                $workDate !== '' ? $workDate : null,
                $hours,
                $rate,
                round($hours * $rate, 2),
                (int) ($it['order_index'] ?? $idx),
            ]);
        }

        return $id;
    }

    public function deleteByInvoice(int $invoiceId): bool
    {
        $pdo = $this->db->pdo();
        return $pdo->prepare('DELETE FROM work_reports WHERE invoice_id = ?')->execute([$invoiceId]);
    }
}
