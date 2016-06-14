<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Dedication block definition
 *
 * @package    block
 * @subpackage dedication
 * @copyright  2008 CICEI http://http://www.cicei.com
 * @author     2008 Borja Rubio Reyes
 *             2011 Aday Talavera Hierro (update to Moodle 2.x)
 *             2016 Planificacion de Entornos Tecnologicos S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Default session time limit in seconds.
define('BLOCK_DEDICATION_DEFAULT_SESSION_LIMIT', 60 * 60);
// Ignore sessions with a duration less than defined value in seconds.
define('BLOCK_DEDICATION_IGNORE_SESSION_TIME', 59);
// Default regeneration time in seconds.
define('BLOCK_DEDICATION_DEFAULT_REGEN_TIME', 60 * 15);

class block_dedication_manager {
    protected $course;
    protected $mintime;
    protected $maxtime;
    protected $limit;

    public function __construct($course, $mintime, $maxtime, $limit) {
        $this->course = $course;
        $this->mintime = $mintime;
        $this->maxtime = $maxtime;
        $this->limit = $limit;
    }

    public function get_students_dedication($students) {
        global $DB;

        $rows = array();
        $userids = array();
        foreach ($students as $user) {
            array_push($userids, $user->id);
        }
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $where = "courseid = :courseid AND userid {$insql} AND timecreated >= :mintime AND timecreated <= :maxtime";

        $queryparams = array(
            'courseid' => $this->course->id,
            'mintime' => $this->mintime,
            'maxtime' => $this->maxtime
        );
        $params = array_merge($queryparams, $inparams);
        $perioddays = ($this->maxtime - $this->mintime) / DAYSECS;

        $logs = $DB->get_recordset_select('logstore_standard_log', $where, $params, 'userid ASC, timecreated ASC',
                'id,userid,timecreated');

        $daysconnected = array();
        $previouslogtime = 0;
        $sessionstart = 0;
        $dedication = 0;
        $previoususerid = -1;
        if ($logs) {
            foreach ($logs as $log) {
                if ($log->userid != $previoususerid) {
                    if ($previoususerid > 0) {
                        $daysconnected = count($daysconnected);
                        $user = self::get_user_from_students($students, $previoususerid);
                        if ($user) {
                            $groups = groups_get_user_groups($this->course->id, $user->id);
                            $group = !empty($groups) && !empty($groups[0]) ? $groups[0][0] : 0;
                            $rows[] = (object)array(
                                'user' => $user,
                                'groupid' => $group,
                                'dedicationtime' => $dedication,
                                'connectionratio' => round($daysconnected / $perioddays, 2),
                            );
                        }
                    }
                    $daysconnected[date('Y-m-d', $log->timecreated)] = 1;
                    $previouslogtime = $log->timecreated;
                    $sessionstart = $log->timecreated;
                    $previoususerid  = $log->userid;
                    $dedication = 0;
                    $daysconnected = array();
                } else {
                    // Another record from the same user.
                    $daysconnected[date('Y-m-d', $log->timecreated)] = 1;
                    if (($log->timecreated - $previouslogtime) > $this->limit) {
                        $dedication += $previouslogtime - $sessionstart;
                        $sessionstart = $log->timecreated;
                    }
                    $previouslogtime = $log->timecreated;
                }
            }

            // Time dedicated on the last user record.
            $dedication += $previouslogtime - $sessionstart;
            // Last user data.
            if ($previoususerid > 0) {
                $daysconnected = count($daysconnected);
                $user = self::get_user_from_students($students, $previoususerid);
                if ($user) {
                    $groups = groups_get_user_groups($this->course->id, $user->id);
                    $group = !empty($groups) && !empty($groups[0]) ? $groups[0][0] : 0;
                    $rows[] = (object)array(
                        'user' => $user,
                        'groupid' => $group,
                        'dedicationtime' => $dedication,
                        'connectionratio' => round($daysconnected / $perioddays, 2),
                    );
                }
            }
        }
        return $rows;
    }

