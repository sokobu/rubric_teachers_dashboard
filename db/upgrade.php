<?php
// Tmake db updates when the version changes 

defined('MOODLE_INTERNAL') || die();

/**
 *
 *
 * @param int $oldversion The plugin version being upgraded from.
 * @return bool success
 */
function xmldb_gradereport_rubrics_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // i hope this works, if version less than this, create the table 
    if ($oldversion < 2025072500) {
        //table rubric_grade_edits.
        $table = new xmldb_table('rubric_grade_edits');

        // fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('criterionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('oldscore', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('newscore', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('editorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // keys .
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('rubricedit_userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('rubricedit_submission_idx', XMLDB_INDEX_NOTUNIQUE, ['submissionid']);

        // only create if doesnt exist yet.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // make sure the update is documented by setting the new version.
        upgrade_plugin_savepoint(true, 2025072500, 'gradereport', 'rubrics');
    }

    return true;
}
