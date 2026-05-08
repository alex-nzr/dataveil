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

Shows the anonymization plan without applying changes.

```bash
php dataveil.phar anonymize configuration.yaml --dry-run
```

Use this command to review which rules and consistency groups are configured before running the real anonymization command.

