<?php

namespace DreamFactory\Core\Resources\System;

use DreamFactory\Core\Enums\AppTypes;
use DreamFactory\Core\Models\App as AppModel;
use DreamFactory\Core\Models\AppGroup as AppGroupModel;
use DreamFactory\Core\Models\Service as ServiceModel;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\User\Services\User;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Scalar;
use DreamFactory\Core\Models\Config as SystemConfig;
use DreamFactory\Library\Utility\Inflector;

class Environment extends BaseSystemResource
{
    /**
     * @return array
     */
    protected function handleGET()
    {
        $result = [];

        $result['platform'] = [
            'version_current'   => '2.0.0',
            'version_latest'    => '2.0.0',
            'upgrade_available' => false,
            'is_hosted'         => false,
            'host'              => php_uname('n'),
        ];

        $login = static::getLoginApi();
        $apps = static::getApps();
        $groupedApps = ArrayUtils::get($apps, 0);
        $noGroupApps = ArrayUtils::get($apps, 1);

        $result['authentication'] = $login;
        $result['app_group'] = (count($groupedApps) > 0) ? $groupedApps : [];
        $result['no_group_app'] = (count($noGroupApps) > 0) ? $noGroupApps : [];

        /*
         * Most API calls return a resource array or a single resource,
         * If an array, shall we wrap it?, With what shall we wrap it?
         */
        $config = [
            'always_wrap_resources' => \Config::get('df.always_wrap_resources'),
            'resources_wrapper'     => \Config::get('df.resources_wrapper'),
            'db'                    => [
                /** The default number of records to return at once for database queries */
                'max_records_returned' => \Config::get('df.db.max_records_returned'),
                'time_format'          => \Config::get('df.db.time_format'),
                'date_format'          => \Config::get('df.db.date_format'),
                'datetime_format'      => \Config::get('df.db.datetime_format'),
                'timestamp_format'     => \Config::get('df.db.timestamp_format'),
            ],
        ];
        $result['config'] = $config;

        if (SessionUtilities::isSysAdmin()) {
            $result['server'] = [
                'server_os' => strtolower(php_uname('s')),
                'release'   => php_uname('r'),
                'version'   => php_uname('v'),
                'host'      => php_uname('n'),
                'machine'   => php_uname('m'),
            ];
            $result['php'] = static::getPhpInfo();
        }

        return $result;
    }

    protected static function getApps()
    {
        if (SessionUtilities::isAuthenticated()) {
            $user = SessionUtilities::user();
            $defaultAppId = $user->default_app_id;

            if (SessionUtilities::isSysAdmin()) {
                $appGroups = AppGroupModel::with(
                    [
                        'app_by_app_to_app_group' => function ($q){
                            $q->whereIsActive(1)->whereNotIn('type', [AppTypes::NONE]);
                        }
                    ]
                )->get();
                $apps = AppModel::whereIsActive(1)->whereNotIn('type', [AppTypes::NONE])->get();
            } else {
                $userId = $user->id;
                $userAppRoles = UserAppRole::whereUserId($userId)->whereNotNull('role_id')->get(['app_id']);
                $appIds = [];
                foreach ($userAppRoles as $uar) {
                    $appIds[] = $uar->app_id;
                }
                $appIdsString = implode(',', $appIds);
                $appIdsString = (empty($appIdsString)) ? '-1' : $appIdsString;
                $typeString = implode(',', [AppTypes::NONE]);
                $typeString = (empty($typeString)) ? '-1' : $typeString;

                $appGroups = AppGroupModel::with(
                    [
                        'app_by_app_to_app_group' => function ($q) use ($appIdsString, $typeString){
                            $q->whereRaw("(app.id IN ($appIdsString) OR role_id > 0) AND is_active = 1 AND type NOT IN ($typeString)");
                        }
                    ]
                )->get();
                $apps =
                    AppModel::whereRaw("(app.id IN ($appIdsString) OR role_id > 0) AND is_active = 1 AND type NOT IN ($typeString)")
                        ->get();
            }
        } else {
            $appGroups = AppGroupModel::with(
                [
                    'app_by_app_to_app_group' => function ($q){
                        $q->where('role_id', '>', 0)
                            ->whereIsActive(1)
                            ->whereNotIn('type', [AppTypes::NONE]);
                    }
                ]
            )->get();
            $apps = AppModel::whereIsActive(1)
                ->where('role_id', '>', 0)
                ->whereNotIn('type', [AppTypes::NONE])
                ->get();
        }

        if (empty($defaultAppId)) {
            $systemConfig = SystemConfig::first(['default_app_id']);
            $defaultAppId = (!empty($systemConfig)) ? $systemConfig->default_app_id : null;
        }

        $inGroups = [];
        $groupedApps = [];
        $noGroupedApps = [];

        foreach ($appGroups as $appGroup) {
            $appArray = $appGroup->getRelation('app_by_app_to_app_group')->toArray();
            if (!empty($appArray)) {
                $appInfo = [];
                foreach ($appArray as $app) {
                    $inGroups[] = $app['id'];
                    $appInfo[] = static::makeAppInfo($app, $defaultAppId);
                }

                $groupedApps[] = [
                    'id'          => $appGroup->id,
                    'name'        => $appGroup->name,
                    'description' => $appGroup->description,
                    'app'         => $appInfo
                ];
            }
        }

        /** @type AppModel $app */
        foreach ($apps as $app) {
            if (!in_array($app->id, $inGroups)) {
                $noGroupedApps[] = static::makeAppInfo($app->toArray(), $defaultAppId);
            }
        }

        return [$groupedApps, $noGroupedApps];
    }

