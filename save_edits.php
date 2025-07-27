<?php
// save_edits.php - handles score edits

// parameters and setup 
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

global $DB, $USER;


$courseid   = required_param('id', PARAM_INT);
$activityid = required_param('activityid', PARAM_INT);
$userid     = required_param('userid', PARAM_INT);

require_login($courseid);
$course  = get_course($courseid);
$cm      = get_coursemodule_from_id('assign', $activityid, $courseid, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('mod/assign:grade', $context);
require_sesskey();

// ge tthe assign object
$assign = new assign($context, $cm, $course);

// get the grade 
$grade = $DB->get_record('assign_grades', [
    'assignment' => $assign->get_instance()->id,
    'userid'     => $userid
], '*', MUST_EXIST);

// read input
$scores   = $_POST['scores'] ?? [];
$comments = $_POST['comments'] ?? [];
$updated  = false;

foreach ($scores as $criterionid => $newscore) {
    $newscore   = floatval($newscore);
    $newcomment = $comments[$criterionid] ?? '';

    // current filling 
    $filling = $DB->get_record('gradingform_rubric_fillings', [
        'instanceid' => $grade->id,
        'criterionid' => $criterionid
    ], '*', IGNORE_MISSING);

    if ($filling) {
        $oldlevel = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid], '*', IGNORE_MISSING);
        $oldscore = $oldlevel ? $oldlevel->score : 0;

        $newlevel = $DB->get_record('gradingform_rubric_levels', [
            'criterionid' => $criterionid,
            'score'       => $newscore
        ], '*', IGNORE_MISSING);

        if ($newlevel && ($newlevel->id != $filling->levelid || $filling->remark != $newcomment)) {
            $filling->levelid       = $newlevel->id;
            $filling->remark        = $newcomment;
            $filling->remarkformat  = FORMAT_HTML;

            //put new info in the new table
            $DB->update_record('gradingform_rubric_fillings', $filling);

            $DB->insert_record('rubric_grade_edits', [
                'userid'       => $userid,
                'submissionid' => $grade->id,
                'criterionid'  => $criterionid,
                'oldscore'     => $oldscore,
                'newscore'     => $newscore,
                'comment'      => $newcomment,
                'editorid'     => $USER->id,
                'timemodified' => time()
            ]);

            $updated = true;
        }
    }
}

if ($updated) {
    // change the total grade according to the new grade 
    $fillings = $DB->get_records('gradingform_rubric_fillings', ['instanceid' => $grade->id]);

    $newtotal = 0.0;
    $maxscore = 0.0;

    foreach ($fillings as $filling) {
        $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid], '*', IGNORE_MISSING);
        if ($level) {
            $newtotal += $level->score;
        }

        $levels = $DB->get_records('gradingform_rubric_levels', ['criterionid' => $filling->criterionid]);
        if ($levels) {
            $max = max(array_map(fn($lvl) => $lvl->score, $levels));
            $maxscore += $max;
        }
    }

    $finalgrade = ($maxscore > 0) ? ($newtotal / $maxscore) * 100 : 0;
    $DB->set_field('assign_grades', 'grade', $finalgrade, ['id' => $grade->id]);
}

// did it work_
header('Content-Type: application/json');

if ($updated) {
    echo json_encode([
        'status' => 'success',
        'message' => '✅ Changes have been saved! Reloading...'
    ]);
} else {
    echo json_encode([
        'status' => 'nochange',
        'message' => '⚠️ No changes were made.'
    ]);
}

exit;
