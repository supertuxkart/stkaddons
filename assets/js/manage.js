/**
 * copyright 2014 Daniel Butum <danibutum at gmail dot com>
 *
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

(function($) {
    "use strict";

    var $manage_body = $("#manage-body"),
        json_url = SITE_ROOT + "json/manage.php";

    // role variables
    var $role_edit_value, $role_edit_btn, $role_delete_btn, selected_role;

    function onPageLoad() {
        $(".table-no-sort").DataTable({"bSort": false, "iDisplayLength": 25});
        $(".table-sort").DataTable({"iDisplayLength": 10});
    }

    function manageFormSubmit(form_identifier, callback_success) {
        onFormSubmit(form_identifier, callback_success, $manage_body, json_url);
    }

    function loadManageMainContent(href) {
        var view = getUrlVars(href)['view'];
        loadContent($manage_body, SITE_ROOT + 'manage-panel.php', {view: view}, function() {
            onPageLoad();
        });
    }

    // left panel item clicked
    $('a.manage-list').click(function() {
        History.pushState(null, '', this.href);

        loadManageMainContent(this.href);

        return false;
    });

    // general settings form submitted
    manageFormSubmit("#form-general-settings", function(data) {
        jsonCallback(data);
    });

    // role clicked
    $manage_body.on("click", "#manage-roles-roles button", function() {
        var $this = $(this),
            $siblings = $this.siblings();

        // can not press the same button twice
        if ($this.hasClass("active")) {
            return;
        }

        // mark as active
        $this.addClass("active");

        // remove mark from others
        $siblings.removeClass("active");

        // update form role
        selected_role = $this.text();
        $("#manage-roles-permission-role").val(selected_role);

        // update toolbox values
        $role_edit_value = $("#manage-roles-edit-value");
        $role_edit_btn = $("#manage-roles-edit-btn");
        $role_delete_btn = $("#manage-roles-delete-btn");

        $role_edit_value.prop("disabled", false).val(selected_role);
        $role_edit_btn.removeClass("disabled");
        $role_delete_btn.removeClass("disabled");

        // update role checkboxes
        $.post(json_url, {action: "get-role", role: selected_role}, function(data) {
            jsonCallback(data, function(jData) {
                var permissions = jData["permissions"];
                var $checkboxes = $(".manage-roles-permission-checkbox");

                // update permissions checkboxes
                $checkboxes.each(function() {
                    this.checked = false; // uncheck

                    // role has permissions
                    if (_.contains(permissions, this.value)) {
                        this.checked = true;
                    }
                });

                return false;
            });
        });
    });

    // role add clicked
    $manage_body.on("click", "#manage-roles-add-btn", function() {
        var role = $("#manage-roles-add-value").val();

        $.post(json_url, {role: role, action: "add-role"}, function(data) {
            jsonCallback(data, function() {
                $("#manage-roles-roles").append('<button type="button" class="btn btn-default">{0}</button>'.format(role))
            })
        });
    });

    // role edit clicked
    $manage_body.on("click", "#manage-roles-edit-btn", function() {
        growlError("Role edit not yet implemented.");
    });

    // role delete clicked
    $manage_body.on("click", "#manage-roles-delete-btn", function() {
        var role = $("#manage-roles-edit-value").val(); // use the value from edit

        $.post(json_url, {role: role, action: "delete-role"}, function(data) {
            jsonCallback(data, function() {
                $("#manage-roles-roles button:contains('{0}')".format(role)).remove();
            });
        })
    });

    // update role permission submitted,
    manageFormSubmit("#manage-roles-permission-form", function(data) {
        jsonCallback(data);
    });

    // add news submitted
    manageFormSubmit("#form-add-news", function(data) {
        jsonCallback(data, function() {
            loadManageMainContent("?view=news"); // cheat the load content page
        });
    });

    // delete news submitted
    $manage_body.on("click", ".news-delete-btn", function() {
        var $this = $(this),
            id = $this.data("id");

        $.post(json_url, {"action": "delete-news", "news-id": id}, function(data) {
            jsonCallback(data, function() {
                $this.closest("tr").remove();
            });
        })
    });

    // empty cache clicked
    $manage_body.on("click", "#btn-empty-cache", function() {
        $.post(json_url, {"action": "clear-cache"}, function(data) {
            jsonCallback(data);
        });
    });

    onPageLoad();

})(jQuery);
