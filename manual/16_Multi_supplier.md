# 16. Více dodavatelů z jedné instalace

MyInvoice umožňuje fakturovat za **libovolný počet dodavatelů (firem / IČ)**
z jedné instalace. Typické scénáře:

- **OSVČ + s.r.o.** — Jan Novák, OSVČ + Novák s.r.o. = 2 dodavatelé
- **Holding** — mateřská firma + 3 dceřiné = 4 dodavatelé
- **Účetní kancelář** — fakturuje za sebe + spravuje fakturaci pro 20 klientů
- **Sdílený workspace pro tým** — každý kolega má vlastní firmu, ale všichni
  vidí svého

Data jsou **plně izolovaná** — klienti jednoho dodavatele nejsou viditelní
pro druhého, faktury mají vlastní řadu varsymbolů, číselné cykly,
e-mailové šablony atd.

## 16.1 Jak to vidět v UI

Po přihlášení se v horní liště zobrazí **přepínač dodavatele**:

![Přepínač dodavatele](img/14_supplier_switcher.webp)

- Pokud je dodavatel jediný, ukazuje se text „Pracuješ jako: **Název firmy**".
- Pokud je víc, ukazuje se dropdown s aktuálním + ostatními ke přepnutí.

Při přepnutí:

- Aplikace se reloadne (router-link na `/`)
- Pokud jsi byl na detailu / editoru entity, přesměruje na seznam (entita
  patří jinému dodavateli, neviděl bys ji)

## 16.2 Přidání nového dodavatele

V hlavním menu **Systém → Dodavatelé**.

![Seznam dodavatelů](img/14_dodavatele_list.webp)

Tabulka:

| Sloupec | Význam |
|---|---|
| Název | Název firmy / OSVČ |
| IČ | České IČ |
| DIČ | Daňové ID |
| Měn | Počet aktivních měn (= počet bankovních účtů) |
| Klientů | Počet klientů pod tímto dodavatelem |
| Faktur | Počet vystavených faktur |
| Vytvořen | Datum |

Tlačítko **+ Nový dodavatel** vpravo nahoře.

### 16.2.1 Modal nového dodavatele

![Nový dodavatel — ARES](img/14_dodavatel_novy.webp)

| Pole | Význam |
|---|---|
| IČ | Zadej a klikni **Načíst z ARES** — předvyplní zbytek |
| Firma | Název |
| DIČ | (volitelné, OSVČ neplátce nech prázdné) |
| Adresa | Ulice / Město / PSČ / Stát |
| E-mail / telefon | Kontakt |
| První bankovní účet | CZK účet (číslo + bank kód) — automaticky se založí v měně CZK |

Po **Vytvořit** je dodavatel okamžitě v dropdownu, můžeš na něj přepnout.

## 16.3 Co je per-dodavatel (izolované)

Každý dodavatel má vlastní:

- **Klienty** + jejich zakázky + faktury
- **Měny** + bankovní účty (CZK + EUR + …)
- **Číselnou řadu varsymbolů** (každý dodavatel má samostatné `2605001`,
  `2605002`, …)
- **Výchozí nastavení** — splatnost, hodinová sazba, DPH
- **E-mailové šablony** (faktura nová / upomínka / reset hesla)
- **Pohoda kódy** pro export
- **From: jméno + Reply-To** v odchozích e-mailech
- **Statistiky** (dashboard ukazuje data jen aktuálního dodavatele)

## 16.4 Co je sdílené (cross-supplier)

- **Uživatelé + role** — uživatel vidí všechny dodavatele
- **Číselníky** (DPH sazby, země) — společné systémové
- **Activity log** — všechny mutace logované, ale filtrovatelné per dodavatel
- **IP allowlist + bezpečnostní nastavení** — globální
- **SMTP konfigurace** — globální (`From:` jméno se ale řídí per-dodavatel)
- **Cron skripty** — projedou všechny dodavatele

## 16.5 Editace dodavatele

**Systém → Dodavatelé → klik na řádek → Editovat**.

Záložky:

### 16.5.1 Základní údaje

Stejné jako při založení (IČ, název, adresa, kontakt). Změna se projeví na
NOVÝCH fakturách. Vystavené mají vlastní snapshot.

### 16.5.2 E-mail branding

| Pole | Význam |
|---|---|
| From: jméno | Jak se zobrazí odesílatel u příjemce („Faktury MyWebdesign" místo „myinvoice@server") |
| Reply-To | Adresa pro odpověď klienta („fakturace@mywebdesign.cz") |

### 16.5.3 Pohoda kódy

| Pole | Význam | Příklad |
|---|---|---|
| Číselná řada | `pohoda_account_code` | `FV` |
| Středisko | `pohoda_centre_code` | `01` |
| Činnost | `pohoda_activity_code` | `100` |
| Předkontace | `pohoda_classification_code` | `300` |

Viz [14. Exporty → § 13.4.2](14_Exporty.md).

## 16.6 Smazání dodavatele

Zatím **není v UI** — vyžaduje SQL zásah z důvodu integrity (faktury,
klienti, zakázky). Pokud potřebuješ, kontaktuj IT — `php api/bin/reset.php
--supplier=N` (TODO).

## 16.7 X-Supplier-Id v API

Aktuální dodavatel se posílá v každém API requestu jako header
`X-Supplier-Id: N`. UI ho posílá z localStorage (`myinvoice.current_supplier_id`).

Pokud header chybí, server fallbackuje na `MIN(supplier.id)` — typicky první
dodavatel = ten z setup wizardu.

Pro programátory: viz `source/04-api.md` v repu.

## 16.8 Tipy

- **Při založení dodavatele použij ARES** — ušetří 5 minut opisování.
- **Nevynechej Pohoda kódy** pokud plánuješ používat Pohoda XML export.
- **Per-dodavatel `From:` jméno** je důležitý pro deliverabilitu — klient
  vidí v inboxu „Faktury MyWebdesign" místo „myinvoice@server-3.hosting.cz".
- **Sample data se vygenerují jen pro jednoho dodavatele** — pokud máš víc
  a chceš testovací sadu pro každého, musíš spustit `php api/bin/sample.php`
  vícekrát s parametrem (TODO).
