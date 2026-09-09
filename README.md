# Radish Changelog

Losse, herbruikbare WordPress-plugin (Variant C, standalone-minimaal) die per release laat zien wat er live is gegaan: de behandelde Jira-tickets, de WordPress-versie en de versies van plugins van derden. Alles leest en schrijft naar `/CHANGELOG.md` in de repo-root, buiten de webroot. In WordPress toont de plugin een dashboard-widget en een beheerpagina "Wijzigingen"; redacteuren kunnen zich inschrijven en krijgen een mail zodra een release met noemenswaardige inhoud live gaat.

## Installatie in een ander project

1. Kopieer de map `radish-changelog` naar `wp-content/plugins/` van het doelproject (of, zodra de plugin op een eigen Composer-package staat, voeg die toe aan `composer.json` en installeer hem daarmee).
2. Maak `/changelog.json` aan in de repo-root (naast waar `/CHANGELOG.md` komt te staan), met de sleutels uit de referentie hieronder. Alle sleutels zijn optioneel; alles wat je weglaat valt terug op de standaardwaarde.
3. Activeer de plugin: `wp plugin activate radish-changelog`.
4. Maak de eerste changelog-entry: `wp changelog init`.

### `changelog.json` referentie

Volledige sleutelset met de standaardwaarden (dit is het contract voor andere projecten):

```json
{
  "branches": {
    "remote": "origin",
    "from": "develop",
    "base": "master",
    "release_prefix": "release/"
  },
  "jira": {
    "base_url": "https://radishconcepts.atlassian.net",
    "project": "KFM"
  },
  "own_authors": [ "Radish Concepts" ],
  "updates": { "include_patch": true },
  "notify": { "environments": [ "production" ] }
}
```

- `branches.remote`: de git-remote waar `wp changelog release` tegen fetcht en (met `--push`) naartoe pusht.
- `branches.from`: standaard brontak voor een release (waar de wijzigingen vandaan komen).
- `branches.base`: standaard doeltak om tegen te diffen (waar de release straks in landt).
- `branches.release_prefix`: voorvoegsel voor de release-branch, bijvoorbeeld `release/` geeft `release/sprint-12`.
- `jira.base_url`: alleen scheme en host, bijvoorbeeld `https://radishconcepts.atlassian.net`; geen pad, query of userinfo.
- `jira.project`: het Jira-projectprefix waarop tickets uit commit-subjects gefilterd worden, bijvoorbeeld `KFM`.
- `own_authors`: lijst van namen die, als ze in de `Author`-header van een plugin voorkomen, die plugin buiten de versielijst van de changelog houden (hun wijzigingen staan al bij de tickets).
- `updates.include_patch`: of patch-only versiebumps (x.x.**x**) in de changelog komen. Zulke bumps triggeren nooit een mail, ongeacht deze instelling.
- `notify.environments`: `wp_get_environment_type()`-waarden waarop de release-mail automatisch verstuurd mag worden, bijvoorbeeld `["production"]`.

Ongeldige JSON of een verkeerd type bij een sleutel levert altijd een duidelijke foutmelding op (bestandsnaam en sleutel); er wordt nooit stil teruggevallen op een default bij een fout bestand.

## Commando's

### `wp changelog init`

Schrijft de eerste changelog-entry (de "Nulmeting"): alleen de op dat moment actuele versies (WordPress core en plugins van derden), geen tickets of updates. Weigert als `/CHANGELOG.md` al één of meer releases bevat, dit commando is bedoeld om precies één keer per project te draaien.

```
wp changelog init [--name=<naam>] [--ref=<ref>] [--yes]
```

- `--name=<naam>`: kop voor de entry. Letters, cijfers, spaties, punten, underscores en streepjes, maximaal 64 tekens. Standaard "Nulmeting" (Dutch: `Baseline` uit de broncode, vertaald via de textdomain).
- `--ref=<ref>`: git-ref om de WordPress- en pluginversies van te lezen. Standaard `origin/<branches.base>` (`origin/master` zonder `changelog.json`).
- `--yes`: sla de bevestigingsvraag over.

### `wp changelog release`

Bouwt de changelog-entry voor een release: detecteert ticket-keys en versiewijzigingen tussen twee refs, toont een preview, maakt daarna de release-branch aan vanaf `--from` en commit de entry.

```
wp changelog release <naam> [--from=<branch>] [--base=<branch>] [--tickets=<KEY,KEY>] [--dry-run] [--push] [--yes]
```

- `<naam>`: releasenaam. Wordt gebruikt voor zowel de branchnaam (`<release_prefix><naam>`) als de kop in de changelog. Letters, cijfers, punten, underscores en streepjes, maximaal 64 tekens.
- `--from=<branch>`: brontak. Een kale naam (zonder "/") probeert eerst `<remote>/<naam>`, anders moet `<naam>` zelf een geldige ref zijn (zo kan ook een lokale, nog niet gepushte branch als bron dienen). Standaard `branches.from`.
- `--base=<branch>`: doeltak om tegen te diffen voor tickets en versiewijzigingen. Zelfde resolutie als `--from`. Standaard `branches.base`.
- `--tickets=<KEY,KEY>`: komma-gescheiden ticket-keys die de automatisch gedetecteerde lijst (uit de commit-subjects tussen `--base` en `--from`) volledig vervangen.
- `--dry-run`: toont de preview zonder een branch aan te maken of iets weg te schrijven.
- `--push`: pusht de nieuwe branch na het commit.
- `--yes`: sla de bevestigingsvraag over.

