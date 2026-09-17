# Third-Party Notices — o8

Stand: 17. September 2026

## Geltungsbereich

Die nachstehenden Fremdkomponenten unterliegen ihren eigenen Lizenzen. Die [o8 Community License](LICENSE-o8.md) schränkt die dadurch eingeräumten Rechte nicht ein (siehe deren Ziffer 6). Diese Datei erteilt keine zusätzlichen Rechte am eigenen o8-Code. Prüfumfang: die aktuell im o8-Projekt enthaltenen Dateien, nicht sämtliche für spätere Meilensteine geplanten Abhängigkeiten.

Diese Hinweise und die vollständigen Lizenztexte sind zusammen mit den betreffenden Komponenten weiterzugeben. Vorhandene Lizenz- und Copyright-Hinweise in den Originaldateien bleiben erhalten, auch bei Minifizierung oder Bündelung.

## Enthaltene Komponenten

| Komponente | Nachgewiesener Stand | Verwendung | Lizenz |
|---|---|---|---|
| Bootstrap | 5.3.8 laut CSS- und JS-Header | Lokale CSS- und JavaScript-Dateien einschließlich Source Maps | MIT |
| Popper (`@popperjs/core`) | 2.11.8 laut Lockfile des bytegleichen offiziellen Bootstrap-5.3.8-Releases | Positionierung; Quelltexte in der JavaScript-Source-Map enthalten | MIT |
| RFS | In Bootstrap 5.3.8 enthaltener Quellstand; eigene Versionsnummer nicht ausgewiesen | Responsive Größen; SCSS-Quelltext in der CSS-Source-Map enthalten | MIT |
| Normalize.css-Anteile | Von Bootstrap Reboot übernommener und angepasster Quellstand | CSS-Normalisierung; Herkunft im Reboot-Quelltext der CSS-Source-Map angegeben | MIT |

Betroffene Dateien:

- `public/assets/vendor/bootstrap/bootstrap.min.css`
- `public/assets/vendor/bootstrap/bootstrap.min.css.map`
- `public/assets/vendor/bootstrap/bootstrap.bundle.min.js`
- `public/assets/vendor/bootstrap/bootstrap.bundle.min.js.map`

Die Einbindung erfolgt in `app/views/workspace.php`.

### Herkunftsnachweis der Bootstrap-Dateien

