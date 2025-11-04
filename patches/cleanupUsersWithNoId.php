<?php

/** THIS SCRIPT IS PATCHED; I DON'T KNOW WHAT EXACTLY! */

/**
 * Cleanup tables that have valid usernames with no user ID
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script that cleans up tables that have valid usernames with no
 * user ID.
 *
 * @ingroup Maintenance
 * @since 1.31
 */
class CleanupUsersWithNoId extends LoggedUpdateMaintenance
{
  private $prefix, $table, $assign, $dryRun, $verbose;
  private $triedCreations = [];
  private $trackedUserIds = [];

  public function __construct()
  {
    parent::__construct();
    $this->addDescription('Cleans up tables that have valid usernames with no user ID');
    $this->addOption('prefix', 'Interwiki prefix to apply to the usernames', true, true, 'p');
    $this->addOption('table', 'Only clean up this table', false, true);
    $this->addOption('assign', 'Assign edits to existing local users if they exist', false, false);
    $this->addOption('dry-run', 'Don\'t make any chanes, just report what would have been done.', false, false);
    $this->addOption('verbose', 'Display details about what changes are being done.', false, false);
    $this->setBatchSize(100);
  }

  protected function getUpdateKey()
  {
    return __CLASS__;
  }

  protected function doDBUpdates()
  {
    $this->prefix = $this->getOption('prefix');
    $this->table = $this->getOption('table', null);
    $this->assign = $this->getOption('assign');
    $this->dryRun = $this->getOption('dry-run');
    $this->verbose = $this->getOption('verbose');

    $this->cleanup(
      'revision',
      'rev_id',
      'rev_user',
      'rev_user_text',
      [],
      ['rev_timestamp', 'rev_id']
    );
    $this->cleanup(
      'archive',
      'ar_id',
      'ar_user',
      'ar_user_text',
      [],
      ['ar_id']
    );
    $this->cleanup(
      'logging',
      'log_id',
      'log_user',
      'log_user_text',
      [],
      ['log_timestamp', 'log_id']
    );
    $this->cleanup(
      'image',
      'img_name',
      'img_user',
      'img_user_text',
      [],
      ['img_timestamp', 'img_name']
    );
    $this->cleanup(
      'oldimage',
      ['oi_name', 'oi_timestamp'],
      'oi_user',
      'oi_user_text',
      [],
      ['oi_name', 'oi_timestamp']
    );
    $this->cleanup(
      'filearchive',
      'fa_id',
      'fa_user',
      'fa_user_text',
      [],
      ['fa_id']
    );
    $this->cleanup(
      'ipblocks',
      'ipb_id',
      'ipb_by',
      'ipb_by_text',
      [],
      ['ipb_id']
    );
    $this->cleanup(
      'recentchanges',
      'rc_id',
      'rc_user',
      'rc_user_text',
      [],
      ['rc_id']
    );

    return true;
  }

  /**
   * Calculate a "next" condition and progress display string
   * @param IDatabase $dbw
   * @param string[] $indexFields Fields in the index being ordered by
   * @param object $row Database row
   * @return string[] [ string $next, string $display ]
   */
  private function makeNextCond($dbw, $indexFields, $row)
  {
    $next = '';
    $display = [];
    for ($i = count($indexFields) - 1; $i >= 0; $i--) {
      $field = $indexFields[$i];
      $display[] = $field . '=' . $row->$field;
      $value = $dbw->addQuotes($row->$field);
      if ($next === '') {
        $next = "$field > $value";
      } else {
        $next = "$field > $value OR $field = $value AND ($next)";
      }
    }
    $display = implode(' ', array_reverse($display));
    return [$next, $display];
  }

  /**
   * Checks if an user id exists on the user table.
   */
  private function userIdExists($dbw, $userId)
  {
    if (isset($this->trackedUserIds[$userId])) {
      return $this->trackedUserIds[$userId];
    }
    $exists = $dbw->selectField(
      ['user'],
      '1',
      ['user_id' => $userId],
      __METHOD__,
      ['LIMIT 1']
    );
    $this->trackedUserIds[$userId] = (bool)$exists;
    return (bool)$exists;
  }

  /**
   * Gets user id from name
   */
  private function getUserIdFromName($name)
  {
    $id = (int)User::idFromName($name);
    if (!$id) {
      // See if any extension wants to create it.
      if (!isset($this->triedCreations[$name])) {
        $this->triedCreations[$name] = true;
        if (!Hooks::run('ImportHandleUnknownUser', [$name])) {
          $id = (int)User::idFromName($name, User::READ_LATEST);
        }
      }
    }
    return $id;
  }

