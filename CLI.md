# CLI commands

DataVeil is distributed as a console application. In a prepared environment the commands are usually executed through the PHAR file:

```bash
php dataveil.phar <command> [arguments] [options]
```

During development the same commands can be executed through the project entrypoint:

```bash
php DataVeil.php <command> [arguments] [options]
```

## list

Shows all available commands.

```bash
php dataveil.phar list
```

Use this command to verify that the application starts correctly and to see which commands are available in the current build.

## help

Shows detailed help for a command.

```bash
php dataveil.phar help anonymize
php dataveil.phar help test:configuration
php dataveil.phar help test:db-connection
```

Use this command when you need to check required arguments, supported options, and command descriptions.

## test:configuration

Validates the YAML configuration file and prints the resolved database settings source and basic rule counts.

```bash
php dataveil.phar test:configuration configuration.yaml
```

This command is needed before running anonymization. It checks that the configuration file can be read and that required sections are present.

## test:db-connection

Checks database connectivity using the database settings described in the YAML configuration.

```bash
php dataveil.phar test:db-connection configuration.yaml
```

The command reads Bitrix database connection parameters from the configured `.settings.php`, opens a database connection, executes diagnostic SELECT queries, and prints connection details such as selected database, server version, server host, and charset.

Use this command after `test:configuration` and before anonymization to confirm that DataVeil will connect to the expected database.

## anonymize

Runs database anonymization according to the YAML configuration.

```bash
php dataveil.phar anonymize configuration.yaml
```

The command reads anonymization rules, connects to the configured database, and applies configured table actions and field strategies. It should be executed only on a copied or test Bitrix24 database, not on production data.

## anonymize --dry-run

Runs a preflight check without applying changes.

```bash
php dataveil.phar anonymize configuration.yaml --dry-run
```

The command connects to the configured database and uses metadata and SELECT queries to verify that configured tables, columns, strategies, and consistency groups can be processed. It prints the rules, consistency groups, and row counts that would be affected.

Use this command after `test:db-connection` and before the real anonymization command.

## backup:anonymize

Planned command for anonymizing an existing Bitrix24 SQL backup without connecting to the live Bitrix24 database.

```bash
php dataveil.phar backup:anonymize configuration.yaml \
    --input backup.sql \
    --output backup.anonymized.sql \
    --temp-db dataveil_tmp_backup
```

The command is intended for the workflow where a client creates a database backup on their side, sends the backup for processing, and DataVeil produces a new anonymized SQL dump. The original live database is not modified.

### Temporary database workflow

The temporary database is a separate technical MySQL/MariaDB database created only for processing one backup.

The command workflow:

1. Read anonymization rules from `configuration.yaml`.
2. Validate the input dump path, output dump path, and temporary database name.
3. Connect to the configured MySQL/MariaDB server with a technical user.
4. Create the temporary database named by `--temp-db`.
5. Import the SQL dump from `--input` into that temporary database.
6. Run anonymization preflight checks against the imported database.
7. If preflight passes and `--dry-run` is not enabled, run anonymization against the temporary database.
8. Export the anonymized temporary database to `--output`.
9. Drop the temporary database and remove temporary files, unless `--keep-temp` is enabled.

Conceptually this is equivalent to:

```bash
mysql -e "CREATE DATABASE dataveil_tmp_backup"
mysql dataveil_tmp_backup < backup.sql
php dataveil.phar anonymize runtime-backup-config.yaml
mysqldump dataveil_tmp_backup > backup.anonymized.sql
mysql -e "DROP DATABASE dataveil_tmp_backup"
```

The real implementation must perform these steps with validation, error handling, and cleanup.

### Options

#### `configuration.yaml`

Required argument. Path to the anonymization configuration file.

The configuration provides anonymization rules, strategies, consistency groups, and the MySQL server connection used for backup processing. In backup mode the command will run anonymization against the temporary database, not against the live Bitrix24 database from a `.settings.php` file.

#### `--input`

Required option. Path to the source backup.

Initial implementation target:

```bash
--input backup.sql
```

The first version should support plain `.sql` dumps. Archive formats such as `.sql.gz`, `.tar.gz`, and `.zip` are planned after the plain SQL workflow is stable.

The input file is read-only. DataVeil must not modify or overwrite it.

#### `--output`

Required option. Path where the anonymized dump will be written.

Example:

```bash
--output backup.anonymized.sql
```

The output path must not be the same as `--input`. If the output file already exists, the command should fail unless an explicit overwrite option is added in the future.

#### `--temp-db`

Required option. Name of the temporary database to create on the MySQL/MariaDB server.

Example:

```bash
--temp-db dataveil_tmp_backup_20260508
```

The database is created before import and dropped after successful export. It must be a dedicated temporary name, not the name of a real Bitrix24 database.

Recommended naming pattern:

```text
dataveil_tmp_<project>_<date>
```

#### `--dry-run`

Optional flag. Imports the dump into the temporary database and runs preflight checks, but does not run anonymization and does not export an anonymized dump.

Example:

```bash
php dataveil.phar backup:anonymize configuration.yaml \
    --input backup.sql \
    --output backup.anonymized.sql \
    --temp-db dataveil_tmp_backup \
    --dry-run
```

Use this mode to verify that the dump can be imported and that the anonymization rules match the imported database schema.

After the dry run, the temporary database is dropped unless `--keep-temp` is also set.

#### `--keep-temp`

Optional flag. Keeps the temporary database after the command finishes or fails.

Example:

```bash
php dataveil.phar backup:anonymize configuration.yaml \
    --input backup.sql \
    --output backup.anonymized.sql \
    --temp-db dataveil_tmp_backup \
    --keep-temp
```

Use this only for debugging: it allows manual inspection of the imported or anonymized database. Without this flag, the command should clean up the temporary database automatically.

When `--keep-temp` is used, the operator is responsible for deleting the temporary database manually after inspection:

```sql
DROP DATABASE dataveil_tmp_backup;
```

### Safety protections

Backup anonymization must include protections against accidentally importing into, modifying, exporting from, or dropping a production database.

Required protections:

- `--temp-db` must be explicitly provided. The command must not generate or infer a production-like database name.
- `--temp-db` must not be empty.
- `--temp-db` must not match dangerous names such as `bitrix`, `b24`, `prod`, `production`, `sitemanager`, `main`, `default`, `mysql`, `information_schema`, `performance_schema`, or `sys`.
- `--temp-db` must not be equal to the database configured as the main/live database.
- `--temp-db` should preferably start with a safe prefix such as `dataveil_tmp_`.
- The command must fail if the temporary database already exists, unless a future explicit reset option is added.
- The command must print the resolved input path, output path, and temporary database name before starting destructive operations.
- The command must never drop any database that it did not create during the current run.
- The command must never modify `--input`.
- The command must fail if `--output` equals `--input`.
- The command must fail if `--output` already exists, unless a future explicit overwrite option is added.
- Cleanup must target only the temporary database created by the command.
- On failure, cleanup should still run unless `--keep-temp` is enabled.

These protections are part of the command contract. They should be covered by automated tests before the command is considered ready for production use.
