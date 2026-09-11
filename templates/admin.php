<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

use OCA\SetTarget\Service\ShareTargetService;

/** @var \OCP\IL10N $l */
/** @var array $_ */

style('files_set_target', 'share-target');

$selected = $_['restrict_mode'] !== ShareTargetService::MODE_ALL;
?>

<section class="section">
    <h2><?php p($l->t('Share Target')); ?></h2>

    <div class="set-target-admin" id="files_set_target_admin"
        data-allowed-users="<?php p(json_encode(array_values($_['allowed_users']))); ?>"
        data-allowed-groups="<?php p(json_encode(array_values($_['allowed_groups']))); ?>">

        <div class="set-target-section">
            <h3><?php p($l->t('Who can set a share target')); ?></h3>
            <p class="set-target-description">
                <?php p($l->t('An account that may not set a target shares normally. The field does not appear for it.')); ?>
            </p>

            <div class="set-target-radio-row">
                <input type="radio" name="files_set_target_mode" value="<?php p(ShareTargetService::MODE_ALL); ?>"
                    id="files_set_target_mode_all" <?php if (!$selected) { ?>checked<?php } ?> />
                <label for="files_set_target_mode_all"><?php p($l->t('All accounts')); ?></label>
            </div>
            <div class="set-target-radio-row">
                <input type="radio" name="files_set_target_mode" value="<?php p(ShareTargetService::MODE_SELECTED); ?>"
                    id="files_set_target_mode_selected" <?php if ($selected) { ?>checked<?php } ?> />
                <label for="files_set_target_mode_selected">
                    <?php p($l->t('Selected accounts and groups')); ?>
                </label>
            </div>

            <div id="files_set_target_selected" class="set-target-selected" <?php if (!$selected) { ?>hidden<?php } ?>>
                <div class="set-target-picker" id="files_set_target_users">
                    <label for="files_set_target_users_search"><?php p($l->t('Accounts')); ?></label>
                    <input type="search" id="files_set_target_users_search" class="set-target-search"
                        list="files_set_target_users_options" autocomplete="off"
                        placeholder="<?php p($l->t('Search accounts…')); ?>" />
                    <ul class="set-target-chips"></ul>
                    <datalist id="files_set_target_users_options"></datalist>
                </div>

                <div class="set-target-picker" id="files_set_target_groups">
                    <label for="files_set_target_groups_search"><?php p($l->t('Groups')); ?></label>
                    <input type="search" id="files_set_target_groups_search" class="set-target-search"
                        list="files_set_target_groups_options" autocomplete="off"
                        placeholder="<?php p($l->t('Search groups…')); ?>" />
                    <ul class="set-target-chips"></ul>
                    <datalist id="files_set_target_groups_options"></datalist>
                </div>
            </div>
        </div>

        <div class="set-target-section">
            <h3><?php p($l->t('Parent Folder')); ?></h3>
            <p class="set-target-description">
                <?php p($l->t('Allow a target path whose parent folder does not exist yet. The app makes the parent folder in the recipient account when the share starts.')); ?>
                <?php p($l->t('User shares only.')); ?>
            </p>
            <div class="set-target-checkbox-row">
                <input type="checkbox" id="files_set_target_auto_create"
                    <?php if ($_['enable_auto_create_dir']) { ?>checked<?php } ?> />
                <label for="files_set_target_auto_create">
                    <?php p($l->t('Make the parent folder if it does not exist')); ?>
                </label>
            </div>
            <div class="set-target-checkbox-row">
                <input type="checkbox" id="files_set_target_auto_remove"
                    <?php if ($_['enable_auto_remove_dir']) { ?>checked<?php } ?> />
                <label for="files_set_target_auto_remove">
                    <?php p($l->t('Remove that folder again when the share goes and the folder is empty')); ?>
                </label>
            </div>
        </div>

        <div id="files_set_target_status" class="set-target-status" hidden aria-live="polite"></div>

        <button id="files_set_target_save" class="button primary">
            <?php p($l->t('Save Settings')); ?>
        </button>
    </div>
</section>