Na een geslaagde `release` (zonder `--dry-run`) staat er een branch met precies één commit met de changelog-entry. Zie ook *Merge terug naar develop* hieronder.

### `wp changelog notify`

Handmatig (of geforceerd) de release-mail versturen of testen, dezelfde beslislogica als de automatische controle op `admin_init`.

```
wp changelog notify [--dry-run] [--force]
```

- `--dry-run`: toont wat er zou gebeuren (release, mail-waardig ja/nee, omgeving actief ja/nee, al gemaild ja/nee, ontvangers) zonder iets te versturen of op te slaan.
- `--force`: negeert de omgevingscheck en de per-release marker, en verstuurt sowieso. Een bewuste handeling, voor testen of opnieuw versturen.

## Titelresolutie voor tickets

Voor elke ticket-key wordt de titel in deze volgorde bepaald:

1. De eerste regel van `.docs/<KEY>/ticket.md` in de repo-root: een kop die begint met de key, gevolgd door een optioneel scheidingsteken (een liggend streepje of dubbele punt) en de titel, bijvoorbeeld `# KFM-191: Omslagafbeelding optimaliseren` of `# KFM-191 Omslagafbeelding optimaliseren`.
2. Jira REST, in één verzamelverzoek voor alle keys die nog geen titel hebben.
3. Anders blijft de key kaal (geen titel); aan het eind van `wp changelog release` toont één `WP_CLI::warning` welke keys dat zijn.

## Jira-credentials

Nodig voor stap 2 van de titelresolutie hierboven en alleen daarvoor; zonder credentials valt de plugin terug op kale keys plus een waarschuwing. In deze volgorde:

1. Omgevingsvariabelen `JIRA_EMAIL` en `JIRA_API_TOKEN`.
2. Anders `~/.config/radish-changelog/jira.json`, één keer per machine voor alle projecten:
   ```json
   {
     "email": "jij@radishconcepts.com",
     "api_token": "…"
   }
   ```
   Dit bestand moet rechten `0600` hebben (alleen leesbaar/schrijfbaar voor de eigenaar); is het voor groep of anderen leesbaar, dan wordt het genegeerd in plaats van vertrouwd.

Zet dit bestand nooit in de repo en commit nooit een token. Het hoort buiten elke projectmap, in de home-directory van de machine die het commando draait.

## Bestandsformaat

`/CHANGELOG.md` is een vast, eigen formaat (geen markdown-library, een eigen parser op dit vaste formaat). De nieuwste release staat bovenaan. Voorbeeld:

```markdown
# Changelog

## Sprint 12 (2026-09-08)

Optionele vrije tekst onder de kop, één of meer alinea's.

### Tickets
- [KFM-189](https://radishconcepts.atlassian.net/browse/KFM-189) Incentive-veld per giftperiode tonen
- [KFM-196](https://radishconcepts.atlassian.net/browse/KFM-196)

### Updates
- WordPress: 6.9 → 7.1
- Gravity Forms: 3.0.4 → 3.1.0.2
- WP Rocket: 3.22.0.1 → 3.22.0.2
- WebP Express: nieuw (0.25.15)
- Akismet: verwijderd (5.7.2)

## Nulmeting (2026-09-08)

### Inventory
- WordPress: 7.1
```

De sectiekoppen (`### Tickets`, `### Updates`, `### Inventory`) staan vast in het Engels, zodat de parser taalonafhankelijk blijft; de beheerpagina en de widget tonen ze vertaald ("Wijzigingen", "Updates", "Inventaris"). Handmatig toegevoegde tekst blijft bij het opnieuw inlezen bewaard.

## Mailgedrag

- Getriggerd vanuit WordPress zelf: bij elk `admin_init`-verzoek in een omgeving uit `notify.environments` vergelijkt de plugin de nieuwste release in het bestand met een marker-optie en mailt hooguit één keer per release. Geen aparte deploy-stap nodig.
- Een release is "mail-waardig" zodra hij minstens één ticket bevat, een WordPress core-update, of een plugin-update die geen zuivere patch-bump is (nieuw, verwijderd, of major/minor). Een release met uitsluitend patch-bumps komt wel in de changelog, maar mailt niet.
- Ontvangers zijn WordPress-gebruikers die zich op de pagina "Wijzigingen" hebben ingeschreven (zelfbediening, geen publiek formulier), met de capability `edit_posts`. Ieder krijgt een eigen `wp_mail()`-aanroep (geen cc/bcc), platte tekst.
- `wp changelog notify` bestaat om dit handmatig te testen of te forceren zonder op een echt `admin_init`-verzoek te wachten, zie *Commando's* hierboven.

## Na een release: merge terug naar develop

`wp changelog release` commit de changelog-entry alleen op de nieuwe release-branch. Na het mergen van die branch (via de PR) naar `branches.base` (meestal `master`) moet je `master` ook terugmergen naar de branch waar je vandaan releasede (meestal `develop`), anders conflicteert `/CHANGELOG.md` bij de volgende release. Deze stap staat ook in de afsluittekst die het commando zelf toont.
