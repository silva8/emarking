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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 *
 * @package mod
 * @subpackage emarking
 * @copyright 2015 Jorge Villalon <villalon@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once (dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once ("lib.php");
require_once ($CFG->libdir . '/tablelib.php');
require_once ($CFG->dirroot . "/mod/emarking/locallib.php");
require_once ($CFG->dirroot . "/mod/emarking/marking/locallib.php");
require_once ($CFG->dirroot . "/mod/emarking/print/locallib.php");
require_once ($CFG->dirroot . "/lib/externallib.php");
require_once ($CFG->dirroot . '/lib/excellib.class.php');
require_once ($CFG->dirroot . "/mod/emarking/classes/event/unauthorizedaccess_attempted.php");
require_once ($CFG->libdir . '/eventslib.php');
global $USER, $OUTPUT, $DB, $CFG, $PAGE;
// Obtains basic data from cm id.
list ($cm, $emarking, $course, $context) = emarking_get_cm_course_instance();
// Check that user is logued in the course.
require_login($course->id);
if (isguestuser()) {
    die();
}
// Get the course module for the emarking, to build the emarking url.
$urlemarking = new moodle_url('/mod/emarking/view.php', array(
    'id' => $cm->id
));
// If it was an import.
if($emarking->parent > 0 && $emarking->copiedfromparent == 0) {
    $srcemarking = $DB->get_record('emarking', array('id'=>$emarking->parent));
    emarking_copy_peer_review($srcemarking, $emarking);
    redirect($urlemarking);
    die();
}
// Table parameters for sorting and pagination.
// Page to show (when paginating).
$page = optional_param('page', 0, PARAM_INT);
// Table sort.
$tsort = optional_param('tsort', '', PARAM_ALPHA);
// Rows per page.
$perpage = 100;
// Export an Excel file with all grades.
$exportcsv = optional_param('exportcsv', null, PARAM_ALPHA);
// Reassign peers in a peer review session.
$reassignpeers = optional_param('reassignpeers', false, PARAM_BOOL);
// Ignore enrolments and show all drafts.
$ignoreenrolments = optional_param('enrolment', false, PARAM_BOOL);
// We are in print & scan mode.
$scan = $emarking->type == EMARKING_TYPE_PRINT_SCAN;
// We set a variable to indicate the emarking type is for marking
$emarkingisstudentmarking = $emarking->type == EMARKING_TYPE_PRINT_ONLY || $emarking->type == EMARKING_TYPE_PRINT_SCAN || $emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING;
// Get the associated exam.
if ((!$exam = $DB->get_record("emarking_exams", array(
    "emarking" => $emarking->id
))) && $emarkingisstudentmarking) {
    print_error(get_string("emarkingwithnoexam", 'mod_emarking'));
}
// If we have a print only emarking we send the user to the exam view.
if ($emarking->type == EMARKING_TYPE_PRINT_ONLY) {
    redirect(new moodle_url("/mod/emarking/print/exam.php", array(
        "id" => $cm->id
    )));
    die();
}
// Obtain user permissions for grading and supervising.
list ($issupervisor, $usercangrade) = emarking_get_grading_permissions($emarking, $context);
// Supervisors and site administrators can see everything always.
if ($issupervisor || is_siteadmin($USER)) {
    $emarking->anonymous = EMARKING_ANON_NONE;
}
// Download Excel if it is the case.
if ($exportcsv && $usercangrade && $issupervisor) {
    if ($exportcsv === 'grades') {
        emarking_download_excel($emarking);
    } else 
        if ($exportcsv === 'delphi') {
            emarking_download_excel_markers_training($emarking);
        } else 
            if ($exportcsv === 'agreement') {
                emarking_download_excel_markers_agreement($cm, $emarking);
            }
    die();
}
// Page navigation and URL settings.
$PAGE->set_url($urlemarking);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('emarking', 'mod_emarking'));
// Require jquery for modal.
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');
$PAGE->requires->jquery_plugin('ui-css');
// Filter view for Markers' training and Peer review. If no drafts have been submitted.
// take the user to the uploadanswers interface.
list($numdrafts, $numdraftsgrading) = emarking_get_drafts_per_status($emarking, $issupervisor);
// For markers and student training we need to upload answers before showing anything.
if (!$emarkingisstudentmarking) {
    if (!$usercangrade && $emarking->type == EMARKING_TYPE_MARKER_TRAINING) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading($emarking->name);
        echo $OUTPUT->notification(get_string("markerstrainingnotforstudents", "mod_emarking"));
        echo $OUTPUT->single_button(new moodle_url("/course/view.php", array(
            "id" => $cm->course
        )), get_string("back"));
        echo $OUTPUT->footer();
        die();
    }
    // If there are no drafts redirect to the upload answers.
    if ($numdrafts == 0) {
        redirect(new moodle_url("/mod/emarking/print/uploadanswers.php", array(
            "id" => $cm->id
        )));
        die();
    }
}
// Show header and heading.
echo $OUTPUT->header();
// Heading and tabs if we are within a course module.
echo $OUTPUT->heading($emarking->name);
// Navigation tabs.
$tabname = $scan ? "scanlist" : "mark";
echo $OUTPUT->tabtree(emarking_tabs($context, $cm, $emarking), $tabname);
// Reassign peers if everything is ok with it.
if ($reassignpeers && $usercangrade && $issupervisor && $numdraftsgrading == 0) {
    if (emarking_assign_peers($emarking)) {
        echo $OUTPUT->notification(get_string('transactionsuccessfull', 'mod_emarking'), 'notifysuccess');
    } else {
        echo $OUTPUT->notification(get_string('fatalerror', 'mod_emarking'), 'notifyproblem');
    }
}
// Get rubric instance.
list ($gradingmanager, $gradingmethod, $rubriccriteria, $rubriccontroller) =
    emarking_validate_rubric(
        $context,
        ($emarkingisstudentmarking && !$scan) || $emarking->type == EMARKING_TYPE_PEER_REVIEW, // Die if no rubric.
        !$scan); // Show rubric creation button.
