<?php
/**
 * Import standard Moodle grade scales from a JSON export.
 *
 * Expected JSON format:
 * {
 *   "format": "moodle-scale-export",
 *   "format_version": 1,
 *   "scales": [
 *     {
 *       "standard": true,
 *       "name": "Scale name",
 *       "items": "Lowest,Middle,Highest",
 *       "description": "",
 *       "descriptionformat": 1,
 *       "files": []
 *     }
 *   ]
 * }
 *
 * Idempotency:
 * - A standard scale is identified by the exact pair name + items.
 * - If that pair already exists, its description is updated.
 * - If the name exists with different items, a separate scale is created.
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Import standard grade scales from JSON.
 *
 * @param string $jsonpath Absolute path to the JSON file.
 * @return void
 */
function playground_import_standard_scales(string $jsonpath): void {
    global $CFG, $DB, $USER;

    require_once($CFG->libdir . '/grade/grade_scale.php');
    require_once($CFG->libdir . '/filelib.php');

    if (!is_readable($jsonpath)) {
        throw new moodle_exception('Scale JSON is not readable: ' . $jsonpath);
    }

    $raw = file_get_contents($jsonpath);
    if ($raw === false || trim($raw) === '') {
        throw new moodle_exception('Scale JSON is empty: ' . $jsonpath);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new moodle_exception(
            'Scale JSON is invalid: ' . json_last_error_msg()
        );
    }

    if (($data['format'] ?? '') !== 'moodle-scale-export') {
        throw new moodle_exception(
            'Unexpected scale JSON format. Expected moodle-scale-export.'
        );
    }

    if ((int)($data['format_version'] ?? 0) !== 1) {
        throw new moodle_exception(
            'Unsupported scale JSON format version: ' .
            (string)($data['format_version'] ?? '')
        );
    }

    if (empty($data['scales']) || !is_array($data['scales'])) {
        throw new moodle_exception('The scale JSON does not contain any scales.');
    }

    $admin = get_admin();
    if (!$admin) {
        throw new moodle_exception('The Moodle administrator account was not found.');
    }
    $USER = $admin;

    $systemcontext = context_system::instance();
    $fs = get_file_storage();

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $filescreated = 0;
    $seen = [];

    foreach ($data['scales'] as $index => $entry) {
        if (!is_array($entry)) {
            $skipped++;
            mtrace('Skipped non-object scale entry at index ' . $index);
            continue;
        }

        // This importer intentionally handles site-wide scales only.
        if (array_key_exists('standard', $entry) && !$entry['standard']) {
            $skipped++;
            mtrace('Skipped non-standard scale at index ' . $index);
            continue;
        }

        $name = trim((string)($entry['name'] ?? ''));
        $items = trim((string)($entry['items'] ?? ''));

        if ($name === '') {
            throw new moodle_exception('Scale at index ' . $index . ' has no name.');
        }

        if ($items === '') {
            throw new moodle_exception(
                'Scale "' . $name . '" has no scale items.'
            );
        }

        // Moodle stores scale items as one comma-separated string.
        $itemarray = array_map('trim', explode(',', $items));
        $itemarray = array_values(array_filter(
            $itemarray,
            static fn($item): bool => $item !== ''
        ));

        if (count($itemarray) < 2) {
            throw new moodle_exception(
                'Scale "' . $name . '" must contain at least two non-empty items.'
            );
        }

        $normaliseditems = implode(', ', $itemarray);
        $key = sha1(core_text::strtolower($name) . "\n" . $normaliseditems);

        if (isset($seen[$key])) {
            $skipped++;
            mtrace(
                'Skipped duplicate entry inside JSON: ' .
                $name . ' [' . $normaliseditems . ']'
            );
            continue;
        }
        $seen[$key] = true;

        $description = (string)($entry['description'] ?? '');
        $descriptionformat = (int)($entry['descriptionformat'] ?? FORMAT_HTML);
        if (!in_array(
            $descriptionformat,
            [FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN],
            true
        )) {
            $descriptionformat = FORMAT_HTML;
        }

        /*
         * Find an exact standard-scale match by name and item string.
         * Text columns are compared portably for all supported databases.
         */
        $select = 'courseid = 0
                   AND ' . $DB->sql_compare_text('name') . ' = ' .
                       $DB->sql_compare_text(':name') . '
                   AND ' . $DB->sql_compare_text('scale') . ' = ' .
                       $DB->sql_compare_text(':scale');

        $matches = $DB->get_records_select(
            'scale',
            $select,
            ['name' => $name, 'scale' => $normaliseditems],
            'id ASC'
        );

        if ($matches) {
            $record = reset($matches);
            $scale = new grade_scale(['id' => (int)$record->id]);
            $scale->standard = true;
            $scale->courseid = 0;
            $scale->userid = (int)$admin->id;
            $scale->name = $name;
            $scale->scale = $normaliseditems;
            $scale->description = $description;
            $scale->descriptionformat = $descriptionformat;

            if (!$scale->update('playground_scale_import')) {
                throw new moodle_exception(
                    'Could not update standard scale: ' . $name
                );
            }

            $scaleid = (int)$scale->id;
            $updated++;
            mtrace(
                'Standard scale updated: ' .
                $name . ' (ID ' . $scaleid . ')'
            );
        } else {
            $scale = new grade_scale(null, false);
            $scale->standard = true;
            $scale->courseid = 0;
            $scale->userid = (int)$admin->id;
            $scale->name = $name;
            $scale->scale = $normaliseditems;
            $scale->description = $description;
            $scale->descriptionformat = $descriptionformat;

            $scaleid = $scale->insert('playground_scale_import');
            if (!$scaleid) {
                throw new moodle_exception(
                    'Could not create standard scale: ' . $name
                );
            }

            $scaleid = (int)$scaleid;
            $created++;
            mtrace(
                'Standard scale created: ' .
                $name . ' (ID ' . $scaleid . ')'
            );
        }

        /*
         * Recreate optional files embedded in the scale description.
         * The manually-created JSON currently contains no files, but supporting
         * them makes the importer reusable for future exports.
         */
        $fs->delete_area_files(
            $systemcontext->id,
            'grade',
            'scale',
            $scaleid
        );

        foreach (($entry['files'] ?? []) as $fileindex => $file) {
            if (!is_array($file)) {
                continue;
            }

            $filename = clean_param(
                (string)($file['filename'] ?? ''),
                PARAM_FILE
            );
            if ($filename === '' || $filename === '.') {
                continue;
            }

            $filepath = (string)($file['filepath'] ?? '/');
            if ($filepath === '' || $filepath[0] !== '/') {
                $filepath = '/' . ltrim($filepath, '/');
            }
            if (substr($filepath, -1) !== '/') {
                $filepath .= '/';
            }

            $content = base64_decode(
                (string)($file['content_base64'] ?? ''),
                true
            );
            if ($content === false) {
                throw new moodle_exception(
                    'Invalid base64 file in scale "' . $name .
                    '" at file index ' . $fileindex
                );
            }

            $filerecord = [
                'contextid' => $systemcontext->id,
                'component' => 'grade',
                'filearea' => 'scale',
                'itemid' => $scaleid,
                'filepath' => $filepath,
                'filename' => $filename,
                'userid' => (int)$admin->id,
            ];

            $fs->create_file_from_string($filerecord, $content);
            $filescreated++;
        }
    }

    \cache_helper::purge_by_event('changesincourse');
    purge_all_caches();

    mtrace(
        'Standard scale import completed: ' .
        $created . ' created, ' .
        $updated . ' updated, ' .
        $skipped . ' skipped, ' .
        $filescreated . ' files created.'
    );
}
