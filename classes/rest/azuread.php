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
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace local_o365\rest;

/**
 * API client for Azure AD graph.
 */
class azuread extends \local_o365\rest\o365api {
    /** @var string A value to use for the Azure AD tenant. If null, will use value from local_o365/aadtenant config setting. */
    protected $tenantoverride = null;

    /**
     * Determine if the API client is configured.
     *
     * @return bool Whether the API client is configured.
     */
    public static function is_configured() {
        $config = get_config('local_o365');
        return (!empty($config->aadtenant)) ? true : false;
    }

    /**
     * Get the API client's oauth2 resource.
     *
     * @return string The resource for oauth2 tokens.
     */
    public static function get_resource() {
        return 'https://graph.windows.net';
    }

    /**
     * Transform the full request URL.
     *
     * @param string $requesturi The full request URI, includes the API uri and called endpoint.
     * @return string The transformed full request URI.
     */
    protected function transform_full_request_uri($requesturi) {
        $requesturi .= (strpos($requesturi, '?') === false) ? '?' : '&';
        $requesturi .= 'api-version=1.5';
        return $requesturi;
    }

    /**
     * Test a tenant value.
     *
     * @param string $tenant A tenant string to test.
     * @return bool True if tenant succeeded, false if not.
     */
    public function test_tenant($tenant) {
        if (!is_string($tenant)) {
            throw new \coding_exception('tenant value must be a string');
        }
        $this->tenantoverride = $tenant;
        $appinfo = $this->get_application_info();
        $this->tenantoverride = null;
        if (is_array($appinfo)) {
            if (isset($appinfo['value']) && isset($appinfo['value'][0]['odata.type'])) {
                return ($appinfo['value'][0]['odata.type'] === 'Microsoft.DirectoryServices.Application') ? true : false;
            }
        }
        return false;
    }

    /**
     * Get the base URI that API calls should be sent to.
     *
     * @return string|bool The URI to send API calls to, or false if a precondition failed.
     */
    public function get_apiuri() {
        $tenant = null;
        if (!empty($this->tenantoverride)) {
            $tenant = $this->tenantoverride;
        } else {
            $config = get_config('local_o365');
            if (!empty($config->aadtenant)) {
                $tenant = $config->aadtenant;
            }
        }

        if (!empty($tenant)) {
            return static::get_resource().'/'.$tenant;
        } else {
            return false;
        }
    }

    /**
     * Get information on the current application.
     *
     * @return array|null Array of application information, or null if failure.
     */
    public function get_application_info() {
        $oidcconfig = get_config('auth_oidc');
        $endpoint = '/applications/?$filter=appId%20eq%20\''.$oidcconfig->clientid.'\'';
        $response = $this->apicall('get', $endpoint);
        if (!empty($response)) {
            $response = @json_decode($response, true);
            if (!empty($response) && is_array($response)) {
                return $response;
            }
        }
        return null;
    }

    /**
     * Get information on the current application.
     *
     * @return array|null Array of application information, or null if failure.
     */
    public function get_all_application_info() {
        $endpoint = '/applications';
        $response = $this->apicall('get', $endpoint);
        if (!empty($response)) {
            $response = @json_decode($response, true);
            if (!empty($response) && is_array($response)) {
                return $response;
            }
        }
        return null;
    }