    private static function get_user_from_students($students, $userid) {
        foreach ($students as $user) {
            if ($userid == $user->id) {
                return $user;
            }
        }
        return null;
    }

    public function download_students_dedication($rows) {
        $groups = groups_get_all_groups($this->course->id);

        $headers = array(
            array(
                get_string('sincerow', 'block_dedication'),
                userdate($this->mintime),
                get_string('torow', 'block_dedication'),
                userdate($this->maxtime),
                get_string('perioddiffrow', 'block_dedication'),
                format_time($this->maxtime - $this->mintime),
            ),
            array(''),
            array(
                get_string('firstname'),
                get_string('lastname'),
                get_string('group'),
                get_string('dedicationrow', 'block_dedication') . ' (' . get_string('mins') . ')',
                get_string('dedicationrow', 'block_dedication'),
                get_string('connectionratiorow', 'block_dedication'),
            ),
        );

        foreach ($rows as $index => $row) {
            $rows[$index] = array(
                $row->user->firstname,
                $row->user->lastname,
                isset($groups[$row->groupid]) ? $groups[$row->groupid]->name : '',
                round($row->dedicationtime / MINSECS),
                selfformat_dedication($row->dedicationtime),
                $row->connectionratio,
            );
        }

        $rows = array_merge($headers, $rows);

        return self::generate_download("{$this->course->shortname}_dedication", $rows);
    }

    public function get_user_dedication($user, $simple = false) {
        global $DB;

        $where = 'courseid = :courseid AND userid = :userid AND timecreated >= :mintime AND timecreated <= :maxtime';
        $params = array(
            'courseid' => $this->course->id,
            'userid' => $user->id,
            'mintime' => $this->mintime,
            'maxtime' => $this->maxtime
        );
        $logs = $DB->get_recordset_select('logstore_standard_log', $where, $params, 'timecreated ASC', 'id,timecreated');

        if ($simple) {
            // Return total dedication time in seconds.
            $total = 0;

            if ($logs) {
                $previouslog = array_shift($logs);
                $previouslogtime = $previouslog->timecreated;
                $sessionstart = $previouslogtime;

                foreach ($logs as $log) {
                    if (($log->timecreated - $previouslogtime) > $this->limit) {
                        $dedication = $previouslogtime - $sessionstart;
                        $total += $dedication;
                        $sessionstart = $log->timecreated;
                    }
                    $previouslogtime = $log->timecreated;
                }
                $dedication = $previouslogtime - $sessionstart;
                $total += $dedication;
            }

            return $total;

        } else {
            // Return user sessions with details.
            $rows = array();

            if ($logs) {
                $previouslog = array_shift($logs);
                $previouslogtime = $previouslog->timecreated;
                $sessionstart = $previouslogtime;
                $ips = array($previouslog->ip => true);

                foreach ($logs as $log) {
                    if (($log->timecreated - $previouslogtime) > $this->limit) {
                        $dedication = $previouslogtime - $sessionstart;

                        // Ignore sessions with a really short duration.
                        if ($dedication > BLOCK_DEDICATION_IGNORE_SESSION_TIME) {
                            $rows[] = (object)array(
                                'start_date'     => $sessionstart,
                                'dedicationtime' => $dedication,
                                'ips'            => array_keys($ips));
                            $ips = array();
                        }
                        $sessionstart = $log->timecreated;
                    }
                    $previouslogtime = $log->timecreated;
                    $ips[$log->ip] = true;
                }

                $dedication = $previouslogtime - $sessionstart;

                // Ignore sessions with a really short duration.
                if ($dedication > BLOCK_DEDICATION_IGNORE_SESSION_TIME) {
                    $rows[] = (object)array(
                        'start_date'     => $sessionstart,
                        'dedicationtime' => $dedication,
                        'ips'            => array_keys($ips));
                }
            }

            return $rows;
        }
    }

