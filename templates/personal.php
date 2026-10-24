<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{alwaysSearch: bool, trusted: bool, saveUrl: string} $_
 */

script('paperless_unified_search', 'personal');
// The styles of the administration settings, whose classes this section shares.
style('paperless_unified_search', 'settings');

// While the administrators trust Paperless, every search includes it, whatever the user chose.
$trusted = $_['trusted'];
?>

<div
	id="paperless-unified-search-personal"
	class="section paperless-unified-search-settings"
	data-save-url="<?php p($_['saveUrl']); ?>">
	<h2><?php p($l->t('Paperless')); ?></h2>

	<p class="settings-hint">
		<?php p($l->t('Nextcloud searches your Paperless documents only while “Search connected services” is on in the search, and it turns the switch off again on every page that loads.')); ?>
	</p>

	<div class="paperless-unified-search-option">
		<input
			id="paperless-unified-search-personal-always-search"
			type="checkbox"
			aria-describedby="paperless-unified-search-personal-always-search-hint"
			<?php if ($trusted || $_['alwaysSearch']) {
				print_unescaped('checked');
			} ?>
			<?php if ($trusted) {
				print_unescaped('disabled');
			} ?>>
		<label for="paperless-unified-search-personal-always-search">
			<?php p($l->t('Always search my Paperless documents')); ?>
		</label>
		<p id="paperless-unified-search-personal-always-search-hint" class="settings-hint">
			<?php p($trusted
				? $l->t('Your administrator includes Paperless in every search of every user, so your searches always include it.')
				: $l->t('Every search of yours then includes Paperless, without the switch. The terms that you search for then go to the Paperless server of this Nextcloud, also when you look for something else, such as a file or a contact.')); ?>
		</p>
	</div>

	<p id="paperless-unified-search-personal-status" class="paperless-unified-search-status" role="status" aria-live="polite"></p>
</div>
