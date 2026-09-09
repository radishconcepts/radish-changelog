# Radish Changelog: project conventions

## Internationalisation (i18n)

**Always treat English as the source language and Dutch (`nl_NL`) as a
first-class translation target.**

Whenever you add or change a user-facing string anywhere in the codebase
(PHP `__()`/`_e()`/`_n()`/`esc_html__()`/`esc_html_e()`/`esc_attr__()`
calls in `src/` or `templates/`: admin notices, error messages, button
labels, mail subject/body lines, CLI defaults, …), always resolve the
textdomain through `Plugin::textdomain()` (never a hardcoded literal, see
`radish-wp-plugin-architecture`):

```php
__( 'My text', Plugin::textdomain() )
```

### The `wp i18n make-pot` gotcha for this plugin

Unlike `radish-2fa` (which uses the literal `'radish-2fa'` string as the
second argument everywhere), this plugin resolves the textdomain through
`Plugin::textdomain()`. `wp i18n make-pot` extracts strings by statically
matching the second argument against `--domain` (or `--slug` when
`--domain` is omitted); a method call like `Plugin::textdomain()` is not a
string literal, so the static scanner **cannot see it** and silently skips
every such call, regardless of whether `--domain` is passed. Running
`wp i18n make-pot . languages/radish-changelog.pot --slug=radish-changelog --domain=radish-changelog`
inside the plugin directory only picks up the three plugin-header strings
(`Plugin Name`, `Description`, `Author`); it will not add or remove any of
the real `__()`/`_e()`/`_n()` calls in `src/` or `templates/`.

This does **not** break translation at runtime: `Plugin::textdomain()`
still evaluates to the literal string `'radish-changelog'` when PHP runs
the call, and `__()` looks the msgid up in the loaded `.mo` for that
domain exactly the same as if the literal had been hardcoded. It only
breaks the *automated extraction* step.

Because of this, `languages/radish-changelog.pot` and
`languages/radish-changelog-nl_NL.po` must be **maintained by hand**:

1. Grep for every `__(`, `_e(`, `_n(`, `esc_html__(`, `esc_html_e(`,
   `esc_attr__(`, `esc_attr_e(` call in `src/` and `templates/`:
   ```
   grep -rn "__(\|_e(\|_n(\|esc_html__\|esc_html_e\|esc_attr__\|esc_attr_e" src/ templates/
   ```
2. For every new or changed msgid, add (or update) the matching entry by
   hand in both `languages/radish-changelog.pot` (empty `msgstr ""`) and
   `languages/radish-changelog-nl_NL.po` (filled-in Dutch `msgstr`), with
   a `#:` reference comment to the file and line, and a `#, php-format`
   flag plus a `#. translators: …` comment for any `sprintf()` placeholder.
3. Compile the `.mo`:
   ```
   wp i18n make-mo languages
   ```
   (or `msgfmt --check --statistics -o languages/radish-changelog-nl_NL.mo languages/radish-changelog-nl_NL.po`)
4. Verify nothing was left untranslated:
   ```
   msgattrib --untranslated languages/radish-changelog-nl_NL.po
   ```
   This must report zero non-header entries before the change is
   complete.

Do not "fix" this by hardcoding a literal domain string in `__()` calls to
make `wp i18n make-pot` happy: the `radish-wp-plugin-architecture` skill's
`Plugin::textdomain()` convention is intentional (rule 5) and is not
overridden by a tooling limitation.

The Dutch translations are part of the deliverable; an English-only PR
is incomplete.

### Tone for Dutch translations

Tutoyeer (use **je**/**jou**, not **u**), matching the existing catalog.
Keep terminology consistent with the project glossary (`.docs/CONTEXT.md`
in the site repo): *Release*, *Changelog*, *Nulmeting*, *Eigen plugin*,
*Mail-waardige release*. File-format section headings (`### Tickets`,
`### Updates`, `### Inventory` in `/CHANGELOG.md`) stay in English on
purpose so the parser (`Changelog\Parser`) is language-independent; only
the UI-facing labels for them (in `Admin\Page::section_label()`) are
translated.

## Tests

```
composer install
vendor/bin/phpunit
```

from the plugin directory. PHP 8.2+ required. Covers the pure-PHP classes
(`Config`, `Changelog\*`, `Cli\Ticket_Keys`, `Cli\Ticket_Titles::from_ticket_md()`,
`Versions\Header_Parser`, `Versions\Version_Diff`); the admin page, widget,
CLI commands, `Jira\Client` and the mail flow are covered by manual QA
(see the plan's Verification section), not by this suite, because they
depend on WordPress or the network.

## Secrets

Never commit Jira credentials. `JIRA_EMAIL`/`JIRA_API_TOKEN` come from the
environment, or from `~/.config/radish-changelog/jira.json` (mode `0600`,
keys `email` and `api_token`); that file lives outside every project
repository, in the home directory of the machine running `wp changelog
release`, shared across all projects on that machine. `Jira\Client`
refuses a config file that is readable by group or others rather than
trusting it. Never log or print the token or the file's contents; error
messages from this plugin only ever mention the config path, never the
values inside it.
