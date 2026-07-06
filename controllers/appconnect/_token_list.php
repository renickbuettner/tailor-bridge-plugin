<?php if (!count($tokens)): ?>
    <p class="text-muted">No tokens issued yet.</p>
<?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Token</th>
                <th>Device</th>
                <th>User</th>
                <th>Created</th>
                <th>Last used</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tokens as $token): ?>
                <tr>
                    <td><code><?= e($token->token_prefix) ?>…</code></td>
                    <td><?= e($token->name ?: '—') ?></td>
                    <td><?= e($token->user ? $token->user->login : '(deleted)') ?></td>
                    <td><?= e($token->created_at->toFormattedDateString()) ?></td>
                    <td><?= e($token->last_used_at ? $token->last_used_at->diffForHumans() : 'never') ?></td>
                    <td>
                        <?php if ($token->revoked_at): ?>
                            <span class="badge bg-danger">revoked</span>
                        <?php elseif (!$token->isActive()): ?>
                            <span class="badge bg-warning">expired</span>
                        <?php else: ?>
                            <span class="badge bg-success">active</span>
                        <?php endif ?>
                    </td>
                    <td class="text-end">
                        <?php if ($token->isActive()): ?>
                            <button
                                class="btn btn-sm btn-outline-danger"
                                data-request="onRevokeToken"
                                data-request-data="token_id: <?= (int) $token->id ?>"
                                data-request-confirm="Revoke this token? The device will lose access immediately.">
                                Revoke
                            </button>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