// If there are no students enrolled in the course, then no .
$students = get_enrolled_users($context, 'mod/emarking:submit');
if(count($students) == 0) {
    echo $OUTPUT->notification(get_string('nostudent', 'mod_emarking'), 'notifyproblem');
    echo $OUTPUT->footer();
    die();
}
// User filter checking capabilities. If user can not grade, then she can not.
// see other users.
$userfilter = 'WHERE 1=1 ';
if (!$usercangrade) {
    $userfilter .= 'AND (u.id = ' . $USER->id . ' OR NM.answerkey = ' . EMARKING_ANSWERKEY_ACCEPTED . ')';
} else 
    if (($emarking->type == EMARKING_TYPE_MARKER_TRAINING) && !is_siteadmin($USER->id) && !$issupervisor) {
        $userfilter .= 'AND um.id = ' . $USER->id;
    } else 
        if ($emarking->type == EMARKING_TYPE_PEER_REVIEW && !$issupervisor && !is_siteadmin($USER->id)) {
            $userfilter .= 'AND (um.id = ' . $USER->id . ' OR u.id = ' . $USER->id . ')';
        }
$qcfilter = ' AND d.qualitycontrol = 0';
if ($emarking->qualitycontrol && ($DB->count_records('emarking_markers', array(
    'emarking' => $emarking->id,
    'marker' => $USER->id,
    'qualitycontrol' => 1
)) > 0 || is_siteadmin($USER))) {
    $qcfilter = '';
}
if ($emarking->type == EMARKING_TYPE_PEER_REVIEW) {
    $qcfilter = '';
}
// Default variables for the number of criteria for this evaluation.
// and minimum and maximum scores.
$numcriteria = 0;
$rubricscores = array(
    'maxscore' => 0,
    'minscore' => 0
);
if ($rubriccriteria) {
    $numcriteria = count($rubriccriteria->rubric_criteria);
    // Getting min and max scores.
    $rubricscores = $rubriccontroller->get_min_max_score();
}
if($usercangrade && $emarkingisstudentmarking) {
    echo $OUTPUT->heading(get_string('markers', 'mod_emarking'), 4);
    emarking_markers_online($context);
}
// If we are in markers training mode draw the marking progress table.
if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) {
    $coursecontext = context_course::instance($course->id);
    list ($markers, $userismarker) = emarking_get_markers_in_training($emarking->id, $context, true);
    list($generalprogress, $chartstable) = emarking_get_marking_progress_table($emarking, $markers, $context,$numcriteria,$numdrafts,$issupervisor);
    if ($generalprogress == 100 && intval($emarking->firststagedate) < time()) {
        $urldelphi = new moodle_url('/mod/emarking/marking/delphi.php', array(
            'id' => $cm->id
        ));
        die("<script>location.href = '$urldelphi'</script>");
    }
    echo emarking_tabs_markers_training($context, $cm, $emarking, $generalprogress, 0);
}
// Show export buttons when grades are available
if($usercangrade) {
    emarking_show_export_buttons($issupervisor, $rubriccriteria, $cm, $emarking, $numdraftsgrading);
}
if($emarking->type == EMARKING_TYPE_MARKER_TRAINING) {
    echo $OUTPUT->heading(get_string('marking_progress', 'mod_emarking'), 5);
    echo html_writer::table($chartstable);
    if (isset($userpercentage) && floor($userpercentage) == 100) {
        echo get_string('marking_completed', 'mod_emarking');
    }
}
$usercanpublishgrades = ($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING ||
    $emarking->type == EMARKING_TYPE_PEER_REVIEW) &&
    has_capability("mod/emarking:supervisegrading", $context) && !$scan;