    protected static function makeAppInfo(array $app, $defaultAppId)
    {
        return [
            'id'                      => $app['id'],
            'name'                    => $app['name'],
            'description'             => $app['description'],
            'url'                     => $app['launch_url'],
            'is_default'              => ($defaultAppId === $app['id']) ? true : false,
            'allow_fullscreen_toggle' => $app['allow_fullscreen_toggle'],
            'requires_fullscreen'     => $app['requires_fullscreen'],
            'toggle_location'         => $app['toggle_location']
        ];
    }

    /**
     * @return array
     */
    protected static function getLoginApi()
    {
        $adminApi = [
            'path'    => 'system/admin/session',
            'verb'    => Verbs::POST,
            'payload' => [
                'email'       => 'string',
                'password'    => 'string',
                'remember_me' => 'bool'
            ]
        ];
        $userApi = [
            'path'    => 'user/session',
            'verb'    => Verbs::POST,
            'payload' => [
                'email'       => 'string',
                'password'    => 'string',
                'remember_me' => 'bool'
            ]
        ];

        if (class_exists(User::class)) {
            $oauth = static::getOAuthServices();
            $ldap = static::getAdLdapServices();

            return [
                'admin'  => $adminApi,
                'user'   => $userApi,
                'oauth'  => $oauth,
                'adldap' => $ldap
            ];
        }

        return ['admin' => $adminApi];
    }

    /**
     * @return array
     */
    protected static function getOAuthServices()
    {
        $oauth = ServiceModel::whereIn(
            'type',
            ['oauth_facebook', 'oauth_twitter', 'oauth_github', 'oauth_google']
        )->whereIsActive(1)->get(['id', 'name', 'type', 'label']);

        $services = [];

        foreach ($oauth as $o) {
            $config = $o->getConfigAttribute();
            $services[] = [
                'path'       => 'user/session?service=' . strtolower($o->name),
                'name'       => $o->name,
                'label'      => $o->label,
                'verb'       => [Verbs::GET, Verbs::POST],
                'type'       => $o->type,
                'icon_class' => $config['icon_class']
            ];
        }

        return $services;
    }

    /**
     * @return array
     */
    protected static function getAdLdapServices()
    {
        $ldap = ServiceModel::whereIn(
            'type',
            ['ldap', 'adldap']
        )->whereIsActive(1)->get(['name', 'type', 'label']);

        $services = [];

        foreach ($ldap as $l) {
            $services[] = [
                'path'    => 'user/session?service=' . strtolower($l->name),
                'name'    => $l->name,
                'label'   => $l->label,
                'verb'    => Verbs::POST,
                'payload' => [
                    'username'    => 'string',
                    'password'    => 'string',
                    'service'     => $l->name,
                    'remember_me' => 'bool'
                ]
            ];
        }

        return $services;
    }