    public function download_user_dedication($user, $rows) {
        $headers = array(
            array(
                get_string('sincerow', 'block_dedication'),
                userdate($this->mintime),
                get_string('torow', 'block_dedication'),
                userdate($this->maxtime),
                get_string('perioddiffrow', 'block_dedication'),
                format_time($this->maxtime - $this->mintime),
            ),
            array(''),
            array(
                get_string('firstname'),
                get_string('lastname'),
                get_string('sessionstart', 'block_dedication'),
                get_string('dedicationrow', 'block_dedication') . ' ' . get_string('secs'),
                get_string('sessionduration', 'block_dedication'),
                'IP',
            )
        );

        foreach ($rows as $index => $row) {
            $rows[$index] = array(
                $user->firstname,
                $user->lastname,
                userdate($row->start_date),
                $row->dedicationtime,
                self::format_dedication($row->dedicationtime),
                implode(', ', $row->ips),
            );
        }

        $rows = array_merge($headers, $rows);

        return self::generate_download("{$this->course->shortname}_dedication", $rows);
    }

    // Formats time based in Moodle function format_time($totalsecs).
    public static function format_dedication($totalsecs) {
        $totalsecs = abs($totalsecs);

        $str = new stdClass();
        $str->hour = get_string('hour');
        $str->hours = get_string('hours');
        $str->min = get_string('min');
        $str->mins = get_string('mins');
        $str->sec = get_string('sec');
        $str->secs = get_string('secs');

        $hours = floor($totalsecs / HOURSECS);
        $remainder = $totalsecs - ($hours * HOURSECS);
        $mins = floor($remainder / MINSECS);
        $secs = $remainder - ($mins * MINSECS);

        $ss = ($secs == 1) ? $str->sec : $str->secs;
        $sm = ($mins == 1) ? $str->min : $str->mins;
        $sh = ($hours == 1) ? $str->hour : $str->hours;

        $ohours = '';
        $omins = '';
        $osecs = '';

        if ($hours) {
            $ohours = $hours . ' ' . $sh;
        }
        if ($mins) {
            $omins = $mins . ' ' . $sm;
        }
        if ($secs) {
            $osecs = $secs . ' ' . $ss;
        }

        if ($hours) {
            return trim($ohours . ' ' . $omins);
        }
        if ($mins) {
            return trim($omins . ' ' . $osecs);
        }
        if ($secs) {
            return $osecs;
        }
        return get_string('now');
    }

    // Formats ips.
    public static function format_ips($ips) {
        return implode(', ', array_map('block_dedication_manager::link_ip', $ips));
    }

    // Generates an linkable ip.
    public static function link_ip($ip) {
        return html_writer::link("http://en.utrace.de/?query=$ip", $ip, array('target' => '_blank'));
    }

    // Table styles.
    public static function get_table_styles() {
        global $PAGE;

        // Twitter Bootstrap styling.
        if (in_array('bootstrapbase', $PAGE->theme->parents)) {
            $styles = array(
                'table_class' => 'table table-striped table-bordered table-hover table-condensed table-dedication',
                'header_style' => 'background-color: #333; color: #fff;'
            );
        } else {
            $styles = array(
                'table_class' => 'table-dedication',
                'header_style' => ''
            );
        }

        return $styles;
    }

    // Generate generic Excel file for download.
    public static function generate_download($downloadname, $rows) {
        global $CFG;

        require_once($CFG->libdir. '/excellib.class.php');

        $workbook = new MoodleExcelWorkbook('-', 'excel5');
        $workbook->send(clean_filename($downloadname));

        $myxls = $workbook->add_worksheet(get_string('pluginname', 'block_dedication'));

        $rowcount = 0;
        foreach ($rows as $row) {
            foreach ($row as $index => $content) {
                $myxls->write($rowcount, $index, $content);
            }
            $rowcount++;
        }

        $workbook->close();

        return $workbook;
    }
}