// Only when marking normally for a grade we can publish grades.
if ($usercanpublishgrades) {
    echo "<form id='publishgrades' action='marking/publish.php' method='post'>";
    echo "<input type='hidden' name='id' value='$cm->id'>";
}
// Calculates the number of criteria assigned to current user.
$numcriteriauser = $DB->count_records_sql("
		SELECT COUNT(DISTINCT criterion)
		FROM {emarking_marker_criterion}
		WHERE emarking=? AND marker=?", array(
    $emarking->id,
    $USER->id
));
$enrolments = explode(',', $exam ? $exam->enrolments : $CFG->emarking_enrolincludes);
for($i = 0; $i < count($enrolments); $i++) {
    $enrolments[$i] = "'" . $enrolments[$i] . "'";
}
$enrolmentsfilter = implode(",", $enrolments);
$sqluser = ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) ? "es.student AS id," : "u.*,";
$sqldraftsorusers = ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) ? "{emarking_submission} es
INNER JOIN {emarking} e ON (e.id = ? AND es.emarking = e.id)" : ($ignoreenrolments ? "(
SELECT u.*, e.course as courseid
FROM {user} u
INNER JOIN {emarking_submission} es ON (es.student = u.id)
INNER JOIN {emarking} e ON (es.emarking = e.id AND e.course = ?)
GROUP BY u.id) as u" : "(
SELECT u.*, e.courseid
FROM {user_enrolments} ue
INNER JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = ? AND e.enrol IN ($enrolmentsfilter))
INNER JOIN {context} c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
INNER JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.userid = ue.userid)
INNER JOIN {role} r on (r.id = ra.roleid AND r.shortname = 'student')
INNER JOIN {user} u ON (ue.userid = u.id)
GROUP BY u.id) as u");
$sqljoinon = ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) ? "(es.student = NM.student AND es.id = NM.submissionid)" : "(u.id = NM.student AND u.courseid = NM.course)";
$sqlgroupby = ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) ? "es.student" : "u.id";
$sqldrafts = "
SELECT
$sqluser
IFNULL(NM.submissionid,0) as submission,
NM.answerkey,
GROUP_CONCAT(IFNULL(NM.groups,0) SEPARATOR '#') as groups,
GROUP_CONCAT(IFNULL(NM.draftid,0) SEPARATOR '#') as draft,
GROUP_CONCAT(IFNULL(NM.status,0) SEPARATOR '#') as status,
GROUP_CONCAT(IFNULL(NM.pages,0) SEPARATOR '#') as pages,
GROUP_CONCAT(IFNULL(NM.comments,0) SEPARATOR '#') as comments,
GROUP_CONCAT(IFNULL(NM.qcs,0) SEPARATOR '#') as qcs,
GROUP_CONCAT(
CASE WHEN 0 = $numcriteria THEN 0 ELSE ROUND( IFNULL(NM.comments,0) / $numcriteria * 100, 0) END
SEPARATOR '#') as pctmarked,
GROUP_CONCAT(
CASE WHEN 0 = $numcriteriauser THEN 0 ELSE ROUND( IFNULL(NM.commentsassigned,0) / $numcriteriauser * 100, 0) END
SEPARATOR '#') as pctmarkeduser,
GROUP_CONCAT(IFNULL(NM.grade,0) SEPARATOR '#') as grade,
GROUP_CONCAT(IFNULL(NM.score,0) SEPARATOR '#') as score,
GROUP_CONCAT(IFNULL(NM.bonus,0) SEPARATOR '#') as bonus,
GROUP_CONCAT(IFNULL(NM.regrades,0) SEPARATOR '#') as regrades,
GROUP_CONCAT(IFNULL(NM.generalfeedback,'') SEPARATOR '#') as feedback,
GROUP_CONCAT(IFNULL(NM.timemodified, 0) SEPARATOR '#') as timemodified,
NM.grademax as grademax,
NM.grademin as grademin,
NM.sort,
NM.commentsassignedids,
NM.flexibility as flexibility,
NM.firststagedate as firststagedate,
NM.secondstagedate as secondstagedate,
GROUP_CONCAT(NM.criteriaids SEPARATOR '#') as criteriaids,
GROUP_CONCAT(NM.criteriascores SEPARATOR '#') as criteriascores,
GROUP_CONCAT(IFNULL(um.picture, '') SEPARATOR '#') as markerpicture,
GROUP_CONCAT(IFNULL(um.lastname, '') SEPARATOR '#') as markerlast,
GROUP_CONCAT(IFNULL(um.firstname, '') SEPARATOR '#') as markerfirst,
GROUP_CONCAT(IFNULL(um.id, 0) SEPARATOR '#') as markerid
FROM $sqldraftsorusers
LEFT JOIN (
SELECT s.student,
s.answerkey,
d.id as draftid,
d.submissionid as submissionid,
d.groupid as groups,
d.status,
d.timemodified,
d.grade,
d.generalfeedback,
d.teacher as marker,
d.qualitycontrol as qcs,
count(distinct p.id) as pages,
count(distinct c.id) as comments,
count(distinct r.id) as regrades,
count(distinct mc.id) as commentsassigned,
IFNULL(GROUP_CONCAT(mc.id),'') as commentsassignedids,
IFNULL(GROUP_CONCAT(l.criterionid),'') as criteriaids,
IFNULL(GROUP_CONCAT(l.score + c.bonus),'') as criteriascores,
nm.course,
nm.id,
nm.grade as grademax,
nm.grademin as grademin,
nm.agreementflexibility as flexibility,
nm.secondstagedate as secondstagedate,
nm.firststagedate as firststagedate,
round(sum(l.score),2) as score,
round(sum(c.bonus),2) as bonus,
d.sort
FROM {emarking} nm
INNER JOIN {emarking_draft} d ON (nm.id = ? AND d.emarkingid = nm.id $qcfilter)
INNER JOIN {emarking_submission} s ON (s.id = d.submissionid)
INNER JOIN {emarking_page} p ON (p.submission = d.submissionid)
LEFT JOIN {emarking_comment} c on (c.page = p.id AND c.draft = d.id AND c.levelid > 0)
LEFT JOIN {gradingform_rubric_levels} l ON (c.levelid = l.id)
LEFT JOIN {emarking_regrade} r ON (r.draft = d.id AND r.criterion = l.criterionid AND r.accepted = 0)
LEFT JOIN {emarking_marker_criterion} mc ON (mc.criterion = l.criterionid AND mc.emarking = nm.id AND mc.marker=?)
GROUP BY d.id
) AS NM ON $sqljoinon
LEFT JOIN {user} as um ON (NM.marker = um.id)
$userfilter
GROUP BY $sqlgroupby
";
if ($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING || $emarking->type == EMARKING_TYPE_PRINT_SCAN || $emarking->type == EMARKING_TYPE_PEER_REVIEW) {
    $params = array(
        $course->id,
        $emarking->id,
        $USER->id
    );
} else {
    $params = array(
        $emarking->id,
        $emarking->id,
        $USER->id
    );
}
// Run the query on the database.
$drafts = $DB->get_recordset_sql($sqldrafts, $params, $page * $perpage, $perpage);
// Counting students for pagination.
$allstudents = emarking_get_students_for_printing($cm->course);
$countstudents = 0;
foreach($allstudents as $student) {
    $countstudents++;
}
$totalstudents = $countstudents;
// If students upload their submissions, get the list or the submission itself.
if($emarking->uploadtype == EMARKING_UPLOAD_FILE) {
    $fs = get_file_storage();
    if($usercangrade) {
        $submissionfiles = $fs->get_area_files($context->id, 'mod_emarking', 'submission');
    } else {
        $submissionfiles = $fs->get_area_files($context->id, 'mod_emarking', 'submission', $USER->id);
    }
}
// Here starts the table
$actionsheader = "";
if (has_capability("mod/emarking:supervisegrading", $context) && !$scan && $rubriccriteria && ($emarking->type != EMARKING_TYPE_MARKER_TRAINING)) {
    $actionsheader .= $usercangrade ? '<input type="checkbox" id="select_all" title="' . get_string('selectall', 'mod_emarking') . '">' : '';
}
$headers = array();
$headers[] = get_string('names', 'mod_emarking');
if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING || ($emarking->type == EMARKING_TYPE_PEER_REVIEW && $issupervisor)) {
    $headers[] = get_string('marker', 'mod_emarking');
}
if ($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING || $emarking->type == EMARKING_TYPE_PEER_REVIEW) {
    $headers[] = get_string('grade', 'mod_emarking');
}
$headers[] = get_string('status', 'mod_emarking');
$headers[] = get_string('actions', 'mod_emarking');
$headers[] = $actionsheader;
$columns = array();
$columns[] = 'lastname';
if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING || ($emarking->type == EMARKING_TYPE_PEER_REVIEW && $issupervisor)) {
    $columns[] = 'marker';
}
if ($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING || $emarking->type == EMARKING_TYPE_PEER_REVIEW) {
    $columns[] = 'grade';
}
$columns[] = 'status';
$columns[] = 'actions';
$columns[] = 'select';
// Define flexible table (can be sorted in different ways).
$showpages = new flexible_table('emarking-view-' . $cm->id);
$showpages->set_attribute('id', 'emarking-main');
$showpages->define_headers($headers);
$showpages->define_columns($columns);
$showpages->define_baseurl($urlemarking);
$showpages->column_class('actions', 'actions');
$showpages->column_class('lastname', 'lastname');
if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING || ($emarking->type == EMARKING_TYPE_PEER_REVIEW && $issupervisor)) {
    $showpages->column_class('marker', 'lastname');
}
$defaulttsort = $emarking->anonymous < 2 ? null : 'lastname';
$showpages->sortable(true, $defaulttsort, SORT_ASC);
if ($emarking->anonymous < 2) {
    $showpages->no_sorting('lastname');
}
$showpages->no_sorting('comment');
$showpages->no_sorting('actions');
$showpages->no_sorting('select');
$showpages->pageable(true);
$showpages->pagesize($perpage, $totalstudents);
$showpages->setup();
// Decide on sorting depending on URL parameters and flexible table configuration.
$orderby = $emarking->anonymous < 2 ? 'ORDER BY sort ASC' : 'ORDER BY u.lastname ASC';
if ($showpages->get_sql_sort()) {
    $orderby = 'ORDER BY ' . $showpages->get_sql_sort();
    $tsort = $showpages->get_sql_sort();
}
// Get submissions with extra info to show.
$sqldrafts .= $orderby;
// Run the query on the database.
$drafts = $DB->get_recordset_sql($sqldrafts, $params, $page * $perpage, $perpage);
$unpublishedsubmissions = 0;
// Prepare data for the table.
foreach($drafts as $draft) {
    // Student info.
    $userinfo = emarking_get_userinfo($draft, $course, $emarking);
    $submissiondrafts = emarking_get_drafts_from_concat($draft);
    // Status and marking progress.
    $pctmarked = '';
    // Action buttons.
    $actions = "";
    // Draft final grade.
    $finalgrade = '';
    // Feedback.
    $feedback = '';
    // Last modified.
    $timemodified = '';
    // Markers pictures.
    $markersstring = '';
    // Select checkbox.
    $selectdraft = '';
    foreach($submissiondrafts as $d) {
        $pctmarked .= emarking_get_draft_status_info($exam, $d, $numcriteria, $numcriteriauser, $emarking, $rubriccriteria);
        $finalgrade .= emarking_get_finalgrade($d, $usercangrade, $issupervisor, $draft, $rubricscores, $emarking);
        $actions .= emarking_get_actions($d, $emarking, $context, $draft, $usercangrade, $issupervisor, $usercanpublishgrades, $numcriteria, $scan, $cm, $rubriccriteria);
        $feedback .= strlen($d->feedback) > 0 ? $d->feedback : '';
        $timemodified .= html_writer::start_div("timemodified");
        $timemodified .= get_string('lastmodification', 'mod_emarking');
        $timemodified .= "&nbsp;";
        $timemodified .= $d->timemodified > 0 ? core_text::strtolower(emarking_time_ago($d->timemodified)) : '';
        $timemodified .= html_writer::end_div();
        // Checkbox for publishing grade.
        if ($usercanpublishgrades && ($d->qc == 0 || ($d->qc == 1 && $emarking->type == EMARKING_TYPE_PEER_REVIEW)) && $d->status >= EMARKING_STATUS_SUBMITTED && $d->status < EMARKING_STATUS_PUBLISHED && $rubriccriteria) {
            $selectdraft .= "<input type=\"checkbox\" name=\"publish[]\" value=\"$d->id\" title=\"" . get_string("select") . "\">";
        }
        if ($emarking->type == EMARKING_TYPE_PEER_REVIEW) {
            $marker = $DB->get_record("user", array(
                "id" => $d->marker
            ));
            if($marker) {
                $markersstring .= $OUTPUT->user_picture($marker) . '&nbsp;' . $marker->lastname . ', ' . $marker->firstname;
            } else {
                $markersstring .= get_string('markernotassigned', 'mod_emarking');
            }
        } else 
            if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING) {
                foreach($markers as $marker) {
                    if ($d->marker == $marker->id) {
                        $markersstring .= $OUTPUT->user_picture($marker) . '&nbsp;';
                    }
                }
            }
        if ($usercanpublishgrades && ($d->qc == 0 || ($d->qc == 1 && $emarking->type == EMARKING_TYPE_PEER_REVIEW)) && $d->status >= EMARKING_STATUS_SUBMITTED && $d->status < EMARKING_STATUS_PUBLISHED && $rubriccriteria) {
            $unpublishedsubmissions++;
        }
    }
    $data = array();
    $data[] = $userinfo;
    if ($emarking->type == EMARKING_TYPE_MARKER_TRAINING || ($emarking->type == EMARKING_TYPE_PEER_REVIEW && $issupervisor)) {
        $data[] = $markersstring;
    }
    if ($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING || $emarking->type == EMARKING_TYPE_PEER_REVIEW) {
        $data[] = $finalgrade;
    }
    $data[] = $pctmarked . ($draft->answerkey ? $OUTPUT->pix_icon('i/badge', get_string('answerkey', 'mod_emarking')) : '');
    $data[] = $actions;
    $data[] = $selectdraft;
    $showpages->add_data($data, $draft->answerkey ? "alert-success" : "");
}
$showpages->finish_html();
?>
<style>
.scol, .generaltable td {
	vertical-align: middle;
}
</style>
<?php if ($usercangrade) {?>
<script type="text/javascript">
$('#select_all').change(function() {
    var checkboxes = $('#emarking-main').find(':checkbox');
    if($(this).is(':checked')) {
    	console.log('jelou guorld true');
        checkboxes.prop('checked', true);
        $('#select_all').prop('title','<?php echo get_string('selectnone', 'mod_emarking') ?>');
    } else {
    	console.log('jelou guorld false');
        checkboxes.prop('checked', false);
        $('#select_all').prop('title','<?php echo get_string('selectall', 'mod_emarking') ?>');
	}
});
$('#searchInput').keyup(function () {
    //split the current value of searchInput
    var data = this.value.split(" ");
    //create a jquery object of the rows
    var jo = $("#emarking-main").find("tbody").find("tr");
    if (this.value == "") {
        jo.filter(function (i, v) {
            var $t = $(this);
            if ($t.hasClass("emptyrow")) {
            	return false;
            }
            return true;
        })
        .show();
        return;
    }
    //hide all the rows
    jo.hide();

    //Recusively filter the jquery object to get results.
    jo.filter(function (i, v) {
        var $t = $(this);
        for (var d = 0; d < data.length; ++d) {
            if ($t.is(":contains('" + data[d] + "')")) {
                return true;
            }
        }
        return false;
    })
    //show the rows that match.
    .show();
}).focus(function () {
    this.value = "";
    $(this).css({
        "color": "black"
    });
    $(this).unbind('focus');
}).css({
    "color": "#C0C0C0"
});
function validatePublish() {
	var checkboxes = $('#emarking-main').find(':checkbox');
	var checked = 0;
	checkboxes.each(function () {
		if($(this).is(':checked')) {
			checked++;
		}
	});
	if(checked > 0) {
		return confirm('<?php echo get_string('areyousure', 'mod_emarking') ?>');
	} else {
		alert('<?php echo get_string('nosubmissionsselectedforpublishing', 'mod_emarking') ?>');
		return false;
	}
}
</script>
<?php
}
if ($usercanpublishgrades && $unpublishedsubmissions > 0 && $rubriccriteria) {
    echo "<input style='float:right;' type='submit' onclick='return validatePublish();' value='" . get_string('publishselectededgrades', 'mod_emarking') . "'>";
    echo "</form>";
} else 
    if ($usercanpublishgrades && $unpublishedsubmissions == 0) {
        echo "<script>$('#select_all').hide();</script>";
    }
