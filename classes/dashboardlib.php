<?php
namespace gradereport_rubrics; //define namespace so I do not clash with other stuff in the plugin. 

defined('MOODLE_INTERNAL') || die(); //make it impossible to access over URL 


use moodle_url;
use html_writer;

class dashboardlib {
//function to get course data 
    private static function get_assignment_objects($course, $cm) {
        $contextmodule = \context_module::instance($cm->id);
        $assignment = new \assign($contextmodule, $cm, $course);

        return [
            'contextmodule' => $contextmodule,
            'assignment' => $assignment,
            'assigninstance' => $assignment->get_instance(),
            'gradeitem' => $assignment->get_grade_item()
    ];
}
    //FUNCTION FOR THE FIRST GLANCE STATS ON TEACHERS DASHBOARD (How many submitted, graded, whats the average grade)
    public static function get_assignment_data($course, $cm) { 

        global $DB;

        $a = self::get_assignment_objects($course, $cm);

        //count how many students have submitted
        $submitted = $DB->count_records('assign_submission', [
            'assignment' => $a['assigninstance']->id,
            'status' => 'submitted'
        ]);
        //count how many assignments have already been graded
         $graded = $DB->count_records_select('grade_grades',
            'itemid = :itemid AND finalgrade IS NOT NULL',
            ['itemid' => $a['gradeitem']->id]
        );
        //average grade calculation 
        $average = $DB->get_field_sql(
            'SELECT AVG(grade) FROM {assign_grades} WHERE assignment = :assignid AND grade IS NOT NULL',
            ['assignid' => $a['assigninstance']->id]
        );


        return [
            'submitted' => $submitted,
            'graded' => $graded,
            'average' => $average !== null ? number_format($average, 2) : '0.00' //average up to 2 decimal points
        ];
}
    //STUDENT TABLE FOR THE TEACHERS DASHBOARD 
    public static function get_student_table_data($course, $cm, $search = '') {
        global $DB;

        $a = self::get_assignment_objects($course, $cm);

        $students = get_enrolled_users($a['contextmodule'], 'mod/assign:submit');

        $rows = [];

        foreach ($students as $student) {
            //if search on, skip names that dont have the term in them 
            $studentid = $student->id; 
            $studentname = fullname($student); 

            if ($search !== ''
                 && stripos(fullname($student), $search) === false
                 && stripos((string)$studentid, $search) === false) {
                echo "<p>Skipping: " . fullname($student) . "</p>"; //debugging!!! DELETE ME 
                continue;
            } else {
            
                $submission = $DB->get_record('assign_submission', [
                    'assignment' => $a['assigninstance']->id,
                    'userid' => $student->id
                ]);

                $grade = $DB->get_record('assign_grades', [
                    'assignment' => $cm->instance,
                    'userid' => $student->id
                ], '*', IGNORE_MISSING);

                $detailurl = new \moodle_url(
                    '/grade/report/rubrics/detail_features.php',
                    [
                        'id'         => $course->id,
                        'activityid' => $cm->id,
                        'userid'     => $student->id
                    ]
                );
                $button = ($submission && $grade && $grade->grade !== null)
                    ? \html_writer::link(
                        $detailurl,
                        get_string('viewdetails', 'gradereport_rubrics'),
                        [
                            'class'  => 'btn btn-sm btn-outline-primary',
                            'target' => '_blank',
                            'rel'    => 'noopener noreferrer'
                        ]
                    )
                    : '';

                $rows[] = [
                    'student_id' => $student->id,
                    'fullname' => fullname($student),
                    'status' => !$submission ? 'No submission'
                                : ($grade && $grade->grade !== null ? 'Graded' : 'Submitted'),
                    'grade' => $grade && $grade->grade !== null ? number_format($grade->grade, 2) : 'Not graded',
                    'button' => $button
                ];
        } 
    }

        return $rows;
}

}
