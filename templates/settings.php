<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{config: \OCA\PaperlessUnifiedSearch\Model\PublicConfig, saveUrl: string, resetUrl: string, clearDiagnosticsUrl: string, history: ?array{since: string, failed: int, retried: int, lastFailure: string, recovery: string, events: list<array{time: string, kind: string, step: string, error: string, durationMs: int}>}} $_
 */

script('paperless_unified_search', 'settings');
style('paperless_unified_search', 'settings');

$config = $_['config'];
$history = $_['history'];
?>

<div
	id="paperless-unified-search-settings"
	class="section paperless-unified-search-settings"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-reset-url="<?php p($_['resetUrl']); ?>"
	data-clear-diagnostics-url="<?php p($_['clearDiagnosticsUrl']); ?>"
	data-token-configured="<?php p($config->tokenConfigured ? 'true' : 'false'); ?>">
	<h2><?php p($l->t('Paperless Unified Search')); ?></h2>

	<p class="settings-hint">
		<?php p($l->t('Use Paperless-ngx OCR and full-text search from Nextcloud. Results open the matching synchronized file in Nextcloud.')); ?>
	</p>

	<form id="paperless-unified-search-form">
		<p>
			<label for="paperless-unified-search-url"><?php p($l->t('Paperless URL')); ?></label>
			<input
				id="paperless-unified-search-url"
				name="url"
				type="url"
				value="<?php p($config->url); ?>"
				placeholder="https://paperless.example.com"
				required>
		</p>

		<p>
			<label for="paperless-unified-search-token"><?php p($l->t('Paperless API token')); ?></label>
			<input
				id="paperless-unified-search-token"
				name="token"
				type="password"
				autocomplete="new-password"
				placeholder="<?php p($config->tokenConfigured ? $l->t('Configured — leave blank to keep it') : $l->t('Required')); ?>">
		</p>

		<p class="settings-hint">
			<?php p($l->t('Use a dedicated, read-only Paperless account. The token is encrypted by Nextcloud and is never returned to the browser.')); ?>
		</p>

		<p>
			<label for="paperless-unified-search-archive-owner"><?php p($l->t('Archive account')); ?></label>
			<input
				id="paperless-unified-search-archive-owner"
				name="archiveOwner"
				type="text"
				autocomplete="off"
				value="<?php p($config->archiveOwner); ?>"
				aria-describedby="paperless-unified-search-archive-owner-hint"
				placeholder="<?php p($config->syncAccount !== '' ? $l->t('Paperless Sync: %s — leave blank to use it', [$config->syncAccount]) : $l->t('User ID of the account that owns the synchronized files')); ?>">
		</p>

		<p id="paperless-unified-search-archive-owner-hint" class="settings-hint">
			<?php p($l->t('Only the files of this account stand for Paperless documents: a user sees a document when this account shares its file with them. Share the archive read-only. Without an archive account, the search shows no Paperless documents.')); ?>
		</p>

		<div class="paperless-unified-search-trusted-service">
			<input
				id="paperless-unified-search-always-search"
				name="alwaysSearch"
				type="checkbox"
				<?php if ($config->alwaysSearch) {
					print_unescaped('checked');
				} ?>>
			<label for="paperless-unified-search-always-search">
				<?php p($l->t('Always include Paperless in global search')); ?>
			</label>
			<p class="settings-hint">
				<?php p($l->t('Treat this Paperless server as a trusted service. When enabled, every global search term from every Nextcloud user is sent to Paperless automatically. The “Search connected services” switch no longer controls this provider. Reload Nextcloud after changing this setting.')); ?>
			</p>
		</div>

		<div class="paperless-unified-search-actions">
			<button id="paperless-unified-search-save" type="submit" class="primary">
				<?php p($l->t('Test connection and save')); ?>
			</button>
			<button id="paperless-unified-search-reset" type="button" <?php if (!$config->tokenConfigured && $config->url === '') {
				print_unescaped('disabled');
			} ?>>
				<?php p($l->t('Disconnect')); ?>
			</button>
		</div>
	</form>

	<p id="paperless-unified-search-status" class="paperless-unified-search-status" role="status" aria-live="polite"></p>

	<div class="paperless-unified-search-note">
		<strong><?php p($l->t('File matching')); ?></strong>
		<p><?php p($l->t('A Paperless document is shown only when the user can open a file of the archive account whose name contains its unique marker, for example [P123].')); ?></p>
	</div>

	<div class="paperless-unified-search-diagnostics">
		<h3><?php p($l->t('Search problems')); ?></h3>
		<div id="paperless-unified-search-diagnostics">
			<?php if ($history === null) { ?>
				<p><?php p($l->t('No problem recorded.')); ?></p>
			<?php } else { ?>
				<table class="paperless-unified-search-summary">
					<tbody>
						<tr>
							<th scope="row"><?php p($l->t('Recorded since')); ?></th>
							<td><?php p($history['since']); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php p($l->t('Failed searches')); ?></th>
							<td><?php p((string)$history['failed']); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php p($l->t('Answered on the second try')); ?></th>
							<td><?php p((string)$history['retried']); ?></td>
						</tr>
						<?php if ($history['lastFailure'] !== '') { ?>
							<tr>
								<th scope="row"><?php p($l->t('Last failed search')); ?></th>
								<td><?php p($history['lastFailure']); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php p($l->t('Searches work again since')); ?></th>
								<td><?php p($history['recovery'] !== '' ? $history['recovery'] : $l->t('No search has worked since.')); ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
				<table class="paperless-unified-search-events">
					<caption><?php p($l->t('The latest problems, newest first')); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php p($l->t('Time')); ?></th>
							<th scope="col"><?php p($l->t('Event')); ?></th>
							<th scope="col"><?php p($l->t('Step')); ?></th>
							<th scope="col"><?php p($l->t('Error')); ?></th>
							<th scope="col"><?php p($l->t('Duration')); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($history['events'] as $event) { ?>
							<tr>
								<td><?php p($event['time']); ?></td>
								<td><?php p($event['kind'] === \OCA\PaperlessUnifiedSearch\Model\SearchEvent::KIND_FAILED ? $l->t('Search failed') : $l->t('Second try answered')); ?></td>
								<td><?php p($event['step'] === \OCA\PaperlessUnifiedSearch\Model\SearchEvent::STEP_FILES ? $l->t('Looking for the files in Nextcloud') : $l->t('Asking Paperless')); ?></td>
								<td><?php p($event['error']); ?></td>
								<td><?php p($l->t('%s ms', [(string)$event['durationMs']])); ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
				<button id="paperless-unified-search-clear-diagnostics" type="button">
					<?php p($l->t('Clear history')); ?>
				</button>
			<?php } ?>
		</div>
		<p class="settings-hint">
			<?php p($l->t('A failed search shows the users no Paperless documents and no error. When a request to Paperless gets no answer, the app sends it a second time. The history keeps the latest %s problems without the API token and the search term; Disconnect and Clear history forget it.', [(string)\OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory::SIZE])); ?>
		</p>
	</div>
</div>
