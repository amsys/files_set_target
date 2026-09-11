<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'ocs' => [
		[
			'name' => 'admin#saveSettings',
			'url' => '/api/v1/admin/settings',
			'verb' => 'POST',
		],
		[
			'name' => 'shareTargetApi#setTarget',
			'url' => '/api/v1/target',
			'verb' => 'POST',
		],
		[
			'name' => 'shareTargetApi#deleteTarget',
			'url' => '/api/v1/target/{fileId}',
			'verb' => 'DELETE',
		],
	],
];
