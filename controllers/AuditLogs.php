<?php namespace Renick\TailorCompanion\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use System\Classes\SettingsManager;

/**
 * AuditLogs backend page: read-only list + preview of every change made
 * through the companion app API.
 */
class AuditLogs extends Controller
{
    /**
     * @var array implement extensions
     */
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
    ];

    /**
     * @var string formConfig file
     */
    public $formConfig = 'config_form.yaml';

    /**
     * @var string listConfig file
     */
    public $listConfig = 'config_list.yaml';

    /**
     * @var array requiredPermissions to view this page
     */
    public $requiredPermissions = ['renick.tailorcompanion.view_audit_log'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Renick.TailorCompanion', 'auditlogs');
    }

    public function index()
    {
        $this->pageTitle = 'App Audit Log';
        $this->asExtension('ListController')->index();
    }

    public function preview($recordId = null)
    {
        $this->pageTitle = 'Audit Entry';
        return $this->asExtension('FormController')->preview($recordId);
    }
}
