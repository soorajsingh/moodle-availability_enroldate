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
        // Get direction.
        if (isset($structure->d) && in_array($structure->d,
                array(self::ENROLDATE_AFTER, self::ENROLDATE_BEFORE))) {
            $this->direction = $structure->d;
        } else {
            throw new \coding_exception('Missing or invalid ->d for date condition');
        }

        // Get time.
        if (isset($structure->t) && is_int($structure->t)) {
            $this->time = $structure->t;
        } else {
            throw new \coding_exception('Missing or invalid ->t for date condition');
        }
    }

    /**
     * Saves tree data back to a structure object.
     *
     * @return \stdClass Structure object (ready to be made into JSON format)
     */
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

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     *
     * If implementations require a course or modinfo, they should use
     * the get methods in $info.
     *
     * The $not option is potentially confusing. This option always indicates
     * the 'real' value of NOT. For example, a condition inside a 'NOT AND'
     * group will get this called with $not = true, but if you put another
     * 'NOT OR' group inside the first group, then a condition inside that will
     * be called with $not = false. We need to use the real values, rather than
     * the more natural use of the current value at this point inside the tree,
     * so that the information displayed to users makes sense.
     *
     * @param bool $not Set true if we are inverting the condition
     * @param info $info Item we're checking
     * @param bool $grabthelot Performance hint: if true, caches information
     *   required for all course-modules, to make the front page and similar
     *   pages work more quickly (works only for current user)
     * @param int $userid User ID to check availability for
     * @return bool True if available
     */
    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        if (!$this->enroltime) {
            $this->set_enrol_time($info);
        }

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

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @param info $info Item we're checking
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description($full, $not, \core_availability\info $info) {
        return $this->get_either_description($not, false);
    }

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are. and description is not dependent to other
     * condition
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @param info $info Item we're checking
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
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

    /**
     * Obtains a representation of the options of this condition as a string,
     * for debugging.
     *
     * @return string Text representation of parameters
     */
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

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * @param int $restoreid the id of restoring data
     * @param int $courseid course id
     * @param base_logger $logger log object
     * @param string $name name value in the restore record
     * @return bool true if restored and get the offset
     */
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

    private function set_enrol_time(\core_availability\info $info): void
    {
        global $USER, $DB;

        $coursecontext = \context_course::instance($info->get_course()->id);

        if (is_enrolled($coursecontext)) {
            $sql = "SELECT max(ue.timecreated) as enroldate
                FROM {user_enrolments} ue
                JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = :courseid)
                JOIN {user} u ON u.id = ue.userid
                WHERE ue.userid = :userid AND u.deleted = 0";

            $params = ['userid' => $USER->id, 'courseid' => $coursecontext->instanceid];

            $this->enroltime = $DB->get_field_sql($sql, $params, IGNORE_MISSING);
        }
    }
}
