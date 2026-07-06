# Bitrix MCP Server

MCP server for **read/write** access to Bitrix **infoblocks** and **highload blocks**. Connects from Cursor on your PC to a remote Bitrix server via **SSH + STDIO**.

- Development: `D:\projects\bitrix-mcp-server`
- Deploy on server: e.g. `/var/www/site/local/mcp/`

API implementation follows [Bitrix iblock API docs](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html) (D7 ORM + classic layer where required). See [AGENTS.md](AGENTS.md).

## Requirements

- PHP >= 8.1 on the Bitrix server
- Bitrix modules: `iblock`, `highloadblock`
- Infoblocks in whitelist must have **API_CODE** («Символьный код API») set
- SSH access from your machine to the server

## Install on server

```bash
cd /var/www/site/local
git clone <your-repo-url> mcp
cd mcp
composer install --no-dev
cp config.sample.php config.php
# Edit config.php: service_user_id, allowed_iblocks, allowed_hlblocks
mkdir -p logs && chmod 755 logs
```

Set a strong token on the server (e.g. in `~/.bashrc` or systemd unit):

```bash
export MCP_AUTH_TOKEN="your-secret-token"
```

## config.php

Copy from `config.sample.php`. Important keys:

| Key | Description |
|-----|-------------|
| `service_user_id` | Bitrix user for `$USER->Authorize()` — needs rights on whitelisted iblocks/HL |
| `allowed_iblocks` | Array of iblock IDs |
| `allowed_hlblocks` | Array of HL block IDs |
| `auth_token` | Must match `MCP_AUTH_TOKEN` env var |

## Cursor MCP config

Add to Cursor settings (`mcpServers`):

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

On Windows, ensure `ssh` works from PowerShell. Optional `~/.ssh/config` host alias:

```
Host bitrix-staging
    HostName staging.example.com
    User deploy
    IdentityFile ~/.ssh/id_ed25519
```

Then use `"deploy@your-server"` → `"bitrix-staging"` in args.

Keep MCP **`bitrix`** enabled in Cursor for documentation while developing this server.

## Tools

### Infoblocks

| Tool | Description |
|------|-------------|
| `iblock_list` | List whitelisted iblocks |
| `iblock_schema` | Properties, enums, input hints |
| `iblock_sections_list` | Sections (`filter_json`) |
| `iblock_elements_list` | Elements (`filter_json`, `select_json`) |
| `iblock_element_get` | Full element + properties |
| `iblock_element_add` | `fields_json`, `properties_json` |
| `iblock_element_update` | Patch fields/properties |
| `iblock_element_delete` | Requires `confirm: true` |

### Highload blocks

| Tool | Description |
|------|-------------|
| `hlblock_list` | Whitelisted HL blocks |
| `hlblock_schema` | ORM + UF fields |
| `hlblock_records_list` | Records list |
| `hlblock_record_get` | Single record |
| `hlblock_record_add` | `fields_json` with `UF_*` |
| `hlblock_record_update` | Patch record |
| `hlblock_record_delete` | Requires `confirm: true` |

## Property value formats (write)

| Type | Format |
|------|--------|
| String `S` | `"VALUE"` |
| Number `N` | `"123.45"` |
| List `L` | enum **ID** (integer) |
| Element link `E` | linked element ID |
| Section link `G` | section ID |
| Directory (HL) | `UF_XML_ID` string |
| HTML (`USER_TYPE` HTML) | HTML string |
| Multiple | JSON array `["a","b"]` |

## Example calls

**List iblocks:**

```json
{ "type": "catalog" }
```

**Create element:**

```json
{
  "iblock_id": 5,
  "fields_json": "{\"NAME\":\"Test\",\"CODE\":\"test-item\",\"ACTIVE\":\"Y\"}",
  "properties_json": "{\"AUTHOR\":\"Agent\",\"TAGS\":[\"news\",\"2026\"]}"
}
```

**HL record add:**

```json
{
  "hlblock_id": 3,
  "fields_json": "{\"UF_NAME\":\"Brand\",\"UF_XML_ID\":\"brand-1\"}"
}
```

## Verification

Local (no Bitrix required):

```bash
php scripts/verify-structure.php
```

On staging after deploy:

1. `ssh deploy@server "DOCUMENT_ROOT=/var/www/site MCP_AUTH_TOKEN=... php /var/www/site/local/mcp/server.php"` — should wait on stdin (no stdout garbage)
2. In Cursor: run `iblock_list` and `hlblock_list`
3. `iblock_schema` for a whitelisted iblock — check `API_CODE` present
4. Write test: `iblock_element_add` → `iblock_element_get` → `iblock_element_update` → `iblock_element_delete` with `confirm: true`
5. Check `logs/audit.log` on server

## Security

- Whitelist only; operations on other IDs are rejected
- `MCP_AUTH_TOKEN` required on every tool call
- Service Bitrix user — no `NOT_CHECK_PERMISSIONS`
- Delete requires explicit `confirm: true`
- Audit log for all tool invocations

## Documentation

- [Iblock API](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html)
- [Iblock overview / API_CODE](https://docs.1c-bitrix.ru/pages/modules/iblocks/overview.html)
- [Performance](https://docs.1c-bitrix.ru/pages/modules/iblocks/performance.html)
- [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk)
