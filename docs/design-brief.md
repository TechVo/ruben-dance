# Zadání pro Claude Design — Ruben Dance (doplnění k homepage)

Kontext: web taneční školy (salsa, bachata…) s rezervačním systémem postaveným ve
WordPressu. Homepage a základní prvky už existují — drž jejich styl (barvy,
typografii, tón). Web je **dvojjazyčný CS/EN** (počítej s delšími českými texty),
**mobile-first** a musí splňovat základní přístupnost (viditelný focus, kontrast,
chybové stavy čitelné i bez barvy). Administrace se NEnavrhuje — běží v nativním
WordPress adminu.

---

## Obrazovky k dogenerování

### 1. Katalog kurzů (`/kurzy/`)
- Lišta filtrů: taneční styl, úroveň, lokalita, den v týdnu (chipy/selecty, mobilně sbalené)
- Karta kurzu: foto, název, styl + úroveň (štítky), krátký popis
- Uvnitř karty řádky termínů: den + čas, období (např. „září–prosinec"), lokalita,
  cena, tlačítko „Přihlásit se"
- Badge stavy termínu: **Obsazeno** (stále klikatelné!), **Early-bird do {datum}**
  (zvýhodněná cena vedle přeškrtnuté základní), **Workshop** (jednorázová akce —
  vizuálně odlišit od kurzu)
- Prázdný stav („Žádné kurzy neodpovídají filtrům")

### 2. Detail kurzu
- Hero: název, styl/úroveň, fotogalerie, popis (delší text)
- Tabulka/seznam vypsaných termínů se všemi údaji + CTA na přihlášku
- Blok lokality (adresa, odkaz na mapu)
- Stav „žádné vypsané termíny" s odkazem na kontakt

### 3. Kalendář (`/kalendar/`)
- Měsíční + týdenní pohled (FullCalendar); **na mobilu výchozí týdenní**
- Filtry styl + lokalita
- Event chip: čas + název kurzu + lokalita; zrušená lekce **přeškrtnutá**
- Odkaz „Přeskočit na seznam" + **seznamová verze** (tabulka nadcházejících lekcí
  na 60 dní) — fallback bez JS a pro přístupnost
- Kliknutí na event → detail kurzu

### 4. Přihláška na termín (`/prihlaska/`)
Nejdůležitější formulář webu. Obsahuje:
- Souhrn termínu nahoře (kurz, den/čas, období, lokalita, cena)
- Varianta pro nepřihlášeného: vložený blok přihlášení/registrace, po něm návrat do formuláře
- Pole: **Kdo se hlásí** (přepínač „já" / „někdo jiný" + jméno — rodič hlásí dítě),
  taneční role (sólo / leader / follower — jen u párových kurzů), jméno partnera
  (nepovinné), poznámka
- **Živý výpočet ceny**: základní cena, − early-bird sleva, − sleva za partnera,
  výsledná cena (rozpis viditelný)
- Povinný checkbox obchodní podmínky + text o zpracování údajů (odkaz), samostatný
  nepovinný checkbox marketing
- Tlačítko s právně povinným zněním: **„Závazně přihlásit s povinností platby"**
  (EN: "Enroll with obligation to pay") — nesmí být jen „Odeslat"
- Varianta **obsazeného termínu**: informační pruh „Termín je plný — přihlásit se
  můžete, ozveme se vám" nad formulářem
- Chybové stavy: souhrn chyb nahoře (alert) + chyby u polí

### 5. Potvrzení přihlášky
- Shrnutí objednávky (kurz, termín, účastník, cena s rozpisem slev)
- **Blok platebních instrukcí**: částka, číslo účtu, variabilní symbol, splatnost,
  **QR platba** (obrázek QR kódu s popiskem) — tenhle blok je sdílená komponenta,
  objevuje se i v Můj účet a v e-mailech
- Info „potvrzení jsme poslali na e-mail"

### 6. Registrace (`/registrace/`)
- Pole: jméno, příjmení, e-mail, telefon, heslo (s indikátorem síly)
- Checkboxy: povinné podmínky + notice o údajích, nepovinný marketing
- Stav po odeslání: „Zkontrolujte e-mail a klikněte na ověřovací odkaz"
- Stavy po prokliku ověření: úspěch / neplatný nebo expirovaný odkaz

### 7. Přihlášení + zapomenuté heslo
- Login: e-mail, heslo, odkazy na registraci a reset; jednotná chybová hláška
  („Nesprávný e-mail nebo heslo") + stav „účet ještě není ověřený"
- Zapomenuté heslo: formulář e-mailu → potvrzení „odesláno" → formulář nového hesla

### 8. Můj účet (`/muj-ucet/`) — 3 záložky
- **Moje přihlášky**: karta přihlášky = kurz, termín, účastník (i dítě), cena,
  status badge (**Čeká na platbu / Zaplaceno / Zrušeno**); u nezaplacené rozbalený
  blok platebních instrukcí s QR + poznámka „Zrušení? Kontaktujte nás"
- **Můj rozvrh**: chronologický seznam nadcházejících lekcí všech aktivních kurzů;
  zrušená/přesunutá lekce zvýrazněná s poznámkou
- **Profil**: jméno, telefon, e-mail (se stavem „čeká na potvrzení nové adresy"),
  změna hesla, preferovaný jazyk, přepínač marketingového souhlasu
- Prázdné stavy všech tří záložek (nový zákazník bez přihlášek)

### 9. Dárkové poukazy (`/darkove-poukazy/`)
- Obsahová stránka (popis nabídky) + krátký poptávkový formulář (jméno, e-mail,
  zpráva) + stav úspěšného odeslání

### 10. Právní stránky
- Šablona pro dlouhý strukturovaný text (obchodní podmínky, zásady ochrany údajů) —
  nadpisy, seznamy, dobrá čitelnost

### 11. (Volitelně) HTML e-maily
Jednoduchá e-mailová šablona (logo, obsah, patička) pro: ověření účtu, potvrzení
přihlášky s platebními instrukcemi + QR, potvrzení platby, zrušení lekce,
připomínka platby. Stačí jedna univerzální šablona + ukázka s platebním blokem.

---

## Sdílené komponenty (design systém doplnit o)

- **Status badge** sada: Čeká na platbu (žlutá/oranžová), Zaplaceno (zelená),
  Zrušeno (šedá/červená), Obsazeno, Early-bird, Workshop — čitelné i černobíle
  (ikona/tvar, ne jen barva)
- **Platební blok** (částka + účet + VS + splatnost + QR) — karta/panel
- **Cenový rozpis** (základ, slevy se znaménkem −, výsledek zvýrazněný;
  přeškrtnutá původní cena u early-bird)
- Formulářové prvky: input/select/textarea s chybovým stavem (červený text POD
  polem, ne jen rámeček), checkbox s delším textem a odkazem, souhrn chyb (alert)
- Notice/alert pruhy: úspěch / varování / chyba / info
- Přepínač jazyka CS/EN (v hlavičce, i mobilně)
- Event chip kalendáře (normální / zrušený)
- Karta kurzu, řádek termínu
- Patička s povinnými odkazy: Obchodní podmínky, Zásady ochrany osobních údajů,
  kontakt
- Prázdné stavy (ilustrace/text) a loading stav

## Na co nezapomenout

- Všechny texty existují v CS i EN — návrhy dělej v češtině, ale ověř, že se delší
  texty vejdou
- Mobil: katalog (karty pod sebou), kalendář (týden), formulář přihlášky (jeden
  sloupec), Můj účet (záložky jako accordion/tabs)
- Přístupnost: focus ring, kontrast 4.5:1, chybové hlášky asociované s poli,
  tlačítka min. 44px dotyková plocha
