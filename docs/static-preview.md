# Statický náhled webu (GitHub Pages)

Veřejný náhled: **https://techvo.github.io/ruben-dance/**

Jde o statickou kopii lokálního wp-env webu, ne o běžící WordPress. Slouží
k tomu, aby si majitelé mohli prohlédnout design a obsah bez zřizování
hostingu. Živý web podle [`launch-checklist.md`](launch-checklist.md) poběží
až na PHP hostingu (wordpress.com Business).

## Jak náhled vygenerovat a nasadit

```bash
npx wp-env start                       # web na http://localhost:8888
npx wp-env run cli wp rd seed          # fixture data (idempotentní)
scripts/export-static-preview.sh       # zápis do build/static-preview/
```

Nasazení do větve `gh-pages`:

```bash
scripts/publish-static-preview.sh
```

Skript `export-static-preview.sh` zrcadlí web pomocí `wget` a pak ho přes
`scripts/static_preview_rewrite.py` upraví pro statický hosting. Náhled
předpokládá, že se servíruje pod cestou `/ruben-dance` — jiný základ se předá
druhým argumentem (`scripts/export-static-preview.sh out/ /jina-cesta`).

## Co v náhledu funguje

- Všechny veřejné obrazovky v CS i EN: homepage, katalog s filtry, detail
  kurzu, kalendář, přihláška, registrace/přihlášení, můj účet, dárkové
  poukazy, právní stránky.
- Kompletní design — vlastní fonty, obrázky, layouty včetně mobilních.
- Kalendář: REST feed `/rd/v1/lessons` je uložený do
  `rd-data/lessons-{cs,en}.json` a FullCalendar si z něj bere data. Statický
  hosting query string ignoruje, takže se vrátí celý snapshot a FullCalendar
  si ho odfiltruje podle zobrazeného rozsahu sám.

## Co v náhledu nefunguje

Cokoliv, co potřebuje PHP:

- **Odesílání formulářů** — přihláška, registrace, přihlášení, obnova hesla.
  Formuláře se vykreslí, ale odeslání nikam nevede.
- **Přihlášený stav.** Crawler je anonymní, takže stránky Přihláška a Můj
  účet ukazují variantu pro nepřihlášeného návštěvníka („Nejdřív se
  přihlaste"). Vlastní formulář přihlášky ani obrazovky účtu v náhledu
  nejsou.
- **Filtry kalendáře** (styl, místo) — filtrují se na serveru, statický
  snapshot na ně nereaguje. Filtry v katalogu kurzů fungují, ty jsou v JS.
- **QR platby, e-maily, administrace.**

Fixture termíny jsou z let 2025/26, takže kalendář se schválně otevírá na
září 2025 (`CALENDAR_INITIAL_DATE` v export skriptu) — na aktuálním měsíci by
byl prázdný.

Náhled má `robots.txt` s `Disallow: /` a v hlavičce každé stránky proužek,
který říká, že jde o náhled — aby ho zákazníci nespletli s ostrým webem.
