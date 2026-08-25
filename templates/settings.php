<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{config: \OCA\PaperlessUnifiedSearch\Model\PublicConfig, saveUrl: string, resetUrl: string} $_
 */

script('paperless_unified_search', 'settings');
style('paperless_unified_search', 'settings');

$config = $_['config'];
?>

<div
	id="paperless-unified-search-settings"
	class="section paperless-unified-search-settings"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-reset-url="<?php p($_['resetUrl']); ?>"
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
		<p><?php p($l->t('A Paperless document is shown only when an accessible Nextcloud filename contains its unique marker, for example [P123].')); ?></p>
	</div>
</div>