    //Following codes are directly copied over from 1.x and is not functional.

//    protected function handleGET()
//    {
//        $_release = null;
//        $_phpInfo = $this->_getPhpInfo();
//
//        if ( false !== ( $_raw = file( static::LSB_RELEASE ) ) && !empty( $_raw ) )
//        {
//            $_release = array();
//
//            foreach ( $_raw as $_line )
//            {
//                $_fields = explode( '=', $_line );
//                $_release[str_replace( 'distrib_', null, strtolower( $_fields[0] ) )] = trim( $_fields[1], PHP_EOL . '"' );
//            }
//        }
//
//        $_response = array(
//            'php_info' => $_phpInfo,
//            'platform' => Config::getCurrentConfig(),
//            'release'  => $_release,
//            'server'   => array(
//                'server_os' => strtolower( php_uname( 's' ) ),
//                'uname'     => php_uname( 'a' ),
//            ),
//        );
//
//        array_multisort( $_response );
//
//        //	Cache configuration
//        Platform::storeSet( static::CACHE_KEY, $_response, static::CONFIG_CACHE_TTL );
//
//        $this->_response = $this->_response ? array_merge( $this->_response, $_response ) : $_response;
//        unset( $_response );
//
//        return $this->_response;
//    }

    /**
     * Parses the data coming back from phpinfo() call and returns in an array
     *
     * @return array
     */
    protected static function getPhpInfo()
    {
        $_html = null;
        $_info = array();
        $_pattern =
            '#(?:<h2>(?:<a name=".*?">)?(.*?)(?:</a>)?</h2>)|(?:<tr(?: class=".*?")?><t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>)?)?</tr>)#s';

        \ob_start();
        @\phpinfo();
        $_html = \ob_get_contents();
        \ob_end_clean();

        if (preg_match_all($_pattern, $_html, $_matches, PREG_SET_ORDER)) {
            foreach ($_matches as $_match) {
                $_keys = array_keys($_info);
                $_lastKey = end($_keys);

                if (strlen($_match[1])) {
                    $_info[$_match[1]] = array();
                } elseif (isset($_match[3])) {
                    $_info[$_lastKey][$_match[2]] = isset($_match[4]) ? array($_match[3], $_match[4]) : $_match[3];
                } else {
                    $_info[$_lastKey][] = $_match[2];
                }

                unset($_keys, $_match);
            }
        }

        return static::cleanPhpInfo($_info);
    }

    /**
     * @param array $info
     *
     * @param bool  $recursive
     *
     * @return array
     */
    protected static function cleanPhpInfo($info, $recursive = false)
    {
        static $_excludeKeys = array('directive', 'variable',);

        $_clean = array();

        //  Remove images and move nested args to root
        if (!$recursive && isset($info[0], $info[0][0]) && is_array($info[0])) {
            $info['general'] = array();

            foreach ($info[0] as $_key => $_value) {
                if (is_numeric($_key) || in_array(strtolower($_key), $_excludeKeys)) {
                    continue;
                }

                $info['general'][$_key] = $_value;
                unset($info[0][$_key]);
            }

            unset($info[0]);
        }

        foreach ($info as $_key => $_value) {
            if (in_array(strtolower($_key), $_excludeKeys)) {
                continue;
            }

            $_key = strtolower(str_replace(' ', '_', $_key));

            if (is_array($_value) && 2 == count($_value) && isset($_value[0], $_value[1])) {
                $_v1 = ArrayUtils::get($_value, 0);

                if ($_v1 == '<i>no value</i>') {
                    $_v1 = null;
                }

                if (Scalar::in(strtolower($_v1), 'on', 'off', '0', '1')) {
                    $_v1 = ArrayUtils::getBool($_value, 0);
                }

                $_value = $_v1;
            }

            if (is_array($_value)) {
                $_value = static::cleanPhpInfo($_value, true);
            }

            $_clean[$_key] = $_value;
        }

        return $_clean;
    }

