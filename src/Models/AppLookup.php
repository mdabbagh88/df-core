<?php
/**
 * This file is part of the DreamFactory Rave(tm)
 *
 * DreamFactory Rave(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace DreamFactory\Rave\Models;

use DreamFactory\Rave\Utility\Cache as CacheUtil;

class AppLookup extends BaseSystemModel
{
    use LookupTrait;

    protected $table = 'app_lookup';

    protected $fillable = ['app_id', 'name', 'value', 'private', 'description'];

    /**
     * @var array
     */
    protected $encrypted = ['value'];

    public static function boot()
    {
        parent::boot();

        static::saved(
            function ( AppLookup $al )
            {
                $cacheKey = CacheUtil::getAppLookupCacheKey($al->app_id);

                if(\Cache::has($cacheKey))
                {
                    \Cache::forget($cacheKey);
                }
            }
        );
    }
}