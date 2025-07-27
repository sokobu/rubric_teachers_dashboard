<?php
namespace gradereport_rubrics;

defined('MOODLE_INTERNAL') || die();
//getting data for the bar chart 
class chartdata {
    public static function get_criteria_score_data($course, $cm) {
        global $DB;
        //i need module instance, the grading manager, and a controller 
        $contextmodule = \context_module::instance($cm->id);
        $manager = get_grading_manager($contextmodule, 'mod_assign', 'submissions');
        $controller = $manager->get_active_controller();
        if (!($controller instanceof \gradingform_rubric_controller)) {
            return [];
        }

        $definition = $controller->get_definition();
        $criteria = $definition->rubric_criteria;

        // criteria and description 
        $criteria_by_id = [];
        $scores = [];
        foreach ($criteria as $crit) {
            $criteria_by_id[$crit['id']] = $crit['description'];
            $scores[$crit['description']] = 0.0;
        }

        // get assign grades for this assignment
        $grades = $DB->get_records('assign_grades', ['assignment' => $cm->instance]);
        if (empty($grades)) return $scores;

        $gradeids = array_keys($grades);

        // get original fillings (instanceid = assign_grade.id)
        list($in_sql, $params) = $DB->get_in_or_equal($gradeids, SQL_PARAMS_NAMED);
        $fills = $DB->get_records_select('gradingform_rubric_fillings', "instanceid $in_sql", $params);

        // Load lvl scores
        $levels = $DB->get_records('gradingform_rubric_levels');
        $level_lookup = [];
        foreach ($levels as $lvl) {
            $level_lookup[$lvl->id] = [
                'criterionid' => $lvl->criterionid,
                'score' => (float)$lvl->score
            ];
        }

        // load latest rubric edits per submission per criterion
        $sql = "
            SELECT r1.*
            FROM {rubric_grade_edits} r1
            INNER JOIN (
                SELECT submissionid, criterionid, MAX(timemodified) AS maxtime
                FROM {rubric_grade_edits}
                GROUP BY submissionid, criterionid
            ) r2 ON r1.submissionid = r2.submissionid AND r1.criterionid = r2.criterionid AND r1.timemodified = r2.maxtime
            WHERE r1.submissionid $in_sql
        ";
        $latest_edits = $DB->get_records_sql($sql, $params);

        //build final scores per criterion
        $finalscores = []; // [criterionid => total score]

        foreach ($fills as $fill) {
            $submissionid = $fill->instanceid;
            $level = $level_lookup[$fill->levelid] ?? null;
            if (!$level) continue;

            $criterionid = $level['criterionid'];

            // check if there's an edit
            $editkey = "$submissionid-$criterionid";
            $edit = null;
            foreach ($latest_edits as $e) {
                if ($e->submissionid == $submissionid && $e->criterionid == $criterionid) {
                    $edit = $e;
                    break;
                }
            }

            $score = ($edit !== null) ? (float)$edit->newscore : $level['score'];

            if (!isset($finalscores[$criterionid])) {
                $finalscores[$criterionid] = 0.0;
            }
            $finalscores[$criterionid] += $score;
        }

        foreach ($finalscores as $critid => $total) {
            $desc = $criteria_by_id[$critid] ?? null;
            if ($desc !== null) {
                $scores[$desc] = $total;
            }
        }

        return $scores;
    }
}
