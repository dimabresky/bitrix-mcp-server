<?php

declare(strict_types=1);

namespace BitrixMcp\Service;

use Bitrix\Main\GroupTable;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;
use BitrixMcp\Config\Config;
use InvalidArgumentException;
use RuntimeException;

final class UserService
{
    private const PUBLIC_FIELDS = [
        'ID',
        'LOGIN',
        'EMAIL',
        'NAME',
        'LAST_NAME',
        'SECOND_NAME',
        'ACTIVE',
        'PERSONAL_PHONE',
    ];

    private const FILTER_FIELDS = [
        'ID',
        'LOGIN',
        'EMAIL',
        'NAME',
        'LAST_NAME',
        'SECOND_NAME',
        'ACTIVE',
        'PERSONAL_PHONE',
        'XML_ID',
    ];

    private const WRITABLE_FIELDS = [
        'LOGIN',
        'EMAIL',
        'NAME',
        'LAST_NAME',
        'SECOND_NAME',
        'ACTIVE',
        'PERSONAL_PHONE',
        'WORK_PHONE',
        'PERSONAL_MOBILE',
        'XML_ID',
        'PASSWORD',
        'CONFIRM_PASSWORD',
        'GROUP_ID',
    ];

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function listUsers(array $filter, ?int $limit, int $offset): array
    {
        $this->assertEnabled();

        $rows = UserTable::getList([
            'filter' => $this->sanitizeFilter($filter),
            'select' => self::PUBLIC_FIELDS,
            'order' => ['ID' => 'DESC'],
            'limit' => $this->config->resolveLimit($limit),
            'offset' => max(0, $offset),
        ]);

        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = $this->mapUser($row);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUser(int $userId): array
    {
        $this->assertEnabled();
        $this->assertUserId($userId);

        $row = UserTable::getList([
            'filter' => ['=ID' => $userId],
            'select' => self::PUBLIC_FIELDS,
            'limit' => 1,
        ])->fetch();

        if (!$row) {
            throw new RuntimeException(sprintf('User %d not found.', $userId));
        }

        $user = $this->mapUser($row);
        $user['GROUP_ID'] = $this->groupIds($userId);

        return $user;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function addUser(array $fields): array
    {
        $this->assertEnabled();
        $prepared = $this->prepareFields($fields, true);

        $user = new \CUser();
        $id = (int) $user->Add($prepared);
        if ($id <= 0) {
            throw new RuntimeException($user->LAST_ERROR ?: 'Failed to add user.');
        }

        return $this->getUser($id);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateUser(int $userId, array $fields): array
    {
        $this->assertEnabled();
        $this->assertUserId($userId);
        $this->assertNotServiceUser($userId, 'change');
        $this->getUser($userId);
        $prepared = $this->prepareFields($fields, false);

        $user = new \CUser();
        if (!$user->Update($userId, $prepared)) {
            throw new RuntimeException($user->LAST_ERROR ?: 'Failed to update user.');
        }

        return $this->getUser($userId);
    }

    /**
     * @return array{deleted: true, ID: int}
     */
    public function deleteUser(int $userId): array
    {
        if (!$this->config->usersDeleteEnabled()) {
            throw new RuntimeException('User deletion is disabled in config.php (users_delete_enabled).');
        }

        $this->assertUserId($userId);
        $this->assertNotServiceUser($userId, 'delete');
        $this->getUser($userId);

        $user = new \CUser();
        if (!$user->Delete($userId)) {
            throw new RuntimeException($user->LAST_ERROR ?: 'Failed to delete user.');
        }

        return ['deleted' => true, 'ID' => $userId];
    }

    /**
     * @return list<array{ID: int, NAME: string, STRING_ID: string, ACTIVE: string}>
     */
    public function listGroups(): array
    {
        $this->assertEnabled();

        $rows = GroupTable::getList([
            'select' => ['ID', 'NAME', 'STRING_ID', 'ACTIVE'],
            'order' => ['C_SORT' => 'ASC', 'ID' => 'ASC'],
        ]);

        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = [
                'ID' => (int) $row['ID'],
                'NAME' => (string) ($row['NAME'] ?? ''),
                'STRING_ID' => (string) ($row['STRING_ID'] ?? ''),
                'ACTIVE' => (string) ($row['ACTIVE'] ?? ''),
            ];
        }

        return $result;
    }

    private function assertEnabled(): void
    {
        if (!$this->config->usersEnabled()) {
            throw new RuntimeException('User tools are disabled in config.php (users_enabled).');
        }
    }

    private function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id must be a positive integer.');
        }
    }