$submission = $DB->get_record('emarking_submission', array(
    'emarking' => $emarking->id,
    'student' => $USER->id
));
if($usercanpublishgrades) {
    emarking_show_orphan_pages_link($context, $cm);
}
// If the user is a tutor or teacher we don't include justice perception.
if ($usercangrade || !$submission) {
    echo $OUTPUT->footer();
    die();
}
// JUSTICE PERCEPTION FOR CURRENT USER.
if ($emarking->justiceperception != EMARKING_JUSTICE_DISABLED && !$submission->seenbystudent) {
    echo $OUTPUT->heading(get_string("justice", "mod_emarking"), 4);
    echo $OUTPUT->notification(get_string("mustseefeedbackbeforejustice", "mod_emarking"), "notifymessage");
    echo $OUTPUT->footer();
    die();
}
$record = $submission ? $DB->get_record('emarking_perception', array(
    "submission" => $submission->id
)) : null;
// If the user can not grade, we show them.
if ($emarking->justiceperception == EMARKING_JUSTICE_PER_CRITERION) {
    require_once ($CFG->dirroot . '/mod/emarking/forms/justice_form_criterion.php');
    $prevrecords = array();
    $criteriarecords = $record ? $DB->get_records('emarking_perception_criteria', array(
        "perception" => $record->id
    )) : null;
    $prevdata = array();
    if ($criteriarecords) {
        foreach($criteriarecords as $criterionjustice) {
            $prevdata['of-' . $criterionjustice->criterion] = $criterionjustice->overall_fairness;
            $prevdata['er-' . $criterionjustice->criterion] = $criterionjustice->expectation_reality;
        }
    }
    $prevdata['comment'] = $record ? $record->comment : '';
    $mform = new justice_form_criterion($urlemarking, array(
        'rubriccriteria' => $rubriccriteria
    ), 'post');
    $mform->set_data($prevdata);
    if ($mform->get_data()) {
        if (!$record) {
            $record = new stdClass();
            $record->overall_fairness = 0;
            $record->expectation_reality = 0;
        }
        $record->submission = $submission->id;
        $record->timecreated = time();
        $record->comment = $mform->get_data()->comment;
        $transaction = $DB->start_delegated_transaction();
        if (isset($record->id)) {
            $DB->update_record('emarking_perception', $record);
        } else {
            $record->id = $DB->insert_record('emarking_perception', $record);
        }
        foreach($rubriccriteria->rubric_criteria as $criterion) {
            $sdata = (array) $mform->get_data();
            $justicecriterion = $DB->get_record('emarking_perception_criteria', array(
                "perception" => $record->id,
                "criterion" => $criterion['id']
            ));
            if (!$justicecriterion) {
                $justicecriterion = new stdClass();
            }
            $justicecriterion->overall_fairness = $sdata['of-' . $criterion['id']];
            $justicecriterion->expectation_reality = $sdata['er-' . $criterion['id']];
            $justicecriterion->timemodified = time();
            if (isset($justicecriterion->id)) {
                $DB->update_record('emarking_perception_criteria', $justicecriterion);
            } else {
                $justicecriterion->perception = $record->id;
                $justicecriterion->criterion = $criterion['id'];
                $justicecriterion->timecreated = time();
                $justicecriterion->id = $DB->insert_record('emarking_perception_criteria', $justicecriterion);
            }
        }
        $DB->commit_delegated_transaction($transaction);
        echo $OUTPUT->notification(get_string('thanksforjusticeperception', 'mod_emarking'), 'notifysuccess');
    }
    $mform->display();
} else 
    if ($emarking->justiceperception == EMARKING_JUSTICE_PER_SUBMISSION) {
        require_once ($CFG->dirroot . '/mod/emarking/forms/justice_form.php');
        $mform = new justice_form($urlemarking, null, 'post');
        $mform->set_data($record);
        if ($mform->get_data()) {
            if (!$record) {
                $record = new stdClass();
            }
            $record->submission = $submission->id;
            $record->overall_fairness = $mform->get_data()->overall_fairness;
            $record->expectation_reality = $mform->get_data()->expectation_reality;
            $record->timecreated = time();
            $record->comment = $mform->get_data()->comment;
            if (isset($record->id)) {
                $DB->update_record('emarking_perception', $record);
            } else {
                $record->id = $DB->insert_record('emarking_perception', $record);
            }
            echo $OUTPUT->notification(get_string('thanksforjusticeperception', 'mod_emarking'), 'notifysuccess');
        }
        $mform->display();
    }