    /**
     * Check whether all permissions defined in $this->get_required_permissions have been assigned.
     *
     * @return array Array of missing permissions.
     */
    public function check_permissions() {
        $this->token->refresh();
        $neededperms = $this->get_required_permissions();
        $servicestoget = array_keys($neededperms);
        $allappdata = $this->get_service_data($servicestoget);
        $currentperms = $this->get_current_permissions();
        $missingperms = [];
        foreach ($neededperms as $app => $perms) {
            $appid = $allappdata[$app]['appId'];
            $appname = $allappdata[$app]['appDisplayName'];
            foreach ($perms as $permname => $neededtype) {
                if (isset($allappdata[$app]['perms'][$permname])) {
                    $permid = $allappdata[$app]['perms'][$permname]['id'];
                    if (!isset($currentperms[$appid][$permid])) {
                        if (isset($allappdata[$app]['perms'][$permname]['adminConsentDisplayName'])) {
                            $permdesc = $allappdata[$app]['perms'][$permname]['adminConsentDisplayName'];
                        } else {
                            $permdesc = $permname;
                        }
                        $missingperms[$appname][$permname] = $permdesc;
                    }
                } else {
                    $missingperms[$appname][$permname] = $permname;
                }
            }
        }

        // Determine whether we have write permissions.
        $writeappid = $allappdata['Microsoft.Azure.ActiveDirectory']['appId'];
        $writepermid = $allappdata['Microsoft.Azure.ActiveDirectory']['perms']['Directory.Write']['id'];
        $impersonatepermid = $allappdata['Microsoft.Azure.ActiveDirectory']['perms']['user_impersonation']['id'];
        $haswrite = (!empty($currentperms[$writeappid][$writepermid])) ? true : false;
        $hasimpersonate = (!empty($currentperms[$writeappid][$impersonatepermid])) ? true : false;
        $canfix = ($hasimpersonate === true) ? true : false;

        return [$missingperms, $canfix];
    }