    private function assertNotServiceUser(int $userId, string $action): void
    {
        if ($userId === $this->config->serviceUserId()) {
            throw new RuntimeException(sprintf('Refusing to %s the MCP service user.', $action));
        }
    }

    /**
     * @return list<int>
     */
    private function groupIds(int $userId): array
    {
        $rows = UserGroupTable::getList([
            'filter' => ['=USER_ID' => $userId],
            'select' => ['GROUP_ID'],
            'order' => ['GROUP_ID' => 'ASC'],
        ]);

        $ids = [];
        while ($row = $rows->fetch()) {
            $ids[] = (int) $row['GROUP_ID'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function sanitizeFilter(array $filter): array
    {
        $clean = [];
        foreach ($filter as $key => $value) {
            if (!is_string($key) || !$this->isFilterValue($value)) {
                throw new InvalidArgumentException('User filter must be a flat object of allowed fields.');
            }

            $field = strtoupper((string) preg_replace('/^[^A-Z]+/i', '', $key));
            if (!in_array($field, self::FILTER_FIELDS, true)) {
                throw new InvalidArgumentException('User filter field is not allowed: ' . $key);
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function isFilterValue(mixed $value): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function prepareFields(array $fields, bool $isAdd): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('fields_json is empty.');
        }

        $prepared = [];
        foreach ($fields as $key => $value) {
            $name = strtoupper((string) $key);
            if (!in_array($name, self::WRITABLE_FIELDS, true)) {
                throw new InvalidArgumentException('Field is not allowed: ' . $key);
            }

            if ($name === 'GROUP_ID') {
                $prepared['GROUP_ID'] = $this->groupIdList($value);
                continue;
            }

            if ($name === 'ACTIVE') {
                $active = strtoupper(trim((string) $value));
                if ($active !== 'Y' && $active !== 'N') {
                    throw new InvalidArgumentException('ACTIVE must be Y or N.');
                }
                $prepared['ACTIVE'] = $active;
                continue;
            }

            if (!is_scalar($value) || $value === null) {
                throw new InvalidArgumentException($name . ' must be a scalar.');
            }

            $prepared[$name] = $name === 'PASSWORD' || $name === 'CONFIRM_PASSWORD'
                ? (string) $value
                : trim((string) $value);
        }

        if (($prepared['CONFIRM_PASSWORD'] ?? '') !== '' && ($prepared['PASSWORD'] ?? '') === '') {
            throw new InvalidArgumentException('PASSWORD is required when CONFIRM_PASSWORD is set.');
        }

        if (($prepared['PASSWORD'] ?? '') !== '' && ($prepared['CONFIRM_PASSWORD'] ?? '') === '') {
            $prepared['CONFIRM_PASSWORD'] = $prepared['PASSWORD'];
        }

        foreach (['LOGIN', 'EMAIL', 'PASSWORD'] as $name) {
            if (array_key_exists($name, $prepared) && $prepared[$name] === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }

        if ($isAdd) {
            foreach (['LOGIN', 'EMAIL', 'PASSWORD'] as $name) {
                if (!isset($prepared[$name]) || $prepared[$name] === '') {
                    throw new InvalidArgumentException($name . ' is required.');
                }
            }
        }

        if (
            isset($prepared['PASSWORD'], $prepared['CONFIRM_PASSWORD'])
            && $prepared['PASSWORD'] !== $prepared['CONFIRM_PASSWORD']
        ) {
            throw new InvalidArgumentException('PASSWORD and CONFIRM_PASSWORD must match.');
        }

        return $prepared;
    }

    /**
     * @return list<int>
     */
    private function groupIdList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('GROUP_ID must be an array of group ids.');
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_int($id)) {
                $intId = $id;
            } elseif (is_string($id) && preg_match('/^[1-9]\d*$/', $id) === 1) {
                $intId = (int) $id;
            } else {
                throw new InvalidArgumentException('GROUP_ID must contain positive integers.');
            }
            if ($intId <= 0) {
                throw new InvalidArgumentException('GROUP_ID must contain positive integers.');
            }
            $ids[] = $intId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapUser(array $row): array
    {
        return [
            'ID' => (int) $row['ID'],
            'LOGIN' => (string) ($row['LOGIN'] ?? ''),
            'EMAIL' => (string) ($row['EMAIL'] ?? ''),
            'NAME' => (string) ($row['NAME'] ?? ''),
            'LAST_NAME' => (string) ($row['LAST_NAME'] ?? ''),
            'SECOND_NAME' => (string) ($row['SECOND_NAME'] ?? ''),
            'ACTIVE' => (string) ($row['ACTIVE'] ?? ''),
            'PERSONAL_PHONE' => (string) ($row['PERSONAL_PHONE'] ?? ''),
        ];
    }
}
