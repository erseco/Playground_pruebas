<?php
/**
 * Import an Adaptable theme JSON export into Moodle Playground.
 *
 * This script is intentionally written for blueprints:
 * - It reads the exported JSON file.
 * - It writes scalar values into mdl_config_plugins as theme_adaptable settings.
 * - It recreates Adaptable file-manager settings such as logo, favicon and
 *   adaptablemarkettingimages in Moodle's File API.
 * - It rewrites absolute pluginfile.php URLs from the source Moodle site to
 *   the current playground wwwroot when they point to theme_adaptable files.
 */

defined('MOODLE_INTERNAL') || die();

function theme_adaptable_playground_import(string $jsonpath): void {
    global $CFG;

    require_once($CFG->libdir . '/filelib.php');

    if (!is_readable($jsonpath)) {
        throw new moodle_exception('Adaptable config JSON not readable: ' . $jsonpath);
    }

    $raw = file_get_contents($jsonpath);
    $settings = json_decode($raw, true);
    if (!is_array($settings)) {
        throw new moodle_exception('Adaptable config JSON is not a valid object: ' . json_last_error_msg());
    }

    $component = 'theme_adaptable';
    $context = context_system::instance();
    $fs = get_file_storage();
    $admin = get_admin();
    $userid = $admin ? (int)$admin->id : 2;

    // Metadata exported by Adaptable; it is useful for humans but must not be
    // written as a theme setting.
    $skip = [
        'moodle_version' => true,
        'plugin_version' => true,
    ];

    $importedsettings = 0;
    $importedfiles = 0;

    foreach ($settings as $name => $value) {
        if (isset($skip[$name])) {
            continue;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else if ($value === null) {
            $value = '';
        } else {
            $value = (string)$value;
        }

        $files = theme_adaptable_playground_decode_file_setting($name, $value);

        if ($files !== null) {
            // The filearea normally has the same name as the Adaptable setting:
            // logo -> theme_adaptable/logo, favicon -> theme_adaptable/favicon, etc.
            $fs->delete_area_files($context->id, $component, $name, 0);

            $firstfilepath = '';
            foreach ($files as $file) {
                if (!is_array($file) || empty($file['filename']) || !isset($file['content'])) {
                    continue;
                }

                $filename = clean_param((string)$file['filename'], PARAM_FILE);
                if ($filename === '' || $filename === '.') {
                    continue;
                }

                $filepath = isset($file['filepath']) ? (string)$file['filepath'] : '/';
                if ($filepath === '') {
                    $filepath = '/';
                }
                if ($filepath[0] !== '/') {
                    $filepath = '/' . $filepath;
                }
                if (substr($filepath, -1) !== '/') {
                    $filepath .= '/';
                }

                $content = base64_decode((string)$file['content'], true);
                if ($content === false) {
                    throw new moodle_exception('Invalid base64 content for Adaptable file: ' . $filename);
                }

                $tmp = tempnam(sys_get_temp_dir(), 'adaptable_');
                file_put_contents($tmp, $content);

                $record = [
                    'contextid' => $context->id,
                    'component' => $component,
                    'filearea' => $name,
                    'itemid' => 0,
                    'filepath' => $filepath,
                    'filename' => $filename,
                    'userid' => $userid,
                    'timecreated' => !empty($file['timecreated']) ? (int)$file['timecreated'] : time(),
                    'timemodified' => !empty($file['timemodified']) ? (int)$file['timemodified'] : time(),
                    'author' => isset($file['author']) ? (string)$file['author'] : '',
                    'license' => isset($file['license']) ? (string)$file['license'] : 'unknown',
                ];

                $fs->create_file_from_pathname($record, $tmp);
                @unlink($tmp);

                if ($firstfilepath === '') {
                    $firstfilepath = $filepath . $filename;
                }
                $importedfiles++;
            }

            // Moodle's admin_setting_configstoredfile stores the first file path
            // in config; the rest remain available in the same filearea.
            set_config($name, $firstfilepath, $component);
            $importedsettings++;
            continue;
        }

        // For HTML/CSS fields that reference files exported above, make those
        // links point to this Playground rather than to the source Moodle site.
        $value = theme_adaptable_playground_rewrite_pluginfile_urls($value);

        set_config($name, $value, $component);
        $importedsettings++;
    }

    set_config('theme', 'adaptable');

    if (function_exists('theme_reset_all_caches')) {
        theme_reset_all_caches();
    }
    purge_all_caches();

    mtrace("Adaptable import completed: {$importedsettings} settings, {$importedfiles} files.");
}

function theme_adaptable_playground_decode_file_setting(string $name, string $value): ?array {
    // Adaptable exports file-manager settings as:
    // {"logo":"[\"{&quot;filepath&quot;: ... , &quot;content&quot;: base64}\"]"}
    $outer = json_decode($value, true);
    if (!is_array($outer) || !array_key_exists($name, $outer) || !is_string($outer[$name])) {
        return null;
    }

    $list = json_decode($outer[$name], true);
    if (!is_array($list)) {
        return null;
    }

    $files = [];
    foreach ($list as $encoded) {
        if (!is_string($encoded) || trim($encoded) === '') {
            continue;
        }

        $decoded = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $file = json_decode($decoded, true);
        if (is_array($file) && isset($file['filename']) && array_key_exists('content', $file)) {
            $files[] = $file;
        }
    }

    return $files;
}

function theme_adaptable_playground_rewrite_pluginfile_urls(string $value): string {
    global $CFG;

    $wwwroot = rtrim($CFG->wwwroot, '/');

    // Example source URL:
    // https://formacion.intef.es/aulavirtual/pluginfile.php/1/theme_adaptable/...
    // Result:
    // {current playground wwwroot}/pluginfile.php/1/theme_adaptable/...
    return preg_replace(
        '~https?://[^"\'\s<>]+(?:/[^"\'\s<>]*)*/pluginfile\.php/([0-9]+/theme_adaptable/)~i',
        $wwwroot . '/pluginfile.php/$1',
        $value
    );
}