echo $OUTPUT->footer();

function emarking_get_userinfo($draft, $course, $emarking) {
    global $OUTPUT, $USER;
    $profileurl = new moodle_url('/user/view.php', array(
        'id' => $draft->id,
        'course' => $course->id
    ));
    if ($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING || $emarking->type == EMARKING_TYPE_PRINT_SCAN || $emarking->type == EMARKING_TYPE_PEER_REVIEW) {
        $userinfo = $emarking->anonymous < 2 && $USER->id != $draft->id ? get_string('anonymousstudent', 'mod_emarking') : $OUTPUT->user_picture($draft, array(
            'size' => 35,
            'class' => 'userpicture'
        )) . '&nbsp;<a href="' . $profileurl . '">' . $draft->lastname . ', ' . $draft->firstname . '</a>';
    } else {
        $userinfo = get_string('exam', 'mod_emarking') . ' ' . $draft->submission;
    }
    return $userinfo;
}

function emarking_get_finalgrade($d, $usercangrade, $issupervisor, $draft, $rubricscores, $emarking) {
    global $USER, $OUTPUT;
    // Bonus info.
    $bonusinfo = $d->bonus != 0 ? round($d->bonus, 2) . " " : ' ';
    $bonusinfo = ($d->bonus > 0 ? '+' : '') . $bonusinfo;
    $gradevalue = round(floatval($d->grade), 2);
    $thisfinalgrade = '-';
    if ((($usercangrade || $issupervisor) && (($d->status >= EMARKING_STATUS_GRADING && $emarking->type != EMARKING_TYPE_PEER_REVIEW) || ($d->status >= EMARKING_STATUS_GRADING && $draft->id != $USER->id && $emarking->type == EMARKING_TYPE_PEER_REVIEW))) || ($d->status >= EMARKING_STATUS_PUBLISHED && ($draft->id == $USER->id || $draft->answerkey))) {
        $thisfinalgrade = $gradevalue;
    } else 
        if ($d->status <= EMARKING_STATUS_MISSING) {
            $thisfinalgrade = "";
        }
    if ($d->status >= EMARKING_STATUS_PUBLISHED) {
        $thisfinalgrade = '<strong>' . $thisfinalgrade . '</strong>';
    }
    $finalgrade = $OUTPUT->box($thisfinalgrade, 'generalbox grade', null, array(
        'title' => round($d->score, 2) . $bonusinfo . get_string('of', 'mod_emarking') . " " . $rubricscores['maxscore'] . " " . get_string('points', 'grades')
    ));
    return $finalgrade;
}

