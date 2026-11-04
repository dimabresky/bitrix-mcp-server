# Agent instructions — bitrix-mcp-server

MCP server exposing Bitrix **infoblocks** and **highload blocks** to AI clients via **Streamable HTTP**.

## Размещение

- На сервере Bitrix: `/var/www/site/local/mcp/`
- HTTP entry point: `public/index.php` → `https://site.ru/local/mcp/public/`

## API sources (mandatory)

Do **not** copy patterns from customer projects (testmile, etc.). Use only:

1. **@1C-Bitrix** — [docs.1c-bitrix.ru](https://docs.1c-bitrix.ru/pages/modules/iblocks/api.html) (ORM + classic layer boundaries)
2. **@1C-Bitrix api** — [dev.1c-bitrix.ru/api_d7](https://dev.1c-bitrix.ru/api_d7/)
3. **MCP `bitrix`** — `searchDocs`, `liveApiGetClassMethods`, `liveApiGetEntityFields` during development

## Implementation rules

- Infoblock **data** CRUD: D7 ORM (`IblockTable::compileEntity`, `Element{ApiCode}Table`, `Section::compileEntityByIblock`)
- Classic API only where docs require: `CIBlockElement::UpdateSearch`, `CIBlockSection::Delete`, file properties on elements (v2), `CIBlockProperty::Add` for `iblock_property_add`
- Property definition **update/delete** and enum edits after create are out of scope: no `CIBlockProperty::Update/Delete`, no `CIBlockPropertyEnum` management. Create only via `iblock_property_add` on a whitelisted iblock. Property **values** on elements via `iblock_element_add` / `iblock_element_update`
- HL records: `HighloadBlockTable::compileEntity` + data class `getList` / `add` / `update` / `delete`
- Iblocks without `API_CODE` must return a clear error, not silent legacy fallback
- HTTP: never write to the response body except valid MCP JSON; PHP errors/warnings → `error_log`, not output
- Auth: `Authorization: Bearer <auth_token>` from config.php
- Write operations: whitelist + service user `Authorize()` + audit log
- Users are optional: `users_enabled` registers list/get/add/update/group_list; `users_delete_enabled` (only with the first flag) registers `user_delete`. Reads use `UserTable` / `GroupTable` / `UserGroupTable`; writes use `CUser`. Never return or audit `PASSWORD` / `CHECKWORD`
- Structure create is optional and off by default. `iblock_create_enabled` registers both `iblock_type_add` and `iblock_add` (`CIBlockType::Add` writes `LANG` for every installed language; `CIBlock::Add` stores `VERSION` 2 and `SITE_ID` as a one-element array, and grants `W` to the service user's groups except group 2 unless it is their only group). `hlblock_create_enabled` registers `hlblock_add` (`HighloadBlockTable::add`). New IDs stay out of `allowed_iblocks` / `allowed_hlblocks`. No section create and no UF create

## Stack

- PHP >= 8.1
- [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk) + `StreamableHttpTransport`
- nyholm/psr7, nyholm/psr7-server, laminas-httphandlerrunner
