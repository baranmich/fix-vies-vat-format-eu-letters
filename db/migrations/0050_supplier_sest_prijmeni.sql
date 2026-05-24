-- MyInvoice.cz — samostatné příjmení sestavitele přiznání (sjednocení s jednatelem).
--
-- Sestavitel měl dosud jen jedno pole `sest_jmeno`; do EPO VetaP se příjmení
-- odvozovalo splitem podle první mezery (křehké u víceslovných příjmení). Jednatel
-- (opr_jmeno/opr_prijmeni) má pole zvlášť — sjednocujeme. Split zůstává jako fallback,
-- když `sest_prijmeni` není vyplněno (BC pro stará data).
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS.

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS sest_prijmeni VARCHAR(100) NULL AFTER sest_jmeno;
