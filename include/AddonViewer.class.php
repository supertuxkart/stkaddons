<?php
/**
 * Copyright        2010 Lucas Baudin <xapantu@gmail.com>
 *           2011 - 2014 Stephen Just <stephenjust@gmail.com>
 *                  2014 Daniel Butum <danibutum at gmail dot com>
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
 * Class AddonViewer
 */
class AddonViewer
{

    /**
     * @var Addon Current addon
     */
    private $addon;

    /**
     * @var string
     */
    private $latestRev;

    /**
     * @var Rating
     */
    private $rating = false;

    /**
     * Constructor
     *
     * @param string $id Add-On ID
     */
    public function __construct($id)
    {
        $this->addon = Addon::get($id);
        $this->latestRev = $this->addon->getLatestRevision();
        $this->rating = new Rating($id);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $return = '';

        if (User::isLoggedIn())
        {
            // write configuration for the submiter and administrator
            if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS) || $this->addon->getUploaderId() === User::getLoggedId())
            {
                $return .= $this->displayConfig();
            }
        }

        return $return;
    }

    /**
     * Fill template with addon info
     *
     * @param Template $template
     */
    public function fillTemplate($template)
    {
        // build template
        $tpl = [
            'name'        => h($this->addon->getName()),
            'description' => h($this->addon->getDescription()),
            'type'        => $this->addon->getType(),
            'designer'    => h($this->addon->getDesigner()),
            'license'     => h($this->addon->getLicense()),
            'rating'      => [
                'label'      => $this->rating->getRatingString(),
                'percent'    => $this->rating->getAvgRatingPercent(),
                'decimal'    => $this->rating->getAvgRating(),
                'count'      => $this->rating->getNumRatings(),
                'min_rating' => Rating::MIN_RATING,
                'max_rating' => Rating::MAX_RATING
            ],
            'badges'      => AddonViewer::badges($this->addon->getStatus()),
            'image_url'   => false,
            'dl'          => [],
            'vote'        => false, // only logged users see this
        ];

        // build permission variables
        $is_logged = User::isLoggedIn();
        $is_owner = $has_permission = $can_edit = false;
        if ($is_logged) // not logged in, no reason to do checking
        {
            $is_owner = ($this->addon->getUploaderId() === User::getLoggedId());
            $has_permission = User::hasPermission(AccessControl::PERM_EDIT_ADDONS);
            $can_edit = ($is_owner || $has_permission);

            $tpl['vote'] = $this->rating->displayUserRating();
        }

        // Get image url
        $image = Cache::getImage($this->addon->getImage(), ['size' => 'big']);
        if ($this->addon->getImage() != 0 && $image['exists'] == true && $image['approved'] == true)
        {
            $tpl['image_url'] = $image['url'];
        }

        // build info table
        $addonUser = User::getFromID($this->addon->getUploaderId());
        $latestRev = $this->addon->getLatestRevision();
        $info = [
            'upload_date'   => $latestRev['timestamp'],
            'submitter'     => h($addonUser->getUserName()),
            'revision'      => $latestRev['revision'],
            'compatibility' => Util::getVersionFormat($latestRev['format'], $this->addon->getType()),
            'link'          => File::rewrite($this->addon->getLink())
        ];
        $tpl['info'] = $info;
        if (!Addon::isTexturePowerOfTwo($latestRev['status']))
        {
            $template->assign(
                "warnings",
                'Warning: This addon may not display correctly on some systems. It uses textures that may not be compatible with all video cards.'
            );
        }

        // Download button, TODO use this in some way
        $file_path = $this->addon->getFile((int)$this->latestRev['revision']);
        if ($file_path !== false && File::exists($file_path))
        {
            $button_text = h(sprintf(_('Download %s'), $this->addon->getName()));
            $shrink = (mb_strlen($button_text) > 20) ? 'style="font-size: 1.1em !important;"' : null;
            $tpl['dl'] = [
                'label'  => $button_text,
                'url'    => DOWNLOAD_LOCATION . $file_path,
                'shrink' => $shrink,
            ];
        }

        // Revision list
        $revisions_db = $this->addon->getAllRevisions();
        $revisions_tpl = [];
        foreach ($revisions_db as $rev_n => $revision)
        {
            if (!$is_logged)
            {
                // Users not logged in cannot see unapproved addons
                if (!Addon::isApproved($revision['status']))
                {
                    continue;
                }
            }
            else
            {
                // If the user is not the uploader, or moderators, then they cannot see unapproved addons
                if (!$can_edit && !Addon::isApproved($revision['status']))
                {
                    continue;
                }
            }

            $rev = [
                'number'    => $rev_n,
                'timestamp' => $revision['timestamp'],
                'file'      => [
                    'path' => DOWNLOAD_LOCATION . $this->addon->getFile($rev_n)
                ],
                'dl_label'  => h(sprintf(_('Download revision %u'), $rev_n))
            ];

            if (!File::exists($rev['file']['path']))
            {
                continue;
            }

            $revisions_tpl[] = $rev;
        }
        $tpl['revisions'] = $revisions_tpl;

        // Image list. Get images
        $image_files_db = $this->addon->getImages();
        $image_files = [];
        foreach ($image_files_db as $image)
        {
            $image['url'] = DOWNLOAD_LOCATION . $image['file_path'];
            $imageCache = Cache::getImage($image['id'], ['size' => 'medium']);
            $image['thumb']['url'] = $imageCache['url'];
            $admin_links = null;
            if ($is_logged)
            {
                // only users that can edit addons
                if ($has_permission)
                {
                    if ($image['approved'] == 1)
                    {
                        $admin_links .= '<a href="' . File::rewrite(
                                $this->addon->getLink() . '&amp;save=unapprove&amp;id=' . $image['id']
                            ) . '">' . _h('Unapprove') . '</a>';
                    }
                    else
                    {
                        $admin_links .= '<a href="' . File::rewrite(
                                $this->addon->getLink() . '&amp;save=approve&amp;id=' . $image['id']
                            ) . '">' . _h('Approve') . '</a>';
                    }
                    $admin_links .= '<br />';
                }

                // edit addons and the owner
                if ($can_edit)
                {
                    if ($this->addon->getType() == Addon::KART)
                    {
                        if ($this->addon->getImage(true) != $image['id'])
                        {
                            $admin_links .= '<a href="' . File::rewrite(
                                    $this->addon->getLink() . '&amp;save=seticon&amp;id=' . $image['id']
                                ) . '">' . _h('Set Icon') . '</a><br />';
                        }
                    }
                    if ($this->addon->getImage() != $image['id'])
                    {
                        $admin_links .= '<a href="' . File::rewrite(
                                $this->addon->getLink() . '&amp;save=setimage&amp;id=' . $image['id']
                            ) . '">' . _h('Set Image') . '</a><br />';
                    }
                    $admin_links .= '<a href="' . File::rewrite(
                            $this->addon->getLink() . '&amp;save=deletefile&amp;id=' . $image['id']
                        ) . '">' . _h('Delete File') . '</a><br />';
                }
            }

            $image['admin_links'] = $admin_links;
            if ($can_edit)
            {
                $image_files[] = $image;
                continue;
            }

            if ($image['approved'] == 1)
            {
                $image_files[] = $image;
            }
        }
        $tpl['images'] = $image_files;

        // Search database for source files
        $source_files_db = $this->addon->getSourceFiles();
        $source_files = [];
        foreach ($source_files_db as $source)
        {
            $source['label'] = sprintf(_h('Source File %u'), count($source_files) + 1);
            $source['details'] = null;
            if ($source['approved'] == 0)
            {
                $source['details'] .= '(' . _h('Not Approved') . ') ';
            }
            $source['details'] .= '<a href="' . DOWNLOAD_LOCATION . $source['file_path'] . '" rel="nofollow">' . _(
                    'Download'
                ) . '</a>';
            if ($is_logged)
            {
                if ($has_permission)
                {
                    if ($source['approved'] == 1)
                    {
                        $source['details'] .= ' | <a href="' . File::rewrite(
                                $this->addon->getLink() . '&amp;save=unapprove&amp;id=' . $source['id']
                            ) . '">' . _h('Unapprove') . '</a>';
                    }
                    else
                    {
                        $source['details'] .= ' | <a href="' . File::rewrite(
                                $this->addon->getLink() . '&amp;save=approve&amp;id=' . $source['id']
                            ) . '">' . _h('Approve') . '</a>';
                    }
                }
                if ($can_edit)
                {
                    $source['details'] .= ' | <a href="' . File::rewrite(
                            $this->addon->getLink() . '&amp;save=deletefile&amp;id=' . $source['id']
                        ) . '">' . _h('Delete File') . '</a><br />';
                }
            }
            if ($can_edit)
            {
                $source_files[] = $source;
                continue;
            }
            if ($source['approved'] == 1)
            {
                $source_files[] = $source;
            }
        }
        $tpl['source_list'] = $source_files;

        $template->assign('addon', $tpl)
            ->assign("can_edit", $can_edit)
            ->assign("is_logged", $is_logged);
    }

    /**
     * Output HTML to display flag badges
     *
     * @param int $status The 'status' value to interperet
     *
     * @return string
     */
    private static function badges($status)
    {
        $string = '';
        if (Addon::isFeatured($status))
        {
            $string .= '<span class="badge f_featured">' . _h('Featured') . '</span>';
        }
        if (Addon::isAlpha($status))
        {
            $string .= '<span class="badge f_alpha">' . _h('Alpha') . '</span>';
        }
        if (Addon::isBeta($status))
        {
            $string .= '<span class="badge f_beta">' . _h('Beta') . '</span>';
        }
        if (Addon::isReleaseCandidate($status))
        {
            $string .= '<span class="badge f_rc">' . _h('Release-Candidate') . '</span>';
        }
        if (Addon::isDFSGCompliant($status))
        {
            $string .= '<span class="badge f_dfsg">' . _h('DFSG Compliant') . '</span>';
        }

        return $string;
    }

    /**
     * @return string
     * @throws AddonException
     */
    private function displayConfig()
    {
        ob_start();

        // Check permission
        if (User::isLoggedIn() == false)
        {
            throw new AddonException('You must be logged in to see this.');
        }
        if (!User::hasPermission(AccessControl::PERM_EDIT_ADDONS) && $this->addon->getUploaderId() !== User::getLoggedId()
        )
        {
            throw new AddonException(_h('You do not have the necessary privileges to perform this action.'));
        }

        echo '<br /><hr /><br /><h3>' . _h('Configuration') . '</h3>';
        echo '<form name="changeProps" action="' . File::rewrite(
                $this->addon->getLink() . '&amp;save=props'
            ) . '" method="POST" accept-charset="utf-8">';

        // Edit designer
        $designer = ($this->addon->getDesigner() == _h('Unknown')) ? null : $this->addon->getDesigner();
        echo '<label for="designer_field">' . _h('Designer:') . '</label><br />';
        echo '<input type="text" name="designer" id="designer_field" value="' . $designer . '" accept-charset="utf-8" /><br />';
        echo '<br />';

        // Edit description
        echo '<label for="desc_field">' . _h('Description:') . '</label> (' . sprintf(_h('Max %u characters'), '140') . ')<br />';
        echo '<textarea name="description" id="desc_field" rows="4" cols="60" onKeyUp="textLimit(document.getElementById(\'desc_field\'),140);"
            onKeyDown="textLimit(document.getElementById(\'desc_field\'),140);" accept-charset="utf-8">' . $this->addon->getDescription(
            ) . '</textarea><br />';

        // Submit
        echo '<input type="submit" value="' . _h('Save Properties') . '" />';
        echo '</form><br />';

        // Delete addon
        if ($this->addon->getUploaderId() === User::getLoggedId() || User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            echo '<input type="button" value="' . _h('Delete Addon') . '"onClick="confirm_delete(\'' . File::rewrite(
                    $this->addon->getLink() . '&amp;save=delete'
                ) . '\')" /><br /><br />';
        }

        // Mark whether or not an add-on has ever been included in STK
        if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            echo '<strong>' . _h('Included in Game Versions:') . '</strong><br />';
            echo '<form method="POST" action="' . File::rewrite($this->addon->getLink() . '&amp;save=include') . '">';
            echo _h('Start:') . ' <input type="text" name="incl_start" size="6" value="' . h(
                    $this->addon->getIncludeMin()
                ) . '" /><br />';
            echo _h('End:') . ' <input type="text" name="incl_end" size="6" value="' . h(
                    $this->addon->getIncludeMax()
                ) . '" /><br />';
            echo '<input type="submit" value="' . _h('Save') . '" /><br />';
            echo '</form><br />';
        }

        // Set status flags
        echo '<strong>' . _h('Status Flags:') . '</strong><br />';
        echo '<form method="POST" action="' . File::rewrite($this->addon->getLink() . '&amp;save=status') . '">';
        echo '<table id="addon_flags" class="info"><thead><tr><th></th>';
        if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            echo '<th>' . Util::getImageLabel(_h('Approved')) . '</th><th>' . Util::getImageLabel(
                    _h('Invisible')
                ) . '</th>';
        }
        echo '<th>' . Util::getImageLabel(_h('Alpha')) . '</th><th>' . Util::getImageLabel(_h('Beta')) . '</th>
            <th>' . Util::getImageLabel(_h('Release-Candidate')) . '</th><th>' . Util::getImageLabel(
                _h('Latest')
            ) . '</th>';
        if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            echo '<th>' . Util::getImageLabel(_h('DFSG Compliant')) . '</th>
                <th>' . Util::getImageLabel(_h('Featured')) . '</th>';
        }
        echo '<th>' . Util::getImageLabel(_h('Invalid Textures')) . '</th><th></th>';
        echo '</tr></thead>';

        $fields = array();
        $fields[] = 'latest';
        foreach ($this->addon->getAllRevisions() AS $rev_n => $revision)
        {
            // Row Header
            echo '<tr><td style="text-align: center;">';
            printf(_h('Rev %u:'), $rev_n);
            echo '</td>';

            if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
            {
                // F_APPROVED
                echo '<td>';
                if ($revision['status'] & F_APPROVED)
                {
                    echo '<input type="checkbox" name="approved-' . $rev_n . '" checked />';
                }
                else
                {
                    echo '<input type="checkbox" name="approved-' . $rev_n . '" />';
                }
                echo '</td>';
                $fields[] = 'approved-' . $rev_n;

                // F_INVISIBLE
                echo '<td>';
                if ($revision['status'] & F_INVISIBLE)
                {
                    echo '<input type="checkbox" name="invisible-' . $rev_n . '" checked />';
                }
                else
                {
                    echo '<input type="checkbox" name="invisible-' . $rev_n . '" />';
                }
                echo '</td>';
                $fields[] = 'invisible-' . $rev_n;
            }

            // F_ALPHA
            echo '<td>';
            if ($revision['status'] & F_ALPHA)
            {
                echo '<input type="checkbox" name="alpha-' . $rev_n . '" checked />';
            }
            else
            {
                echo '<input type="checkbox" name="alpha-' . $rev_n . '" />';
            }
            echo '</td>';
            $fields[] = 'alpha-' . $rev_n;

            // F_BETA
            echo '<td>';
            if ($revision['status'] & F_BETA)
            {
                echo '<input type="checkbox" name="beta-' . $rev_n . '" checked />';
            }
            else
            {
                echo '<input type="checkbox" name="beta-' . $rev_n . '" />';
            }
            echo '</td>';
            $fields[] = 'beta-' . $rev_n;

            // F_RC
            echo '<td>';
            if ($revision['status'] & F_RC)
            {
                echo '<input type="checkbox" name="rc-' . $rev_n . '" checked />';
            }
            else
            {
                echo '<input type="checkbox" name="rc-' . $rev_n . '" />';
            }
            echo '</td>';
            $fields[] = 'rc-' . $rev_n;

            // F_LATEST
            echo '<td>';
            if ($revision['status'] & F_LATEST)
            {
                echo '<input type="radio" name="latest" value="' . $rev_n . '" checked />';
            }
            else
            {
                echo '<input type="radio" name="latest" value="' . $rev_n . '" />';
            }
            echo '</td>';

            if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
            {
                // F_DFSG
                echo '<td>';
                if ($revision['status'] & F_DFSG)
                {
                    echo '<input type="checkbox" name="dfsg-' . $rev_n . '" checked />';
                }
                else
                {
                    echo '<input type="checkbox" name="dfsg-' . $rev_n . '" />';
                }
                echo '</td>';
                $fields[] = 'dfsg-' . $rev_n;

                // F_FEATURED
                echo '<td>';
                if ($revision['status'] & F_FEATURED)
                {
                    echo '<input type="checkbox" name="featured-' . $rev_n . '" checked />';
                }
                else
                {
                    echo '<input type="checkbox" name="featured-' . $rev_n . '" />';
                }
                echo '</td>';
                $fields[] = 'featured-' . $rev_n;
            }

            // F_TEX_NOT_POWER_OF_2
            echo '<td>';
            if ($revision['status'] & F_TEX_NOT_POWER_OF_2)
            {
                echo '<input type="checkbox" name="texpower-' . $rev_n . '" checked disabled />';
            }
            else
            {
                echo '<input type="checkbox" name="texpower-' . $rev_n . '" disabled />';
            }
            echo '</td>';

            // Delete revision button
            echo '<td>';
            echo '<input type="button" value="' . sprintf(_h('Delete revision %d'), $rev_n)
                . '" onClick="confirm_delete(\'' . File::rewrite(
                    $this->addon->getLink() . '&amp;save=del_rev&amp;rev=' . $rev_n
                ) . '\');" />';
            echo '</td>';

            echo '</tr>';
        }
        echo '</table>';
        echo '<input type="hidden" name="fields" value="' . implode(',', $fields) . '" />';
        echo '<input type="submit" value="' . _h('Save Changes') . '" />';
        echo '</form><br />';

        // Moderator notes
        echo '<strong>' . _h('Notes from Moderator to Submitter:') . '</strong><br />';
        if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            echo '<form method="POST" action="' . File::rewrite($this->addon->getLink() . '&amp;save=notes') . '">';
        }

        $fields = array();
        foreach ($this->addon->getAllRevisions() AS $rev_n => $revision)
        {
            printf(_h('Rev %u:') . '<br />', $rev_n);
            echo '<textarea name="notes-' . $rev_n . '"
                id="notes-' . $rev_n . '" rows="4" cols="60"
                onKeyUp="textLimit(document.getElementById(\'notes-' . $rev_n . '\'),4000);"
                onKeyDown="textLimit(document.getElementById(\'notes-' . $rev_n . '\'),4000);">';
            echo $revision['moderator_note'];
            echo '</textarea><br />';
            $fields[] = 'notes-' . $rev_n;
        }

        if (User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
        {
            echo '<input type="hidden" name="fields" value="' . implode(',', $fields) . '" />';
            echo '<input type="submit" value="' . _h('Save Notes') . '" />';
            echo '</form>';
        }

        return ob_get_clean();
    }

}
