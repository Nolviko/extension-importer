<?php

declare(strict_types=1);

namespace NolvikoExt\Importer;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Who may see and read each source forum (importer 1.1.0, P1 "members-only forums became public"). Adds the
 * fields the core import target maps to Nolviko permissions: `guest_view`, `guest_read`, `member_view`,
 * `member_read` (0/1), `readers` (comma-separated source group IDs that may read beyond members), `protected`
 * (the source had a forum password) and `link` (a redirect forum's address).
 *
 * - SMF 2.1: `boards.member_groups` lists the groups that may enter a board (-1 guests, 0 regular members);
 *   entering means seeing and reading. `deny_member_groups` removes groups again. A board with `redirect` is a link.
 * - MyBB 1.8: `forumpermissions` rows per forum and group (canview, canviewthreads), inherited from the nearest
 *   parent in `parentlist` that has rows, else the group defaults in `usergroups`. Guests are gid 1, registered
 *   members gid 2. `forums.password` marks a protected forum, `forums.linkto` a link.
 * - phpBB 3.3: `acl_groups` with options `f_list` and `f_read`, directly or through `acl_roles_data`; NEVER (-1)
 *   wins over YES. Guests and registered members are the GUESTS and REGISTERED system groups. `forum_password`
 *   marks a protected forum, `forum_link` (forum type 2) a link.
 *
 * When the permission tables cannot be read, the fields are left out and the core keeps its safe default (the
 * forum follows its parent and the site default); no forum is opened wider than the source had it.
 */
final class ForumAccess
{
    /**
     * @param Closure(string): string $table Quoted, prefixed table name.
     * @param list<array<string, mixed>> $rows Forum rows with `id` (and `parent` for MyBB inheritance).
     * @return list<array<string, mixed>>
     */
    public static function enrich(Connection $db, string $engine, Closure $table, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        try {
            $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $access = match ($engine) {
                'smf' => self::smf($db, $table, $ids),
                'mybb' => self::mybb($db, $table, $ids),
                'phpbb' => self::phpbb($db, $table, $ids),
                default => [],
            };
        } catch (Throwable) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $row += $access[(int) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, int|string>>
     */
    private static function smf(Connection $db, Closure $table, array $ids): array
    {
        $result = [];
        foreach (
            $db->fetchAllAssociative('SELECT id_board, member_groups, deny_member_groups, redirect FROM '
                . $table('boards') . ' WHERE id_board IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $board
        ) {
            $allowed = self::list((string) $board['member_groups']);
            $denied = self::list((string) $board['deny_member_groups']);
            $open = array_values(array_diff($allowed, $denied));
            $guest = in_array(-1, $open, true) ? 1 : 0;
            $member = in_array(0, $open, true) ? 1 : 0;
            $result[(int) $board['id_board']] = ['guest_view' => $guest, 'guest_read' => $guest,
                'member_view' => $member, 'member_read' => $member,
                'readers' => implode(',', array_filter($open, static fn(int $group): bool => $group > 0)),
                'link' => (string) $board['redirect']];
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, int|string>>
     */
    private static function mybb(Connection $db, Closure $table, array $ids): array
    {
        $defaults = [];
        foreach ($db->fetchAllAssociative('SELECT gid, canview, canviewthreads FROM ' . $table('usergroups')) as $g) {
            $defaults[(int) $g['gid']] = [(int) $g['canview'], (int) $g['canviewthreads']];
        }
        $forums = $db->fetchAllAssociative('SELECT fid, parentlist, password, linkto FROM ' . $table('forums')
            . ' WHERE fid IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        $chains = [];
        $needed = [];
        foreach ($forums as $forum) {
            $chain = array_reverse(array_map('intval', explode(',', (string) $forum['parentlist'])));
            $chains[(int) $forum['fid']] = $chain;
            $needed = [...$needed, ...$chain];
        }
        $rules = [];
        if ($needed !== []) {
            $found = $db->fetchAllAssociative(
                'SELECT fid, gid, canview, canviewthreads FROM ' . $table('forumpermissions') . ' WHERE fid IN (?)',
                [array_values(array_unique($needed))],
                [ArrayParameterType::INTEGER],
            );
            foreach ($found as $rule) {
                $rules[(int) $rule['fid']][(int) $rule['gid']] = [
                    (int) $rule['canview'],
                    (int) $rule['canviewthreads'],
                ];
            }
        }
        $result = [];
        foreach ($forums as $forum) {
            $effective = [];
            foreach (array_keys($defaults) as $group) {
                $effective[$group] = $defaults[$group];
                // The nearest forum in the chain (the forum itself first) with a row for the group decides.
                foreach ($chains[(int) $forum['fid']] as $fid) {
                    if (isset($rules[$fid][$group])) {
                        $effective[$group] = $rules[$fid][$group];
                        break;
                    }
                }
            }
            $view = static fn(int $group): int => (int) (($effective[$group][0] ?? 0) === 1);
            $read = static fn(int $group): int => (int) (($effective[$group][0] ?? 0) === 1
                && ($effective[$group][1] ?? 0) === 1);
            $readers = [];
            foreach ($effective as $group => [$canView, $canRead]) {
                if ($group > 2 && $canView === 1 && $canRead === 1) {
                    $readers[] = $group;
                }
            }
            $result[(int) $forum['fid']] = ['guest_view' => $view(1), 'guest_read' => $read(1),
                'member_view' => $view(2), 'member_read' => $read(2), 'readers' => implode(',', $readers),
                'protected' => (string) $forum['password'] !== '' ? 1 : 0, 'link' => (string) $forum['linkto']];
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, int|string>>
     */
    private static function phpbb(Connection $db, Closure $table, array $ids): array
    {
        $options = $db->fetchAllKeyValue('SELECT auth_option_id, auth_option FROM ' . $table('acl_options')
            . " WHERE auth_option IN ('f_list', 'f_read')");
        $optionIds = array_map('intval', array_keys($options));
        $roles = [];
        if ($optionIds !== []) {
            foreach (
                $db->fetchAllAssociative('SELECT role_id, auth_option_id, auth_setting FROM ' . $table('acl_roles_data')
                    . ' WHERE auth_option_id IN (?)', [$optionIds], [ArrayParameterType::INTEGER]) as $data
            ) {
                $roles[(int) $data['role_id']][$options[$data['auth_option_id']]] = (int) $data['auth_setting'];
            }
        }
        $system = $db->fetchAllKeyValue('SELECT group_name, group_id FROM ' . $table('groups')
            . " WHERE group_type = 3 AND group_name IN ('GUESTS', 'REGISTERED')");
        $guests = (int) ($system['GUESTS'] ?? 0);
        $registered = (int) ($system['REGISTERED'] ?? 0);
        $settings = [];
        foreach (
            $db->fetchAllAssociative('SELECT group_id, forum_id, auth_option_id, auth_role_id, auth_setting FROM '
                . $table('acl_groups') . ' WHERE forum_id IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $acl
        ) {
            $granted = (int) $acl['auth_role_id'] > 0 ? ($roles[(int) $acl['auth_role_id']] ?? [])
                : (isset($options[$acl['auth_option_id']])
                    ? [$options[$acl['auth_option_id']] => (int) $acl['auth_setting']] : []);
            foreach ($granted as $option => $setting) {
                $current = $settings[(int) $acl['forum_id']][(int) $acl['group_id']][$option] ?? 0;
                // NEVER (-1) beats YES (1), YES beats NO (0).
                $settings[(int) $acl['forum_id']][(int) $acl['group_id']][$option] = $current === -1 || $setting === -1
                    ? -1 : max($current, $setting);
            }
        }
        $meta = [];
        foreach (
            $db->fetchAllAssociative('SELECT forum_id, forum_password, forum_link FROM ' . $table('forums')
                . ' WHERE forum_id IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $forum
        ) {
            $meta[(int) $forum['forum_id']] = $forum;
        }
        $result = [];
        foreach ($ids as $id) {
            $groups = $settings[$id] ?? [];
            $yes = static fn(int $group, string $option): int => (int) (($groups[$group][$option] ?? 0) === 1);
            $readers = [];
            foreach ($groups as $group => $granted) {
                if (
                    !in_array($group, [$guests, $registered], true) && ($granted['f_list'] ?? 0) === 1
                    && ($granted['f_read'] ?? 0) === 1
                ) {
                    $readers[] = $group;
                }
            }
            $result[$id] = ['guest_view' => $yes($guests, 'f_list'), 'guest_read' => $yes($guests, 'f_read'),
                'member_view' => $yes($registered, 'f_list'), 'member_read' => $yes($registered, 'f_read'),
                'readers' => implode(',', $readers),
                'protected' => (string) ($meta[$id]['forum_password'] ?? '') !== '' ? 1 : 0,
                'link' => (string) ($meta[$id]['forum_link'] ?? '')];
        }

        return $result;
    }

    /** @return list<int> */
    private static function list(string $value): array
    {
        return array_values(array_map('intval', array_filter(
            array_map('trim', explode(',', $value)),
            static fn(string $part): bool => preg_match('/^-?[0-9]{1,9}$/D', $part) === 1,
        )));
    }
}
