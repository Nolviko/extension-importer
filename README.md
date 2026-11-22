# Importer for Nolviko

Official [Nolviko](https://github.com/Nolviko/nolviko) extension: moves an **SMF 2.1, MyBB 1.8 or phpBB 3.3** forum to
Nolviko from the admin panel. Install it, import, publish, remove it again.

## How it works

1. **Install** from Admin → Extensions (official catalog), then enable it.
2. **Add the old forum's database** (Admin → Import an old forum). It may be on another server. The extension finds
   the forum software, version and table prefix by itself and shows what the forum holds before anything is copied.
   The password is stored encrypted with the forum's app secret and never shown again.
3. **Start.** The import runs by itself while the page is open, several small batches per request (shared-hosting
   limits). Close the tab and it continues from the saved point when you come back.
4. **Check the results** — copied, verified, skipped and to-review records per kind, all notes, and a JSON summary
   without member data.
5. **Publish.** Until then imported forums stay hidden and imported members cannot sign in.
6. **Remove the extension** (Admin → Extensions → disable → Remove). Its stored database passwords are deleted.
   Everything the import created stays and keeps working: members, forums, topics, posts, private archives, files,
   polls, old passwords and redirects from the old addresses.

No SQL dump is uploaded: the extension reads the old database directly. Use a copy of the old forum (or close it
during the import) and, if you can, a read-only database account.

## Requirements

Nolviko 0.1.0-beta.12 or newer (extension API 1.3, import contract 1). The old forum's database must be MySQL or
MariaDB and reachable from the server Nolviko runs on; attachments are read from a folder on that server.

## Development

The core keeps the import target (ADR-038): this extension reads the source and hands converted records to it.
Tests run from the Nolviko workspace:

```
php dev/vendor/bin/phpunit --bootstrap dev/vendor/autoload.php extensions-src/nolviko/importer/tests
```

## Changes

### 1.1.0 (for Nolviko 0.1.0-beta.18)
- Forum access is read from the source (SMF member groups, MyBB forum permissions, phpBB ACL): members-only and group-only forums stay closed after publishing; password and link forums are marked.
- MyBB poll options are split on `||~|~||`; phpBB poll questions and options are plain text instead of storage XML.
- SMF `[attach id=N]` inline tags become inline attachments.
- SMF with several upload folders: the panel accepts one folder per line (`N=path`), and folders listed in SMF's own settings are found automatically.
- Private messages keep their conversations (SMF head message, phpBB root message, MyBB participants and subject); SMF sent and received copies are read in one pass.
- The preflight shows exact counts of what will be imported (categories apart from forums, messages instead of mailbox copies) and says that SMF and MyBB keep no private-message files.
- Jobs started with 1.0.0 are not continued with 1.1.0 (reader version 2); start the import again.

## License

Apache-2.0
