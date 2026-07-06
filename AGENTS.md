# Agent instructions — bitrix-mcp-server

MCP server exposing Bitrix **infoblocks** and **highload blocks** to AI clients via SSH + STDIO.

## Project location

- Development root: `D:\projects\bitrix-mcp-server`
- Deploy on Bitrix server: e.g. `/var/www/site/local/mcp/`

## API sources (mandatory)

Do **not** copy patterns from customer projects (testmile, etc.). Use only:

1. **@1C-Bitrix** — [docs.1c-bitrix.ru](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html) (ORM + classic layer boundaries)
2. **@1C-Bitrix api** — [dev.1c-bitrix.ru/api_d7](https://dev.1c-bitrix.ru/api_d7/)
3. **MCP `bitrix`** — `searchDocs`, `liveApiGetClassMethods`, `liveApiGetEntityFields` during development

## Implementation rules

- Infoblock **data** CRUD: D7 ORM (`IblockTable::compileEntity`, `Element{ApiCode}Table`, `Section::compileEntityByIblock`)
- Classic API only where docs require: `CIBlockElement::UpdateSearch`, `CIBlockSection::Delete`, property metadata, files (v2)
- HL records: `HighloadBlockTable::compileEntity` + data class `getList` / `add` / `update` / `delete`
- Iblocks without `API_CODE` must return a clear error, not silent legacy fallback
- STDIO: never write to STDOUT except JSON-RPC (use STDERR for logs)
- Write operations: whitelist + service user `Authorize()` + audit log

## Stack

- PHP >= 8.1
- [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk) + `StdioTransport`