    public function getApiDocInfo()
    {
        $path = '/' . $this->getServiceName() . '/' . $this->getFullPathName();
        $eventPath = $this->getServiceName() . '.' . $this->getFullPathName('.');
        $name = Inflector::camelize($this->name);
        $plural = Inflector::pluralize($name);
        $words = str_replace('_', ' ', $this->name);
        $pluralWords = Inflector::pluralize($words);
        $wrapper = ResourcesWrapper::getWrapper();

        $apis = [
            [
                'path'        => $path,
                'description' => "Operations for retrieving system environment.",
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getEnvironment() - Retrieve system environment.',
                        'nickname'         => 'getEnvironment',
                        'type'             => 'EnvironmentResponse',
                        'event_name'       => $eventPath . '.list',
                        'consumes'         => ['application/json', 'application/xml', 'text/csv'],
                        'produces'         => ['application/json', 'application/xml', 'text/csv'],
                        'parameters'       => [],
                        'responseMessages' => [],
                        'notes'            =>
                            'Minimum environment information given without a valid user session.' .
                            ' More information given based on user privileges.',
                    ],
                ],
            ],
        ];

        $models = [
            'EnvironmentResponse' => [
                'id'         => 'EnvironmentResponse',
                'properties' => [
                    'platform' => [
                        'type'        => 'array',
                        'description' => 'Array of system records.',
                        'items'       => [
                            '$ref' => $name . 'Response',
                        ],
                    ],
                    'authentication'   => [
                        'type'        => 'Metadata',
                        'description' => 'Array of metadata returned for GET requests.',
                    ],
                    'app_group'   => [
                        'type'        => 'array',
                        'description' => 'Array of system records.',
                        'items'       => [
                            '$ref' => $name . 'Response',
                        ],
                    ],
                    'no_app_group'   => [
                        'type'        => 'array',
                        'description' => 'Array of system records.',
                        'items'       => [
                            '$ref' => $name . 'Response',
                        ],
                    ],
                    'config'   => [
                        'type'        => 'Metadata',
                        'description' => 'Array of metadata returned for GET requests.',
                    ],
                    'server'   => [
                        'type'        => 'Metadata',
                        'description' => 'Array of metadata returned for GET requests.',
                    ],
                ],
            ],
        ];

        return ['apis' => $apis, 'models' => $models];
    }

    /*
     *
{
  "platform": {
    "version_current": "2.0.0",
    "version_latest": "2.0.0",
    "upgrade_available": false,
    "is_hosted": false,
    "host": "DF-Lees-MBP"
  },
  "authentication": {
    "admin": {
      "path": "system/admin/session",
      "verb": "POST",
      "payload": {
        "email": "string",
        "password": "string",
        "remember_me": "bool"
      }
    },
    "user": {
      "path": "user/session",
      "verb": "POST",
      "payload": {
        "email": "string",
        "password": "string",
        "remember_me": "bool"
      }
    },
    "oauth": [],
    "adldap": []
  },
  "app_group": [],
  "no_group_app": [
    {
      "id": 1,
      "name": "admin",
      "description": "An application for administering this instance.",
      "url": "http://df.local/dreamfactory/dist/index.html",
      "is_default": false,
      "allow_fullscreen_toggle": true,
      "requires_fullscreen": false,
      "toggle_location": "top"
    },
    {
      "id": 2,
      "name": "swagger",
      "description": "A Swagger-base application allowing viewing and testing API documentation.",
      "url": "http://df.local/swagger/index.html",
      "is_default": false,
      "allow_fullscreen_toggle": true,
      "requires_fullscreen": false,
      "toggle_location": "top"
    },
    {
      "id": 3,
      "name": "filemanager",
      "description": "An application for managing file services.",
      "url": "http://df.local/filemanager/index.html",
      "is_default": false,
      "allow_fullscreen_toggle": true,
      "requires_fullscreen": false,
      "toggle_location": "top"
    }
  ],
  "config": {
    "always_wrap_resources": true,
    "resources_wrapper": "resource",
    "db": {
      "max_records_returned": 1000,
      "time_format": null,
      "date_format": null,
      "datetime_format": null,
      "timestamp_format": null
    }
  },
  "server": {
    "server_os": "darwin",
    "release": "14.4.0",
    "version": "Darwin Kernel Version 14.4.0: Thu May 28 11:35:04 PDT 2015; root:xnu-2782.30.5~1/RELEASE_X86_64",
    "host": "DF-Lees-MBP",
    "machine": "x86_64"
  },
  "php": {
    */
}