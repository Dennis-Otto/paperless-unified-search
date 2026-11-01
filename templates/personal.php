<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{alwaysSearchAllowed: bool, alwaysSearch: bool, trusted: bool, saveUrl: string, alertsAllowed: bool, everyDocument: bool, terms: string, mostTerms: int, alertsUrl: string} $_
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
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-alerts-url="<?php p($_['alertsUrl']); ?>">
	<h2><?php p($l->t('Paperless')); ?></h2>

	<?php if ($_['alwaysSearchAllowed']) { ?>
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
	<?php } ?>

	<?php if ($_['alertsAllowed']) { ?>
		<h3><?php p($l->t('Notify me about new documents')); ?></h3>

		<p class="settings-hint">
			<?php p($l->t('Nextcloud notifies you when Paperless adds a document that you ask for here, as soon as its file has arrived and you can open it. A document that you can’t open never reaches you.')); ?>
		</p>

		<div class="paperless-unified-search-option">
			<input
				id="paperless-unified-search-personal-every-document"
				type="checkbox"
				<?php if ($_['everyDocument']) {
					print_unescaped('checked');
				} ?>>
			<label for="paperless-unified-search-personal-every-document">
				<?php p($l->t('Every new document that I can open')); ?>
			</label>
		</div>

		<p>
			<label for="paperless-unified-search-personal-terms">
				<?php p($l->t('Or only those that these searches find, one per line')); ?>
			</label>
			<textarea
				id="paperless-unified-search-personal-terms"
				rows="4"
				spellcheck="false"
				aria-describedby="paperless-unified-search-personal-terms-hint"
				placeholder="<?php p('correspondent:"tax office"' . "\n" . 'tag:unpaid'); ?>"
				<?php if ($_['everyDocument']) {
					print_unescaped('disabled');
				} ?>><?php p($_['terms']); ?></textarea>
		</p>

		<p id="paperless-unified-search-personal-terms-hint" class="settings-hint">
			<?php p($l->t('Up to %s searches in the syntax of Paperless, the same as in the search of Nextcloud. They stay stored with your account, and a background job of this Nextcloud sends them to the Paperless server of this Nextcloud whenever it has added documents.', [$_['mostTerms']])); ?>
		</p>

		<div class="paperless-unified-search-actions">
			<button id="paperless-unified-search-personal-alerts-save" type="button" class="primary">
				<?php p($l->t('Save')); ?>
			</button>
		</div>

		<p id="paperless-unified-search-personal-alerts-status" class="paperless-unified-search-status" role="status" aria-live="polite"></p>
	<?php } ?>
</div>
