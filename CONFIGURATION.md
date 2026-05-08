# Руководство по конфигурации

## Обзор

Файл `configuration.yaml` определяет, как DataVeil должен анонимизировать вашу базу данных. Конфигурация содержит три основных раздела:

1. **database**: настройки подключения к базе данных.
2. **rules**: простые правила анонимизации.
3. **consistency_groups**: сложные взаимосвязи, требующие согласованности данных.

Для минимального CRM-сценария Bitrix24 в репозитории есть пример `configuration_bitrix24_crm_minimal.yaml`. Он покрывает пользователей, лиды, контакты, компании, сделки, реквизиты, CRM-индексы и мультиполя телефонов/email.

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
