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
 * Date condition.
 *
 * @package availability_enroldate
 * @copyright 2021 Sooraj Singh
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_enroldate;

defined('MOODLE_INTERNAL') || die();

/**
 * Date condition.
 *
 * @package availability_enroldate
 * @copyright 2021 Sooraj Singh
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition extends \core_availability\condition {
    /** @var string Availabile only from specified date. */
    const ENROLDATE_AFTER = '>=';

    /** @var string Availabile only until specified date. */
    const ENROLDATE_BEFORE = '<';

    /** @var string One of the DIRECTION_xx constants. */
    private $direction;

    /** @var int Time (Unix epoch seconds) for condition. */
    private $time;

    /** @var int Time (Unix epoch seconds) for condition. */
    private $enroltime;

    /** @var int Forced current time (for unit tests) or 0 for normal. */
    private static $forcecurrenttime = 0;

    /**
     * Constructor.
     *
     * @param \stdClass $structure Data structure from JSON decode
     * @throws \coding_exception If invalid data structure.
     */
    public function __construct($structure) {
        global $CFG, $USER, $COURSE, $DB;
        // Get direction.
        if (isset($structure->d) && in_array($structure->d,
                array(self::ENROLDATE_AFTER, self::ENROLDATE_BEFORE))) {
            $this->direction = $structure->d;
        } else {
            throw new \coding_exception('Missing or invalid ->d for date condition');
        }

        $coursecontext = \context_course::instance($COURSE->id);

        if (is_enrolled($coursecontext)) {
            $sql = "SELECT max(ue.timecreated) as enroldate
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = :courseid)
                      JOIN {user} u ON u.id = ue.userid
                     WHERE ue.userid = :userid AND u.deleted = 0";
            $params = array('userid' => $USER->id, 'courseid' => $coursecontext->instanceid);
            $enroldate = $DB->get_field_sql($sql, $params, IGNORE_MISSING);
            $this->enroltime = $enroldate;
        }
        // Get time.
        if (isset($structure->t) && is_int($structure->t)) {
            $this->time = $structure->t;
        } else {
            throw new \coding_exception('Missing or invalid ->t for date condition');
        }
    }

    public function save() {
        return (object)array('type' => 'date',
                'd' => $this->direction, 't' => $this->time);
    }

    /**
     * Returns a JSON object which corresponds to a condition of this type.
     *
     * Intended for unit testing, as normally the JSON values are constructed
     * by JavaScript code.
     *
     * @param string $direction DIRECTION_xx constant
     * @param int $time Time in epoch seconds
     * @return stdClass Object representing condition
     */
    public static function get_json($direction, $time) {
        return (object)array('type' => 'date', 'd' => $direction, 't' => (int)$time);
    }

    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        return $this->is_available_for_all($not);
    }

    public function is_available_for_all($not = false) {
        // Check condition.
        $now = self::get_time();
        switch ($this->direction) {
            case self::ENROLDATE_AFTER:
                $allow = $this->enroltime >= $this->time;
                break;
            case self::ENROLDATE_BEFORE:
                $allow = $this->enroltime < $this->time;
                break;
            default:
                throw new \coding_exception('Unexpected direction');
        }
        if ($not) {
            $allow = !$allow;
        }

        return $allow;
    }

    /**
     * Obtains the actual direction of checking based on the $not value.
     *
     * @param bool $not True if condition is negated
     * @return string Direction constant
     * @throws \coding_exception
     */
    protected function get_logical_direction($not) {
        switch ($this->direction) {
            case self::ENROLDATE_AFTER:
                return $not ? self::ENROLDATE_BEFORE : self::ENROLDATE_AFTER;
            case self::ENROLDATE_BEFORE:
                return $not ? self::ENROLDATE_AFTER : self::ENROLDATE_BEFORE;
            default:
                throw new \coding_exception('Unexpected direction');
        }
    }

    public function get_description($full, $not, \core_availability\info $info) {
        return $this->get_either_description($not, false);
    }

    public function get_standalone_description(
            $full, $not, \core_availability\info $info) {
        return $this->get_either_description($not, true);
    }

    /**
     * Shows the description using the different lang strings for the standalone
     * version or the full one.
     *
     * @param bool $not True if NOT is in force
     * @param bool $standalone True to use standalone lang strings
     */
    protected function get_either_description($not, $standalone) {
        $direction = $this->get_logical_direction($not);
        $midnight = self::is_midnight($this->time);
        $midnighttag = $midnight ? '_date' : '';
        $satag = $standalone ? 'short_' : 'full_';
        switch ($direction) {
            case self::ENROLDATE_AFTER:
                return get_string($satag . 'from' . $midnighttag, 'availability_enroldate',
                        self::show_time($this->time, $midnight, false));
            case self::ENROLDATE_BEFORE:
                return get_string($satag . 'until' . $midnighttag, 'availability_enroldate',
                        self::show_time($this->time, $midnight, true));
        }
    }

    protected function get_debug_string() {
        return $this->direction . ' ' . gmdate('Y-m-d H:i:s', $this->time);
    }

    /**
     * Gets time. This function is implemented here rather than calling time()
     * so that it can be overridden in unit tests. (Would really be nice if
     * Moodle had a generic way of doing that, but it doesn't.)
     *
     * @return int Current time (seconds since epoch)
     */
    protected static function get_time() {
        if (self::$forcecurrenttime) {
            return self::$forcecurrenttime;
        } else {
            return time();
        }
    }

    /**
     * Forces the current time for unit tests.
     *
     * @param int $forcetime Time to return from the get_time function
     */
    public static function set_current_time_for_test($forcetime = 0) {
        self::$forcecurrenttime = $forcetime;
    }

    /**
     * Shows a time either as a date or a full date and time, according to
     * user's timezone.
     *
     * @param int $time Time
     * @param bool $dateonly If true, uses date only
     * @param bool $until If true, and if using date only, shows previous date
     * @return string Date
     */
    protected function show_time($time, $dateonly, $until = false) {
        // For 'until' dates that are at midnight, e.g. midnight 5 March, it
        // is better to word the text as 'until end 4 March'.
        $daybefore = false;
        if ($until && $dateonly) {
            $daybefore = true;
            $time = strtotime('-1 day', $time);
        }
        return userdate($time,
                get_string($dateonly ? 'strftimedate' : 'strftimedatetime', 'langconfig'));
    }

    /**
     * Checks whether a given time refers exactly to midnight (in current user
     * timezone).
     *
     * @param int $time Time
     * @return bool True if time refers to midnight, false otherwise
     */
    protected static function is_midnight($time) {
        return usergetmidnight($time) == $time;
    }

    public function update_after_restore(
            $restoreid, $courseid, \base_logger $logger, $name) {
        // Update the date, if restoring with changed date.
        $dateoffset = \core_availability\info::get_restore_date_offset($restoreid);
        if ($dateoffset) {
            $this->time += $dateoffset;
            return true;
        }
        return false;
    }

    /**
     * Changes all date restrictions on a course by the specified shift amount.
     * Used by the course reset feature.
     *
     * @param int $courseid Course id
     * @param int $timeshift Offset in seconds
     */
    public static function update_all_dates($courseid, $timeshift) {
        global $DB;

        $modinfo = get_fast_modinfo($courseid);
        $anychanged = false;

        // Adjust dates from all course modules.
        foreach ($modinfo->cms as $cm) {
            if (!$cm->availability) {
                continue;
            }
            $info = new \core_availability\info_module($cm);
            $tree = $info->get_availability_tree();
            $dates = $tree->get_all_children('availability_enroldate\condition');
            $changed = false;
            foreach ($dates as $date) {
                $date->time += $timeshift;
                $changed = true;
            }

            // Save the updated course module.
            if ($changed) {
                $DB->set_field('course_modules', 'availability', json_encode($tree->save()),
                        array('id' => $cm->id));
                $anychanged = true;
            }
        }

        // Adjust dates from all course sections.
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->availability) {
                continue;
            }

            $info = new \core_availability\info_section($section);
            $tree = $info->get_availability_tree();
            $dates = $tree->get_all_children('availability_enroldate\condition');
            $changed = false;
            foreach ($dates as $date) {
                $date->time += $timeshift;
                $changed = true;
            }

            // Save the updated course module.
            if ($changed) {
                $updatesection = new \stdClass();
                $updatesection->id = $section->id;
                $updatesection->availability = json_encode($tree->save());
                $updatesection->timemodified = time();
                $DB->update_record('course_sections', $updatesection);

                $anychanged = true;
            }
        }

        // Ensure course cache is cleared if required.
        if ($anychanged) {
            rebuild_course_cache($courseid, true);
        }
    }
}
