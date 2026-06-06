<?php
/**
 * Import Moodle role presets from XML files in a Playground blueprint.
 *
 * The importer is idempotent:
 * - Missing roles are created.
 * - Existing roles with the same shortname are updated.
 * - Context levels, system-level capabilities and role relationships are replaced
 *   with the values from the XML presets.
 * - Capabilities belonging to plugins that are not installed are skipped.
 *
 * Designed for Moodle 4.5 and execution after config.php has been loaded.
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Import a group of Moodle XML role presets.
 *
 * @param string[] $xmlpaths Absolute paths to the XML files.
 * @return void
 */
function playground_import_role_presets(array $xmlpaths): void {
    global $CFG, $DB, $USER;

    require_once($CFG->dirroot . '/admin/roles/classes/preset.php');
    require_once($CFG->libdir . '/accesslib.php');

    if (empty($xmlpaths)) {
        throw new moodle_exception('No role preset XML files were provided.');
    }

    $admin = get_admin();
    if (!$admin) {
        throw new moodle_exception('The Moodle administrator account could not be found.');
    }
    $USER = $admin;

    $systemcontext = context_system::instance();
    $presets = [];
    $roleids = [];

    // Read and validate every XML before making any changes.
    foreach ($xmlpaths as $xmlpath) {
        if (!is_string($xmlpath) || !is_readable($xmlpath)) {
            throw new moodle_exception('Role preset XML is not readable: ' . (string)$xmlpath);
        }

        $xml = file_get_contents($xmlpath);
        if ($xml === false || trim($xml) === '') {
            throw new moodle_exception('Role preset XML is empty: ' . $xmlpath);
        }

        if (!core_role_preset::is_valid_preset($xml)) {
            throw new moodle_exception('Invalid Moodle role preset XML: ' . $xmlpath);
        }

        $info = core_role_preset::parse_preset($xml);
        if (!is_array($info) || empty($info['shortname']) || empty($info['name'])) {
            throw new moodle_exception('Role preset lacks a valid shortname or name: ' . $xmlpath);
        }

        $shortname = (string)$info['shortname'];
        if (isset($presets[$shortname])) {
            throw new moodle_exception('Duplicate role shortname in imported presets: ' . $shortname);
        }

        $presets[$shortname] = [
            'path' => $xmlpath,
            'xml' => $xml,
            'initial' => $info,
        ];
    }

    /*
     * First pass: create or update all role records.
     * This ensures that cross-references such as coordinacion <-> tutor can be
     * resolved when the XML files are parsed again in the second pass.
     */
    foreach ($presets as $shortname => $preset) {
        $info = $preset['initial'];
        $name = (string)($info['name'] ?? $shortname);
        $description = (string)($info['description'] ?? '');
        $archetype = (string)($info['archetype'] ?? '');

        $role = $DB->get_record('role', ['shortname' => $shortname]);

        if ($role) {
            $role->name = $name;
            $role->description = $description;
            $role->archetype = $archetype;
            $DB->update_record('role', $role);
            $roleids[$shortname] = (int)$role->id;
            mtrace("Role record updated: {$shortname} (ID {$role->id})");
        } else {
            $roleid = create_role($name, $shortname, $description, $archetype);
            $roleids[$shortname] = (int)$roleid;
            mtrace("Role record created: {$shortname} (ID {$roleid})");
        }
    }

    /*
     * Second pass: parse again now that all custom roles exist, then replace
     * contexts, capabilities and inter-role relationships.
     */
    foreach ($presets as $shortname => $preset) {
        $info = core_role_preset::parse_preset($preset['xml']);
        if (!is_array($info)) {
            throw new moodle_exception('Role preset could not be parsed on second pass: ' . $preset['path']);
        }

        $roleid = $roleids[$shortname];

        // Assignment contexts.
        $contextlevels = array_values(array_map('intval', $info['contextlevels'] ?? []));
        set_role_contextlevels($roleid, $contextlevels);

        // Replace system-level role capability definitions.
        $DB->delete_records('role_capabilities', [
            'roleid' => $roleid,
            'contextid' => $systemcontext->id,
        ]);

        $applied = 0;
        $inherited = 0;
        $skipped = 0;

        foreach (($info['permissions'] ?? []) as $capability => $permission) {
            $permission = (int)$permission;

            // CAP_INHERIT is represented by the absence of a DB override.
            if ($permission === CAP_INHERIT) {
                $inherited++;
                continue;
            }

            // XML exports may include capabilities from plugins absent in Playground.
            if (!get_capability_info((string)$capability, false)) {
                $skipped++;
                continue;
            }

            assign_capability(
                (string)$capability,
                $permission,
                $roleid,
                $systemcontext,
                true,
                [ACCESSLIB_HINT_CONTEXT_EXISTS, ACCESSLIB_HINT_NO_EXISTING]
            );
            $applied++;
        }

        // Replace role relationship tables.
        $relations = [
            'allowassign' => [
                'table' => 'role_allow_assign',
                'targetfield' => 'allowassign',
                'helper' => 'core_role_set_assign_allowed',
            ],
            'allowoverride' => [
                'table' => 'role_allow_override',
                'targetfield' => 'allowoverride',
                'helper' => 'core_role_set_override_allowed',
            ],
            'allowswitch' => [
                'table' => 'role_allow_switch',
                'targetfield' => 'allowswitch',
                'helper' => 'core_role_set_switch_allowed',
            ],
            'allowview' => [
                'table' => 'role_allow_view',
                'targetfield' => 'allowview',
                'helper' => 'core_role_set_view_allowed',
            ],
        ];

        $relationcount = 0;

        foreach ($relations as $infokey => $relation) {
            $DB->delete_records($relation['table'], ['roleid' => $roleid]);

            $targets = [];
            foreach (($info[$infokey] ?? []) as $targetroleid) {
                $targetroleid = (int)$targetroleid;

                // core_role_preset uses -1 to represent a self-reference.
                if ($targetroleid === -1) {
                    $targetroleid = $roleid;
                }

                if ($targetroleid <= 0 || !$DB->record_exists('role', ['id' => $targetroleid])) {
                    continue;
                }

                $targets[$targetroleid] = $targetroleid;
            }

            foreach ($targets as $targetroleid) {
                if (!$DB->record_exists($relation['table'], [
                    'roleid' => $roleid,
                    $relation['targetfield'] => $targetroleid,
                ])) {
                    call_user_func($relation['helper'], $roleid, $targetroleid);
                    $relationcount++;
                }
            }
        }

        mtrace(
            "Role {$shortname}: {$applied} capabilities applied, " .
            "{$inherited} inherited, {$skipped} unavailable capabilities skipped, " .
            "{$relationcount} role relationships applied."
        );
    }

    accesslib_reset_role_cache();
    purge_all_caches();

    // Final verification.
    foreach ($roleids as $shortname => $roleid) {
        if (!$DB->record_exists('role', ['id' => $roleid, 'shortname' => $shortname])) {
            throw new moodle_exception('Role verification failed after import: ' . $shortname);
        }
    }

    mtrace('Role preset import completed successfully: ' . implode(', ', array_keys($roleids)));
}
