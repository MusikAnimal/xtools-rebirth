<?php
/**
 * This file contains only the AdminStatsRepository class.
 */

namespace Xtools;

use DateInterval;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;

/**
 * AdminStatsRepository is responsible for retrieving data from the database
 * about users with administrative rights on a given wiki.
 */
class AdminStatsRepository extends Repository
{
    const ADMIN_PERMISSIONS = [
        'block',
        'delete',
        'deletedhistory',
        'deletedtext',
        'deletelogentry',
        'deleterevision',
        'editinterface',
        'globalblock',
        'hideuser',
        'protect',
        'suppressionlog',
        'suppressrevision',
        'undelete',
        'userrights',
    ];

    /**
     * Core function to get statistics about users who have admin-like permissions.
     * @param  Project $project
     * @param  string  $start SQL-ready format.
     * @param  string  $end
     * @return string[] with keys 'user_name', 'user_id', mdelete', 'mrestore', 'mblock',
     *   'munblock', 'mprotect', 'munprotect', 'mrights', 'mimport', and 'mtotal'.
     */
    public function getStats(Project $project, $start, $end)
    {
        $cacheKey = 'adminstats.'.$project->getDatabaseName();
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $userTable = $project->getTableName('user');
        $loggingTable = $project->getTableName('logging', 'userindex');
        $userGroupsTable = $project->getTableName('user_groups');
        $ufgTable = $project->getTableName('user_former_groups');

        $adminGroups = join(array_map(function ($group) {
            return "'$group'";
        }, $this->getAdminGroups($project)), ',');

        $sql = "SELECT user_name, user_id,
                    SUM(IF( (log_type = 'delete'  AND log_action != 'restore'),1,0)) AS mdelete,
                    SUM(IF( (log_type = 'delete'  AND log_action  = 'restore'),1,0)) AS mrestore,
                    SUM(IF( (log_type = 'block'   AND log_action != 'unblock'),1,0)) AS mblock,
                    SUM(IF( (log_type = 'block'   AND log_action  = 'unblock'),1,0)) AS munblock,
                    SUM(IF( (log_type = 'protect' AND log_action != 'unprotect'),1,0)) AS mprotect,
                    SUM(IF( (log_type = 'protect' AND log_action  = 'unprotect'),1,0)) AS munprotect,
                    SUM(IF( log_type  = 'rights',1,0)) AS mrights,
                    SUM(IF( log_type  = 'import',1,0)) AS mimport,
                    SUM(IF(log_type  != '',1,0)) AS mtotal
                FROM $loggingTable
                JOIN $userTable ON user_id = log_user
                WHERE log_timestamp > '$start' AND log_timestamp <= '$end'
                  AND log_type IS NOT NULL
                  AND log_action IS NOT NULL
                  AND log_type IN ('block', 'delete', 'protect', 'import', 'rights')
                GROUP BY user_name
                HAVING mdelete > 0 OR user_id IN (
                    # Make sure they were at some point were in a qualifying user group.
                    SELECT ug_user
                    FROM $userGroupsTable
                    WHERE ug_group IN ($adminGroups)
                    UNION
                    SELECT ufg_user
                    FROM $ufgTable
                    WHERE ufg_group IN ($adminGroups)
                )
                ORDER BY mtotal DESC";

        $results = $this->getProjectsConnection()->query($sql)->fetchAll();

        // Cache for 10 minutes.
        $cacheItem = $this->cache
            ->getItem($cacheKey)
            ->set($results)
            ->expiresAfter(new DateInterval('PT10M'));
        $this->cache->save($cacheItem);

        return $results;
    }

    // /**
    //  * Get the user IDs of all current and former admins. This is used in self::getStats().
    //  * @fixme: Do we even need this? We could include in getStats()
    //  * @return int[] The user IDs.
    //  */
    // private function getAdminIds(Project $project)
    // {
    //     $userGroupsTable = $project->getTableName('user_groups');
    //     $ufgTable = $project->getTableName('user_former_groups');
    //     $sql = "SELECT ug_user AS user_id
    //             FROM $userGroupsTable
    //             WHERE ug_group = 'sysop'
    //             UNION
    //             SELECT ufg_user AS user_id
    //             FROM $ufgTable
    //             WHERE ufg_group = 'sysop'";
    //     return $this->getProjectsConnection()->query($sql)->fetchColumn();
    // }

    /**
     * Get all user groups with admin-like permissions.
     * @param  Project $project
     * @return array Each entry contains 'name' (user group) and 'rights' (the permissions).
     */
    public function getAdminGroups(Project $project)
    {
        $cacheKey = 'admingroups.'.$project->getDatabaseName();
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $userGroups = [];

        $params = [
            'meta' => 'siteinfo',
            'siprop' => 'usergroups',
        ];
        $api = $this->getMediawikiApi($project);
        $query = new SimpleRequest('query', $params);
        $res = $api->getRequest($query);

        // If there isn't a usergroups hash than let it error out...
        // Something else must have gone horribly wrong.
        foreach($res['query']['usergroups'] as $userGroup) {
            // If they are able to add and remove user groups,
            // we'll treat them as having the 'userrights' permission.
            if (isset($userGroup['add']) || isset($userGroup['remove'])) {
                array_push($userGroup['rights'], 'userrights');
            }

            if (count(array_intersect($userGroup['rights'], self::ADMIN_PERMISSIONS)) > 0) {
                $userGroups[] = $userGroup['name'];
            }
        }

        // Cache for a week.
        $cacheItem = $this->cache
            ->getItem($cacheKey)
            ->set($userGroups)
            ->expiresAfter(new DateInterval('P7D'));
        $this->cache->save($cacheItem);

        return $userGroups;
    }
}