Alle vier lokalen Dateien wurden am 17. September 2026 bytegenau gegen
[`twbs/bootstrap`, Tag `v5.3.8`, Verzeichnis `dist/`](https://github.com/twbs/bootstrap/tree/v5.3.8/dist)
verglichen und stimmen überein. Der lokale Übernahmeweg war die Bootstrap-Kopie
im o7-Referenzprojekt; die Zuordnung zum offiziellen Release wurde unabhängig davon geprüft.
Das [Release-Lockfile](https://github.com/twbs/bootstrap/blob/v5.3.8/package-lock.json)
weist für `node_modules/@popperjs/core` Version 2.11.8 aus. Dies ist ein abgeleiteter
Versionsnachweis aus Release und Lockfile, keine eigenständige Versionsangabe im Bundle.

| Datei unter `public/assets/vendor/bootstrap/` | SHA-256 |
|---|---|
| `bootstrap.min.css` | `d85327d99c7a3ee1f9b5d0500d1370acea3ad2db39c163c2f51f232baedbdede` |
| `bootstrap.min.css.map` | `48144faf6aa0fb3cd2ce748d9730238f888f4ab715f05dabd1c9af2c5671988a` |
| `bootstrap.bundle.min.js` | `e4fd49181388c48ec5040bd3fe66f57c29c8e67fcd8502b3354b96ec7ab47cc7` |
| `bootstrap.bundle.min.js.map` | `c61123e58cc0a4b65d737ba070c485911b3dbec6d7b802bdf6628395abd9c08b` |

Die CSS-Source-Map enthält `scss/vendor/_rfs.scss` mit dem MIT-Verweis auf RFS
sowie `scss/_reboot.scss` mit dem Herkunftshinweis auf Normalize.css. Eine genaue
RFS-/Normalize-Version lässt sich daraus nicht feststellen; die unten verlinkten
Lizenzquellen sind nicht als Versionsnachweis für diese eingebetteten Anteile zu verstehen.

## Inline-SVG-Icons und Projektzeichen: geprüfte Herkunft

In `app/views/workspace.php` stehen 21 lokale `<symbol>`-Definitionen. Sie werden
über `<use>` referenziert, nicht durch ein installiertes Icon-Paket oder eine externe
Icon-Schrift bereitgestellt. Der Vergleich erfolgte mit den Original-SVGs von
[Feather Icons 4.29.2](https://github.com/feathericons/feather/tree/v4.29.2/icons).

| Lokales Symbol | Prüfbefund |
|---|---|
| `i-plus` | Gleiche Liniengeometrie wie [Feather `plus.svg`](https://github.com/feathericons/feather/blob/v4.29.2/icons/plus.svg): (12,5)–(12,19), (5,12)–(19,12). Lokal als zusammengefasster SVG-Pfad statt zweier `line`-Elemente. |
| `i-close` | Gleiche Liniengeometrie wie [Feather `x.svg`](https://github.com/feathericons/feather/blob/v4.29.2/icons/x.svg): Diagonalen (6,6)–(18,18) und (6,18)–(18,6). Lokal als Pfad, teils umgekehrte Zeichenrichtung. |
| `i-document`, `i-folder`, `i-search`, `i-inbox`, `i-chart`, `i-settings`, `i-link`, `i-trash`, `i-arrow`, `i-check`, `i-filter`, `i-moon`, `i-menu`, `i-info`, `i-mail`, `i-upload`, `i-cloud`, `i-restore`, `i-eye` | Keine unveränderte Übernahme der jeweils entsprechenden Feather-4.29.2-Symbole nachgewiesen; Pfade, Maße oder Zusammenstellung unterscheiden sich. Stilähnlichkeit belegt weder Urheberschaft noch eine bestimmte Sammlung oder Lizenz. |
| `public/assets/favicon.svg` und CSS-/HTML-Projektzeichen | Lokale Konstruktion aus Grundformen bzw. Text. Im geprüften Bestand kein Quellen-, Autoren- oder Lizenznachweis für eine fremde Vorlage vorhanden. Daraus folgt kein positiver Nachweis eigenständiger Urheberschaft. |

**Grenze des Nachweises:** Im vorliegenden Projekt fehlt eine auswertbare
Entstehungshistorie der Symbole. Auch die Übereinstimmung bei Plus und Schließen
beweist angesichts dieser einfachen Grundformen keinen tatsächlichen Import aus
Feather; ebenso wenig lässt sich daraus eine Herkunft aus Lucide, Tabler oder einer
anderen Sammlung ausschließen. Deshalb werden die übrigen Symbole ausdrücklich
nicht pauschal als Feather, Bootstrap Icons oder gesichert eigene Werke bezeichnet.

Für die geometrischen Übereinstimmungen wird vorsorglich der vollständige
Feather-MIT-Hinweis unten mitgeführt: Copyright (c) 2013–2023 Cole Bemis,
[Original-Lizenz von Feather 4.29.2](https://github.com/feathericons/feather/blob/v4.29.2/LICENSE).
Diese vorsorgliche Nennung ist keine nachträgliche Herkunftsbestätigung und klärt
keine möglichen Rechte anderer Urheber. Vor einer Veröffentlichung ist die offene
SVG-Herkunft durch Entstehungsnachweise zu bestätigen oder durch einen dokumentierten
Austausch gegen eindeutig lizenzierte Symbole zu schließen. Bei dieser Prüfung wurden
keine Icon-Geometrien verändert.

Die als `data:image/svg+xml` eingebetteten Bedienelemente in Bootstrap-CSS gehören
zum oben nachgewiesenen Bootstrap-Release und fallen unter dessen MIT-Lizenz;
sie sind von den 21 o8-Inline-Symbolen zu unterscheiden.

## Abgrenzung und Pflege

- Eine jQuery-Einbindung oder mitgelieferte jQuery-Bibliothek wurde im geprüften Archiv nicht gefunden. Die optionale jQuery-Unterstützung im Bootstrap-Code stellt keine mitgelieferte jQuery-Kopie dar.
- Es werden lokale Systemschriften verwendet; zusätzliche Fontdateien oder externe Font-Einbindungen wurden nicht gefunden.
- Der lokale Symlink `o7/` ist eine Entwicklungsreferenz und keine o8-Laufzeitabhängigkeit. Nicht als Verzeichnisinhalt dereferenzieren oder mit veröffentlichen; ein separat mitgeliefertes o7 benötigt eine eigene vollständige Lizenzinventur.
- Die Python-Browsertests importieren Playwright. Das Playwright-Paket und Browser-Binärdateien sind nicht im Archiv enthalten; eine installierte Version ist nicht festgelegt. Playwright for Python steht unter Apache-2.0, siehe [Projekt](https://github.com/microsoft/playwright-python). Bei einer späteren Mitlieferung sind die Lizenz- und gegebenenfalls NOTICE-Dateien der konkret ausgelieferten Pakete und Browser zu ergänzen.
- PHP, Python und Node.js werden als separat installierte Laufzeitumgebungen verwendet und sind nicht Bestandteil dieses Archivs.
- Bei Änderungen an Abhängigkeiten, Source Maps oder ausgelieferten Paketen ist diese Datei zu aktualisieren.

## Feather Icons: vorsorglich mitgeführter Lizenztext

Zuordnung und Einschränkung des Herkunftsnachweises siehe SVG-Prüfung oben.
Quelle: [Feather 4.29.2 LICENSE](https://github.com/feathericons/feather/blob/v4.29.2/LICENSE)

```text
The MIT License (MIT)

Copyright (c) 2013-2023 Cole Bemis

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## Bootstrap: Lizenztext

Quelle: [Bootstrap LICENSE](https://github.com/twbs/bootstrap/blob/v5.3.8/LICENSE)

```text
The MIT License (MIT)

Copyright (c) 2011-2025 The Bootstrap Authors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

## Popper: Lizenztext

Quelle: [Popper 2.11.8 LICENSE](https://github.com/floating-ui/floating-ui/blob/v2.11.8/LICENSE.md)

```text
The MIT License (MIT)

Copyright (c) 2019 Federico Zivolo

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```

## RFS: Lizenztext

Quelle: [RFS LICENSE](https://github.com/twbs/rfs/blob/main/LICENSE)

```text
MIT License

Copyright (c) 2017-2019 Martijn Cuppens

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## Normalize.css: Lizenztext

Quelle: [Normalize.css LICENSE](https://github.com/necolas/normalize.css/blob/master/LICENSE.md)

```text
# The MIT License (MIT)

Copyright © Nicolas Gallagher and Jonathan Neal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
of the Software, and to permit persons to whom the Software is furnished to do
so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
