<?php
// Minimal custom course page with SCORM list and progress

require_once(__DIR__ . '/../../config.php');

global $DB, $USER, $PAGE, $OUTPUT;

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);


// Check if user can view course
$context = context_course::instance($course->id);

// Instead of require_capability(), check enrollment:
require_login($course); // ensures user is logged in and enrolled

// var_dump($USER->id);
// var_dump($course->id);
// var_dump(has_capability('moodle/course:view', $context));

// Require the capability to view the course
// if (!has_capability('moodle/course:view', $context)) {
//     throw new required_capability_exception($context, 'moodle/course:view', 'nopermissions', '');
// }

// Optional: explicitly check if user is enrolled
if (!is_enrolled($context, $USER->id)) {
    print_error('notenrolled', 'error', '', $course->fullname);
}

// Minimal layout: no Boost navigation, no tabs
$PAGE->set_context($context);
$PAGE->set_url('/local/customcourse/index.php', ['id' => $courseid]);
$PAGE->set_pagelayout('base'); // base = minimal layout
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

// header with boost tabs
// echo $OUTPUT->header();

// header without boost tabs but with moodle home / courses / links
// echo $OUTPUT->standard_top_of_body_html();

function scorm_duration_to_seconds($duration) {
    if (preg_match('/PT((\d+)H)?((\d+)M)?((\d+(\.\d+)?)S)?/', $duration, $matches)) {
        $hours = !empty($matches[2]) ? (int)$matches[2] : 0;
        $minutes = !empty($matches[4]) ? (int)$matches[4] : 0;
        $seconds = !empty($matches[6]) ? (float)$matches[6] : 0;
        return $hours * 3600 + $minutes * 60 + $seconds;
    }
    return 0;
}

echo $OUTPUT->doctype();

?>
<html <?php echo $OUTPUT->htmlattributes(); ?>>
<head>
    <title><?php echo $PAGE->title; ?></title>
    <?php echo $OUTPUT->standard_head_html(); ?>

    <link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/local/customcourse/assets/css/styles.css">


</head>
<!-- NOTHING from Boost header will appear -->
 

<?php
// Rewrite URLs first
$summary = file_rewrite_pluginfile_urls(
    $course->summary,
    'pluginfile.php',
    $context->id,
    'course',
    'summary',
    null
);

// Then format
// echo format_text($summary, $course->summaryformat, ['context' => $context]);


// Use regex to find first <img> src
$courseimageurl = null;
if (preg_match('/<img[^>]+src="([^">]+)"/i', $summary, $matches)) {
    $courseimageurl = $matches[1];
}

if ($courseimageurl) :
    // echo '<div class="course-hero" style="background-image: url(' . $courseimageurl . ');">';
    // echo '<h1>' . format_string($course->fullname) . '</h1>';
    // echo '</div>';
    ?>
    
    <body <?php echo $OUTPUT->body_attributes(); ?> style="background-image:url(<?php echo $courseimageurl; ?>); ?>">

    <?php else: ?>

    <body <?php echo $OUTPUT->body_attributes(); ?>>

    <?php endif; ?>


<div class="course-header">


<?php



    // $fs = get_file_storage();
    // $files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0, 'sortorder', false);

    // if ($files) {
    //     $file = reset($files);
    //     $url = moodle_url::make_pluginfile_url(
    //         $file->get_contextid(),
    //         $file->get_component(),
    //         $file->get_filearea(),
    //         $file->get_itemid(),
    //         $file->get_filepath(),
    //         $file->get_filename()
    //     );
    //     echo html_writer::empty_tag('img', ['src' => $url, 'alt' => '']);
    // }

    $fs = get_file_storage();

    // Get the overview files (course image)
    $files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0, 'sortorder', false);

    if ($files) {
        $file = reset($files); // take the first image
        $courseimg_url = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            null, //$file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    
    ?>


    <div class="course-thumb"><img src="<?php echo $courseimg_url ; ?>" alt=""></div>
    <h1><?php echo format_string($course->fullname); ?></h1>
    <p><?php //echo format_string($course->summary); ?></p>

</div>

<?php
// Get all SCORM modules in course
$mods = get_fast_modinfo($course)->get_instances_of('scorm');
$completioninfo = new \completion_info($course);
?>

<?php if (!empty($mods)) : ?>
<div class="scorm-grid">
    <?php foreach ($mods as $mod):
        if (!$mod) { continue; }

        $cm = get_coursemodule_from_id('scorm', $mod->id, $course->id, false, MUST_EXIST);
                
        $intro = $mod->get_formatted_content();
        $url = new moodle_url('/mod/scorm/view.php', ['id' => $cm->id]);
        
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $scormid = $scorm->id;
        $userid = $USER->id;
                
        
        // Get all attempts for this user and SCORM
        $attemptid = $DB->get_field('scorm_attempt', 'id', ['scormid'=>$scormid,'userid'=>$userid]);
        $attemptcount = $DB->get_field('scorm_attempt', 'attempt', ['scormid'=>$scormid,'userid'=>$userid]);
    
        // get progress
        $progress = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>17]);
    
        // normalize progress to percent
        $progresspercent = $progress * 100;

        // get success and completion
        $success = 'inconnu';
        $completion = 'inconnu';
        $success = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>18]);
        $completion = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>3]);
    
        $duration = $DB->get_field('scorm_scoes_value', 'value', ['attemptid'=>$attemptid,'elementid'=>2]);
        $totaltime_in_seconds = scorm_duration_to_seconds($duration);

        // Check if SCORM is done
        $status_done = ($completion === 'completed' && $success === 'passed');

        // Default class
        $cardclass = '';
        
        if ($lockNext) {
            // Already found an incomplete one before: lock this
            $cardclass = 'locked';
        } elseif (!$status_done) {
            // First not completed: mark it as active, lock the following
            $lockNext = true;
        }

    ?>
    <div class="scorm-card <?php echo $cardclass; ?>">
        <div class="scorm-thumb">
             <?php if ($cardclass === 'locked'): ?>
                <div class="scorm-title locked-title"><?php echo $intro; ?> 🔒</div>
            <?php else: ?>
                <a href="<?php echo $url; ?>"><?php echo $intro; ?></a>
            <?php endif; ?>
        </div>
        <div class="scorm-details">
            <strong><a href="<?php echo $url; ?>" class="scorm-title"><?php echo format_string($mod->name); ?></a></strong>
            <div><span>Complétion : <?php echo $completion; ?></span> - Success : <?php echo $success; ?></span></div>
            <div>Tentative(s) : <?php echo $attemptcount; ?></div>
            <div>Total time : <?php echo $totaltime_in_seconds; ?>s</div>
            <div class="progress-bar">
                <div class="progress-fill" data-progress="<?echo $progress; ?>">
                    <div class="fill" style="width: <?php echo $progresspercent; ?>%"></div>
                </div>
                <div class="time-percent">
                    <div class="progress-percent"><?php echo $progresspercent; ?>%</div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="course-content">
    <?php //echo $OUTPUT->main_content(); ?>
</div>

<div class="spacer-120"></div>

<?php
// echo $OUTPUT->footer();
?>
</body>
</html>
