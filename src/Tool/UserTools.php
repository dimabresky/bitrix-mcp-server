<?php

declare(strict_types=1);

namespace BitrixMcp\Tool;

use BitrixMcp\Audit\AuditLogger;
use BitrixMcp\Auth\TokenAuthenticator;
use BitrixMcp\Service\UserService;
use Mcp\Capability\Attribute\McpTool;

final class UserTools extends AbstractToolHandler
{
    public function __construct(
        TokenAuthenticator $auth,
        AuditLogger $audit,
        private readonly UserService $service,
    ) {
        parent::__construct($auth, $audit);
    }

    #[McpTool(
        name: 'user_list',
        description: 'List users. filter_json is a flat object: ID, LOGIN, EMAIL, NAME, LAST_NAME, SECOND_NAME, ACTIVE, PERSONAL_PHONE, XML_ID. Password and checkword are never returned.',
    )]
    public function userList(
        ?string $filter_json = null,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        return $this->run('user_list', [
            'filter_json' => $filter_json,
            'limit' => $limit,
            'offset' => $offset,
        ], fn () => [
            'items' => $this->service->listUsers($this->decodeJsonObject($filter_json), $limit, $offset),
        ]);
    }

    #[McpTool(name: 'user_get', description: 'Get one user and GROUP_ID. Does not return password or checkword.')]
    public function userGet(int $user_id): array
    {
        return $this->run('user_get', [
            'user_id' => $user_id,
        ], fn () => $this->service->getUser($user_id));
    }

    #[McpTool(
        name: 'user_add',
        description: 'Create a user. fields_json requires LOGIN, EMAIL, PASSWORD. Optional: NAME, LAST_NAME, SECOND_NAME, ACTIVE (Y/N), PERSONAL_PHONE, WORK_PHONE, PERSONAL_MOBILE, XML_ID, CONFIRM_PASSWORD, GROUP_ID (array of ids; replaces groups).',
    )]
    public function userAdd(string $fields_json): array
    {
        $fields = $this->decodeJsonObject($fields_json);

        return $this->run('user_add', [
            'fields' => $this->fieldNames($fields),
        ], fn () => $this->service->addUser($fields));
    }

    #[McpTool(
        name: 'user_update',
        description: 'Patch a user. Same fields as user_add. GROUP_ID replaces the user groups; omit it to keep them. Cannot change service_user_id.',
    )]
    public function userUpdate(int $user_id, string $fields_json): array
    {
        $fields = $this->decodeJsonObject($fields_json);

        return $this->run('user_update', [
            'user_id' => $user_id,
            'fields' => $this->fieldNames($fields),
        ], fn () => $this->service->updateUser($user_id, $fields));
    }

    #[McpTool(
        name: 'user_delete',
        description: 'Delete a user. Requires users_delete_enabled in config.php and confirm=true. Cannot delete service_user_id.',
    )]
    public function userDelete(int $user_id, bool $confirm = false): array
    {
        if (!$confirm) {
            throw new \InvalidArgumentException('Set confirm=true to delete a user.');
        }

        return $this->run('user_delete', [
            'user_id' => $user_id,
            'confirm' => $confirm,
        ], fn () => $this->service->deleteUser($user_id));
    }

    #[McpTool(name: 'group_list', description: 'List Bitrix user groups: ID, NAME, STRING_ID, ACTIVE.')]
    public function groupList(): array
    {
        return $this->run('group_list', [], fn () => [
            'items' => $this->service->listGroups(),
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private function fieldNames(array $fields): array
    {
        return array_map('strval', array_keys($fields));
    }
}