function emarking_get_actions($d, $emarking, $context, $draft, $usercangrade, $issupervisor, $publishgradesform, $numcriteria, $scan, $cm, $rubriccriteria) {
    global $OUTPUT, $USER;
    $owndraft = $USER->id == $draft->id;
    // Action buttons.
    $actionsarray = array();
    // EMarking popup url.
    $popupurl = new moodle_url('/mod/emarking/marking/index.php', array(
        'id' => $d->id
    ));
    if($emarking->type == EMARKING_TYPE_PEER_REVIEW && $owndraft && $d->status < EMARKING_STATUS_PUBLISHED) {
        return null;
    }
    // EMarking button.
    if (($usercangrade && $d->status >= EMARKING_STATUS_SUBMITTED && $numcriteria > 0) || $d->status >= EMARKING_STATUS_PUBLISHED || ($emarking->type == EMARKING_TYPE_PRINT_SCAN && $d->status >= EMARKING_STATUS_SUBMITTED)) {
        $label = ($usercangrade && !$scan) ? get_string('annotatesubmission', 'mod_emarking') : get_string('viewsubmission', 'mod_emarking');
        $actionsarray[] = $OUTPUT->action_link($popupurl, $label, new popup_action('click', $popupurl, 'emarking' . $d->id, array(
            'menubar' => 'no',
            'titlebar' => 'no',
            'status' => 'no',
            'toolbar' => 'no',
            'width' => 860,
            'height' => 600
        )));
    }
    // Mark draft as absent/sent.
    if ((($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING && $d->qc == 0) || ($emarking->type == EMARKING_TYPE_PEER_REVIEW && $d->qc == 1)) && (is_siteadmin($USER) || ($issupervisor && $usercangrade)) && $d->status > EMARKING_STATUS_MISSING) {
        if($emarking->type == EMARKING_TYPE_ON_SCREEN_MARKING && $d->qc == 0) {
        $newstatus = $d->status >= EMARKING_STATUS_SUBMITTED ? EMARKING_STATUS_ABSENT : EMARKING_STATUS_SUBMITTED;
        $deletesubmissionurl = new moodle_url('/mod/emarking/marking/updatesubmission.php', array(
            'ids' => $d->id,
            'id' => $cm->id,
            'status' => $newstatus
        ));
        $msgstatus = $d->status >= EMARKING_STATUS_SUBMITTED ? get_string('setasabsent', 'mod_emarking') : get_string('setassubmitted', 'mod_emarking');
        $actionsarray[] = $OUTPUT->action_link($deletesubmissionurl, $msgstatus);
        }
        $printversionurl = new moodle_url('/mod/emarking/marking/printversion.php', array(
            'id' => $cm->id,
            'did' => $d->id
        ));
        $actionsarray[] = $OUTPUT->action_link($printversionurl, "PDF");
    }
    if($emarking->uploadtype == EMARKING_UPLOAD_FILE &&
            has_capability('mod/emarking:submit', $context) &&
            ($owndraft || $issupervisor)) {
        $uploadanswerurl = new moodle_url('/mod/emarking/marking/uploadsubmission.php', array(
            'id' => $cm->id,
            'sid' => $draft->id
        ));
        if($d->status < EMARKING_STATUS_SUBMITTED) {
            $actionsarray[] = $OUTPUT->action_link($uploadanswerurl, get_string('uploadsubmission', 'mod_emarking'));
        } else if($owndraft) {
            $printversionurl = new moodle_url('/mod/emarking/marking/printversion.php', array(
                'id' => $cm->id,
                'did' => $d->id
            ));
            $actionsarray[] = $OUTPUT->action_link($printversionurl, "PDF");
        }
    }
    $divclass = $usercangrade ? 'printactions' : 'useractions';
    $actionshtml = implode("&nbsp;|&nbsp;", $actionsarray);
    if ($emarking->type != EMARKING_TYPE_MARKER_TRAINING) {
        $actions = html_writer::div($actionshtml, $divclass);
    } else {
        $actions = $actionshtml . "&nbsp;|&nbsp;";
    }
    return $actions;
}

