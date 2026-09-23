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
| Feather Icons | 4.29.2, festgelegter Commit und Einzeldatei-Prüfsummen | 21 lokal eingebettete SVG-Symbole | MIT |
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

## Inline-SVG-Icons und Projektzeichen: nachgewiesene Herkunft

Die bisherigen 21 Symbole ohne Importnachweis wurden am 17. September 2026
vollständig ersetzt. Der aktuell ausgelieferte Satz stammt aus **Feather Icons 4.29.2**,
Copyright (c) 2013–2023 Cole Bemis, **MIT-Lizenz**.

Unveränderlicher Quellstand: [Commit `1b002399e8758fb2bccea4d07312dc9e0f43dcb8`](https://github.com/feathericons/feather/tree/1b002399e8758fb2bccea4d07312dc9e0f43dcb8).
Die ursprünglichen SVG-Dateien und die Original-Lizenz liegen unter
`public/assets/vendor/feather/`. [provenance.json](public/assets/vendor/feather/provenance.json)
dokumentiert für jede Datei Quell-URL, Upstream-SHA-256 und lokalen SHA-256.
Nur abschließende Leerzeichen/Zeilenumbrüche der gespeicherten Textdateien wurden
auf einen abschließenden Zeilenumbruch vereinheitlicht.

Die 21 Feather-SVG-Inhalte werden geometrisch unverändert in `app/views/icons.php`
als `<symbol>` eingebettet; `app/views/workspace.php` bindet diese Datei ein.
Angepasst sind ausschließlich die äußeren SVG-Wrapper, lokale IDs und die Darstellung
von Größe/Strichstärke über die bestehende o8-CSS-Klasse. Kein Feather-JavaScript,
kein CDN und keine Icon-Schrift werden verwendet.

Das zusätzliche Symbol `i-tag` wurde für die o8-Dokumentliste direkt als
Projektbestandteil gezeichnet; es gehört nicht zum Feather-Import.

| o8-Symbol | Zugehörige Originaldatei am festgelegten Commit |
|---|---|
| `i-document` | [`file-text.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/file-text.svg) |
| `i-folder` | [`folder.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/folder.svg) |
| `i-search` | [`search.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/search.svg) |
| `i-inbox` | [`inbox.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/inbox.svg) |
| `i-chart` | [`bar-chart-2.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/bar-chart-2.svg) |
| `i-settings` | [`sliders.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/sliders.svg) |
| `i-plus` | [`plus.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/plus.svg) |
| `i-close` | [`x.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/x.svg) |
| `i-link` | [`link.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/link.svg) |
| `i-trash` | [`trash-2.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/trash-2.svg) |
| `i-arrow` | [`chevron-right.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/chevron-right.svg) |
| `i-check` | [`check.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/check.svg) |
| `i-filter` | [`filter.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/filter.svg) |
| `i-moon` | [`moon.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/moon.svg) |
| `i-menu` | [`menu.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/menu.svg) |
| `i-info` | [`info.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/info.svg) |
| `i-mail` | [`mail.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/mail.svg) |
| `i-upload` | [`upload.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/upload.svg) |
| `i-cloud` | [`cloud.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/cloud.svg) |
| `i-restore` | [`rotate-ccw.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/rotate-ccw.svg) |
| `i-eye` | [`eye.svg`](https://github.com/feathericons/feather/blob/1b002399e8758fb2bccea4d07312dc9e0f43dcb8/icons/eye.svg) |

Die MIT-Lizenz bleibt für diese Symbole maßgeblich; sie werden nicht unter die
einschränkenderen Bestimmungen der o8 Community License umgelizenziert. Der vollständige
Lizenztext steht unten und zusätzlich in `public/assets/vendor/feather/LICENSE`.

**Projektzeichen/Favicon:** `public/assets/favicon.svg` wurde ebenfalls neu aufgebaut:
ein abgerundetes Rechteck und der gewöhnliche Text „o8“, dargestellt mit einer lokal
vorhandenen Systemschrift. Diese SVG-Konstruktion entstand am 17. September 2026 direkt
für o8; es wurden keine fremden Icon-Pfade, Logo-Vorlagen oder Fontdateien übernommen.
Die HTML-/CSS-Wortmarke zeigt ebenfalls „o8“ mit Systemschrift. Sie ist kein Feather-Icon.
Für eigene Projektbestandteile gilt die o8-Lizenz, soweit entsprechende Rechte bestehen;
Markenrechte Dritter werden damit nicht behauptet oder geprüft.

Die als `data:image/svg+xml` eingebetteten Bedienelemente in Bootstrap-CSS gehören
zum oben nachgewiesenen Bootstrap-Release und fallen unter dessen MIT-Lizenz.

Der Austausch klärt die Herkunft des **jetzt ausgelieferten** Icon-Satzes; er behauptet
keine rückwirkende Herkunft der zuvor vorhandenen Pfade. Die Offline-Tests prüfen
Dateiprüfsummen, Lizenztext-Zuordnung, Symbolgeometrie und Vollständigkeit aller Referenzen.

## Abgrenzung und Pflege

- Eine jQuery-Einbindung oder mitgelieferte jQuery-Bibliothek wurde im geprüften Archiv nicht gefunden. Die optionale jQuery-Unterstützung im Bootstrap-Code stellt keine mitgelieferte jQuery-Kopie dar.
- Es werden lokale Systemschriften verwendet; zusätzliche Fontdateien oder externe Font-Einbindungen wurden nicht gefunden.
- Der lokale Symlink `o7/` ist eine Entwicklungsreferenz und keine o8-Laufzeitabhängigkeit. Nicht als Verzeichnisinhalt dereferenzieren oder mit veröffentlichen; ein separat mitgeliefertes o7 benötigt eine eigene vollständige Lizenzinventur.
- Die Python-Browsertests importieren Playwright. Das Playwright-Paket und Browser-Binärdateien sind nicht im Archiv enthalten; eine installierte Version ist nicht festgelegt. Playwright for Python steht unter Apache-2.0, siehe [Projekt](https://github.com/microsoft/playwright-python). Bei einer späteren Mitlieferung sind die Lizenz- und gegebenenfalls NOTICE-Dateien der konkret ausgelieferten Pakete und Browser zu ergänzen.
- PHP, Python und Node.js werden als separat installierte Laufzeitumgebungen verwendet und sind nicht Bestandteil dieses Archivs.
- Bei Änderungen an Abhängigkeiten, Source Maps oder ausgelieferten Paketen ist diese Datei zu aktualisieren.

## Feather Icons: Lizenztext

Zuordnung der enthaltenen Symbole und vorgenommene Wrapper-Anpassungen siehe oben.
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