    /**
     * Update permissions for the application.
     *
     * @return bool Whether the operation was successful.
     */
    public function push_permissions() {
        $this->token->refresh();
        $appinfo = $this->get_application_info();
        $reqdperms = $this->get_required_permissions();
        $svcdata = $this->get_service_data(array_keys($reqdperms));

        $newperms = [];
        foreach ($reqdperms as $appname => $perms) {
            $appid = $svcdata[$appname]['appId'];
            $appperms = ['resourceAppId' => $appid, 'resourceAccess' => []];
            foreach ($perms as $permname => $permtype) {
                $appperms['resourceAccess'][] = [
                    'id' => $svcdata[$appname]['perms'][$permname]['id'],
                    'type' => $permtype
                ];
            }
            $newperms[] = $appperms;
        }
        $newperms = ['value' => $newperms];
        $newperms = json_encode($newperms);
        $endpoint = '/applications/'.$appinfo['value'][0]['objectId'].'/requiredResourceAccess';
        $response = $this->apicall('merge', $endpoint, $newperms);

        if ($response === '') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get the currently assigned permissions for this application.
     *
     * @return array Array of currently assign permissions, using service and permission IDs.
     */
    public function get_current_permissions() {
        $currentperms = [];
        $response = $this->get_application_info();
        if (isset($response['value'][0]['requiredResourceAccess'])) {
            foreach ($response['value'][0]['requiredResourceAccess'] as $i => $permset) {
                if (isset($permset['resourceAppId']) && isset($permset['resourceAccess'])) {
                    if (!isset($currentperms[$permset['resourceAppId']])) {
                        $currentperms[$permset['resourceAppId']] = [];
                    }
                    foreach ($permset['resourceAccess'] as $i => $access) {
                        if (isset($access['id']) && isset($access['type'])) {
                            $currentperms[$permset['resourceAppId']][$access['id']] = $access['type'];
                        }
                    }
                }
            }
            unset($response);
        }
        return $currentperms;
    }

    /**
     * Get an array of the current required permissions.
     *
     * @return array Array of required Azure AD application permissions.
     */
    public function get_required_permissions() {
        return [
            'Microsoft.Azure.ActiveDirectory' => [
                'Directory.Read' => 'Scope',
                'User.Read.All' => 'Scope',
            ],
            'Microsoft.SharePoint' => [
                'AllSites.Read' => 'Scope',
                'AllSites.Write' => 'Scope',
                'AllSites.Manage' => 'Scope',
                'AllSites.FullControl' => 'Scope',
                'MyFiles.Read' => 'Scope',
                'MyFiles.Write' => 'Scope',
            ],
            'Microsoft.Exchange' => [
                'Calendars.Read' => 'Scope',
                'Calendars.ReadWrite' => 'Scope',
            ],
            'OneNote' => [
                'Notes.ReadWrite' => 'Scope',
                'Notes.Read' => 'Scope',
                'Notes.Create' => 'Scope',
            ],
        ];
    }

    /**
     * Get information on specified services.
     *
     * @param array $servicenames Array of service names to get. (See keys in get_required_permissions for examples.)
     * @param bool $transform Whether to transform the result for easy consumption (see check_permissions and push_permissions)
     * @return array|null Array of service information, or null if error.
     */
    public function get_service_data(array $servicenames, $transform = true) {
        $filterstr = 'displayName%20eq%20\''.implode('\'%20or%20displayName%20eq%20\'', $servicenames).'\'';
        $response = $this->apicall('get', '/servicePrincipals()?$filter='.$filterstr);
        if (!empty($response)) {
            $response = @json_decode($response, true);
            if (!empty($response) && is_array($response)) {
                if ($transform === true) {
                    $transformed = [];
                    foreach ($response['value'] as $i => $appdata) {
                        $transformed[$appdata['displayName']] = [
                            'appId' => $appdata['appId'],
                            'appDisplayName' => $appdata['appDisplayName'],
                            'perms' => []
                        ];
                        foreach ($appdata['oauth2Permissions'] as $i => $permdata) {
                            $transformed[$appdata['displayName']]['perms'][$permdata['value']] = $permdata;
                        }
                    }
                    return $transformed;
                } else {
                    return $response;
                }
            }
        }
        return null;
    }

    /**
     * Get all users in the configured directory.
     *
     * @param string|array $params Requested user parameters.
     * @param string $skiptoken A skiptoken param from a previous get_users query. For pagination.
     * @return array|null Array of user information, or null if failure.
     */
    public function get_users($params = 'default', $skiptoken = '') {
        $endpoint = '/users';
        if ($params === 'default') {
            $params = ['mail', 'city', 'country', 'department', 'givenName', 'surname', 'preferredLanguage', 'userPrincipalName'];
        }
        if (empty($skiptoken) || !is_string($skiptoken)) {
            $skiptoken = '';
        }
        if (!empty($params) && is_array($params)) {
            $endpoint .= '?$skiptoken='.$skiptoken;
        }
        $response = $this->apicall('get', $endpoint);
        $expectedparams = ['value' => null];
        return $this->process_apicall_response($response, $expectedparams);
    }

    /**
     * Get a specific user's information.
     *
     * @param string $oid The user's object id.
     * @return array|null Array of user information, or null if failure.
     */
    public function get_user($oid) {
        $endpoint = "/users/{$oid}";
        $response = $this->apicall('get', $endpoint);
        $expectedparams = [
            'odata.type' => 'Microsoft.DirectoryServices.User',
            'objectId' => null,
            'userPrincipalName' => null,
        ];
        return $this->process_apicall_response($response, $expectedparams);
    }

    /**
     * Get the Azure AD UPN of a connected Moodle user.
     *
     * @param \stdClass $user The Moodle user.
     * @return string|bool The user's Azure AD UPN, or false if failure.
     */
    public static function get_muser_upn($user) {
        global $DB;
        $now = time();

        if (is_numeric($user)) {
            $user = $DB->get_record('user', ['id' => $user]);
            if (empty($user)) {
                \local_o365\utils::debug('User not found', 'rest\azuread\get_muser_upn', $user);
                return false;
            }
        }

        // Get user UPN.
        $userobjectdata = $DB->get_record('local_o365_objects', ['type' => 'user', 'moodleid' => $user->id]);
        if (!empty($userobjectdata)) {
            return $userobjectdata->o365name;
        } else {
            // Get user data.
            $authoidcuserdata = $DB->get_record('auth_oidc_token', ['username' => $user->username]);
            if (empty($authoidcuserdata)) {
                // No data for the user in the OIDC token table. Can't proceed.
                \local_o365\utils::debug('No oidc token found for user.', 'rest\azuread\get_muser_upn', $user->username);
                return false;
            }
            $httpclient = new \local_o365\httpclient();
            try {
                $clientdata = \local_o365\oauth2\clientdata::instance_from_oidc();
            } catch (\Exception $e) {
                \local_o365\utils::debug($e->getMessage());
                return false;
            }
            $resource = static::get_resource();
            $token = \local_o365\oauth2\systemtoken::instance(null, $resource, $clientdata, $httpclient);
            $aadapiclient = new \local_o365\rest\azuread($token, $httpclient);
            $aaduserdata = $aadapiclient->get_user($authoidcuserdata->oidcuniqid);
            $userobjectdata = (object)[
                'type' => 'user',
                'subtype' => '',
                'objectid' => $aaduserdata['objectId'],
                'o365name' => $aaduserdata['userPrincipalName'],
                'moodleid' => $user->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $userobjectdata->id = $DB->insert_record('local_o365_objects', $userobjectdata);
            return $userobjectdata->o365name;
        }
    }

    /**
     * Add a user to a course o365 usergoup.
     *
     * @param int $courseid The ID of the moodle group.
     * @param int $userid The ID of the moodle user.
     * @return bool|null|string True if successful, null if not applicable, string if other API error.
     */
    public function add_user_to_course_group($courseid, $userid) {
        global $DB;

        $filters = ['type' => 'group', 'subtype' => 'course', 'moodleid' => $courseid];
        $coursegroupobject = $DB->get_record('local_o365_objects', $filters);
        if (empty($coursegroupobject)) {
            return null;
        }

        $sql = 'SELECT u.*,
                       tok.oidcuniqid as userobjectid
                  FROM {auth_oidc_token} tok
                  JOIN {user} u ON u.username = tok.username
                 WHERE tok.resource = ? AND u.id = ? AND u.deleted = "0"';
        $params = ['https://graph.windows.net', $userid];
        $userobject = $DB->get_record_sql($sql, $params);
        if (empty($userobject)) {
            return null;
        }

        $response = $this->add_member_to_group($coursegroupobject->objectid, $userobject->userobjectid);
        return $response;
    }

    /**
     * Remove a user from a course o365 usergoup.
     *
     * @param int $courseid The ID of the moodle group.
     * @param int $userid The ID of the moodle user.
     * @return bool|null|string True if successful, null if not applicable, string if other API error.
     */
    public function remove_user_from_course_group($courseid, $userid) {
        global $DB;

        $filters = ['type' => 'group', 'subtype' => 'course', 'moodleid' => $courseid];
        $coursegroupobject = $DB->get_record('local_o365_objects', $filters);
        if (empty($coursegroupobject)) {
            return null;
        }

        $sql = 'SELECT u.*,
                       tok.oidcuniqid as userobjectid
                  FROM {auth_oidc_token} tok
                  JOIN {user} u ON u.username = tok.username
                 WHERE tok.resource = ? AND u.id = ? AND u.deleted = "0"';
        $params = ['https://graph.windows.net', $userid];
        $userobject = $DB->get_record_sql($sql, $params);
        if (empty($userobject)) {
            return null;
        }

        $response = $this->remove_member_from_group($coursegroupobject->objectid, $userobject->userobjectid);
        return $response;
    }

    /**
     * Add member to group.
     *
     * @param string $groupobjectid The object ID of the group to add to.
     * @param string $memberobjectid The object ID of the item to add (can be group object id or user object id).
     * @return bool|string True if successful, returned string if not (may contain error info, etc).
     */
    public function add_member_to_group($groupobjectid, $memberobjectid) {
        $config = get_config('local_o365');
        if (empty($config->aadtenant)) {
            return null;
        }
        $endpoint = '/groups/'.$groupobjectid.'/$links/members';
        $data = [
            'url' => $this->get_apiuri().'/directoryObjects/'.$memberobjectid
        ];
        $response = $this->apicall('post', $endpoint, json_encode($data));
        return ($response === '') ? true : $response;
    }

    /**
     * Remove member from group.
     *
     * @param string $groupobjectid The object ID of the group to remove from.
     * @param string $memberobjectid The object ID of the item to remove (can be group object id or user object id).
     * @return bool|string True if successful, returned string if not (may contain error info, etc).
     */
    public function remove_member_from_group($groupobjectid, $memberobjectid) {
        $config = get_config('local_o365');
        if (empty($config->aadtenant)) {
            return null;
        }
        $endpoint = '/groups/'.$groupobjectid.'/$links/members/'.$memberobjectid;
        $response = $this->apicall('delete', $endpoint);
        return ($response === '') ? true : $response;
    }
}