function emarking_get_drafts_from_concat($draft) {
    $draftids = explode('#', $draft->draft);
    $draftqcs = explode('#', $draft->qcs);
    $draftstatuses = explode('#', $draft->status);
    $draftspages = explode('#', $draft->pages);
    $draftscomments = explode('#', $draft->comments);
    $draftspctmarked = explode('#', $draft->pctmarked);
    $draftspctmarkeduser = explode('#', $draft->pctmarkeduser);
    $draftscriteriaids = explode('#', $draft->criteriaids);
    $draftscriteriascores = explode('#', $draft->criteriascores);
    $draftsregrades = explode('#', $draft->regrades);
    $draftsbonus = explode('#', $draft->bonus);
    $draftsgrade = explode('#', $draft->grade);
    $draftsscore = explode('#', $draft->score);
    $feedbacks = explode('#', $draft->feedback);
    $timesmodified = explode('#', $draft->timemodified);
    $markersids = explode('#', $draft->markerid);
    $drafts = array();
    for($i = 0; $i < count($draftids); $i++) {
        $newdraft = new stdClass();
        $newdraft->id = $draftids[$i];
        $newdraft->qc = $draftqcs[$i];
        $newdraft->status = $draftstatuses[$i];
        $newdraft->pages = $draftspages[$i];
        $newdraft->comments = $draftscomments[$i];
        $newdraft->pctmarked = $draftspctmarked[$i];
        $newdraft->pctmarkeduser = $draftspctmarkeduser[$i];
        $newdraft->criteriaids = $draftscriteriaids[$i];
        $newdraft->criteriascores = $draftscriteriascores[$i];
        $newdraft->regrades = $draftsregrades[$i];
        $newdraft->bonus = $draftsbonus[$i];
        $newdraft->grade = $draftsgrade[$i];
        $newdraft->score = $draftsscore[$i];
        $newdraft->feedback = $feedbacks[$i];
        $newdraft->timemodified = $timesmodified[$i];
        $newdraft->marker = $markersids[$i];
        $drafts[] = $newdraft;
    }
    return $drafts;
}

