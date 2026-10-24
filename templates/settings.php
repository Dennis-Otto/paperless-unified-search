<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{config: \OCA\PaperlessUnifiedSearch\Model\PublicConfig, saveUrl: string, resetUrl: string, clearDiagnosticsUrl: string, tryUrl: string, history: ?array{since: string, failed: int, retried: int, lastFailure: string, recovery: string, events: list<array{time: string, kind: string, step: string, error: string, durationMs: int}>}, shares: ?array{summary: string, warnings: list<string>}} $_
 */

script('paperless_unified_search', 'settings');
style('paperless_unified_search', 'settings');

$config = $_['config'];
$history = $_['history'];
$shares = $_['shares'];
// The archive account in use: the one of the settings, or else that of Paperless Sync.
$archiveAccount = $config->archiveOwner !== '' ? $config->archiveOwner : $config->syncAccount;
?>

<div
	id="paperless-unified-search-settings"
	class="section paperless-unified-search-settings"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-reset-url="<?php p($_['resetUrl']); ?>"
	data-clear-diagnostics-url="<?php p($_['clearDiagnosticsUrl']); ?>"
	data-try-url="<?php p($_['tryUrl']); ?>"
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

		<div class="paperless-unified-search-option">
			<input
				id="paperless-unified-search-allow-local"
				name="allowLocal"
				type="checkbox"
				aria-describedby="paperless-unified-search-allow-local-hint"
				<?php if ($config->allowLocal) {
					print_unescaped('checked');
				} ?>>
			<label for="paperless-unified-search-allow-local">
				<?php p($l->t('Paperless runs in my local network')); ?>
			</label>
			<p id="paperless-unified-search-allow-local-hint" class="settings-hint">
				<?php p($l->t('Select this when Nextcloud reaches Paperless at an address of your local network or by the name of its Docker service, such as http://192.168.1.20:8000 or http://paperless:8000. Nextcloud refuses such addresses unless they are allowed. This allows only the Paperless URL above, without redirects, and every other app keeps Nextcloud\'s protection.')); ?>
			</p>
		</div>

		<p>
			<label for="paperless-unified-search-token"><?php p($l->t('Paperless API token')); ?></label>
			<input
				id="paperless-unified-search-token"
				name="token"
				type="password"
				autocomplete="new-password"
				aria-describedby="paperless-unified-search-token-unreadable paperless-unified-search-token-hint"
				placeholder="<?php p($config->tokenConfigured ? $l->t('Configured — leave blank to keep it') : $l->t('Required')); ?>"
				<?php if (!$config->tokenConfigured) {
					print_unescaped('required');
				} ?>>
		</p>

		<p id="paperless-unified-search-token-unreadable" class="paperless-unified-search-warning"><?php if ($config->tokenUnreadable) {
			p($l->t('The stored API token can\'t be decrypted, for example because the secret in config.php changed. Enter it again.'));
		} ?></p>

		<p id="paperless-unified-search-token-hint" class="settings-hint">
			<?php p($l->t('Use a dedicated, read-only Paperless account. The token is encrypted by Nextcloud and is never returned to the browser. It is only sent to the server it was saved for, so another server needs it entered again.')); ?>
		</p>

		<p>
			<label for="paperless-unified-search-archive-owner"><?php p($l->t('Archive account')); ?></label>
			<input
				id="paperless-unified-search-archive-owner"
				name="archiveOwner"
				type="text"
				autocomplete="off"
				value="<?php p($config->archiveOwner); ?>"
				aria-describedby="paperless-unified-search-archive-owner-missing paperless-unified-search-archive-owner-hint"
				placeholder="<?php p($config->syncAccount !== '' ? $l->t('Paperless Sync: %s — leave blank to use it', [$config->syncAccount]) : $l->t('User ID of the account that owns the synchronized files')); ?>">
		</p>

		<p id="paperless-unified-search-archive-owner-missing" class="paperless-unified-search-warning"><?php if ($config->archiveOwnerMissing) {
			p($l->t('The archive account %s does not exist.', [$archiveAccount]));
		} ?></p>

		<p id="paperless-unified-search-archive-owner-hint" class="settings-hint">
			<?php p($l->t('Only the files of this account stand for Paperless documents: a user sees a document when this account shares its file with them. Share the archive read-only. Without an archive account, the search shows no Paperless documents.')); ?>
		</p>

		<?php if ($shares !== null) { ?>
			<div id="paperless-unified-search-shares" class="paperless-unified-search-shares">
				<p id="paperless-unified-search-shares-summary"><?php p($shares['summary']); ?></p>
				<?php if ($shares['warnings'] !== []) { ?>
					<ul id="paperless-unified-search-shares-warnings" class="paperless-unified-search-shares-warnings">
						<?php foreach ($shares['warnings'] as $warning) { ?>
							<li class="paperless-unified-search-warning"><?php p($warning); ?></li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		<?php } ?>

		<div class="paperless-unified-search-option">
			<input
				id="paperless-unified-search-always-search"
				name="alwaysSearch"
				type="checkbox"
				aria-describedby="paperless-unified-search-always-search-hint"
				<?php if ($config->alwaysSearch) {
					print_unescaped('checked');
				} ?>>
			<label for="paperless-unified-search-always-search">
				<?php p($l->t('Always include Paperless in global search')); ?>
			</label>
			<p id="paperless-unified-search-always-search-hint" class="settings-hint">
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
				<?php
				// On a phone, each row is a card whose cells name their column (data-label).
				// The roles keep it a table for screen readers, which some browsers no
				// longer see in a table whose parts the style displays as blocks.
				$columns = [
					'time' => $l->t('Time'),
					'event' => $l->t('Event'),
					'step' => $l->t('Step'),
					'error' => $l->t('Error'),
					'duration' => $l->t('Duration'),
				];
				?>
				<table class="paperless-unified-search-events" role="table">
					<caption><?php p($l->t('The latest problems, newest first')); ?></caption>
					<thead role="rowgroup">
						<tr role="row">
							<?php foreach ($columns as $column) { ?>
								<th scope="col" role="columnheader"><?php p($column); ?></th>
							<?php } ?>
						</tr>
					</thead>
					<tbody role="rowgroup">
						<?php foreach ($history['events'] as $event) { ?>
							<tr role="row">
								<td role="cell" data-label="<?php p($columns['time']); ?>"><?php p($event['time']); ?></td>
								<td role="cell" data-label="<?php p($columns['event']); ?>"><?php p($event['kind'] === \OCA\PaperlessUnifiedSearch\Model\SearchEvent::KIND_FAILED ? $l->t('Search failed') : $l->t('Second try answered')); ?></td>
								<td role="cell" data-label="<?php p($columns['step']); ?>"><?php p($event['step'] === \OCA\PaperlessUnifiedSearch\Model\SearchEvent::STEP_FILES ? $l->t('Looking for the files in Nextcloud') : $l->t('Asking Paperless')); ?></td>
								<td role="cell" data-label="<?php p($columns['error']); ?>"><?php p($event['error']); ?></td>
								<td role="cell" data-label="<?php p($columns['duration']); ?>"><?php p($l->t('%s ms', [(string)$event['durationMs']])); ?></td>
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

	<div class="paperless-unified-search-try">
		<h3><?php p($l->t('Try a search')); ?></h3>
		<p id="paperless-unified-search-try-hint" class="settings-hint">
			<?php p($l->t('See which Paperless documents a user finds for a term, and why the others don\'t show to them. The answer lists the first %s documents that Paperless ranks for the term, by their titles, without their content. The audit log of Nextcloud records who tried a search as whom.', [(string)\OCA\PaperlessUnifiedSearch\Controller\SettingsController::TRY_LIMIT])); ?>
		</p>
		<form id="paperless-unified-search-try-form">
			<p>
				<label for="paperless-unified-search-try-term"><?php p($l->t('Search term')); ?></label>
				<input
					id="paperless-unified-search-try-term"
					name="term"
					type="search"
					autocomplete="off"
					aria-describedby="paperless-unified-search-try-hint"
					required>
			</p>
			<p>
				<label for="paperless-unified-search-try-user"><?php p($l->t('Search as the user')); ?></label>
				<input
					id="paperless-unified-search-try-user"
					name="user"
					type="text"
					autocomplete="off"
					placeholder="<?php p($l->t('User ID')); ?>"
					required>
			</p>
			<div class="paperless-unified-search-actions">
				<button id="paperless-unified-search-try" type="submit">
					<?php p($l->t('Try a search')); ?>
				</button>
			</div>
		</form>
		<p id="paperless-unified-search-try-status" class="paperless-unified-search-status" role="status" aria-live="polite"></p>
		<div id="paperless-unified-search-try-result"></div>
		<div class="paperless-unified-search-note">
			<strong><?php p($l->t('Why a document doesn\'t show')); ?></strong>
			<ul class="paperless-unified-search-reasons">
				<li><?php p($l->t('Shared with write permission: the user may change the file of the archive account, or add files to its folder, so its name proves nothing. Share it read-only with the user.')); ?></li>
				<li><?php p($l->t('Not shared: the archive account has the file of the document, but hasn\'t shared it with the user. Share the file, or a folder that holds it, read-only with the user.')); ?></li>
				<li><?php p($l->t('Not a file of the archive account: the user sees a file with the marker of the document, but only the files of the archive account stand for documents.')); ?></li>
				<li><?php p($l->t('No file: no file of the archive account has the marker of the document, such as [P123], in its name. The synchronization hasn\'t written it yet, or names files without it.')); ?></li>
			</ul>
		</div>
	</div>
</div>
