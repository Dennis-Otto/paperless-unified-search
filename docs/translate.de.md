---
description: "Eine Übersetzung von Paperless Unified Search korrigieren oder eine Sprache hinzufügen: die Kataloge in l10n/, die Texte des App Stores, die Wörter von Nextcloud, was die Prüfung der Übersetzungen prüft, und der Pull Request."
---

# Übersetzen

[Sprachen](configure.de.md#sprachen) · [FAQ](faq.de.md) · [Mitwirken](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CONTRIBUTING.md)

Die App spricht Englisch, Deutsch, Französisch, Spanisch und Niederländisch. Die französischen, spanischen und niederländischen Texte sind mit Hilfe einer KI entstanden, mit den Wörtern, die Nextcloud selbst in diesen Sprachen verwendet, und warten darauf, dass Muttersprachler sie lesen: Ein Wort, das dir in Nextcloud falsch klingt, ist einen Pull Request wert, und eine Sprache, die die App noch nicht spricht, ebenso. Du brauchst einen Texteditor oder den Editor von GitHub, kein PHP und keinen Build.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/how-a-translation-reaches-its-readers.de.dark.svg">
  <img src="images/diagrams/how-a-translation-reaches-its-readers.de.svg" alt="Wie eine Übersetzung ihre Leser erreicht: Eine Übersetzerin korrigiert oder ergänzt einen Text in l10n/fr.json, dem Katalog des Französischen, und die Zusammenfassung und die Beschreibung auf Französisch in appinfo/info.xml. node scripts/check-l10n.mjs --write schreibt aus dem Katalog l10n/fr.js, das der Browser lädt. Die Prüfung der Übersetzungen fragt dann, ob jeder Text des Codes seine Übersetzung hat, mit seinen Platzhaltern und den Formen seiner Plurale, ob l10n/fr.js dasselbe enthält wie l10n/fr.json und ob appinfo/info.xml eine Zusammenfassung und eine Beschreibung auf Französisch hat. Wenn nicht, nennt ihre Ausgabe jedes Problem und seine Lösung. Wenn ja, wird der Pull Request gemergt und veröffentlicht, und Nextcloud zeigt die Suche, das Dashboard und die Einstellungen jedem Konto mit der Sprache Französisch auf Französisch, und der App Store zeigt die Zusammenfassung und die Beschreibung auf Französisch.">
</picture>

## Die Sprachen

| Sprache | Katalog | Spricht dich an mit | Geschrieben von |
| --- | --- | --- | --- |
| Englisch | die Texte im Code | *you* | dem Maintainer |
| Deutsch | `l10n/de.json`, und `l10n/de_DE.json` für *Deutsch (Förmlich: Sie)* | *du*, und *Sie* | dem Maintainer |
| Französisch | `l10n/fr.json` | *vous* | einer KI, wartet auf Durchsicht |
| Spanisch | `l10n/es.json` | *tú* | einer KI, wartet auf Durchsicht |
| Niederländisch | `l10n/nl.json` | *je* | einer KI, wartet auf Durchsicht |

Nextcloud nimmt den Katalog der Sprache eines Kontos und die englischen Texte des Codes, wo die App keinen Katalog in dieser Sprache hat. Es nimmt einen Katalog nur für genau diese Sprache: Ein Konto mit einer regionalen Variante, etwa *Español (México)*, `es_MX`, braucht einen eigenen Katalog, auch einen, der `l10n/es.json` kopiert.

## Wo die Texte sind

- **`l10n/<language>.json`**, etwa `l10n/fr.json`: die Texte, die PHP zeigt, in der Suche und ihren Treffern, im Widget *Neu in Paperless* des Dashboards, in den persönlichen und den Verwaltungseinstellungen und in den Warnungen der Übersicht. Korrigiere die Texte hier.
- **`l10n/<language>.js`**: dieselben Texte für den Browser, der die Meldungen der Einstellungen zeigt. Ein Befehl schreibt sie aus der `.json`; bearbeite sie nie von Hand.
- **`appinfo/info.xml`**: der Eintrag im App Store, `<summary lang="fr">` mit einer Zeile und `<description lang="fr">` mit der Seite der App in Markdown. Der App Store liest ihn mit jedem Release. Die Website hat nur Seiten auf Englisch und Deutsch, also verlinkt die Beschreibung einer anderen Sprache die englische Dokumentation.

## Wie ein Katalog sich liest

Jede Zeile verbindet einen Text des Codes, auf Englisch, mit seiner Übersetzung:

```json
{
	"translations": {
		"Paperless documents": "Documents Paperless",
		"The archive account %s does not exist.": "Le compte d’archive %s n’existe pas.",
		"The request failed (HTTP {status}).": "La requête a échoué (HTTP {status}).",
		"_%n user_::_%n users_": ["%n utilisateur", "%n d’utilisateurs", "%n utilisateurs"]
	},
	"pluralForm": "nplurals=3; plural=(n == 0 || n == 1) ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;"
}
```

- **Der englische Text links bleibt, wie er ist,** Zeichen für Zeichen: Der Code sucht die Übersetzung über ihn. Ändere nur den Text rechts.
- **Platzhalter bleiben,** etwa `%s`, `%1$s`, `%n`, `{status}` und `{account}`: Die App setzt dort einen Namen, eine Zahl oder einen Link ein. Die Wörter um sie herum dürfen wandern, und `%1$s` und `%2$s` dürfen die Plätze tauschen.
- **Ein Plural,** dessen Text `_singular_::_plural_` lautet, hat so viele Formen, wie `pluralForm` mit `nplurals` nennt, in der Reihenfolge seiner Regel: Französisch und Spanisch eins, viele (für Millionen, etwa *1 000 000 d’utilisateurs*) und andere, Deutsch und Niederländisch eins und andere. `pluralForm` ist die des Katalogs von Nextcloud selbst für die Sprache.
- **Die Wörter von Nextcloud:** Ein Wort, das auch Nextcloud zeigt, bekommt die Übersetzung von Nextcloud, etwa *Search connected services*, auf Französisch *Rechercher dans les services connectés*, auf Spanisch *Buscar servicios conectados* und auf Niederländisch *Verbonden diensten zoeken*; die [Kataloge des Servers von Nextcloud](https://github.com/nextcloud/server/tree/master/core/l10n) haben sie. Die Wörter von Paperless, etwa die Berechtigung *View* für *Correspondents*, bekommen die von Paperless-ngx in der Sprache.
- **Französische Typografie:** ein geschütztes Leerzeichen vor `:`, `;`, `?` und `!` und innerhalb von `«` und `»`, wie Nextcloud Französisch schreibt.

## Eine Übersetzung korrigieren

1. Öffne den Katalog in [`l10n/`](https://github.com/Dennis-Otto/paperless-unified-search/tree/main/l10n) auf GitHub, etwa `fr.json`, und bearbeite ihn mit dem Stift; GitHub legt einen Fork und einen Branch für dich an. Oder bearbeite die Datei in einem Klon in deinem Editor.
2. Ändere die Übersetzung rechts. Für den App Store ändere `<summary lang="fr">` oder `<description lang="fr">` in `appinfo/info.xml`.
3. Schreib den Katalog des Browsers neu und prüfe die Übersetzungen, mit Node.js:

    ```sh
    node scripts/check-l10n.mjs --write
    node scripts/check-l10n.mjs
    ```

    Ohne Node.js öffne den Pull Request trotzdem: Seine Prüfung nennt jedes Problem mit seiner Lösung, und der Fix-Bot schreibt den Katalog des Browsers für dich oder schlägt ihn bei einem Fork vor.

4. Öffne einen Pull Request mit einem Titel wie `fix(l10n): correct the French of the dashboard` und schreib in seine Beschreibung, was falsch klang. Jeder Commit braucht ein Sign-off, `git commit -s`; der Editor von GitHub fügt es hinzu, wenn du sein Kästchen ankreuzt. [CONTRIBUTING.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CONTRIBUTING.md) hat den Rest.

## Eine Sprache hinzufügen

1. Kopiere `l10n/de.json` nach `l10n/<language>.json`, benannt mit dem Code der Sprache in Nextcloud, etwa `it` für Italienisch oder `pt_BR` für brasilianisches Portugiesisch, den Namen der Dateien in den [Katalogen des Servers von Nextcloud](https://github.com/nextcloud/server/tree/master/core/l10n).
2. Übersetze jeden Text rechts und setze `pluralForm` auf die aus `core/l10n/<language>.json` von Nextcloud, mit so vielen Formen für jeden Plural, wie sie nennt.
3. Füge `<summary lang="it">` und `<description lang="it">` zu `appinfo/info.xml` hinzu, nach denen der anderen Sprachen, übersetzt aus den englischen; die Beschreibung endet mit dem Link auf die englische Dokumentation.
4. Führe die beiden Befehle oben aus, die `l10n/<language>.js` schreiben und jeden Katalog prüfen.
5. Nenne die Sprache dort, wo die anderen genannt sind: [Sprachen](configure.de.md#sprachen) in `README.md` und `README.de.md`, die FAQ in `docs/faq.md` und `docs/faq.de.md`, die Tabelle oben auf dieser Seite und ihrem englischen Zwilling, die Sprachen des App Stores in `tests/Unit/AppInfo/AppStoreListingTest.php` und die Versionen von Nextcloud in der neuen Beschreibung für `scripts/check-project.sh`, etwa `- Nextcloud 33 a 35`.
6. Öffne einen Pull Request mit einem Titel wie `feat(l10n): speak Italian`, der die Sprache mit dem nächsten Release ausliefert.

## Was die Prüfung prüft

`node scripts/check-l10n.mjs` läuft mit `bash scripts/check.sh` und in jedem Pull Request und schlägt fehl, wenn:

- ein Text des Codes in einem Katalog keine Übersetzung hat oder ein Katalog einen Text übersetzt, den kein Code mehr verwendet;
- eine Übersetzung leer ist oder andere Platzhalter hat als ihr Text;
- ein Plural eine andere Zahl von Formen hat, als die `pluralForm` seines Katalogs nennt;
- ein Katalog für den Browser, `l10n/<language>.js`, etwas anderes enthält als seine `l10n/<language>.json`;
- `appinfo/info.xml` keine Zusammenfassung oder keine Beschreibung für den App Store in der Sprache eines Katalogs hat, wobei eine regionale Variante wie `de_DE` die ihrer Sprache nimmt, `de`.

Jedes Problem nennt die Datei, den Text und die Lösung. Eine gute Übersetzung von einer schlechten unterscheiden kann die Prüfung nicht: Dafür braucht es Muttersprachler.
