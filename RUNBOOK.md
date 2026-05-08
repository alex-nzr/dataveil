# Runbook: обработка Bitrix24-бэкапа

Документ описывает ручной безопасный порядок подготовки, проверки и восстановления обезличенного бэкапа Bitrix24.

## 1. Подготовить исходный бэкап

1. Создайте бэкап БД штатным механизмом Bitrix24 или подготовьте SQL-дамп другим контролируемым способом.
2. Убедитесь, что исходный файл не находится в production-директории, где его может перезаписать другой процесс.
3. Не меняйте исходный файл вручную. DataVeil читает его только как источник.

Поддерживаемые форматы:
- `.sql` - одиночный SQL-дамп;
- `.tar.gz` - архив БД Bitrix24 с основным SQL и `*_after_connect.sql`.

## 2. Подготовить конфигурацию

1. Создайте рабочий `configuration.yaml` на основе `configuration_example.yaml`.
2. Оставьте активным только один источник подключения:
   - `database.source: bitrix_settings` для работы с установленной копией Bitrix24;
   - `database.source: mysql` для прямого подключения к подготовленной БД.
3. Проверьте, что rules покрывают нужные сущности: пользователей, лиды, контакты, компании, сделки, реквизиты, адреса и смарт-процессы.
4. Для смарт-процессов проверьте таблицы `b_crm_dynamic_items_*` и чувствительные `UF_*` поля конкретного портала.

## 3. Проверить конфигурацию

```bash
php dataveil.phar test:configuration configuration.yaml
php dataveil.phar test:db-connection configuration.yaml
```

Если окружение не содержит `mysql` и `mysqldump` в `PATH`, подготовьте явные пути к бинарникам для backup-команды:

```bash
--mysql-bin "/usr/bin/mysql"
--mysqldump-bin "/usr/bin/mysqldump"
```

## 4. Выполнить dry-run бэкапа

```bash
php dataveil.phar backup:anonymize configuration.yaml \
    --input backup.tar.gz \
    --temp-db dataveil_tmp_project_check \
    --dry-run
```

Dry-run импортирует дамп во временную БД, запускает preflight и удаляет временную БД после проверки. Анонимизация и экспорт результата не выполняются.

Если временная БД уже существует, команда завершится ошибкой и не удалит эту БД.

## 5. Выполнить анонимизацию бэкапа

```bash
php dataveil.phar backup:anonymize configuration.yaml \
    --input backup.tar.gz \
    --output backup.anonymized.tar.gz \
    --temp-db dataveil_tmp_project_run
```

Если `--output` не указан, DataVeil построит имя автоматически, добавив `.anonymized` перед датой Bitrix-дампа.

После успешного запуска:
- исходный backup остаётся без изменений;
- временная БД удаляется;
- результат записывается в `--output`.

## 6. Проверить результат

1. Убедитесь, что output-файл создан и имеет ожидаемый размер.
2. Для `.tar.gz` проверьте состав архива:

```bash
tar -tf backup.anonymized.tar.gz
```

Внутри должны быть переименованные файлы:

```text
bitrix/backup/<name>.anonymized_<timestamp>_sql_<suffix>.sql
bitrix/backup/<name>.anonymized_<timestamp>_sql_<suffix>_after_connect.sql
```

3. Выполните dry-run уже по anonymized-архиву:

```bash
php dataveil.phar backup:anonymize configuration.yaml \
    --input backup.anonymized.tar.gz \
    --temp-db dataveil_tmp_project_verify \
    --dry-run
```

Это проверяет, что новый архив импортируется и соответствует правилам конфигурации.

## 7. Восстановить anonymized-бэкап в тестовую среду

1. Используйте только тестовую или временную среду.
2. Не восстанавливайте anonymized-бэкап поверх production.
3. После восстановления проверьте вход в Bitrix24, CRM-карточки, списки, сделки, контакты, компании и смарт-процессы.
4. Проверьте, что телефоны/email заменены согласованно в карточках и активностях.
5. Проверьте, что связи не сломаны: сделки связаны с контактами/компаниями, UF-ссылки открывают связанные элементы.

## 8. Что делать при ошибке

- Если ошибка возникла до создания временной БД, удалять ничего не нужно.
- Если ошибка возникла после создания временной БД и `--keep-temp` не указан, DataVeil удалит только БД, созданную текущим запуском.
- Если использован `--keep-temp`, временную БД нужно удалить вручную после анализа:

```sql
DROP DATABASE dataveil_tmp_project_run;
```

## 9. Минимальный чек-лист перед передачей

- `test:configuration` проходит.
- `test:db-connection` показывает ожидаемую БД.
- `backup:anonymize --dry-run` проходит на исходном бэкапе.
- Полный `backup:anonymize` создаёт anonymized-файл.
- Anonymized-файл проходит повторный `backup:anonymize --dry-run`.
- Восстановление anonymized-файла проверено в тестовой среде.
