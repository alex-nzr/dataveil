# Руководство по конфигурации

## Обзор

Файл `configuration.yaml` определяет, как DataVeil должен анонимизировать вашу базу данных. Конфигурация содержит три основных раздела:

1. **database**: настройки подключения к базе данных.
2. **rules**: простые правила анонимизации.
3. **consistency_groups**: сложные взаимосвязи, требующие согласованности данных.

Рабочий файл обычно называется `configuration.yaml` и не хранится в репозитории, потому что содержит путь к локальному Bitrix24. Пример заполнения хранится в `configuration_example.yaml`.

## Раздел database

Содержит информацию для подключения к базе данных.
В текущей версии в качестве источника поддерживается только `bitrix_settings`.
Он описывается параметрами:
- `settings_file` - путь к файлу конфигурации битрикса 
- `connection_name` - имя соединения с базой данных

```yaml
database:
    source: "bitrix_settings"
    bitrix:
        settings_file: "/home/bitrix/www/bitrix/.settings.php"
        connection_name: "default"
```

## Раздел rules

В разделе описываются простые правила: очистка таблицы или замена простых полей.
В настоящее время в разделе `rules` допустим только один ключ `tables` описывающий правила для таблиц.
Каждое правило состоит из:
- `name` - названия таблицы
- `action` - строка описывающее действие (`truncate`, `update`)
- `comment` - опциональный комментарий для таблицы
- `fields` - описание обезличиваемых столбцов
- `where` - ограничения (SQL WHERE) для `update` действия

### Очистка таблицы

Пример полной очистки таблицы:

```yaml
rules:
    tables:
        - name: "b_search_content"
          action: "truncate"
          comment: "Поисковой индекс"
```

### Замена простых полей таблицы

Заменить конкретные значения столбцов на фейковые данные:

```yaml
rules:
    tables:
        - name: "b_user"
          action: "update"
          fields:
              - column: "EMAIL"
                strategy: "email_fake"
              - column: "LOGIN"
                strategy: "string_random"
                options: { length: 10, prefix: "user_" }
              - column: "NAME"
                strategy: "name_fake"
              - column: "PERSONAL_PHONE"
                strategy: "phone_fake"
          where: "ID NOT IN (1)"
```

В приведенном примере будет произведено простое обезличивание таблицы `b_user` (все записи у которых ID != 1).
К столбцам будут приведены правила:
- `EMAIL` - будет заменен на случайный
- `LOGIN` - будет состоять из 10 символов с префиксом `user_`
- `NAME` - будет заменен на имея 
- `PERSONAL_PHONE` - будет заменен на телефон

## Группы согласованности

Для таблиц со сложными взаимосвязями, где данные должны оставаться синхронизированными.

### Структура

```yaml
consistency_groups:
    - id: "group_name"
      comment: "Описание"
      anchor:
          table: "source_table"
          id_column: "ID"
          context_columns: ["COLUMN1", "COLUMN2"]
          filters:
              - column: "TYPE_ID"
                value: "PHONE"
      generator:
          deterministic: true
          strategy: "phone_fake"
          salt_source: "row_id"
      targets:
          - table: "target_table1"
            column: "field_name"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "target_table2"
            column: "serialized_field"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
            serialization:
                type: "php_serialize"
                match_key: "PHONE"
                match_value_source: "anchor.VALUE"
```

Для serialized-связей используется `join.type: "serialized_value_match"`. Фильтры могут брать значение из anchor-строки через `value_source`, например:

```yaml
join:
    type: "serialized_value_match"
    entity_id_column: "OWNER_ID"
    ref_entity_column: "ENTITY_ID"
    filters:
        - column: "OWNER_TYPE_ID"
          value_source: "anchor.ENTITY_TYPE_ID"
serialization:
    type: "php_serialize"
    match_key: "PHONE"
    match_value_source: "anchor.VALUE"
```

Такой вариант используется для синхронизации телефонов и email из `b_crm_field_multi` со связанными serialized-данными активности.

## Смарт-процессы Bitrix24

Смарт-процессы в Bitrix24 хранятся в таблицах вида `b_crm_dynamic_items_*`. Их набор и пользовательские поля отличаются от портала к порталу, поэтому на текущем этапе они описываются вручную в рабочем `configuration.yaml`.

Минимальный подход:
- добавить правило `update` для нужной таблицы `b_crm_dynamic_items_*`;
- анонимизировать стандартные поля `TITLE`, `XML_ID`, `SOURCE_DESCRIPTION`, `COMMENTS`, если они есть;
- анонимизировать денежные поля `OPPORTUNITY`, `TAX_VALUE`, `OPPORTUNITY_ACCOUNT`, `TAX_VALUE_ACCOUNT` через `amount_fake`;
- очищать чувствительные текстовые `UF_*` поля через `empty` или заменять суммы через `amount_fake`;
- добавить `truncate` для связанной index-таблицы `b_crm_dynamic_items_*_index`.

Пользовательские поля `UF_*` нужно выбирать по смыслу и типу из `b_user_field`. Связи и справочники обычно не анонимизируются:
- `crm`, `iblock_element`, `iblock_section`, `employee`, `file`, `hlblock`, `enumeration` оставляйте без правила, чтобы сохранить привязки;
- строковые и текстовые поля с персональными данными очищайте через `empty` или заменяйте через подходящую стратегию;
- числовые суммы заменяйте через `amount_fake`, обычные числа через `number_fake`;
- даты, флаги, стадии, статусы и источники обычно сохраняйте как техническую структуру данных.

