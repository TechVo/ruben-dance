# Design — Ruben Dance (z Claude Design)

Zdroj: export z claude.ai/design (2026-07-11).

| Soubor | Co to je |
|---|---|
| `ruben-dance-design.dc.html` | Originální bundlovaný export (fonty embedded, rozbaluje se JS). Otevřít v prohlížeči = plný náhled. **Needitovat.** |
| `screens.html` | **Rozbalený markup všech obrazovek** — čitelné HTML s inline styly, fonty přes Google Fonts. Toto je referenční zdroj pro implementaci. |

## Mapa obrazovek (kotvy v `screens.html`)

| Sekce | ID | Obrazovka |
|---|---|---|
| A | `#2a` | Design system — paleta, typografie, tlačítka, menu, formuláře |
| A | `#3a` | Sdílené komponenty — badges, platební blok, cenový rozpis, alerty, chybové stavy, patička |
| B | `#1a` / `#2c` / `#2b` | Homepage — mobil 390 / tablet 834 / desktop 1280 |
| C | `#3b` / `#4a` / `#4b` | Katalog kurzů — mobil / tablet / desktop (filtry v levém panelu) |
| C | `#3c` / `#4c` | Detail kurzu — mobil (vč. stavu bez termínů) / desktop |
| D | `#3d` / `#4d` | Kalendář — mobil (týden + seznamový fallback) / desktop |
| E | `#3e` / `#4e` | Přihláška — mobil (obsazený termín, login blok, chyby) / desktop |
| E | `#3f` / `#4f` | Potvrzení přihlášky s platebním blokem a QR — mobil / desktop |
| F | `#3g` / `#4g` | Registrace, přihlášení, zapomenuté heslo — mobil / desktop |
| F | `#3h` / `#4i` / `#4h` | Můj účet (3 záložky, prázdné stavy) — mobil / tablet / desktop |
| G | `#3i` / `#4j` | Dárkové poukazy + šablona právní stránky |
| G | `#3j` | Univerzální HTML e-mail s platebním blokem |
| H | `#1b`, `#1c` | Archiv — nepoužité směry homepage (**neimplementovat**) |

## Design tokeny

### Barvy

```css
--rd-coral:        #E8604C;  /* primární akce; hover #D14E3B */
--rd-orange:       #F08A24;  /* akcent */
--rd-yellow:       #F5B840;  /* dekor, early-bird, zvýraznění na tmavé */
--rd-cocoa:        #2B1710;  /* text, tmavé plochy, sekundární tlačítka */
--rd-cream:        #FDF6EA;  /* pozadí stránky */
--rd-white:        #FFFFFF;  /* karty */
/* stavové */
--rd-success-bg:   #DDF0E2;  --rd-success-fg: #1E6B3C;   /* zaplaceno, úspěch */
--rd-warning-bg:   #FCEBC9;  --rd-warning-fg: #8A5500;   /* čeká na platbu, varování */
--rd-error-bg:     #FCE0DB;  --rd-error-fg:   #B3372A;   /* chyby polí i alerty */
/* kalendářové chipy */
--rd-chip-salsa:   #FCE4DE;  /* + border-left 4px coral */
--rd-chip-alt:     #FCEBC9;  /* + border-left 4px yellow */
/* zrušená lekce: rgba(43,23,16,.06) + border rgba(43,23,16,.3) + line-through */
/* tlumený text: rgba(43,23,16,.55–.75); bordery polí: rgba(43,23,16,.25) */
```

### Typografie — Bricolage Grotesque (Google Fonts, 400–800)

| Role | Hodnoty |
|---|---|
| H1 | 44/800, letter-spacing −0.03em (desktop až 66) |
| H2 | 26/800, −0.02em (desktop 36) |
| H3 | 17/700 |
| Text | 15/400, line-height 1.5, barva rgba(43,23,16,.75) |
| Popisek/eyebrow | 11/800, uppercase, letter-spacing .12–.14em |

(Archivo / Archivo Black / Instrument Serif se objevují jen v archivních variantách H — nepoužívat.)

### Komponenty — klíčová pravidla

- **Tlačítka**: pilulky (`border-radius: 99px`). Primární = coral bg + bílý text 700; sekundární = 2px border cocoa, hover invertuje; malé tmavé pilulky v kartách; disabled = coral 40% opacity; focus = `outline: 3px solid #2B1710; outline-offset: 3px`.
- **Karty**: bílé, radius 18–24 px, bez borderu (jen jemný stín kontejneru).
- **Formuláře**: input radius 12 px, border 1.5px rgba(43,23,16,.25); focus 2px coral; chyba 2px `#B3372A` + červená hláška POD polem s „⚠"; povinná pole hvězdička.
- **Status badges** (ikona + text, čitelné černobíle): ⏱ čeká na platbu (warning), ✓ zaplaceno (success), ✕ zrušeno (šedá + přeškrtnutí), ● obsazeno (cocoa/cream), ⚡ early-bird (yellow), ★ workshop (bílá + 2px dashed coral, radius 8).
- **Platební blok**: bílá karta s 2px `#F5B840` borderem — částka, účet, VS, splatnost + QR oddělený tečkovanou linkou.
- **Cenový rozpis**: přeškrtnutý základ, slevy zeleně se znaménkem −, součet nad 2px cocoa linkou, cena coral.
- **Jazykový přepínač**: CZ/EN pilulka v hlavičce (aktivní = cocoa bg / cream text).
- **Workshop** vždy odlišen dashed coral rámečkem (karta i event chip).
- **Kalendář**: event chip = čas tučně + název + lokalita, `border-left: 4px`; zrušené přeškrtnuté; mobil = týdenní seznam po dnech + tabulkový seznamový fallback.
