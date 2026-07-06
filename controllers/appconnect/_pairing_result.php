<div class="callout callout-success mb-4">
    <div class="header p-3">
        <h3>Device pairing ready<?= $model->name ? ' — ' . e($model->name) : '' ?></h3>
        <p class="mb-0">
            Scan this QR code with the Tailor Companion app.
            <strong>The token is shown only once.</strong>
            <?php if ($model->expires_at): ?>
                Expires <?= e($model->expires_at->toDayDateTimeString()) ?>.
            <?php endif ?>
        </p>
    </div>
    <div class="content p-3" style="background: white;">
        <div id="pairingQr" style="width: 220px; height: 220px;"></div>
        <div class="mt-3">
            <label class="form-label">Manual entry</label>
            <table class="table table-sm" style="max-width: 640px;">
                <tr>
                    <th style="width: 90px;">URL</th>
                    <td><code><?= e(url('/')) ?></code></td>
                </tr>
                <tr>
                    <th>Login</th>
                    <td><code><?= e(BackendAuth::getUser()->login) ?></code></td>
                </tr>
                <tr>
                    <th>Token</th>
                    <td><code style="word-break: break-all;"><?= e($rawToken) ?></code></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<script>
    (function () {
        var el = document.getElementById('pairingQr');
        el.innerHTML = '';
        new QRCode(el, {
            text: <?= json_encode($payloadJson) ?>,
            width: 220,
            height: 220,
            correctLevel: QRCode.CorrectLevel.M
        });
    })();
</script>
