<?php

/**
 * Copyright 2011-2013 Stephen Just <stephenjust@users.sf.net>
 *                2014 Daniel Butum <danibutum at gmail dot com>
 * This file is part of stkaddons
 *
 * stkaddons is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * stkaddons is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with stkaddons.  If not, see <http://www.gnu.org/licenses/>.
 */
require_once(INCLUDE_DIR . 'DBConnection.class.php');

/**
 * Handles management of temporary files
 *
 * @author Stephen
 */
class Cache
{

    /**
     * Empty the cache folder, leave certain files in place
     *
     * @return bool
     */
    public static function clear()
    {
        $exclude_regex = '/^(cache_graph_.*\.png)$/i';
        File::deleteRecursive(CACHE_DIR, $exclude_regex);
        @mkdir(CACHE_DIR);

        try
        {
            $cache_list = DBConnection::get()->query(
                'SELECT `file`
                 FROM `' . DB_PREFIX . 'cache`',
                DBConnection::FETCH_ALL
            );
            foreach ($cache_list as $cache_item)
            {
                if (preg_match($exclude_regex, $cache_item['file']))
                {
                    continue;
                }
                DBConnection::get()->query(
                    'DELETE FROM `' . DB_PREFIX . 'cache`
                    WHERE `file` = :file',
                    DBConnection::NOTHING,
                    array(':file' => (string)$cache_item['file'])
                );
            }

            return true;
        }
        catch(DBException $e)
        {
            return false;
        }
    }

    /**
     * Clear the addon cache files
     *
     * @param string $addon
     *
     * @return bool
     */
    public static function clearAddon($addon)
    {
        $addon = Addon::cleanId($addon);
        if (!Addon::exists($addon))
        {
            return false;
        }

        try
        {
            $cache_list = DBConnection::get()->query(
                'SELECT `file`
                FROM `' . DB_PREFIX . 'cache`
                WHERE `addon` = :addon',
                DBConnection::FETCH_ALL,
                array(':addon' => (string)$addon)
            );
            foreach ($cache_list AS $cache_item)
            {
                unlink(CACHE_DIR . $cache_item['file']);
                DBConnection::get()->query(
                    'DELETE FROM `' . DB_PREFIX . 'cache`
                    WHERE `file` = :file',
                    DBConnection::NOTHING,
                    array(':file' => (string)$cache_item['file'])
                );
            }

            return true;
        }
        catch(DBException $e)
        {
            return false;
        }
    }

    /**
     * Add a database record for a cache file
     *
     * @param string $path  Relative to cache root
     * @param string $addon Associated add-on's id or NULL
     * @param string $props File properties (e.g. w=1,h=3)
     *
     * @return boolean
     */
    public static function createFile($path, $addon = null, $props = null)
    {
        $addon = (Addon::exists($addon)) ? Addon::cleanId($addon) : null;

        try
        {
            DBConnection::get()->insert(
                "cache",
                array(
                    "file" => $path,
                    "addon" => $addon,
                    "props" => $props
                )
            );

            return true;
        }
        catch(DBException $e)
        {
            return false;
        }
    }

    /**
     * Check if a cache file exist (based on database record of its existence)
     *
     * @param string $path Relative to upload root
     *
     * @return array Empty array on failure, array with 'path', 'addon' otherwise
     */
    public static function fileExists($path)
    {
        try
        {
            $result = DBConnection::get()->query(
                'SELECT `addon`, `props`
                FROM `' . DB_PREFIX . 'cache`
                WHERE `file` = :file',
                DBConnection::FETCH_ALL,
                array(':file' => (string)$path)
            );
            if (empty($result))
            {
                return array();
            }

            return $result;
        }
        catch(DBException $e)
        {
            return array();
        }
    }

    /**
     * Get image properties for a cacheable image
     *
     * @param integer $id
     * @param array   $props
     *
     * @return array
     * @throws CacheException
     */
    public static function getImage($id, $props = array())
    {
        try
        {
            $result = DBConnection::get()->query(
                'SELECT `file_path`, `approved`
                FROM `' . DB_PREFIX . 'files`
                WHERE `id` = :id
                LIMIT 1',
                DBConnection::FETCH_ALL,
                array(':id' => (int)$id)
            );

            if (empty($result))
            {
                return array(
                    'url'      => SITE_ROOT . 'image/notfound.png',
                    'approved' => true,
                    'exists'   => false
                );
            }

            $return = array(
                'url'      => DOWN_LOCATION . $result[0]['file_path'],
                'approved' => (boolean)$result[0]['approved'],
                'exists'   => true
            );

            $cache_prefix = null;
            if (array_key_exists('size', $props))
            {
                $cache_prefix = Cache::cachePrefix($props['size']);

                $return['url'] = SITE_ROOT . 'image.php?type=' . $props['size'] . '&amp;pic=' . $result[0]['file_path'];
            }

            if (Cache::fileExists($cache_prefix . basename($result[0]['file_path'])))
            {
                $return['url'] = CACHE_DL . $cache_prefix . basename($result[0]['file_path']);
            }

            return $return;
        }
        catch(DBException $e)
        {
            throw new CacheException('Failed to look up image file.');
        }
    }

    /**
     * @param string $size
     *
     * @return null|string
     */
    private static function cachePrefix($size)
    {
        if (empty($size))
        {
            return null;
        }
        if ($size === 'big')
        {
            return '300--';
        }
        if ($size === 'medium')
        {
            return '75--';
        }
        if ($size === 'small')
        {
            return '25--';
        }

        return null;
    }
}
