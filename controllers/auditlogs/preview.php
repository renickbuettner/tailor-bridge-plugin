<?php Block::put('breadcrumb') ?>
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= Backend::url('renick/tailorcompanion/auditlogs') ?>">App Audit Log</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(__($this->pageTitle)) ?></li>
    </ol>
<?php Block::endPut() ?>

<?php if (!$this->fatalError): ?>

    <div class="scoreboard">
        <div data-control="toolbar">
            <div class="scoreboard-item title-value">
                <h4>Entry</h4>
                <p>#<?= $formModel->id ?></p>
            </div>
            <div class="scoreboard-item title-value">
                <h4>Action</h4>
                <p><?= e($formModel->action) ?></p>
            </div>
            <div class="scoreboard-item title-value">
                <h4>Date &amp; Time</h4>
                <p><?= $formModel->created_at->toDayDateTimeString() ?></p>
            </div>
        </div>
    </div>

    <div class="flex-grow-1">
        <?= $this->formRenderPreview() ?>
    </div>

<?php else: ?>

    <p class="flash-message static error"><?= e(__($this->fatalError)) ?></p>

<?php endif ?>

<p>
    <a href="<?= Backend::url('renick/tailorcompanion/auditlogs') ?>" class="btn btn-default oc-icon-chevron-left">
        Return to Audit Log
    </a>
</p>
