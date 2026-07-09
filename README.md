# Bitrix MCP Server

MCP-сервер для **чтения и записи** данных в **инфоблоках** и **highload-блоках** Bitrix. Работает по протоколу [Model Context Protocol](https://modelcontextprotocol.io/) (**Streamable HTTP**) и **не привязан к конкретному редактору** — подходит любому MCP-клиенту с поддержкой URL.

Endpoint размещается на том же сайте Bitrix, например: `https://site.ru/local/mcp/public/`.

Реализация API следует [документации по инфоблокам](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html) (D7 ORM + классический API там, где это требуется). См. также [AGENTS.md](AGENTS.md).

## Требования

- PHP >= 8.1 на сервере с Bitrix (PHP-FPM или Apache mod_php)
- Модули Bitrix: `iblock`, `highloadblock`
- У инфоблоков из whitelist должен быть задан **API_CODE** («Символьный код API»)
- HTTPS на production/staging (рекомендуется обязательно)

## Установка на сервере

```bash
cd /var/www/site/local
git clone https://github.com/dimabresky/bitrix-mcp-server.git mcp
cd mcp
composer install --no-dev
cp config.sample.php config.php
# Отредактируйте config.php: auth_token, service_user_id, allowed_iblocks, allowed_hlblocks
mkdir -p logs sessions && chmod 755 logs sessions
```

## config.php

Скопируйте из `config.sample.php`. Основные параметры:

| Ключ | Описание |
|------|----------|
| `service_user_id` | ID пользователя Bitrix для `$USER->Authorize()` — нужны права на iblock/HL из whitelist |
| `allowed_iblocks` | Массив ID инфоблоков |
| `allowed_hlblocks` | Массив ID highload-блоков |
| `auth_token` | Секретный Bearer-токен для MCP-клиентов |
| `session_store_path` | Каталог для MCP-сессий (writable) |
| `session_ttl` | TTL сессий в секундах (по умолчанию 3600) |

## nginx

Добавьте location (внутри server-блока сайта Bitrix):

```nginx
location ^~ /local/mcp/public/ {
    try_files $uri $uri/ /local/mcp/public/index.php?$query_string;
}
```

Дальше — стандартный `fastcgi_pass` / PHP-FPM, как для остальных PHP-файлов Bitrix.

Опционально ограничьте доступ по IP:

```nginx
location ^~ /local/mcp/public/ {
    allow 10.0.0.0/8;
    deny all;
    try_files $uri $uri/ /local/mcp/public/index.php?$query_string;
}
```

## Подключение MCP-клиента (пример: Cursor)

**Пример для Cursor** — добавьте в настройки (`mcpServers`):

```json
{
  "mcpServers": {
    "bitrix-data": {
      "url": "https://staging.example.com/local/mcp/public/",
      "headers": {
        "Authorization": "Bearer your-secret-token"
      }
    }
  }
}
```

Токен в `Authorization` должен совпадать с `auth_token` в `config.php`.

Альтернатива для отладки: заголовок `X-MCP-Token: your-secret-token`.

**Другие клиенты:** скопируйте блок `bitrix-data` в конфиг вашего MCP-клиента — структура `url` / `headers` та же.

Для разработки и сверки API в Cursor дополнительно можно держать включённым MCP **`bitrix`** — это отдельный сервер документации ядра, не путать с `bitrix-data`.

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

1. Без токена — `401 Unauthorized`:

```bash
curl -s -o /dev/null -w "%{http_code}" https://staging.example.com/local/mcp/public/
```

2. С токеном — MCP initialize (пример):

```bash
curl -s -X POST https://staging.example.com/local/mcp/public/ \
  -H "Authorization: Bearer your-secret-token" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

3. В MCP-клиенте (Cursor): вызовите `iblock_list` и `hlblock_list`
4. `iblock_schema` для инфоблока из whitelist — проверьте наличие `API_CODE`
5. Тест записи: `iblock_element_add` → `iblock_element_get` → `iblock_element_update` → `iblock_element_delete` с `confirm: true`
6. Проверьте `logs/audit.log` на сервере

## Безопасность

- Только **HTTPS** на production; endpoint даёт доступ к записи в iblock/HL
- Bearer-токен в `Authorization` (или `X-MCP-Token` для отладки)
- Только сущности из whitelist; остальные ID отклоняются
- Сервисный пользователь Bitrix, без `NOT_CHECK_PERMISSIONS`
- Удаление только с явным `confirm: true`
- Аудит всех вызовов tools
- `config.php` не коммитить; при необходимости — IP whitelist на nginx

## Документация

- [API инфоблоков](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html)
- [Обзор / API_CODE](https://docs.1c-bitrix.ru/pages/modules/iblocks/overview.html)
- [Производительность](https://docs.1c-bitrix.ru/pages/modules/iblocks/performance.html)
- [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk)
