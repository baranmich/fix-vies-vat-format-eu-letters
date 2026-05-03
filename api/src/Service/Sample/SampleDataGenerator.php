<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Generuje testovací sample data — 5 klientů, 8 zakázek, 20 faktur, 4 dobropisy.
 * Sdílená logika pro `bin/sample.php` (CLI) i `SetupSampleAction` (HTTP wizard).
 *
 * Vrací: ['clients' => 5, 'projects' => 8, 'invoices' => 20, 'credit_notes' => 4]
 */
final class SampleDataGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly StatsRecomputer $stats,
    ) {}

    /**
     * @return array{clients:int, projects:int, invoices:int, credit_notes:int}
     */
    public function generate(int $supplierId, int $adminUserId): array
    {
        $pdo = $this->db->pdo();

        $resolveCurrency = function (string $code) use ($pdo, $supplierId): int {
            $stmt = $pdo->prepare(
                'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stmt->execute([$supplierId, strtoupper($code)]);
            $id = (int) $stmt->fetchColumn();
            if ($id === 0) {
                throw new \RuntimeException("Currency $code nenalezena pro supplier #$supplierId");
            }
            return $id;
        };
        $czkId = $resolveCurrency('CZK');
        $eurId = $resolveCurrency('EUR');

        $clients = [
            ['ACME Czech s.r.o.',     '12345678', 'CZ12345678', 'Václavské náměstí 1',  '11000', 'Praha 1',  'CZ', 'invoice@acme.cz',     1, 'cs', $czkId, 'CZK'],
            ['BlueWave Digital a.s.', '87654321', 'CZ87654321', 'Husova 23',            '60200', 'Brno',     'CZ', 'finance@bluewave.cz', 1, 'cs', $czkId, 'CZK'],
            ['Bratislava Soft s.r.o.','46782931', 'SK2023456789','Mlynská 5',            '81101', 'Bratislava','SK', 'fakturace@bsoft.sk',  0, 'cs', $eurId, 'EUR'],
            ['Studio Fialka',         null,       null,         'Nádražní 7',           '70030', 'Ostrava',  'CZ', 'jana@fialka.cz',      0, 'cs', $czkId, 'CZK'],
            ['NorthLight GmbH',       null,       'DE123456789','Hauptstrasse 12',      '10115', 'Berlin',   'DE', 'billing@northlight.de', 1, 'en', $eurId, 'EUR'],
        ];

        $clientIds = [];
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        foreach ($clients as [$company, $ic, $dic, $street, $zip, $city, $iso2, $email, $rc, $lang, $currencyId, $currencyCode]) {
            $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
            $stmtCountry->execute([$iso2]);
            $countryId = (int) $stmtCountry->fetchColumn() ?: $czId;
            $stmt = $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                                      language, currency_default_id, reverse_charge)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$supplierId, $company, $ic, $dic, $street, $city, $zip, $countryId, $email, $lang, $currencyId, $rc]);
            $clientIds[] = (int) $pdo->lastInsertId();
        }

        $projects = [
            [0, 'Údržba webu 2026',         '15000', '0102/2026', 14, 1500, $czkId, 'CZK'],
            [0, 'Refactor backendu Q2',     '15001', '0103/2026', 14, 1800, $czkId, 'CZK'],
            [1, 'Mobile app iOS',           '4523',  '2026/M-12', 30, 1500, $czkId, 'CZK'],
            [1, 'SEO konzultace',           null,    null,        14, 1500, $czkId, 'CZK'],
            [2, 'Cloud migration',          'BSF-7', '0204/2026',  7, 60,   $eurId, 'EUR'],
            [3, 'Tisk + grafika',           null,    null,        14, 1200, $czkId, 'CZK'],
            [4, 'API integration consulting', 'NL-PROJ-A', null,  21, 90,   $eurId, 'EUR'],
            [4, 'Annual support contract',    null,  'NL-2026',   30, 80,   $eurId, 'EUR'],
        ];
        $projectIds = [];
        foreach ($projects as [$ci, $name, $projNum, $contractNum, $due, $rate, $currencyId, $currencyCode]) {
            $stmt = $pdo->prepare(
                'INSERT INTO projects (client_id, name, payment_due_days, project_number, contract_number,
                                       hourly_rate, currency_id, status)
                 VALUES (?,?,?,?,?,?,?,"active")'
            );
            $stmt->execute([$clientIds[$ci], $name, $due, $projNum, $contractNum, $rate, $currencyId]);
            $projectIds[] = (int) $pdo->lastInsertId();
        }

        $today  = new \DateTimeImmutable('today');
        $thisMonth = $today->format('Y-m');
        $prevMonth = $today->modify('-1 month')->format('Y-m');

        $stdVat = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn();
        $rcVat  = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-RC' LIMIT 1")->fetchColumn();

        $invoices = [];
        for ($i = 0; $i < 20; $i++) {
            $month = $i < 10 ? $prevMonth : $thisMonth;
            $clientIdx = $i % 5;
            $clientCurrencyId = $clients[$clientIdx][10];
            $clientCurrency   = $clients[$clientIdx][11];
            $clientReverseCharge = $clients[$clientIdx][8];
            $compatibleProjects = array_filter($projects, fn ($p, $k) => $p[0] === $clientIdx, ARRAY_FILTER_USE_BOTH);
            $compatibleProjectKeys = array_keys($compatibleProjects);
            $projKey = $compatibleProjectKeys[$i % count($compatibleProjectKeys)] ?? null;
            $projectId = $projKey !== null ? $projectIds[$projKey] : null;

            $day = ($i * 3) % 28 + 1;
            $issueDate = "$month-" . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            if ($issueDate > $today->format('Y-m-d')) $issueDate = $today->format('Y-m-d');
            $taxDate = $issueDate;
            $dueDate = (new \DateTimeImmutable($issueDate))->modify('+14 days')->format('Y-m-d');

            $period = str_replace('-', '', $month);
            $vs = $this->nextVarsymbol($pdo, $supplierId, 'invoice', $period);

            $status = match (true) {
                $i < 6  => 'paid',
                $i < 14 => 'sent',
                default => 'issued',
            };

            $vatRate = $clientReverseCharge ? $rcVat : $stdVat;
            $vatPct  = $clientReverseCharge ? 0 : 21;

            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                    (supplier_id, varsymbol, invoice_type, client_id, project_id, issue_date, tax_date, due_date,
                     currency_id, reverse_charge, language, total_without_vat, total_vat, total_with_vat,
                     status, sent_at, paid_at, created_by)
                 VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)'
            );
            $sentAt = in_array($status, ['sent', 'paid'], true) ? $issueDate . ' 14:00:00' : null;
            $paidAt = $status === 'paid'
                ? (new \DateTimeImmutable($issueDate))->modify('+' . random_int(3, 12) . ' days')->format('Y-m-d')
                : null;
            $stmt->execute([
                $supplierId, $vs, $clientIds[$clientIdx], $projectId, $issueDate, $taxDate, $dueDate,
                $clientCurrencyId, $clientReverseCharge ? 1 : 0,
                $clients[$clientIdx][9], $status, $sentAt, $paidAt, $adminUserId,
            ]);
            $invId = (int) $pdo->lastInsertId();
            $invoices[] = ['id' => $invId, 'vs' => $vs, 'currency' => $clientCurrency, 'currency_id' => $clientCurrencyId, 'rc' => $clientReverseCharge];

            $itemCount = random_int(1, 3);
            $totalBase = 0; $totalVat = 0;
            for ($k = 0; $k < $itemCount; $k++) {
                $hours = random_int(2, 40);
                $rate = $clientCurrency === 'EUR' ? random_int(60, 100) : random_int(1200, 2000);
                $base = $hours * $rate;
                $vatAmt = $clientReverseCharge ? 0 : round($base * 0.21, 2);
                $totalBase += $base;
                $totalVat  += $vatAmt;

                $itemMonth = (new \DateTimeImmutable($issueDate))->format('n/Y');
                $description = match ($k) {
                    0 => "Konzultace $itemMonth",
                    1 => "Vývoj — sprint $itemMonth",
                    default => "Údržba $itemMonth",
                };
                $pdo->prepare(
                    'INSERT INTO invoice_items
                        (invoice_id, description, quantity, unit, unit_price_without_vat,
                         vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                     VALUES (?,?,?,"h",?,?,?,?,?,?,?)'
                )->execute([
                    $invId, $description, $hours, $rate, $vatRate, $vatPct, $base, $vatAmt, $base + $vatAmt, $k,
                ]);
            }
            $totalWithVat = $totalBase + $totalVat;
            $pdo->prepare(
                'UPDATE invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
            )->execute([$totalBase, $totalVat, $totalWithVat, $invId]);
        }

        // Dobropisy (4 ks k prvním 4 fakturám)
        $creditTargets = array_slice($invoices, 0, 4);
        foreach ($creditTargets as $parent) {
            $month = $thisMonth;
            $period = str_replace('-', '', $month);
            $vs = $this->nextVarsymbol($pdo, $supplierId, 'credit_note', $period);
            $issueDate = $today->modify('-' . random_int(0, 5) . ' days')->format('Y-m-d');

            $parentInv = $pdo->prepare(
                'SELECT i.*, cur.code AS currency
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.id = ?'
            );
            $parentInv->execute([$parent['id']]);
            $p = $parentInv->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                    (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, project_id,
                     issue_date, tax_date, due_date, currency_id, reverse_charge, language,
                     total_without_vat, total_vat, total_with_vat, status, sent_at, created_by)
                 VALUES (?, ?, "credit_note", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "sent", ?, ?)'
            );
            $stmt->execute([
                $supplierId, $vs, $p['id'], $p['client_id'], $p['project_id'],
                $issueDate, $issueDate, $issueDate,
                (int) $p['currency_id'], $p['reverse_charge'], $p['language'],
                -$p['total_without_vat'], -$p['total_vat'], -$p['total_with_vat'],
                $issueDate . ' 12:00:00', $adminUserId,
            ]);
            $cnId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat,
                     vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?,?,-1,"ks",?,?,?,?,?,?,0)'
            )->execute([
                $cnId,
                "Dobropis k faktuře {$p['varsymbol']}",
                $p['total_without_vat'],
                $p['reverse_charge'] ? $rcVat : $stdVat,
                $p['reverse_charge'] ? 0 : 21,
                -$p['total_without_vat'], -$p['total_vat'], -$p['total_with_vat'],
            ]);
        }

        // Sample data nejdou přes InvoiceActions, takže project/client revenue cache by zůstaly prázdné
        // → dashboard a top-clients koláč by hlásily nulu. Recompute všech vygenerovaných entit.
        foreach ($projectIds as $pid) $this->stats->recomputeProject((int) $pid);
        foreach ($clientIds  as $cid) $this->stats->recomputeClient((int) $cid);

        return [
            'clients'      => count($clientIds),
            'projects'     => count($projectIds),
            'invoices'     => 20,
            'credit_notes' => 4,
        ];
    }

    private function nextVarsymbol(PDO $pdo, int $supplierId, string $type, string $period): string
    {
        $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, invoice_type, period, last_number) VALUES (?,?,?,1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        )->execute([$supplierId, $type, $period]);
        $stmt = $pdo->prepare('SELECT last_number FROM invoice_counters WHERE supplier_id=? AND invoice_type=? AND period=?');
        $stmt->execute([$supplierId, $type, $period]);
        $num = (int) $stmt->fetchColumn();
        $yy = substr($period, 2, 2);
        $mm = substr($period, 4, 2);
        $prefix = $type === 'proforma' ? '9' : ($type === 'credit_note' ? '7' : '');
        return $prefix . $yy . $mm . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
    }
}
