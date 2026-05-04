-- MyInvoice.cz — Stored procedures pro provoz a údržbu.
--
-- Použití (z mysql CLI nebo phpMyAdmin):
--   CALL sp_recompute_client_revenue(11);
--   CALL sp_recompute_project_revenue(5);
--   CALL sp_recompute_all_caches();
--   CALL sp_find_invoices_with_bad_dates();
--   CALL sp_cleanup_expired_sessions();
--   CALL sp_cleanup_old_login_attempts();
--
-- Procedury jsou idempotentní (DROP IF EXISTS + CREATE), opětovná aplikace
-- migrace by samozřejmě skončila s "already applied" — recreate dělej v nové migraci.

DROP PROCEDURE IF EXISTS sp_recompute_client_revenue;
DROP PROCEDURE IF EXISTS sp_recompute_project_revenue;
DROP PROCEDURE IF EXISTS sp_recompute_all_caches;
DROP PROCEDURE IF EXISTS sp_find_invoices_with_bad_dates;
DROP PROCEDURE IF EXISTS sp_cleanup_expired_sessions;
DROP PROCEDURE IF EXISTS sp_cleanup_old_login_attempts;

DELIMITER //

-- Přepočte client_revenue_cache pro jednoho klienta.
-- Mirror StatsRecomputer::recomputeClient — zachovat synchronizaci při úpravách logiky.
-- revenue / invoice_count agregují jen 'invoice' + 'credit_note' (proforma není daňový doklad);
-- last_invoice_date je MAX přes všechny aktivity (vč. proform), aby reflektoval poslední dění.
CREATE PROCEDURE sp_recompute_client_revenue(IN p_client_id INT)
BEGIN
    DELETE FROM client_revenue_cache WHERE client_id = p_client_id;
    INSERT INTO client_revenue_cache (client_id, currency_id, revenue, last_invoice_date, invoice_count)
    SELECT i.client_id,
           i.currency_id,
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note') THEN i.total_with_vat ELSE 0 END),
           MAX(COALESCE(i.tax_date, i.issue_date)),
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note') THEN 1 ELSE 0 END)
      FROM invoices i
     WHERE i.client_id = p_client_id
       AND i.status IN ('issued','sent','reminded','paid')
       AND i.invoice_type != 'cancellation'
  GROUP BY i.client_id, i.currency_id;
END //

-- Přepočte project_revenue_cache pro jeden projekt.
CREATE PROCEDURE sp_recompute_project_revenue(IN p_project_id INT)
BEGIN
    DELETE FROM project_revenue_cache WHERE project_id = p_project_id;
    INSERT INTO project_revenue_cache (project_id, currency_id, revenue, last_invoice_date, invoice_count)
    SELECT i.project_id,
           i.currency_id,
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note') THEN i.total_with_vat ELSE 0 END),
           MAX(COALESCE(i.tax_date, i.issue_date)),
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note') THEN 1 ELSE 0 END)
      FROM invoices i
     WHERE i.project_id = p_project_id
       AND i.status IN ('issued','sent','reminded','paid')
       AND i.invoice_type != 'cancellation'
  GROUP BY i.project_id, i.currency_id;
END //

-- Full rebuild — vhodné po hromadných opravách dat (jako fix typů 3025→2025 apod.).
-- Ekvivalent `php api/bin/recompute-stats.php`, ale spustitelné přímo z DB.
CREATE PROCEDURE sp_recompute_all_caches()
BEGIN
    DECLARE v_done TINYINT DEFAULT 0;
    DECLARE v_id INT;
    DECLARE cur_clients CURSOR FOR SELECT id FROM clients;
    DECLARE cur_projects CURSOR FOR SELECT id FROM projects;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    DELETE FROM client_revenue_cache;
    DELETE FROM project_revenue_cache;

    OPEN cur_clients;
    cli_loop: LOOP
        FETCH cur_clients INTO v_id;
        IF v_done THEN LEAVE cli_loop; END IF;
        CALL sp_recompute_client_revenue(v_id);
    END LOOP;
    CLOSE cur_clients;

    SET v_done = 0;
    OPEN cur_projects;
    prj_loop: LOOP
        FETCH cur_projects INTO v_id;
        IF v_done THEN LEAVE prj_loop; END IF;
        CALL sp_recompute_project_revenue(v_id);
    END LOOP;
    CLOSE cur_projects;
END //

-- Diagnostika: faktury s neplausibilními daty (typo "3025" místo "2025" apod.).
-- Vrací řádky, kde je některý z datumů > 2 roky v budoucnu nebo < rok 2000.
CREATE PROCEDURE sp_find_invoices_with_bad_dates()
BEGIN
    SELECT i.id, i.varsymbol, i.invoice_type, i.status,
           i.issue_date, i.tax_date, i.due_date,
           c.company_name AS client
      FROM invoices i
      JOIN clients c ON c.id = i.client_id
     WHERE i.issue_date > DATE_ADD(CURDATE(), INTERVAL 2 YEAR)
        OR i.tax_date   > DATE_ADD(CURDATE(), INTERVAL 2 YEAR)
        OR i.due_date   > DATE_ADD(CURDATE(), INTERVAL 2 YEAR)
        OR i.issue_date < '2000-01-01'
        OR i.tax_date   < '2000-01-01'
        OR i.due_date   < '2000-01-01'
     ORDER BY i.id;
END //

-- Cleanup: smaže expirované sessions (mirror cron-cleanup.php — jen runnable z DB).
CREATE PROCEDURE sp_cleanup_expired_sessions()
BEGIN
    DELETE FROM sessions WHERE expires_at < NOW();
    SELECT ROW_COUNT() AS deleted_sessions;
END //

-- Cleanup: smaže login_attempts starší 24 hodin (matches cron-cleanup.php window).
CREATE PROCEDURE sp_cleanup_old_login_attempts()
BEGIN
    DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 24 HOUR;
    SELECT ROW_COUNT() AS deleted_login_attempts;
END //

DELIMITER ;
