# Bitrix MCP Server

MCP-сервер для **чтения и записи** данных в **инфоблоках** и **highload-блоках** Bitrix.

Работает **только по HTTP** — протокол [Model Context Protocol](https://modelcontextprotocol.io/) (**Streamable HTTP**). Точка входа — обычный PHP-скрипт на том же сайте Bitrix (`public/index.php`). Клиент подключается по **URL** и Bearer-токену; **не нужны** `command` / `args`, stdio и SSH-туннель к `server.php`.

Пример endpoint: `https://site.ru/local/bitrix-mcp-server/public/`

Реализация API следует [документации по инфоблокам](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html) (D7 ORM + классический API там, где это требуется). См. также [AGENTS.md](AGENTS.md).

## Как это работает

```
MCP-клиент (Cursor и др.)
    │  HTTPS POST/GET, JSON-RPC
    │  Authorization: Bearer <auth_token>
    ▼
public/index.php
    ├─ проверка Bearer-токена → 401 без токена
    ├─ BitrixBootstrap: prolog_before, модули iblock/highloadblock, $USER->Authorize()
    ├─ StreamableHttpTransport (mcp/sdk): сессии в каталоге sessions/
    └─ tools → IblockService / HighloadService → Bitrix ORM
         └─ audit.log
```

1. **HTTP-запрос** попадает в `public/index.php` через веб-сервер сайта (Apache, nginx, IIS — как для любого PHP в Bitrix).
2. **Авторизация MCP** — заголовок `Authorization: Bearer …` (или `X-MCP-Token` для отладки). Токен сверяется с `auth_token` в `config.php`.
3. **Bitrix** поднимается через `prolog_before.php`; все операции выполняются от имени `service_user_id`.
4. **MCP-сессии** (initialize, tools/list, tools/call) хранятся в файловом хранилище `session_store_path` — это часть Streamable HTTP, не отдельный процесс.
5. **Whitelist** — tools работают только с ID из `allowed_iblocks` / `allowed_hlblocks`.
6. **Аудит** — каждый вызов tool пишется в `audit_log_path`.

Legacy-режим через `server.php` и stdio **удалён**. Если файл `server.php` есть в каталоге — его нужно убрать.

## Требования

- PHP >= 8.1 на сервере Bitrix (PHP-FPM, mod_php и т.п.)
- Модули Bitrix: `iblock`, `highloadblock`
- У инфоблоков из whitelist задан **API_CODE** («Символьный код API»)
- HTTPS на production/staging
- Каталоги `logs/` и `sessions/` доступны на запись для PHP

## Установка

Каталог размещается в `local/` сайта Bitrix (имя папки произвольное, URL зависит от пути):

```bash
cd /var/www/site/local
git clone https://github.com/dimabresky/bitrix-mcp-server.git bitrix-mcp-server
cd bitrix-mcp-server
composer install --no-dev
cp config.sample.php config.php
# Отредактируйте config.php
mkdir -p logs sessions && chmod 755 logs sessions
```

Endpoint будет доступен по адресу вида:

`https://<домен>/local/bitrix-mcp-server/public/`

## config.php

Скопируйте из `config.sample.php`. Основные параметры:

| Ключ | Описание |
|------|----------|
| `site_id` | Идентификатор сайта (LID), по умолчанию `s1` |
| `service_user_id` | ID пользователя Bitrix для `$USER->Authorize()` — права на iblock/HL из whitelist |
| `auth_token` | Секретный Bearer-токен для MCP-клиентов |
| `allowed_iblocks` | Массив ID инфоблоков |
| `allowed_hlblocks` | Массив ID highload-блоков |
| `max_list_limit` | Лимит записей в list-tools (фактический максимум — **100**) |
| `audit_log_path` | Путь к файлу аудита (например `logs/audit.log`) |
| `session_store_path` | Каталог MCP HTTP-сессий (writable) |
| `session_ttl` | TTL сессий в секундах (по умолчанию 3600) |

`config.php` не коммитить.

## Подключение MCP-клиента

Клиент указывает **URL endpoint** и заголовок с токеном. Формат `command` + `args` (stdio, SSH, локальный PHP-процесс) **не используется**.

### Пример: Cursor

В настройках MCP (`mcpServers`):

```json
{
  "mcpServers": {
    "bitrix-data": {
      "url": "https://staging.example.com/local/bitrix-mcp-server/public/",
      "headers": {
        "Authorization": "Bearer your-secret-token"
      }
    }
  }
}
```

Токен в `Authorization` должен совпадать с `auth_token` в `config.php`.

Для отладки можно передать `X-MCP-Token: your-secret-token` вместо Bearer.

**Другие клиенты** с поддержкой remote MCP (Streamable HTTP): та же пара `url` + `headers`.

Для разработки в Cursor можно дополнительно держать MCP **`bitrix`** — это отдельный сервер **документации ядра**, не путать с `bitrix-data` (данные сайта).

## Инструменты (tools)

### Инфоблоки

| Tool | Описание |
|------|----------|
| `iblock_list` | Список инфоблоков из whitelist |
| `iblock_schema` | Свойства, enum, подсказки по формату ввода |
| `iblock_sections_list` | Разделы (`filter_json`) |
| `iblock_elements_list` | Элементы (`filter_json`, `select_json`) |
| `iblock_element_get` | Элемент по ID; `properties_mode`: `smart_filter` (по умолчанию) или `all`; опционально `section_id` |
| `iblock_smart_filter_schema` | Свойства с `SMART_FILTER=Y` для раздела |
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

| Уровень | Что это | Статус |
|---------|---------|--------|
| **Значения свойств на элементах** | `properties_json` в add/update, `PROPERTIES` в get | Поддерживается |
| **Определения свойств** | `CIBlockProperty`, `CIBlockPropertyEnum` | **Не поддерживается** |

Что **можно**: `iblock_schema` (чтение), запись значений через `iblock_element_*`.

Чего **нет**: `iblock_property_*`, управление enum, файловые свойства `F` на элементах.

Для HL: запись `UF_*` в записях поддерживается; изменение структуры HL-блока — нет.

## Форматы значений свойств (запись)

| Тип | Формат |
|-----|--------|
| Строка `S` | `"VALUE"` |
| Число `N` | `"123.45"` |
| Список `L` | ID значения enum (целое число) |
| Привязка к элементу `E` | ID элемента |
| Привязка к разделу `G` | ID раздела |
| Справочник (HL) | строка `UF_XML_ID` |
| HTML | HTML-строка |
| Множественное | JSON-массив `["a","b"]` |

## Примеры аргументов tools

**Список инфоблоков:**

```json
{ "type": "catalog" }
```

**Элемент каталога (свойства умного фильтра, по умолчанию):**

```json
{
  "iblock_id": 16,
  "element_id": 2449677
}
```

**Все свойства элемента:**

```json
{
  "iblock_id": 16,
  "element_id": 2449677,
  "properties_mode": "all"
}
```

**Схема умного фильтра для раздела:**

```json
{
  "iblock_id": 16,
  "section_id": 6294
}
```

**Создание элемента:**

```json
{
  "iblock_id": 5,
  "fields_json": "{\"NAME\":\"Test\",\"CODE\":\"test-item\",\"ACTIVE\":\"Y\"}",
  "properties_json": "{\"AUTHOR\":\"Agent\",\"TAGS\":[\"news\",\"2026\"]}"
}
```

**Запись HL:**

```json
{
  "hlblock_id": 3,
  "fields_json": "{\"UF_NAME\":\"Brand\",\"UF_XML_ID\":\"brand-1\"}"
}
```

## Проверка

**Локально** (Bitrix не нужен — структура и autoload):

```bash
php scripts/verify-structure.php
```

**На стенде** после деплоя:

1. Без токена — `401`:

```bash
curl -s -o /dev/null -w "%{http_code}" https://staging.example.com/local/bitrix-mcp-server/public/
```

2. С токеном — MCP `initialize`:

```bash
curl -s -X POST https://staging.example.com/local/bitrix-mcp-server/public/ \
  -H "Authorization: Bearer your-secret-token" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

3. В MCP-клиенте: `iblock_list`, `hlblock_list`
4. `iblock_schema` — проверьте `API_CODE` у инфоблока
5. Цепочка записи: add → get → update → delete с `confirm: true`
6. Запись в `logs/audit.log`

## Безопасность

- **HTTPS** на production; endpoint даёт доступ к записи в iblock/HL
- Bearer-токен в каждом HTTP-запросе
- Только сущности из whitelist
- Сервисный пользователь Bitrix через `$USER->Authorize()`, без `NOT_CHECK_PERMISSIONS`
- Удаление только с `confirm: true`
- Аудит всех вызовов tools
- `config.php` с секретами не коммитить

## Документация

- [API инфоблоков](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html)
- [Обзор / API_CODE](https://docs.1c-bitrix.ru/pages/modules/iblocks/overview.html)
- [MCP PHP SDK (Streamable HTTP)](https://github.com/modelcontextprotocol/php-sdk)
