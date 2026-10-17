---
description: "Die Anleitung von Paperless Unified Search ist auf eigene Seiten umgezogen: Installieren, Einrichten, Suchen, Fehlerbehebung, FAQ und Referenz. Diese Seite führt zu dem Teil, den du suchst."
search:
  exclude: true
---

# Anleitung

Die Anleitung auf einer Seite ist jetzt eine Reihe von Seiten, jede für eine Aufgabe. Ein Link auf einen Teil der Anleitung führt von selbst zu seinem neuen Ort; die Tabelle sagt, wohin jeder Teil gegangen ist.

| Teil der Anleitung | Jetzt in |
| --- | --- |
| Schnellstart | [Start](index.de.md#schnellstart) |
| Voraussetzungen | [Installieren](install.de.md#was-sie-braucht) |
| So funktioniert es | [Einrichten](configure.de.md#so-funktioniert-es) |
| Konfiguration | [Einrichten](configure.de.md#konfiguration) |
| Paperless in deinem lokalen Netzwerk | [Einrichten](configure.de.md#paperless-in-deinem-lokalen-netzwerk) |
| Wenn der Test der Verbindung fehlschlägt | [Fehlerbehebung](troubleshooting.de.md#wenn-der-test-der-verbindung-fehlschlägt) |
| Sprachen | [Einrichten](configure.de.md#sprachen) |
| Funktioniert mit jeder Synchronisierung | [Einrichten](configure.de.md#funktioniert-mit-jeder-synchronisierung) |
| Verwendung, und in Talk, Text, Deck und Mail | [Suchen](usage.de.md#verwendung) |
| Suchen wie in Paperless | [Suchen](usage.de.md#suchen-wie-in-paperless) |
| Aus Skripten und KI-Assistenten nutzen | [Suchen](usage.de.md#aus-skripten-und-ki-assistenten-nutzen) |
| Mit occ einrichten und prüfen | [Referenz](reference.de.md#mit-occ-einrichten-und-prüfen) |
| Sicherheit und Datenschutz | [Referenz](reference.de.md#sicherheit-und-datenschutz) |

Neu sind die [Fehlerbehebung](troubleshooting.de.md), die [FAQ](faq.de.md) und die [Referenz](reference.de.md) für Administratoren.

<script>
  // A link to a part of the guide, such as guide.html#usage in the App Store, opens
  // the page that has the part now, at its German heading.
  // cspell:ignore konfiguration verwendung sicherheit
  (() => {
    const pages = {
      "quick-start": "index.html#schnellstart",
      "requirements": "install.html#was-sie-braucht",
      "how-it-works": "configure.html#so-funktioniert-es",
      "configuration": "configure.html#konfiguration",
      "paperless-in-your-local-network": "configure.html#paperless-in-deinem-lokalen-netzwerk",
      "when-the-test-of-the-connection-fails": "troubleshooting.html#wenn-der-test-der-verbindung-fehlschlägt",
      "languages": "configure.html#sprachen",
      "works-with-any-synchronization": "configure.html#funktioniert-mit-jeder-synchronisierung",
      "usage": "usage.html#verwendung",
      "in-talk-text-deck-and-mail": "usage.html#in-talk-text-deck-und-mail",
      "search-like-in-paperless": "usage.html#suchen-wie-in-paperless",
      "use-it-from-scripts-and-ai-assistants": "usage.html#aus-skripten-und-ki-assistenten-nutzen",
      "set-up-and-check-it-with-occ": "reference.html#mit-occ-einrichten-und-prüfen",
      "security-and-privacy": "reference.html#sicherheit-und-datenschutz",
    }
    const part = decodeURIComponent(location.hash.slice(1))
    const page = Object.hasOwn(pages, part) ? pages[part] : undefined
    if (page) {
      location.replace(page)
    }
  })()
</script>
