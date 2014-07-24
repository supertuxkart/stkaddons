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

/**
 * Class Addon
 */
class Addon extends Base
{
    const KART = "karts";

    const TRACK = "tracks";

    const ARENA = "arenas";

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var int
     */
    protected $uploaderId;

    /** The addon creation date
     * @var string
     */
    protected $creationDate;

    /**
     * @var string
     */
    protected $designer;

    /**
     * @var string
     */
    protected $description;

    /**
     * @var string
     */
    protected $license;

    /**
     * @var int
     */
    protected $minInclude;

    /**
     * @var int
     */
    protected $maxInclude;

    /**
     * @var int
     */
    protected $image = 0;

    /**
     * @var int
     */
    protected $icon = 0;

    /**
     * @var string
     */
    protected $permalink;

    /**
     * @var array
     */
    protected $revisions = [];

    /**
     * @var
     */
    protected $latestRevision;


    /**
     * @param string $message
     *
     * @throws AddonException
     */
    protected static function throwException($message)
    {
        throw new AddonException($message);
    }

    /**
     * Load the revisions from the database into the current instance
     *
     * @throws AddonException
     */
    protected function loadRevisions()
    {
        try
        {
            $revisions = DBConnection::get()->query(
                'SELECT *
                FROM `' . DB_PREFIX . $this->type . '_revs`
                WHERE `addon_id` = :id
                ORDER BY `revision` ASC',
                DBConnection::FETCH_ALL,
                [':id' => $this->id]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to read the requested add-on\'s revision information.'));
        }

        if (empty($revisions))
        {
            throw new AddonException(_h('No revisions of this add-on exist. This should never happen.'));
        }