  /**
   * Cleanup a table
   *
   * @param string $table Table to migrate
   * @param string|string[] $primaryKey Primary key of the table.
   * @param string $idField User ID field name
   * @param string $nameField User name field name
   * @param array $conds Query conditions
   * @param string[] $orderby Fields to order by
   */
  protected function cleanup(
    $table,
    $primaryKey,
    $idField,
    $nameField,
    array $conds,
    array $orderby
  ) {
    if ($this->table !== null && $this->table !== $table) {
      return;
    }

    $dbw = $this->getDB(DB_MASTER);
    if (
      !$dbw->fieldExists($table, $idField, __METHOD__) ||
      !$dbw->fieldExists($table, $nameField, __METHOD__)
    ) {
      $this->output("Skipping $table, fields $idField and/or $nameField do not exist\n");
      return;
    }

    $primaryKey = (array)$primaryKey;
    $pkFilter = array_flip($primaryKey);
    $this->output("Beginning cleanup of $table\n");

    $next = '1=1';
    $countUnassigned = 0;
    $countAssigned = 0;
    $countPrefixed = 0;
    $lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
    while (true) {
      // Fetch the rows needing update
      $res = $dbw->select(
        $table,
        array_merge($primaryKey, [$idField, $nameField], $orderby),
        array_merge($conds, [$next]),
        __METHOD__,
        [
          'ORDER BY' => $orderby,
          'LIMIT' => $this->mBatchSize,
        ]
      );
      if (!$res->numRows()) {
        break;
      }

      // Update the existing rows
      foreach ($res as $row) {
        $name = $row->$nameField;
        if ($row->$idField) {
          // Check for possible problematic users with an user ID that we actually
          // have in the database, and break migration scripts
          if (User::isIP($name)) {
            // IP address
            $set = [$idField => 0];
            $counter = &$countUnassigned;
            if ($this->verbose) {
              $this->output(
                "Action: Unassign user id {$row->$idField} for IP address $name\n"
              );
            }
          } else if (!$this->userIdExists($dbw, (int)$row->$idField)) {
            $id = 0;
            if ($this->assign) {
              $id = $this->getUserIdFromName($name);
            }
            if ($id) {
              $set = [$idField => $id];
              $counter = &$countAssigned;
              if ($this->verbose) {
                $this->output(
                  "Action: Assign user id $id for existing user name $name but invalid id {$row->$idField}\n"
                );
              }
            } else {
              $set = [
                $idField => 0,
                $nameField => substr($this->prefix . '>' . $name, 0, 255)
              ];
              $counter = &$countPrefixed;
              if ($this->verbose) {
                $this->output(
                  "Action: Prefix user name $name of unexisting user id {$row->$idField}\n"
                );
              }
            }
          } else {
            continue;
          }
        } else {
          if (!User::isUsableName($name)) {
            continue;
          }
          $id = 0;
          if ($this->assign) {
            $id = $this->getUserIdFromName($name);
          }
          if ($id) {
            $set = [$idField => $id];
            $counter = &$countAssigned;
            if ($this->verbose) {
              $this->output(
                "Action: Assign user id $id for existing user name $name\n"
              );
            }
          } else {
            $set = [$nameField => substr($this->prefix . '>' . $name, 0, 255)];
            $counter = &$countPrefixed;
            if ($this->verbose) {
              $this->output(
                "Action: Prefix user name $name of empty user id\n"
              );
            }
          }
        }

        if (!$this->dryRun) {
          $dbw->update(
            $table,
            $set,
            array_intersect_key((array)$row, $pkFilter) + [
              $idField => $row->$idField,
              $nameField => $name,
            ],
            __METHOD__
          );
          $counter += $dbw->affectedRows();
        } else {
          $counter += (int)$dbw->selectField(
            $table,
            'COUNT(*)',
            array_intersect_key((array)$row, $pkFilter) + [
              $idField => $row->$idField,
              $nameField => $name,
            ],
            __METHOD__
          );
        }
      }

      list($next, $display) = $this->makeNextCond($dbw, $orderby, $row);
      $this->output("... $display\n");
      $lbFactory->waitForReplication();
    }

    $this->output(
      "Completed cleanup, unassigned $countUnassigned, assigned $countAssigned and prefixed $countPrefixed row(s)\n"
    );
  }
}

$maintClass = CleanupUsersWithNoId::class;
require_once RUN_MAINTENANCE_IF_MAIN;
