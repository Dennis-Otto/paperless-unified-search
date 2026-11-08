---
description: "Correct a translation of Paperless Unified Search or add a language: the catalogs in l10n/, the texts of the App Store, the words of Nextcloud, what the check of the translations checks, and the pull request."
---

# Translate

[Languages](configure.md#languages) · [FAQ](faq.md) · [Contributing](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CONTRIBUTING.md)

The app speaks English, German, French, Spanish, Dutch, Simplified Chinese and Traditional Chinese. The French, Spanish, Dutch and Chinese texts were drafted with the help of an AI, with the words that Nextcloud itself uses in these languages, and wait for native speakers to read them: a word that sounds wrong to you in Nextcloud is worth a pull request, and so is a language that the app doesn't speak yet. You need a text editor or the editor of GitHub, no PHP and no build.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/how-a-translation-reaches-its-readers.dark.svg">
  <img src="images/diagrams/how-a-translation-reaches-its-readers.svg" alt="How a translation reaches its readers: A translator corrects or adds a text in l10n/fr.json, the catalog of French, and the summary and the description in French in appinfo/info.xml. node scripts/check-l10n.mjs --write writes l10n/fr.js, which the browser loads, from the catalog. The check of the translations then asks whether every text of the code has its translation, with its placeholders and the forms of its plurals, whether l10n/fr.js holds the same as l10n/fr.json, and whether appinfo/info.xml has a summary and a description in French. If not, its output names each problem and its fix. If so, the pull request is merged and released, and Nextcloud shows the search, the dashboard and the settings in French to every account set to French, and the App Store shows the summary and the description in French.">
</picture>

## The languages

| Language | Catalog | Addresses you with | Written by |
| --- | --- | --- | --- |
| English | the texts in the code | *you* | the maintainer |
| German | `l10n/de.json`, and `l10n/de_DE.json` for *Deutsch (Förmlich: Sie)* | *du*, and *Sie* | the maintainer |
| French | `l10n/fr.json` | *vous* | an AI, waiting for review |
| Spanish | `l10n/es.json` | *tú* | an AI, waiting for review |
| Dutch | `l10n/nl.json` | *je* | an AI, waiting for review |
| Simplified Chinese | `l10n/zh_CN.json` | *您* | an AI, waiting for review |
| Traditional Chinese, as written in Taiwan | `l10n/zh_TW.json` | *您* | an AI, waiting for review |

Nextcloud takes the catalog of the language of an account, and the English texts of the code where the app has no catalog in that language. It takes a catalog for the exact language alone: an account set to a regional variant, such as *Español (México)*, `es_MX`, needs a catalog of its own, even one that copies `l10n/es.json`. So an account set to *正體中文（香港）*, `zh_HK`, reads the app in English: the catalog of Traditional Chinese is that of Taiwan, `zh_TW`.

## Where the texts are

- **`l10n/<language>.json`**, such as `l10n/fr.json`: the texts that PHP shows, in the search and its results, the widget *New in Paperless* of the dashboard, the personal and the administration settings and the warnings of the overview. Correct the texts here.
- **`l10n/<language>.js`**: the same texts for the browser, which shows the messages of the settings. A command writes it from the `.json`; never edit it by hand.
- **`appinfo/info.xml`**: the listing of the App Store, `<summary lang="fr">` with one line and `<description lang="fr">` with the page of the app in Markdown. The App Store reads it with every release. The website has pages in English and German only, so the description of another language links the English documentation.

## How a catalog reads

Each line pairs a text of the code, in English, with its translation:

```json
{
  "translations": {
    "Paperless documents": "Documents Paperless",
    "The archive account %s does not exist.": "Le compte d’archive %s n’existe pas.",
    "The request failed (HTTP {status}).": "La requête a échoué (HTTP {status}).",
    "_%n user_::_%n users_": ["%n utilisateur", "%n utilisateurs", "%n utilisateurs"]
  },
  "pluralForm": "nplurals=3; plural=(n == 0 || n == 1) ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;"
}
```

- **The English text on the left stays as it is,** character for character: the code looks the translation up by it. Change only the text on the right.
- **Placeholders stay,** such as `%s`, `%1$s`, `%n`, `{status}` and `{account}`: the app puts a name, a number or a link there. The words around them may move, and `%1$s` and `%2$s` may swap places.
- **A plural,** whose text reads `_singular_::_plural_`, has as many forms as `pluralForm` names with `nplurals`: German and Dutch one and other, French and Spanish three, Chinese one for every count. Nextcloud, however, picks only *one* or *other* for French and Spanish (form 0 or 1), never the third form for millions, so the middle form repeats the *other* form, as in Nextcloud's own catalogs; `scripts/check-l10n.mjs` fails otherwise. `pluralForm` is the one of Nextcloud's own catalog of the language.
- **Nextcloud's words:** a word that Nextcloud shows as well takes Nextcloud's translation, such as *Search connected services*, which is *Rechercher dans les services connectés*, *Buscar servicios conectados*, *Verbonden diensten zoeken*, *搜索已连接的服务* in Simplified and *搜尋已連結的服務* in Traditional Chinese; the [catalogs of Nextcloud's server](https://github.com/nextcloud/server/tree/master/core/l10n) have them. The words of Paperless, such as the permission *View* of *Correspondents*, take those of Paperless-ngx in the language.
- **The words of Paperless Sync:** where [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync), the companion of this app, names the same thing, such as the archive, the marker, the API token or the correspondents, a translation takes its word in the language, so that the two apps read as one.
- **Traditional Chinese is not Simplified Chinese in other characters:** it takes the words of Nextcloud's and Paperless-ngx's own catalogs for Taiwan, such as *檔案* for a file, *資料夾* for a folder, *帳號* for an account, *使用者* for a user, *分享* for a share and *關聯方* for the correspondent, where Simplified Chinese has *文件*, *文件夹*, *账号*, *用户*, *共享* and *往来方*; *文件* itself is a file in Simplified Chinese, but a document in Taiwan, as in Paperless-ngx. The credentials manager of Nextcloud, which keeps the API token, is its *憑證管理器*, as in *全域憑證*, never *認證資訊管理器*, and a client is a *客戶端*, as Nextcloud writes them for Taiwan and Paperless Sync takes them too. A text converted character by character from `l10n/zh_CN.json` reads wrong in Taiwan.
- **French typography:** a no-break space before `:`, `;`, `?` and `!` and inside `«` and `»`, as Nextcloud writes French.
- **Chinese typography:** the full-width punctuation of Chinese, such as `，`, `。`, `：` and `（）`, with the quotes `“”` in Simplified and `「」` in Traditional Chinese, as Nextcloud has them, the dash as two, `——`, without spaces, such as `已設定——留空即保持不變`, and a space between a Chinese word and a word in Latin letters, a number or a placeholder, such as `Paperless 文件` and `%n 個新文件`.

## Correct a translation

1. Open the catalog in [`l10n/`](https://github.com/Dennis-Otto/paperless-unified-search/tree/main/l10n) on GitHub, such as `fr.json`, and edit it with the pencil; GitHub makes a fork and a branch for you. Or, in a clone, edit the file in your editor.
2. Change the translation on the right. For the App Store, change `<summary lang="fr">` or `<description lang="fr">` in `appinfo/info.xml`.
3. Write the catalog of the browser anew and check the translations, with Node.js:

    ```sh
    node scripts/check-l10n.mjs --write
    node scripts/check-l10n.mjs
    ```

    Without Node.js, open the pull request anyway: its check names each problem with its fix, and the fix bot writes the catalog of the browser for you, or suggests it on a fork.

4. Open a pull request with a title such as `fix(l10n): correct the French of the dashboard`, and say in its description what read wrong. Each commit needs a sign-off, `git commit -s`; the editor of GitHub adds it when you tick its box. [CONTRIBUTING.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CONTRIBUTING.md) has the rest.

## Add a language

1. Copy `l10n/de.json` to `l10n/<language>.json`, named with the code of the language in Nextcloud, such as `it` for Italian or `pt_BR` for Brazilian Portuguese, the names of the files in the [catalogs of Nextcloud's server](https://github.com/nextcloud/server/tree/master/core/l10n).
2. Translate every text on the right, and set `pluralForm` to the one of Nextcloud's `core/l10n/<language>.json`, with as many forms for each plural as it names.
3. Add `<summary lang="it">` and `<description lang="it">` to `appinfo/info.xml`, after those of the other languages, translated from the English ones; the description ends with the link to the English documentation. The `lang` is the code of the language in the App Store: the language alone, such as `it`, and `de` for `de_DE`, but a code of its own for the few variants that the [schema of the App Store](https://apps.nextcloud.com/schema/apps/info.xsd) lists, such as `pt-br` for `pt_BR`, `zh-hans` for `zh_CN` and `zh-hant` for `zh_TW`. The check below names it.
4. Run the two commands above, which write `l10n/<language>.js` and check every catalog.
5. Name the language where the others are named: [Languages](configure.md#languages) in `README.md` and `README.de.md`, the FAQ in `docs/faq.md` and `docs/faq.de.md`, the table above in this page and its German twin, the languages of the App Store in `tests/Unit/AppInfo/AppStoreListingTest.php`, and the versions of Nextcloud in the new description for `scripts/check-project.sh`, such as `- Nextcloud 33 a 35`.
6. Open a pull request with a title such as `feat(l10n): speak Italian`, which ships the language in the next release.

## What the check checks

`node scripts/check-l10n.mjs` runs with `bash scripts/check.sh` and in every pull request, and fails when:

- a text of the code has no translation in a catalog, or a catalog translates a text that no code uses any more;
- a translation is empty, or has other placeholders than its text;
- a plural has another number of forms than the `pluralForm` of its catalog names;
- a catalog for the browser, `l10n/<language>.js`, holds anything other than its `l10n/<language>.json`;
- `appinfo/info.xml` has no summary or no description for the App Store in the language of a catalog, where a regional variant, such as `de_DE`, takes those of its language, `de`, and a variant that the App Store knows those of its code, such as `zh-hans` for `zh_CN` and `zh-hant` for `zh_TW`;
- Chinese, in a catalog or for the App Store, writes a dash as one `—` or with a space beside `——`, or Traditional Chinese a word of Simplified Chinese in its characters, such as *日誌* for a log or *用戶* for a user, or a word for which Nextcloud for Taiwan writes another, such as *認證資訊* for credentials: the problem names the word that the catalogs of Nextcloud for Taiwan write instead, such as *記錄檔*, *使用者* or *憑證*. `taiwanWords` in `scripts/check-l10n.mjs` lists the words of Simplified Chinese, the same that Paperless Sync checks, and `nextcloudWords` the others. `node scripts/check-l10n.mjs --write` writes the dashes anew, as `——` without spaces, and the fix bot runs it on a pull request; a word you change yourself, since a word of Simplified Chinese can have more than one word of Taiwan.

Each problem names the file, the text and the fix. The check can't tell a good translation from a bad one: that takes a native speaker.