        foreach ($revisions as $rev)
        {
            $currentRev = [
                'file'           => $rev['fileid'],
                'format'         => $rev['format'],
                'image'          => $rev['image'],
                'icon'           => (isset($rev['icon'])) ? $rev['icon'] : 0,
                'moderator_note' => $rev['moderator_note'],
                'revision'       => $rev['revision'],
                'status'         => $rev['status'],
                'timestamp'      => $rev['creation_date']
            ];

            // revision is latest
            if (Addon::isLatest($currentRev['status']))
            {
                $this->latestRevision = $rev['revision'];
                $this->image = $rev['image'];
                $this->icon = (isset($rev['icon'])) ? $rev['icon'] : 0;
            }

            $this->revisions[$rev['revision']] = $currentRev;
        }
    }

    /**
     * Instance constructor
     *
     * @param string $id
     * @param array  $addonData
     * @param bool   $loadRevisions load also the revisions
     *
     * @throws AddonException
     */
    protected function __construct($id, $addonData, $loadRevisions = true)
    {
        $this->id = (string)static::cleanId($id);
        $this->type = $addonData['type'];
        $this->name = $addonData['name'];
        $this->uploaderId = $addonData['uploader'];
        $this->creationDate = $addonData['creation_date'];
        $this->designer = $addonData['designer'];
        $this->description = $addonData['description'];
        $this->license = $addonData['license'];
        $this->permalink = SITE_ROOT . 'addons.php?type=' . $this->type . '&amp;name=' . $this->id;
        $this->minInclude = $addonData['min_include_ver'];
        $this->maxInclude = $addonData['max_include_ver'];

        // load revisions
        if ($loadRevisions)
        {
            $this->loadRevisions();
        }
    }

    /**
     * Create an add-on revision
     *
     * @param array  $attributes
     * @param string $file_id
     * @param string $moderator_message
     *
     * @throws AddonException
     */
    public function createRevision($attributes, $file_id, $moderator_message = "")
    {
        foreach ($attributes['missing_textures'] as $tex)
        {
            $moderator_message .= "Texture not found: $tex\n";
        }

        // Check if logged in
        if (!User::isLoggedIn())
        {
            throw new AddonException(_h('You must be logged in to create an add-on revision.'));
        }

        // Make sure an add-on file with this id does not exist
        try
        {
            $rows = DBConnection::get()->query(
                'SELECT * FROM ' . DB_PREFIX . $this->type . '_revs WHERE `id` = :id',
                DBConnection::ROW_COUNT,
                [':id' => $file_id]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(sprintf('Failed to acces the %s_revs table.', $this->type));
        }
        if ($rows)
        {
            throw new AddonException(_h('The file you are trying to create already exists.'));
        }

        // Make sure user has permission to upload a new revision for this add-on
        if (User::getLoggedId() !== $this->uploaderId && !User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            throw new AddonException(_h('You do not have the necessary permissions to perform this action.'));
        }

        // Update the addon name
        $this->setName($attributes['name']);

        // Update license file record
        $this->setLicense($attributes['license']);

        // Prevent duplicate images from being created.
        $images = $this->getImageHashes();

        // Compare with new image
        $new_image = File::getPath($attributes['image']);
        $new_hash = md5_file(UP_PATH . $new_image);
        $images_count = count($images);
        for ($i = 0; $i < $images_count; $i++)
        {
            // Skip image that was just uploaded
            if ($images[$i]['id'] === $attributes['image'])
            {
                continue;
            }

            if ($new_hash === $images[$i]['hash'])
            {
                File::delete($attributes['image']);
                $attributes['image'] = $images[$i]['id'];
                break;
            }
        }

        // Calculate the next revision number
        $highest_rev = max(array_keys($this->revisions));
        $rev = $highest_rev + 1;

        // Add revision entry
        $fields_data = [
            ":id"       => $file_id,
            ":addon_id" => $this->id,
            ":fileid"   => $attributes['fileid'],
            ":revision" => $rev,
            ":format"   => $attributes['version'],
            ":image"    => $attributes['image'],
            ":status"   => $attributes['status']
        ];

        if ($this->type === static::KART)
        {
            $fields_data[":icon"] = $attributes['image'];
        }

        // Add moderator message if available
        if ($moderator_message)
        {
            $fields_data[":moderator_note"] = $moderator_message;
        }

        try
        {
            DBConnection::get()->insert($this->type . '_revs', $fields_data);
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to create add-on revision.'));
        }

        // Send mail to moderators
        moderator_email(
            'New Addon Upload',
            sprintf(
                "%s has uploaded a new revision for %s '%s' %s",
                User::getLoggedUserName(),
                $this->type,
                $attributes['name'],
                (string)$this->id
            )
        );
        writeAssetXML();
        writeNewsXML();
        Log::newEvent("New add-on revision for '{$attributes['name']}'");
    }

    /**
     * Delete an add-on record and all associated files and ratings
     *
     * @throws AddonException
     */
    public function delete()
    {
        if (!User::isLoggedIn())
        {
            throw new AddonException(_h('You must be logged in to perform this action.'));
        }

        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS) && User::getLoggedId() !== $this->uploaderId)
        {
            throw new AddonException(_h('You do not have the necessary permissions to perform this action.'));
        }

        // Remove cache files for this add-on
        Cache::clearAddon($this->id);

        // Remove files associated with this addon
        try
        {
            $files = DBConnection::get()->query(
                'SELECT *
                FROM `' . DB_PREFIX . "files`
                WHERE `addon_id` = :id",
                DBConnection::FETCH_ALL,
                [":id" => $this->id]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to find files associated with this addon.'));
        }

        foreach ($files as $file)
        {
            if (file_exists(UP_PATH . $file['file_path']) && !unlink(UP_PATH . $file['file_path']))
            {
                echo '<span class="error">' . _h('Failed to delete file:') . ' ' . $file['file_path'] . '</span><br>';
            }
        }

        // Remove file records associated with addon
        try
        {
            DBConnection::get()->delete("files", "`addon_id` = :id", [":id" => $this->id]);
        }
        catch(DBException $e)
        {
            echo '<span class="error">' . _h('Failed to remove file records for this addon.') . '</span><br>';
        }

        // Remove addon entry
        // FIXME: The two queries above should be included with this one
        // in a transaction, or database constraints added so that the two
        // queries above are no longer needed.
        try
        {
            DBConnection::get()->delete("addons", "`id` = :id", [":id" => $this->id]);
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to remove addon.'));
        }

        writeAssetXML();
        writeNewsXML();
        Log::newEvent("Deleted add-on '{$this->name}'");
    }

    /**
     * Delete a file by id
     *
     * @param int $file_id
     *
     * @throws AddonException
     */
    public function deleteFile($file_id)
    {
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS) && $this->uploaderId !== User::getLoggedId())
        {
            throw new AddonException(_h('You do not have the necessary permissions to perform this action.'));
        }

        if (!File::delete($file_id))
        {
            throw new AddonException(_h('Failed to delete file.'));
        }
    }

    /**
     * Delete a revision by id
     *
     * @param int $rev
     *
     * @throws AddonException
     */
    public function deleteRevision($rev)
    {
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS) && $this->uploaderId !== User::getLoggedId())
        {
            throw new AddonException(_h('You do not have the necessary permissions to perform this action.'));
        }

        $rev = (int)$rev;
        if ($rev < 1 || !isset($this->revisions[$rev]))
        {
            throw new AddonException(_h('The revision you are trying to delete does not exist.'));
        }
        if (count($this->revisions) === 1)
        {
            throw new AddonException(_h('You cannot delete the last revision of an add-on.'));
        }
        if (Addon::isLatest($this->revisions[$rev]['status']))
        {
            throw new AddonException(
                _h(
                    'You cannot delete the latest revision of an add-on. Please mark a different revision to be the latest revision first.'
                )
            );
        }

        // Queue addon file for deletion
        if (!File::queueDelete($this->revisions[$rev]['file']))
        {
            throw new AddonException(_h('The add-on file could not be queued for deletion.'));
        }

        // Remove the revision record from the database
        try
        {
            DBConnection::get()->delete(
                $this->type . '_revs',
                "`addon_id` = :id AND `revision` = :revision",
                [
                    ':addon_id' => $this->id,
                    ':revision' => $rev
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('The add-on revision could not be deleted.'));
        }

        Log::newEvent('Deleted revision ' . $rev . ' of \'' . $this->name . '\'');
        writeAssetXML();
        writeNewsXML();
    }

    /**
     * Get the id of the addon
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the id of image if $icon is set or the id of the icon
     *
     * @param bool $icon
     *
     * @return int
     */
    public function getImage($icon = false)
    {
        if ($icon === false)
        {
            return $this->image;
        }

        return $this->icon;
    }

    /**
     * @return string
     */
    public function getCreationDate()
    {
        return $this->creationDate;
    }

    /**
     * @return int
     */
    public function getIcon()
    {
        return $this->icon;
    }

    /**
     * @return int
     */
    public function getMaxInclude()
    {
        return $this->maxInclude;
    }

    /**
     * @return int
     */
    public function getMinInclude()
    {
        return $this->minInclude;
    }

    /**
     * @return string
     */
    public function getPermalink()
    {
        return $this->permalink;
    }

    /**
     * @return array
     */
    public function getRevisions()
    {
        return $this->revisions;
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Get the addon designed if known
     *
     * @return string
     */
    public function getDesigner()
    {
        if ($this->designer === null)
        {
            return _h('Unknown');
        }

        return $this->designer;
    }

    /**
     * Get all the revisions
     *
     * @return array
     */
    public function getAllRevisions()
    {
        return $this->revisions;
    }

    /**
     * Get the last revision
     *
     * @return string
     */
    public function getLatestRevision()
    {
        return $this->revisions[$this->latestRevision];
    }

    /**
     * Get the current status of the latest revision
     *
     * @return string
     */
    public function getStatus()
    {
        return $this->revisions[$this->latestRevision]['status'];
    }

    /**
     * Get the addon type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Get the addon name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the license text
     *
     * @return string
     */
    public function getLicense()
    {
        return $this->license;
    }

    /**
     * Get the html link
     *
     * @return string
     */
    public function getLink()
    {
        // Don't rewrite here, because we might be editing the URL later
        return $this->permalink;
    }

    /**
     * Get the id of the uploader
     *
     * @return int the id of the uploader
     */
    public function getUploaderId()
    {
        return $this->uploaderId;
    }

    /**
     * Get the minimum stk version that this addon supports
     *
     * @return string
     */
    public function getIncludeMin()
    {
        return $this->minInclude;
    }

    /**
     * Get the maximum stk version that this addon supports
     *
     * @return string
     */
    public function getIncludeMax()
    {
        return $this->maxInclude;
    }

    /**
     * Get the path to the requested add-on file
     *
     * @param integer $revision Revision number
     *
     * @throws AddonException
     *
     * @return string File path relative to the asset directory
     */
    public function getFile($revision)
    {
        if (!is_int($revision))
        {
            throw new AddonException(_h('An invalid revision was provided.'));
        }

        // Look up file ID
        try
        {
            $file_id_lookup = DBConnection::get()->query(
                'SELECT `fileid`
                FROM `' . DB_PREFIX . $this->type . '_revs`
                WHERE `addon_id` = :addon_id
                AND `revision` = :revision',
                DBConnection::FETCH_FIRST,
                [
                    ':addon_id' => $this->id,
                    ':revision' => $revision
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to look up file ID'));
        }

        if (empty($file_id_lookup))
        {
            throw new AddonException(_h('There is no add-on found with the specified revision number.'));
        }

        $file_id = $file_id_lookup['fileid'];

        // Look up file path from database
        try
        {
            $file = DBConnection::get()->query(
                'SELECT `file_path` FROM `' . DB_PREFIX . 'files`
                WHERE `id` = :id
                LIMIT 1',
                DBConnection::FETCH_FIRST,
                [':id' => $file_id]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to search for the file in the database.'));
        }

        if (empty($file))
        {
            throw new AddonException(_h('The requested file does not have an associated file record.'));
        }

        return $file['file_path'];
    }

    /**
     * Get the md5sums of all the image files of this addon
     *
     * @throws AddonException
     *
     * @return array
     */
    public function getImageHashes()
    {
        try
        {
            $paths = DBConnection::get()->query(
                "SELECT `id`, `file_path`
                FROM `" . DB_PREFIX . "files`
                WHERE `addon_id` = :addon_id
                AND `file_type` = 'image'
                LIMIT 50",
                DBConnection::FETCH_ALL,
                [':addon_id' => $this->id]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('DB error when fetching images associated with this add-on.'));
        }

        $return = [];
        foreach ($paths as $path)
        {
            $return[] = [
                'id'   => $path['id'],
                'path' => $path['file_path'],
                'hash' => md5_file(UP_PATH . $path['file_path'])
            ];
        }

        return $return;
    }

    /**
     * Get the image files associated with this addon
     *
     * @return array
     */
    public function getImages()
    {
        try
        {
            $result = DBConnection::get()->query(
                'SELECT * FROM `' . DB_PREFIX . 'files`
                WHERE `addon_id` = :addon_id
                AND `file_type` = :file_type',
                DBConnection::FETCH_ALL,
                [
                    ':addon_id'  => $this->id,
                    ':file_type' => 'image'
                ]
            );
        }
        catch(DBException $e)
        {
            return [];
        }

        return $result;
    }

    /**
     * Get all of the source files associated with an addon
     *
     * @return array
     */
    public function getSourceFiles()
    {
        try
        {
            $result = DBConnection::get()->query(
                'SELECT * FROM `' . DB_PREFIX . 'files`
                WHERE `addon_id` = :addon_id
                AND `file_type` = :file_type',
                DBConnection::FETCH_ALL,
                [
                    ':addon_id'  => $this->id,
                    ':file_type' => 'source'
                ]
            );
        }
        catch(DBException $e)
        {
            return [];
        }

        return $result;
    }

    /**
     * Set the add-on's description
     *
     * @param string $description
     *
     * @throws AddonException
     */
    public function setDescription($description)
    {
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS) && $this->uploaderId !== User::getLoggedId())
        {
            throw new AddonException(_h('You do not have the neccessary permissions to perform this action.'));
        }

        try
        {
            DBConnection::get()->query(
                'UPDATE `' . DB_PREFIX . 'addons`
                 SET `description` = :description
                 WHERE `id` = :id',
                DBConnection::NOTHING,
                [
                    ':description' => h($description),
                    ':id'          => $this->id
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to update the description record for this add-on.'));
        }

        writeAssetXML();
        writeNewsXML();
        $this->description = $description;
    }

    /**
     * Set the add-on's designer
     *
     * @param string $designer
     *
     * @throws AddonException
     */
    public function setDesigner($designer)
    {
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS) && $this->uploaderId !== User::getLoggedId())
        {
            throw new AddonException(_h('You do not have the neccessary permissions to perform this action.'));
        }

        try
        {
            DBConnection::get()->query(
                'UPDATE `' . DB_PREFIX . 'addons`
                SET `description` = :description
                WHERE `id` = :id',
                DBConnection::NOTHING,
                [
                    ':designer' => h($designer),
                    ':id'       => $this->id
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to update the designer record for this add-on.'));
        }

        writeAssetXML();
        writeNewsXML();
        $this->designer = $designer;
    }

    /**
     * Set the image for the latest revision of this add-on.
     *
     * @param integer $image_id
     * @param string  $field
     *
     * @throws AddonException
     */
    public function setImage($image_id, $field = 'image')
    {
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS) && $this->uploaderId !== User::getLoggedId())
        {
            throw new AddonException(_h('You do not have the neccessary permissions to perform this action.'));
        }

        try
        {
            DBConnection::get()->query(
                "UPDATE `" . DB_PREFIX . $this->type . "_revs`
                SET `" . $field . "` = :image_id
                WHERE `addon_id` = :addon_id
                AND `status` & " . F_LATEST,
                DBConnection::NOTHING,
                [
                    ':image_id' => $image_id,
                    ':addon_id' => $this->id
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to update the image record for this add-on.'));
        }
    }

    /**
     * @param $start_ver
     * @param $end_ver
     *
     * @throws AddonException
     */
    public function setIncludeVersions($start_ver, $end_ver)
    {
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            throw new AddonException(_h('You do not have the neccessary permissions to perform this action.'));
        }

        try
        {
            Validate::versionString($start_ver);
            Validate::versionString($end_ver);
            DBConnection::get()->query(
                'UPDATE `' . DB_PREFIX . 'addons`
                SET `min_include_ver` = :start_ver, `max_include_ver` = :end_ver
                WHERE `id` = :addon_id',
                DBConnection::NOTHING,
                [
                    ':addon_id'  => $this->id,
                    ':start_ver' => $start_ver,
                    ':end_ver'   => $end_ver
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('An error occurred while setting the min/max include versions.'));
        }

        writeAssetXML();
        writeNewsXML();
        $this->minInclude = $start_ver;
        $this->maxInclude = $end_ver;
    }

    /**
     * Set the license of this addon
     *
     * @param string $license
     *
     * @throws AddonException
     */
    public function setLicense($license)
    {
        try
        {
            DBConnection::get()->query(
                'UPDATE `' . DB_PREFIX . 'addons`
                SET `license` = :license,
                WHERE `id` = :addon_id',
                DBConnection::NOTHING,
                [
                    ':license'  => $license,
                    ':addon_id' => $this->id
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to update the license record for this add-on.'));
        }

        $this->license = $license;
    }

    /**
     * Set the name of this addon
     *
     * @param string $name
     *
     * @throws AddonException
     */
    public function setName($name)
    {
        try
        {
            DBConnection::get()->query(
                'UPDATE `' . DB_PREFIX . 'addons`
                SET `name` = :name,
                WHERE `id` = :addon_id',
                DBConnection::NOTHING,
                [
                    ':name'     => $name,
                    ':addon_id' => $this->id
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to update the name record for this add-on.'));
        }

        $this->name = $name;
    }

    /**
     * Set notes on this addon
     *
     * @param string $fields
     *
     * @throws AddonException
     */
    public function setNotes($fields)
    {
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            throw new AddonException(_h('You do not have the necessary permissions to perform this action.'));
        }

        $fields = explode(',', $fields);
        $notes = [];
        foreach ($fields as $field)
        {
            if (!isset($_POST[$field]))
            {
                $_POST[$field] = null;
            }
            $fieldinfo = explode('-', $field);
            $revision = (int)$fieldinfo[1];

            // Update notes
            $notes[$revision] = $_POST[$field];
        }

        // Save record in database
        foreach ($notes as $revision => $value)
        {
            try
            {
                DBConnection::get()->query(
                    'UPDATE `' . DB_PREFIX . $this->type . '_revs`
                    SET `moderator_note` = :moderator_note
                    WHERE `addon_id` = :addon_id
                    AND `revision` = :revision',
                    DBConnection::NOTHING,
                    [
                        ':moderator_note' => $value,
                        ':addon_id'       => $this->id,
                        ':revision'       => $revision
                    ]
                );
            }
            catch(DBException $e)
            {
                throw new AddonException(_h('Failed to write add-on status.'));
            }
        }

        // Generate email
        $email_body = null;
        $notes = array_reverse($notes, true);
        foreach ($notes as $revision => $value)
        {
            $email_body .= "\n== Revision $revision ==\n";
            $value = strip_tags(str_replace('\r\n', "\n", $value));
            $email_body .= "$value\n\n";
        }

        // Get uploader email address
        try
        {
            $user = DBConnection::get()->query(
                'SELECT `name`,`email`
                FROM `' . DB_PREFIX . 'users`
                WHERE `id` = :user_id
                LIMIT 1',
                DBConnection::FETCH_FIRST,
                [
                    ':user_id' => $this->uploaderId,
                ]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Failed to find user record.'));
        }

        try
        {
            $mail = new SMail;
            $mail->addonNoteNotification($user['email'], $this->id, $email_body);
        }
        catch(Exception $e)
        {
            throw new AddonException('Failed to send email to user. ' . $e->getMessage());
        }

        Log::newEvent("Added notes to '{$this->name}'");
    }

    /**
     * Check if any of an addon's revisions have been approved
     *
     * @return bool
     */
    public function hasApprovedRevision()
    {
        foreach ($this->revisions as $rev)
        {
            if (Addon::isApproved($rev['status']))
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the status flags of an addon
     *
     * @param string $fields
     *
     * @throws AddonException
     */
    public function setStatus($fields)
    {
        $fields = explode(',', $fields);
        $has_permission = User::hasPermission(AccessControl::PERM_EDIT_ADDONS);

        // Initialise the status field to its present values
        // (Remove any checkboxes that the user could have checked)
        $status = [];
        foreach ($this->revisions as $rev_n => $rev)
        {
            $mask = F_LATEST + F_ALPHA + F_BETA + F_RC;
            if ($has_permission)
            {
                $mask = $mask + F_APPROVED + F_INVISIBLE + F_DFSG + F_FEATURED;
            }

            $status[$rev_n] = ($rev['status'] & ~$mask);
        }

        // Iterate through each field
        foreach ($fields as $field)
        {
            if (!isset($_POST[$field]))
            {
                $_POST[$field] = null;
            }
            if ($field === 'latest')
            {
                $field_info = ['', (int)$_POST['latest']];
            }
            else
            {
                $field_info = explode('-', $field);
            }

            // Initialize the status of the current revision if it has
            // not been created yet.
            if (!isset($status[$field_info[1]]))
            {
                $status[$field_info[1]] = 0;
            }

            // Mark the "latest" revision
            if ($field === 'latest')
            {
                $status[(int)$_POST['latest']] += F_LATEST;
                continue;
            }

            // Update status values for all flags
            if ($_POST[$field] === 'on')
            {
                $revision = (int)$field_info[1];
                switch ($field_info[0])
                {
                    case 'approved':
                        if (!$has_permission)
                        {
                            break;
                        }
                        $status[$revision] += F_APPROVED;
                        break;

                    case 'invisible':
                        if (!$has_permission)
                        {
                            break;
                        }
                        $status[$revision] += F_INVISIBLE;
                        break;

                    case 'alpha':
                        $status[$revision] += F_ALPHA;
                        break;

                    case 'beta':
                        $status[$revision] += F_BETA;
                        break;

                    case 'rc':
                        $status[$revision] += F_RC;
                        break;

                    case 'dfsg':
                        if (!$has_permission)
                        {
                            break;
                        }
                        $status[$revision] += F_DFSG;
                        break;

                    case 'featured':
                        if (!$has_permission)
                        {
                            break;
                        }
                        $status[$revision] += F_FEATURED;
                        break;

                    default:
                        break;
                }
            }
        }

        // Loop through each addon revision
        foreach ($status as $revision => $value)
        {
            // Write new addon status
            try
            {
                DBConnection::get()->query(
                    'UPDATE `' . DB_PREFIX . $this->type . '_revs`
                    SET `status` = :status
                    WHERE `addon_id` = :addon_id
                    AND `revision` = :revision',
                    DBConnection::NOTHING,
                    [
                        ':status'   => $value,
                        ':addon_id' => $this->id,
                        ':revision' => $revision
                    ]
                );
            }
            catch(DBException $e)
            {
                throw new AddonException(_h('Failed to write add-on status.'));
            }
        }

        writeAssetXML();
        writeNewsXML();
        Log::newEvent("Set status for add-on '{$this->name}'");
    }

    /**
     * Factory method for the addon
     *
     * @param string $addonId
     * @param bool   $loadRevisions flag that indicates to load the addon revisions
     *
     * @return Addon
     * @throws AddonException
     */
    public static function get($addonId, $loadRevisions = true)
    {
        $data = static::getFromField("addons", "id", $addonId, DBConnection::PARAM_STR, _h('The requested add-on does not exist.'));

        return new Addon($data["id"], $data, $loadRevisions);
    }

    /**
     * Get the addon name
     *
     * @param int $id
     *
     * @return string empty string on error
     */
    public static function getNameByID($id)
    {
        $id = static::cleanId($id);

        try
        {
            $addon = DBConnection::get()->query(
                'SELECT `name`
                FROM `' . DB_PREFIX . 'addons`
                WHERE `id` = :id
                LIMIT 1',
                DBConnection::FETCH_FIRST,
                [':id' => $id]
            );
        }
        catch(DBException $e)
        {
            return "";
        }

        if (empty($addon))
        {
            // silently fail
            return "";
        }

        return $addon['name'];
    }

    /**
     * Get the addon type
     *
     * @param int $id
     *
     * @return string empty string on error
     */
    public static function getTypeByID($id)
    {
        $id = static::cleanId($id);

        try
        {
            $addon = DBConnection::get()->query(
                'SELECT `type`
                FROM `' . DB_PREFIX . 'addons`
                WHERE `id` = :id
                LIMIT 1',
                DBConnection::FETCH_FIRST,
                [':id' => $id]
            );
        }
        catch(DBException $e)
        {
            return "";
        }

        if (empty($addon))
        {
            // silently fail
            return "";
        }

        return $addon['type'];
    }

    /**
     * Check if the type is allowed
     *
     * @param string $type
     *
     * @return bool true if allowed and false otherwise
     */
    public static function isAllowedType($type)
    {
        return in_array($type, static::getAllowedTypes());
    }

    /**
     * Get an array of allowed types
     *
     * @return array
     */
    public static function getAllowedTypes()
    {
        return [static::KART, static::TRACK, static::ARENA];
    }

    /**
     * Perform a cleaning operation on the id
     *
     * @param string $id what we want to clean
     *
     * @return string|bool
     */
    public static function cleanId($id)
    {
        if (!is_string($id))
        {
            //trigger_error("ID is not a string");

            return false;
        }

        $length = mb_strlen($id);
        if (!$length)
        {
            return false;
        }
        $id = mb_strtolower($id);

        // Validate all characters in addon id
        // Rather than using str_replace, and removing bad characters,
        // it makes more sense to only allow certain characters
        for ($i = 0; $i < $length; $i++)
        {
            $substr = mb_substr($id, $i, 1);
            if (!preg_match('/^[a-z0-9\-_]$/i', $substr))
            {
                $substr = '-';
            }
            $id = substr_replace($id, $substr, $i, 1);
        }

        return $id;
    }

    /**
     * Search for an addon by its name or description
     *
     * @param string $search_query
     * @param bool   $search_description search also in description
     *
     * @throws AddonException
     * @return array Matching addon id, name and type
     */
    public static function search($search_query, $search_description = true)
    {
        // build query
        $query = "SELECT * FROM `" . DB_PREFIX . "addons` WHERE `name` LIKE :search_query";

        if ($search_description)
        {
            $query .= " OR `description` LIKE :search_query";
        }

        try
        {
            $addons = DBConnection::get()->query(
                $query,
                DBConnection::FETCH_ALL,
                [':search_query' => '%' . $search_query . '%']
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Search failed!'));
        }

        return $addons;
    }

    /**
     * Get all the addon's of a type
     *
     * @param string $type
     * @param bool   $featuredFirst
     *
     * @return array
     */
    public static function getAddonList($type, $featuredFirst = false)
    {
        if (!static::isAllowedType($type))
        {
            return [];
        }

        // build query
        $query = 'SELECT `a`.`id`, (`r`.`status` & ' . F_FEATURED . ') AS `featured`
                      FROM `' . DB_PREFIX . 'addons` `a`
                      LEFT JOIN `' . DB_PREFIX . $type . '_revs` `r`
                      ON `a`.`id` = `r`.`addon_id`
                      WHERE `a`.`type` = :type
                      AND `r`.`status` & :latest_bit ';
        if ($featuredFirst)
        {
            $query .= 'ORDER BY `featured` DESC, `a`.`name` ASC, `a`.`id` ASC';
        }
        else
        {
            $query .= 'ORDER BY `name` ASC, `id` ASC';
        }

        try
        {
            $list = DBConnection::get()->query(
                $query,
                DBConnection::FETCH_ALL,
                [
                    ':type'       => $type,
                    ':latest_bit' => F_LATEST
                ]
            );
        }
        catch(DBException $e)
        {
            return [];
        }

        $return = [];
        foreach ($list as $addon)
        {
            $return[] = $addon['id'];
        }

        return $return;
    }

    /**
     * Generate a random id based on the name
     *
     * @param string $type
     * @param string $name
     *
     * @return string the new id
     */
    public static function generateId($type, $name)
    {
        // TODO find usage for $type
        if (!is_string($name))
        {
            return false;
        }

        $addon_id = static::cleanId($name);
        if (!$addon_id)
        {
            return false;
        }

        // Check database
        while (static::exists($addon_id))
        {
            // If the addon id already exists, add an incrementing number to it
            $matches = [];
            if (preg_match('/^.+_([0-9]+)$/i', $addon_id, $matches))
            {
                $next_num = (int)$matches[1];
                $next_num++;
                $addon_id = str_replace($matches[1], $next_num, $addon_id);
            }
            else
            {
                $addon_id .= '_1';
            }
        }

        return $addon_id;
    }

    /**
     * Create a new add-on record and an initial revision
     * @global string $moderator_message Initial revision status message
     *                                   FIXME: put this in $attributes somewhere
     *
     * @param string  $type              Add-on type
     * @param array   $attributes        Contains properties of the add-on. Must have the
     *                                   following elements: name, designer, license, image, fileid, status, (arena)
     * @param string  $fileid            ID for revision file (see FIXME below)
     * @param string  $moderator_message
     *
     * @throws AddonException
     */
    public static function create($type, $attributes, $fileid, $moderator_message)
    {
        foreach ($attributes['missing_textures'] as $tex)
        {
            $moderator_message .= "Texture not found: $tex\n";
        }

        // Check if logged in
        if (!User::isLoggedIn())
        {
            throw new AddonException(_h('You must be logged in to create an add-on.'));
        }

        if (!static::isAllowedType($type))
        {
            throw new AddonException(_h('An invalid add-on type was provided.'));
        }

        $id = static::generateId($type, $attributes['name']);

        // Make sure the add-on doesn't already exist
        if (static::exists($id))
        {
            throw new AddonException(_h('An add-on with this ID already exists. Please try to upload your add-on again later.'));
        }

        // Make sure no revisions with this id exists
        // FIXME: Check if this id is redundant or not. Could just
        //        auto-increment this column if it is unused elsewhere.
        try
        {
            $rows = DBConnection::get()->query(
                'SELECT * FROM ' . DB_PREFIX . $type . '_revs WHERE `id` = :id',
                DBConnection::ROW_COUNT,
                [':id' => $fileid]
            );
        }
        catch(DBException $e)
        {
            throw new AddonException(sprintf('Failed to acces the %s_revs table.', $type));
        }

        if ($rows)
        {
            throw new AddonException(_h('The add-on you are trying to create already exists.'));
        }

        // add addon to database
        $fields_data = [
            ":id"       => $id,
            ":type"     => $type,
            ":name"     => $attributes['name'],
            ":uploader" => User::getLoggedId(),
            ":designer" => $attributes['designer'],
            ":license"  => $attributes['license']
        ];
        if ($type === static::TRACK)
        {
            if ($attributes['arena'] === 'Y')
            {
                $fields_data[":props"] = '1';
            }
            else
            {
                $fields_data[":props"] = '0';
            }
        }

        try
        {
            DBConnection::get()->insert("addons", $fields_data);
        }
        catch(DBException $e)
        {
            throw new AddonException(_h('Your add-on could not be uploaded.'));
        }

        // Add the first revision
        $rev = 1;

        // Generate revision entry
        $fields_data = [
            ":id"       => $fileid,
            ":addon_id" => $id,
            ":fileid"   => $attributes['fileid'],
            ":revision" => $rev,
            ":format"   => $attributes['version'],
            ":image"    => $attributes['image'],
            ":status"   => $attributes['status']

        ];
        if ($type === static::KART)
        {
            $fields_data[":icon"] = $attributes['image'];
        }

        // Add moderator message if available
        if ($moderator_message)
        {
            $fields_data[":moderator_note"] = $moderator_message;
        }

        try
        {
            DBConnection::get()->insert($type . '_revs', $fields_data);
        }
        catch(DBException $e)
        {
            throw new AddonException($e->getMessage());
        }

        // Send mail to moderators
        moderator_email(
            'New Addon Upload',
            sprintf(
                "%s has uploaded a new %s '%s' %s",
                User::getLoggedUserName(),
                $type,
                $attributes['name'],
                (string)$id
            )
        );
        writeAssetXML();
        writeNewsXML();
        Log::newEvent("New add-on '{$attributes['name']}'");
    }

    /**
     * Check if an add-on of the specified ID exists
     *
     * @param string $id Addon ID
     *
     * @return bool
     */
    public static function exists($id)
    {
        return static::existsField("addons", "id", Addon::cleanId($id), DBConnection::PARAM_STR);
    }

    /**
     * If addon is approved
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isApproved($status)
    {
        return $status & F_APPROVED;
    }

    /**
     * If addon is in alpha
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isAlpha($status)
    {
        return $status & F_ALPHA;
    }

    /**
     * If addon is in beta
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isBeta($status)
    {
        return $status & F_BETA;
    }

    /**
     * If addon is in release candidate
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isReleaseCandidate($status)
    {
        return $status & F_RC;
    }

    /**
     * If addon is invisible
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isInvisible($status)
    {
        return $status & F_INVISIBLE;
    }

    /**
     * If addon is Debian Free Software Guidelines compliant
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isDFSGCompliant($status)
    {
        return $status & F_DFSG;
    }

    /**
     * If addon is featured
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isFeatured($status)
    {
        return $status & F_FEATURED;
    }

    /**
     * If addon is latest
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isLatest($status)
    {
        return $status & F_LATEST;
    }

    /**
     * If texture is a power of two
     *
     * @param int $status
     *
     * @return bool
     */
    public static function isTexturePowerOfTwo($status)
    {
        return !($status & F_TEX_NOT_POWER_OF_2);
    }
}
