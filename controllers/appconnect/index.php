<div class="padded-container">

    <h4>Connect a new device</h4>
    <p class="text-muted">
        Creates an access token for your admin account and shows it as a QR code.
        Scan it with the Tailor Companion app — or enter URL, login and token manually.
        The token is only displayed once.
    </p>

    <form
        data-request="onCreateToken"
        data-request-flash
        class="form-inline mb-4"
        onsubmit="return false;">
        <div class="d-flex align-items-center" style="gap: 10px; max-width: 520px;">
            <input
                type="text"
                name="device_name"
                class="form-control flex-grow-1"
                placeholder="Device name (e.g. Renick's iPhone)"
                maxlength="120" />
            <button type="submit" class="btn btn-primary" data-request="onCreateToken">
                Connect new device
            </button>
        </div>
    </form>

    <div id="pairingResult"></div>

    <hr />

    <h4>Issued tokens</h4>
    <div id="tokenList">
        <?= $this->makePartial('token_list') ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js"></script>