Анонимизатор меняет только поля, явно указанные в `configuration.yaml`. Если поле связи не описано в правилах, его значение остается прежним, и связь с элементом инфоблока, сделкой, контактом, сотрудником или списком сохраняется.

Пример:

```yaml
rules:
    tables:
        - name: "b_crm_dynamic_items_164"
          action: "update"
          fields:
              - column: "TITLE"
                strategy: "string_random"
                options: { length: 12, prefix: "smart_" }
                salt_source: "id"
              - column: "XML_ID"
                strategy: "empty"
              - column: "OPPORTUNITY"
                strategy: "amount_fake"
                options: { min: 1000, max: 1000000, decimals: 2, preserve_sign: true }
                salt_source: "id"
              - column: "UF_CRM_11_SHIPMENT_ADRESS"
                strategy: "empty"

        - name: "b_crm_dynamic_items_164_index"
          action: "truncate"
```

Перед реальным запуском обязательно выполните `anonymize --dry-run`: он проверит существование таблиц, колонок и стратегий и покажет количество строк.

## CRM: адреса, источники и реквизиты

Строковые поля адресов в основных CRM-таблицах, например `ADDRESS`, анонимизируются только если они явно добавлены в `rules.tables[].fields`. В MVP они очищаются через `empty`.

Списочные поля вроде `SOURCE_ID`, статусы и стадии не должны очищаться без отдельного решения: это ссылки на справочники/статусы Bitrix24, поэтому их лучше оставлять неизменными для сохранения структуры воронок, источников и аналитики.

Реквизиты в `b_crm_requisite` и банковские реквизиты в `b_crm_bank_detail` покрываются отдельными правилами. Отдельное хранилище CRM-адресов `b_crm_addr`, если оно есть в базе, покрывается отдельным правилом очистки строковых адресных полей.

### Типовые сценарии

#### Сценарий 1: Простой якорь → цель

```yaml
consistency_groups:
    - id: "phone_numbers"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
          filters:
              - column: "TYPE_ID"
                value: "PHONE"
      generator:
          deterministic: true
          strategy: "phone_fake"
          salt_source: "row_id"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join:
                key_column: "ID"
                ref_column: "ID"
```

#### Сценарий 2: Якорь → несколько целей

```yaml
consistency_groups:
    - id: "email_sync"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
      generator:
          deterministic: true
          strategy: "email_fake"
          salt_source: "row_id"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "b_crm_act_comm"
            column: "ENTITY_SETTINGS"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
                ref_entity_column: "ENTITY_ID"
            serialization:
                type: "php_serialize"
                match_key: "EMAIL"
                match_value_source: "anchor.VALUE"
```

## Параметры стратегии

### Управление детерминизмом

- `salt_source: null` - Случайный вывод при каждом запуске
- `salt_source: "row_id"` - Детерминированный на основе ID строки якоря (рекомендуется)
- `salt_source: "id"` - Детерминированный на основе первичного ключа

### Параметры string_random

```yaml
fields:
    - column: "LOGIN"
      strategy: "string_random"
      options:
          prefix: "user_"
          length: 10
```

Параметры `options` передаются в стратегию при выполнении правила. Для `string_random` сейчас поддерживаются:
- `prefix` - строковый префикс перед сгенерированной частью;
- `length` - длина сгенерированной части без учета префикса.

### Параметры number_fake и amount_fake

`number_fake` генерирует целое число, `amount_fake` - денежное значение с двумя знаками после точки по умолчанию.

```yaml
fields:
    - column: "OPPORTUNITY"
      strategy: "amount_fake"
      options:
          min: 1000
          max: 100000
          decimals: 2
          preserve_sign: true
```

Поддерживаемые параметры:
- `min` - минимальное значение;
- `max` - максимальное значение;
- `decimals` - количество знаков после точки;
- `preserve_sign` - сохранять отрицательный знак исходного значения.

### Должности

Для полей должности, например `POST` в лидах и контактах, используйте стратегию `job_title_fake`.

```yaml
fields:
    - column: "POST"
      strategy: "job_title_fake"
      salt_source: "id"
```

## Полный пример конфигурации

```yaml
version: 1.0

database:
    source: "bitrix_settings"
    bitrix:
        settings_file: "/home/bitrix//www/bitrix/.settings.php"
        connection_name: "default"

rules:
    tables:
        - name: "b_search_content"
          action: "truncate"
          comment: "Индекс поиска - безопасно удалить"

        - name: "b_user"
          action: "update"
          fields:
              - column: "EMAIL"
                strategy: "email_fake"
              - column: "LOGIN"
                strategy: "string_random"
                options: { length: 10, prefix: "user_" }
              - column: "NAME"
                strategy: "name_fake"
              - column: "LAST_NAME"
                strategy: "lastname_fake"
              - column: "PERSONAL_PHONE"
                strategy: "phone_fake"
          where: "ID NOT IN (1)"

consistency_groups:
    - id: "crm_phone_unique"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
          filters:
              - column: "TYPE_ID"
                value: "PHONE"
      generator:
          deterministic: true
          strategy: "phone_fake"
          salt_source: "row_id"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "b_crm_act_comm"
            column: "ENTITY_SETTINGS"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
            serialization:
                type: "php_serialize"
                match_key: "PHONE"
                match_value_source: "anchor.VALUE"

    - id: "crm_email_unique"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
          filters:
              - column: "TYPE_ID"
                value: "EMAIL"
      generator:
          deterministic: true
          strategy: "email_fake"
          salt_source: "row_id"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "b_crm_act_comm"
            column: "ENTITY_SETTINGS"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
            serialization:
                type: "php_serialize"
                match_key: "EMAIL"
                match_value_source: "anchor.VALUE"
```
