<?php
/**
 * This file contains only the AdminStats class.
 */

namespace Xtools;

use Symfony\Component\DependencyInjection\Container;
use DateTime;

/**
 * AdminStats returns information about users with administrative
 * rights on a given wiki.
 */
class AdminStats extends Model
{
    /** @var string[] Keyed by user name, values are arrays containing actions and counts. */
    protected $adminStats;

    /** @var string[] Keys are user names, values are their abbreviated user groups. */
    protected $adminsAndGroups = [];

    /** @var int Number of admins who haven't made any actions within the time period. */
    protected $adminsWithoutActions = 0;

    /** @var int Start of time period as UTC timestamp */
    protected $start;

    /** @var int End of time period as UTC timestamp */
    protected $end;

    /**
     * TopEdits constructor.
     * @param Project $project
     * @param int $start as UTC timestamp.
     * @param int $end as UTC timestamp.
     */
    public function __construct(Project $project, $start = null, $end = null)
    {
        $this->project = $project;
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Get users of the project that are capable of making 'admin actions',
     * keyed by user name with abbreviations for the user groups as the values.
     * @see Project::getAdmins()
     * @return string[]
     */
    public function getAdminsAndGroups()
    {
        if ($this->adminsAndGroups) {
            return $this->adminsAndGroups;
        }

        /**
         * Each user group that is considered capable of making 'admin actions'.
         * @var string[]
         */
        $adminGroups = $this->getRepository()->getAdminGroups($this->project);

        /** @var string[] Keys are the usernames, values are thier user groups. */
        $admins = $this->project->getUsersInGroups($adminGroups);

        /**
         * Keys are the database-stored names, values are the abbreviations.
         * FIXME: i18n this somehow.
         * @var string[]
         */
        $userGroupAbbrMap = [
            'sysop' => 'A',
            'bureaucrat' => 'B',
            'steward' => 'S',
            'checkuser' => 'CU',
            'oversight' => 'OS',
            'bot' => 'Bot',
        ];

        foreach ($admins as $admin => $groups) {

            $abbrGroups = [];

            foreach ($groups as $group) {
                if (isset($userGroupAbbrMap[$group])) {
                    $abbrGroups[] = $userGroupAbbrMap[$group];
                }
            }

            // Make 'A' (admin) come before 'CU' (CheckUser), etc.
            sort($abbrGroups);

            $this->adminsAndGroups[$admin] = implode('/', $abbrGroups);
        }

        return $this->adminsAndGroups;
    }

    /**
     * The number of days we're spanning between the start and end date.
     * @return int
     */
    public function numDays()
    {
        return ($this->end - $this->start) / 60 / 60 / 24;
    }

    public function prepareStats()
    {
        if (isset($this->adminStats)) {
            return $this->adminStats;
        }

        // UTC to YYYYMMDDHHMMSS.
        $startDb = date('Ymd000000', $this->start);
        $endDb = date('Ymd235959', $this->end);

        $stats = $this->getRepository()->getStats($this->project, $startDb, $endDb);

        // Group by username.
        $stats = $this->groupAdminStatsByUsername($stats);

        $this->adminStats = $stats;
        return $this->adminStats;
    }

    public function getStats()
    {
        if (isset($this->adminStats)) {
            $this->adminStats = $this->prepareStats();
        }
        return $this->adminStats;
    }

    /**
     * Given the data returned by AdminStatsRepository::getStats,
     * return the stats keyed by user name, adding in a key/value for user groups.
     * @param  string[] $data As retrieved by AdminStatsRepository::getStats
     * @return string[]       Stats keyed by user name.
     * Functionality covered in test for self::getStats().
     * @codeCoverageIgnore
     */
    private function groupAdminStatsByUsername($data)
    {
        $adminsAndGroups = $this->getAdminsAndGroups();
        $users = [];

        foreach ($data as $datum) {
            $username = $datum['user_name'];

            // Push to array containing all users with admin actions.
            $users[$username] = $datum;

            // Set the 'groups' property with the user groups they belong to (if any),
            // going off of self::getAdminsAndGroups().
            if (isset($adminsAndGroups[$username])) {
                $users[$username]['groups'] = $adminsAndGroups[$username];

                // Remove from actual admin list so later we can re-populate with zeros.
                unset($adminsAndGroups[$username]);
            } else {
                $users[$username]['groups'] = '';
            }

            if ($users[$username]['mtotal'] === 0) {
                $this->adminsWithoutActions++;
            }
        }

        // Push any inactive admins back to $users with zero values.
        $users = $this->fillInactiveAdmins($users, $adminsAndGroups);

        return $users;
    }

    private function fillInactiveAdmins($users, $adminsAndGroups)
    {
        foreach ($adminsAndGroups as $username => $groups) {
            $users[$username] = [
                'user_name' => $username,
                'mdelete' => 0,
                'mrestore' => 0,
                'mblock' => 0,
                'munblock' => 0,
                'mprotect' => 0,
                'munprotect' => 0,
                'mrights' => 0,
                'mimport' => 0,
                'mtotal' => 0,
                'groups' => $groups,
            ];
            $this->adminsWithoutActions++;
        }

        return $users;
    }

    /**
     * Get the formatted start date.
     * @return string
     */
    public function getStart()
    {
        return date('Y-m-d', $this->start);
    }

    /**
     * Get the formatted end date.
     * @return string
     */
    public function getEnd()
    {
        return date('Y-m-d', $this->end);
    }

    /**
     * Get the total number of admins (users currently with qualifying permissions).
     * @return int
     */
    public function numAdmins()
    {
        return count($this->getAdminsAndGroups());
    }

    /**
     * Get the total number of users we're reporting as having made admin actions.
     * @return int
     */
    public function numUsers()
    {
        return count($this->adminStats);
    }

    /**
     * Number of admins who did make actions within the time period.
     * @return int
     */
    public function getNumAdminsWithActions()
    {
        return $this->numAdmins() - $this->adminsWithoutActions;
    }

    /**
     * Number of admins who did not make any actions within the time period.
     * @return int
     */
    public function getNumAdminsWithoutActions()
    {
        return $this->adminsWithoutActions;
    }
}
