# Bitrix MCP Server

MCP-сервер для **чтения и записи** данных в **инфоблоках** и **highload-блоках** Bitrix. Работает по открытому протоколу [Model Context Protocol](https://modelcontextprotocol.io/) (STDIO + JSON-RPC) и **не привязан к конкретному редактору** — подходит любому MCP-клиенту.

Типичная схема: MCP-клиент на локальной машине запускает `server.php` на удалённом сервере с Bitrix через **SSH + STDIO**. Ниже в качестве **примера** настройки показан Cursor; тот же конфиг (`command`, `args`, `env`) переносится в Claude Desktop, VS Code с MCP, Cursor SDK и другие совместимые клиенты.

Развёртывание на сервере: например `/var/www/site/local/mcp/`.

Реализация API следует [документации по инфоблокам](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html) (D7 ORM + классический API там, где это требуется). См. также [AGENTS.md](AGENTS.md).

## Требования

- PHP >= 8.1 на сервере с Bitrix
- Модули Bitrix: `iblock`, `highloadblock`
- У инфоблоков из whitelist должен быть задан **API_CODE** («Символьный код API»)
- SSH-доступ с вашей машины на сервер

## Установка на сервере

```bash
cd /var/www/site/local
git clone https://github.com/dimabresky/bitrix-mcp-server.git mcp
cd mcp
composer install --no-dev
cp config.sample.php config.php
# Отредактируйте config.php: service_user_id, allowed_iblocks, allowed_hlblocks
mkdir -p logs && chmod 755 logs
```

Задайте надёжный токен на сервере (например, в `~/.bashrc` или unit systemd):

```bash
export MCP_AUTH_TOKEN="your-secret-token"
```

## config.php

Скопируйте из `config.sample.php`. Основные параметры:

| Ключ | Описание |
|------|----------|
| `service_user_id` | ID пользователя Bitrix для `$USER->Authorize()` — нужны права на iblock/HL из whitelist |
| `allowed_iblocks` | Массив ID инфоблоков |
| `allowed_hlblocks` | Массив ID highload-блоков |
| `auth_token` | Должен совпадать с переменной окружения `MCP_AUTH_TOKEN` |

## Подключение MCP-клиента (пример: Cursor)

Любой MCP-клиент с поддержкой STDIO подключается одинаково: локально запускается `ssh … php …/server.php`, обмен идёт через stdin/stdout.

**Пример для Cursor** — добавьте в настройки (`mcpServers`):

```json
{
  "mcpServers": {
    "bitrix-data": {
      "command": "ssh",
      "args": [
        "deploy@your-server",
        "php",
        "/var/www/site/local/mcp/server.php"
      ],
      "env": {
        "DOCUMENT_ROOT": "/var/www/site",
        "MCP_AUTH_TOKEN": "your-secret-token"
      }
    }
  }
}
```

На Windows убедитесь, что `ssh` работает из PowerShell. Опционально — алиас в `~/.ssh/config`:

```
Host bitrix-staging
    HostName staging.example.com
    User deploy
    IdentityFile ~/.ssh/id_ed25519
```

Тогда в `args` вместо `"deploy@your-server"` можно указать `"bitrix-staging"`.

**Другие клиенты:** скопируйте блок `bitrix-data` в конфиг вашего MCP-клиента (например, `claude_desktop_config.json` у Claude Desktop) — структура `command` / `args` / `env` та же.

Для разработки и сверки API в Cursor (или другом клиенте) дополнительно можно держать включённым MCP **`bitrix`** — это отдельный сервер документации ядра, не путать с `bitrix-data`.

## Инструменты (tools)

### Инфоблоки

| Tool | Описание |
|------|----------|
| `iblock_list` | Список инфоблоков из whitelist |
| `iblock_schema` | Свойства, enum, подсказки по формату ввода |
| `iblock_sections_list` | Разделы (`filter_json`) |
| `iblock_elements_list` | Элементы (`filter_json`, `select_json`) |
| `iblock_element_get` | Элемент со всеми свойствами |
| `iblock_element_add` | `fields_json`, `properties_json` |
| `iblock_element_update` | Частичное обновление полей и свойств |
| `iblock_element_delete` | Требует `confirm: true` |

### Highload-блоки

| Tool | Описание |
|------|----------|
| `hlblock_list` | HL-блоки из whitelist |
| `hlblock_schema` | Поля ORM и UF |
| `hlblock_records_list` | Список записей |
| `hlblock_record_get` | Одна запись |
| `hlblock_record_add` | `fields_json` с ключами `UF_*` |
| `hlblock_record_update` | Частичное обновление записи |
| `hlblock_record_delete` | Требует `confirm: true` |

## Ограничения: свойства инфоблока

Два разных уровня — не путать:

| Уровень | Что это | Статус в v1 |
|---------|---------|-------------|
| **Значения свойств на элементах** | `properties_json` в `iblock_element_add` / `iblock_element_update`, блок `PROPERTIES` в `iblock_element_get` | Поддерживается |
| **Определения свойств инфоблока** | Создание/изменение/удаление свойств (`CIBlockProperty`), enum (`CIBlockPropertyEnum`) | **Не поддерживается** |

### Что можно

- `iblock_schema` — **только чтение** списка свойств, типов и enum
- Запись **значений** свойств при создании/обновлении элементов (`iblock_element_*`)

### Чего нет в v1

- `iblock_property_add`, `iblock_property_update`, `iblock_property_delete`
- Добавление/изменение/удаление вариантов списка (`CIBlockPropertyEnum::Add` и т.д.)
- Файловые свойства `F` на элементах

Ответ `iblock_schema` содержит блок `limitations` с тем же пояснением для агента.

Для HL-блоков: запись значений `UF_*` в записях (`hlblock_record_*`) поддерживается; отдельные tools для изменения **структуры** HL-блока (добавление UF-полей) не предусмотрены.

## Форматы значений свойств (запись)

| Тип | Формат |
|-----|--------|
| Строка `S` | `"VALUE"` |
| Число `N` | `"123.45"` |
| Список `L` | **ID** значения enum (целое число) |
| Привязка к элементу `E` | ID связанного элемента |
| Привязка к разделу `G` | ID раздела |
| Справочник (HL) | строка `UF_XML_ID` |
| HTML (`USER_TYPE` HTML) | HTML-строка |
| Множественное | JSON-массив `["a","b"]` |

## Примеры вызовов

**Список инфоблоков:**

```json
{ "type": "catalog" }
```

**Создание элемента:**

```json
{
  "iblock_id": 5,
  "fields_json": "{\"NAME\":\"Test\",\"CODE\":\"test-item\",\"ACTIVE\":\"Y\"}",
  "properties_json": "{\"AUTHOR\":\"Agent\",\"TAGS\":[\"news\",\"2026\"]}"
}
```

**Добавление записи HL:**

```json
{
  "hlblock_id": 3,
  "fields_json": "{\"UF_NAME\":\"Brand\",\"UF_XML_ID\":\"brand-1\"}"
}
```

## Проверка

Локально (Bitrix не нужен):

```bash
php scripts/verify-structure.php
```

На staging после деплоя:

1. `ssh deploy@server "DOCUMENT_ROOT=/var/www/site MCP_AUTH_TOKEN=... php /var/www/site/local/mcp/server.php"` — процесс должен ждать stdin (в stdout не должно быть лишнего вывода)
2. В MCP-клиенте (например, Cursor): вызовите `iblock_list` и `hlblock_list`
3. `iblock_schema` для инфоблока из whitelist — проверьте наличие `API_CODE`
4. Тест записи: `iblock_element_add` → `iblock_element_get` → `iblock_element_update` → `iblock_element_delete` с `confirm: true`
5. Проверьте `logs/audit.log` на сервере

## Безопасность

- Только сущности из whitelist; остальные ID отклоняются
- На каждый вызов tool нужен `MCP_AUTH_TOKEN`
- Сервисный пользователь Bitrix, без `NOT_CHECK_PERMISSIONS`
- Удаление только с явным `confirm: true`
- Аудит всех вызовов tools

## Документация

- [API инфоблоков](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html)
- [Обзор / API_CODE](https://docs.1c-bitrix.ru/pages/modules/iblocks/overview.html)
- [Производительность](https://docs.1c-bitrix.ru/pages/modules/iblocks/performance.html)
- [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk)
